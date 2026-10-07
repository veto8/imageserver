<?php

namespace Salamander\Imageserver;

class IS_Server_Api
{
    public const API_PATH = '/api/patterns';
    public const TIMEOUT = 5;

    public static function fetch($source)
    {
        $source = untrailingslashit(esc_url_raw((string) $source));

        if ($source === '' || !wp_http_validate_url($source)) {
            return new \WP_Error('imageserver_source', 'The image server source is not a valid URL.');
        }

        $response = wp_remote_get($source . self::API_PATH, [
            'timeout' => self::TIMEOUT,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code < 200 || $code >= 300) {
            return new \WP_Error('imageserver_http', sprintf('The image server returned HTTP %d.', $code));
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($data) || !isset($data['patterns']) || !is_array($data['patterns'])) {
            return new \WP_Error('imageserver_json', 'The image server did not return a pattern manifest.');
        }

        $patterns = [];

        foreach ($data['patterns'] as $key => $pattern) {
            $key = sanitize_key($key);
            $pattern = self::sanitize_pattern($pattern);

            if ($key !== '' && $pattern !== '') {
                $patterns[$key] = $pattern;
            }
        }

        if (empty($patterns)) {
            return new \WP_Error('imageserver_empty', 'The image server manifest contains no usable patterns.');
        }

        $examples = [];

        if (isset($data['examples']) && is_array($data['examples'])) {
            foreach ($data['examples'] as $key => $example) {
                $key = sanitize_key($key);
                $example = self::sanitize_example($example);

                if ($key !== '' && $example !== '') {
                    $examples[$key] = $example;
                }
            }
        }

        $defaults = isset($data['defaults']) && is_array($data['defaults']) ? $data['defaults'] : [];

        $options = [];

        if (isset($data['resize_options']) && is_array($data['resize_options'])) {
            foreach ($data['resize_options'] as $option) {
                $option = sanitize_key($option);

                if ($option !== '' && isset($patterns[$option])) {
                    $options[] = $option;
                }
            }
        }

        if (empty($options)) {
            $options = array_keys($patterns);
        }

        $roles = isset($data['roles']) && is_array($data['roles']) ? $data['roles'] : ['original' => 'plain'];

        return [
            'patterns' => $patterns,
            'examples' => $examples,
            'roles' => $roles,
            'resize_options' => $options,
            'defaults' => $defaults,
        ];
    }

    private static function sanitize_example($value)
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        if ($value === '' || strpos($value, '://') !== false) {
            return '';
        }

        return '/' . ltrim($value, '/');
    }

    private static function sanitize_pattern($value)
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        if ($value === '' || strpos($value, '{path}') === false) {
            return '';
        }

        return '/' . ltrim($value, '/');
    }
}
