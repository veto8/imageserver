<?php

namespace Salamander\Imageserver;

class IS_Frontend
{
    public function register()
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        if (!$this->enabled()) {
            return;
        }

        add_filter('woocommerce_product_get_image', [$this, 'product_image'], 10, 3);

        if (is_admin()) {
            return;
        }

        add_filter('woocommerce_single_product_image_thumbnail_html', [$this, 'single_product_image'], 10, 2);
        add_filter('woocommerce_cart_item_thumbnail', [$this, 'cart_item_thumbnail'], 10, 3);
        add_filter('woocommerce_order_item_thumbnail', [$this, 'email_order_item_thumbnail'], 10, 2);
        add_filter('woocommerce_store_api_cart_item_images', [$this, 'cart_item_images'], 10, 3);
        add_filter('render_block', [$this, 'render_block'], 10, 3);
    }

    public function single_product_image($html, $attachment_id)
    {
        $product = $this->current_product();
        $index = $this->gallery_index($product, $attachment_id);
        $size = $index > 0 ? 'woocommerce_gallery_thumbnail' : 'woocommerce_single';

        return $this->rewrite_html($html, $this->product_image_url($product, $size, $index));
    }

    public function product_image($html, $product, $size = 'woocommerce_thumbnail')
    {
        $is_variation = is_object($product) && method_exists($product, 'get_type') && $product->get_type() === 'variation';

        return $this->rewrite_html($html, $this->product_image_url($product, $is_variation ? 'woocommerce_single' : $size));
    }

    public function cart_item_thumbnail($html, $cart_item, $cart_item_key = '')
    {
        $product = is_array($cart_item) && isset($cart_item['data']) ? $cart_item['data'] : null;

        return $this->rewrite_html($html, $this->product_image_url($product, 'woocommerce_thumbnail'));
    }

    public function cart_item_images($images, $cart_item, $cart_item_key = '')
    {
        if (is_array($images) && count($images) > 0) {
            return $images;
        }

        $product = is_array($cart_item) && isset($cart_item['data']) ? $cart_item['data'] : null;
        $paths = $this->product_paths($product);

        if (!$paths) {
            return $images;
        }

        $thumbnail = $this->path_url($paths[0], 'woocommerce_thumbnail');
        $full = $this->path_url($paths[0], 'woocommerce_single');

        if (!$thumbnail || !$full) {
            return $images;
        }

        $name = is_object($product) && method_exists($product, 'get_name') ? $product->get_name() : '';
        $id = is_object($product) && method_exists($product, 'get_id') ? (int) $product->get_id() : 0;

        return [(object) [
            'id'               => $id,
            'src'              => $full,
            'thumbnail'        => $thumbnail,
            'srcset'           => '',
            'sizes'            => '',
            'thumbnail_srcset' => '',
            'thumbnail_sizes'  => '',
            'name'             => $name,
            'alt'              => $name,
        ]];
    }

    public function email_order_item_thumbnail($html, $item)
    {
        $product = is_object($item) && method_exists($item, 'get_product') ? $item->get_product() : null;

        return $this->rewrite_html($html, $this->product_image_url($product, 'woocommerce_thumbnail'));
    }

    public function render_block($block_content, $block, $instance)
    {
        if (empty($block['blockName']) || $block['blockName'] !== 'woocommerce/product-image') {
            return $block_content;
        }

        $post_id = is_object($instance) && isset($instance->context['postId']) ? (int) $instance->context['postId'] : 0;

        if (!$post_id || !is_string($block_content) || stripos($block_content, '<img') === false || !function_exists('wc_get_product')) {
            return $block_content;
        }

        $paths = $this->product_paths(wc_get_product($post_id));

        if (!$paths) {
            return $block_content;
        }

        $width = 0;
        $height = 0;

        if (preg_match('/<img[^>]*\swidth\s*=\s*["\']?(\d+)/i', $block_content, $matches)) {
            $width = (int) $matches[1];
        }

        if (preg_match('/<img[^>]*\sheight\s*=\s*["\']?(\d+)/i', $block_content, $matches)) {
            $height = (int) $matches[1];
        }

        return $this->rewrite_sources($block_content, $this->path_url($paths[0], 'woocommerce_thumbnail', $width, $height));
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

    private function path_url($path, $size, $width = null, $height = null)
    {
        $path = trim($path);

        if (preg_match('#^https?://#i', $path)) {
            return esc_url_raw($path);
        }

        $settings = wp_parse_args((array) get_option(IS_Admin::OPTION, []), IS_Admin::defaults());
        $source = untrailingslashit($settings['source']);
        $path = ltrim($path, '/');
        $encoded_path = implode('/', array_map('rawurlencode', explode('/', $path)));
        $dimensions = $this->size_dimensions($size);

        if (is_numeric($width) && (int) $width > 0) {
            $dimensions[0] = (int) $width;
        }

        if (is_numeric($height) && (int) $height > 0) {
            $dimensions[1] = (int) $height;
        }
        $pattern = $size ? $settings['resize_pattern'] : $settings['original_pattern'];
        $pattern = strtr($pattern, [
            '{path}' => $encoded_path,
            '{size}' => rawurlencode((string) $size),
            '{width}' => rawurlencode((string) $dimensions[0]),
            '{height}' => rawurlencode((string) $dimensions[1]),
        ]);

        return esc_url_raw($source . $pattern);
    }

    private function size_dimensions($size)
    {
        $width = 0;
        $height = 0;

        if (function_exists('wc_get_image_size') && is_string($size) && $size !== '') {
            $registered = wc_get_image_size($size);

            if (is_array($registered)) {
                $width = isset($registered['width']) ? (int) $registered['width'] : 0;
                $height = isset($registered['height']) ? (int) $registered['height'] : 0;
            }
        }

        if ($width <= 0) {
            $width = is_numeric($size) ? (int) $size : 0;
        }

        if ($height <= 0) {
            $height = $width;
        }

        return [$width, $height];
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

    private function rewrite_sources($html, $url)
    {
        if (!$url || !is_string($html) || stripos($html, '<img') === false) {
            return $html;
        }

        $html = preg_replace_callback(
            '/(\ssrc\s*=\s*)(["\'])(.*?)\2/i',
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
