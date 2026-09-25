<?php

namespace Salamander\Imageserver;

class IS_Frontend
{
    public function register()
    {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }

        if (!class_exists('WooCommerce')) {
            return;
        }

        if (!$this->enabled()) {
            return;
        }

        add_filter('woocommerce_single_product_image_thumbnail_html', [$this, 'single_product_image'], 10, 2);
        add_filter('woocommerce_gallery_thumbnail_html', [$this, 'gallery_thumbnail'], 10, 2);
        add_filter('woocommerce_catalog_product_thumbnail', [$this, 'catalog_thumbnail'], 10, 4);
        add_filter('woocommerce_cart_item_thumbnail', [$this, 'cart_item_thumbnail'], 10, 3);
        add_filter('woocommerce_email_order_item_thumbnail', [$this, 'email_order_item_thumbnail'], 10, 3);
        add_filter('woocommerce_variation_image_html', [$this, 'variation_image'], 10, 3);
    }

    public function single_product_image($html, $attachment_id)
    {
        $product = $this->current_product();

        return $this->rewrite_html($html, $this->product_image_url($product, 'woocommerce_single'));
    }

    public function gallery_thumbnail($html, $attachment_id)
    {
        $product = $this->current_product();
        $index = $this->gallery_index($product, $attachment_id);

        return $this->rewrite_html($html, $this->product_image_url($product, 'woocommerce_gallery_thumbnail', $index));
    }

    public function catalog_thumbnail($html, $thumbnail_url, $product_id, $product = null)
    {
        $product = $this->resolve_product($product, $product_id);

        return $this->rewrite_html($html, $this->product_image_url($product, 'woocommerce_thumbnail'));
    }

    public function cart_item_thumbnail($html, $cart_item_key, $cart_item)
    {
        $product = is_array($cart_item) && isset($cart_item['data']) ? $cart_item['data'] : null;

        return $this->rewrite_html($html, $this->product_image_url($product, 'woocommerce_thumbnail'));
    }

    public function email_order_item_thumbnail($html, $attachment_id, $image_url)
    {
        $product = $this->current_product();

        return $this->rewrite_html($html, $this->product_image_url($product, 'woocommerce_thumbnail'));
    }

    public function variation_image($html, $attachment_id, $variation_id = null)
    {
        $product = $this->resolve_product($this->current_product(), $variation_id);

        return $this->rewrite_html($html, $this->product_image_url($product, 'woocommerce_single'));
    }

    private function enabled()
    {
        $settings = wp_parse_args((array) get_option(IS_Admin::OPTION, []), IS_Admin::defaults());

        return !empty($settings['enabled']);
    }

    private function current_product()
    {
        global $product;

        if (is_object($product) && method_exists($product, 'get_meta')) {
            return $product;
        }

        if (function_exists('wc_get_product') && function_exists('get_queried_object')) {
            $queried_object = get_queried_object();

            if ($queried_object && isset($queried_object->ID)) {
                return wc_get_product($queried_object->ID);
            }
        }

        return null;
    }

    private function resolve_product($product, $product_id = null)
    {
        if (is_object($product) && method_exists($product, 'get_meta')) {
            return $product;
        }

        if (is_object($product_id) && method_exists($product_id, 'get_meta')) {
            return $product_id;
        }

        if (is_numeric($product_id) && function_exists('wc_get_product')) {
            return wc_get_product((int) $product_id);
        }

        if (is_numeric($product) && function_exists('wc_get_product')) {
            return wc_get_product((int) $product);
        }

        return $this->current_product();
    }

    private function product_paths($product)
    {
        if (!is_object($product) || !method_exists($product, 'get_meta')) {
            return [];
        }

        $paths = [];
        $is_variation = method_exists($product, 'get_type') && $product->get_type() === 'variation';
        $value = $is_variation
            ? $product->get_meta('picture_path', true)
            : $product->get_meta('picture_paths', true);

        $this->collect_paths($value, $paths);

        if (!$paths && $is_variation && method_exists($product, 'get_parent_id')) {
            $parent_id = (int) $product->get_parent_id();

            if ($parent_id && function_exists('wc_get_product')) {
                $parent = wc_get_product($parent_id);
                $this->collect_paths(is_object($parent) && method_exists($parent, 'get_meta') ? $parent->get_meta('picture_paths', true) : [], $paths);
            }
        }

        return array_values(array_unique(array_filter($paths, static function ($path) {
            return is_string($path) && trim($path) !== '';
        })));
    }

    private function collect_paths($value, &$paths)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                $this->collect_paths($decoded, $paths);
                return;
            }

            foreach (preg_split('/[;,|]+/', $value) as $path) {
                $path = trim($path);

                if ($path !== '') {
                    $paths[] = $path;
                }
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $this->collect_paths($item, $paths);
            }
        }
    }

    private function gallery_index($product, $attachment_id)
    {
        if (!is_object($product) || !method_exists($product, 'get_gallery_image_ids')) {
            return 0;
        }

        $attachment_id = (int) $attachment_id;
        $gallery_ids = array_map('intval', (array) $product->get_gallery_image_ids());
        $index = array_search($attachment_id, $gallery_ids, true);

        return $index === false ? 0 : $index + 1;
    }

    private function product_image_url($product, $size, $index = 0)
    {
        $paths = $this->product_paths($product);

        if (!$paths || !isset($paths[$index])) {
            return '';
        }

        return $this->path_url($paths[$index], $size);
    }

    private function path_url($path, $size)
    {
        $path = trim($path);

        if (preg_match('#^https?://#i', $path)) {
            return esc_url_raw($path);
        }

        $settings = wp_parse_args((array) get_option(IS_Admin::OPTION, []), IS_Admin::defaults());
        $source = untrailingslashit($settings['source']);
        $path = ltrim($path, '/');
        $encoded_path = implode('/', array_map('rawurlencode', explode('/', $path)));
        $pattern = $size ? $settings['resize_pattern'] : $settings['original_pattern'];
        $pattern = strtr($pattern, [
            '{path}' => $encoded_path,
            '{size}' => rawurlencode((string) $size),
        ]);

        return esc_url_raw($source . $pattern);
    }

    private function rewrite_html($html, $url)
    {
        if (!$url || !is_string($html) || stripos($html, '<img') === false) {
            return $html;
        }

        $html = preg_replace_callback(
            '/(\s(?:src|data-thumb|href)\s*=\s*)(["\'])(.*?)\2/i',
            static function ($matches) use ($url) {
                return $matches[1] . $matches[2] . esc_url($url) . $matches[2];
            },
            $html
        );

        return preg_replace_callback(
            '/(\ssrcset\s*=\s*)(["\'])(.*?)\2/i',
            static function ($matches) use ($url) {
                return $matches[1] . $matches[2] . esc_url($url) . ' 1x' . $matches[2];
            },
            $html
        );
    }
}
