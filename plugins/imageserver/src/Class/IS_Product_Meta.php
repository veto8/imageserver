<?php

namespace Salamander\Imageserver;

class IS_Product_Meta
{
    public const PRODUCT_KEY = 'picture_paths';
    public const VARIATION_KEY = 'picture_path';
    public const PRODUCT_FIELD = self::PRODUCT_KEY;
    public const VARIATION_FIELD = 'variable_picture_path';
    public const NONCE_FIELD = 'imageserver_meta_nonce';
    public const NONCE_ACTION = 'imageserver_save_picture_paths';

    public function register()
    {
        add_action('init', [$this, 'register_meta_keys']);

        if (!is_admin()) {
            return;
        }

        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product'], 30, 1);
        add_action('woocommerce_product_after_variable_attributes', [$this, 'render_variation_field'], 10, 3);
        add_action('woocommerce_save_product_variation', [$this, 'save_variation'], 10, 2);
    }

    public function register_meta_keys()
    {
        $auth = function ($allowed, $meta_key, $post_id) {
            return current_user_can('edit_post', $post_id);
        };

        register_post_meta('product', self::PRODUCT_KEY, [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => $auth,
        ]);

        register_post_meta('product_variation', self::VARIATION_KEY, [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => $auth,
        ]);
    }

    public function add_meta_box()
    {
        add_meta_box(
            'imageserver-picture-paths',
            'Image server paths',
            [$this, 'render_meta_box'],
            'product',
            'normal',
            'default'
        );
    }

    public function render_meta_box($post)
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $paths = $this->normalize_paths(get_post_meta($post->ID, self::PRODUCT_KEY, true));
        $settings = wp_parse_args((array) get_option(IS_Admin::OPTION, []), IS_Admin::defaults());
        $source = untrailingslashit($settings['source']);
        $pattern = $settings['original_pattern'];
        ?>
        <div
            class="imageserver-paths"
            data-source="<?php echo esc_attr($source); ?>"
            data-pattern="<?php echo esc_attr($pattern); ?>"
            data-field="<?php echo esc_attr(self::PRODUCT_FIELD); ?>"
        >
            <p class="description">
                The images the front end serves, in <code>category/name</code> form, for example
                <code>BC/BCSB51.png</code>. The product sync overwrites this list on its next run.
            </p>
            <ul class="imageserver-paths-list" style="margin:0;">
                <?php foreach ($paths as $path) : ?>
                    <?php $this->render_path_row($path, $source, $pattern); ?>
                <?php endforeach; ?>
            </ul>
            <p>
                <input type="text" class="regular-text code" id="imageserver-new-path" placeholder="BC/BCSB51.png" spellcheck="false">
                <button type="button" class="button" id="imageserver-add-path">Add image</button>
            </p>
        </div>
        <script>
            (function () {
                var wrap = document.querySelector('.imageserver-paths');
                if (!wrap) { return; }
                var list = wrap.querySelector('.imageserver-paths-list');
                var input = wrap.querySelector('#imageserver-new-path');
                var add = wrap.querySelector('#imageserver-add-path');
                var source = wrap.getAttribute('data-source');
                var pattern = wrap.getAttribute('data-pattern');
                var field = wrap.getAttribute('data-field');
                function url(path) {
                    var encoded = path.split('/').map(encodeURIComponent).join('/');
                    return source + pattern.replace('{path}', encoded).replace('{size}', '').replace('{width}', '').replace('{height}', '');
                }
                function row(path) {
                    var li = document.createElement('li');
                    li.className = 'imageserver-path';
                    li.style.cssText = 'display:flex;align-items:center;gap:8px;margin:0 0 6px;';
                    var img = document.createElement('img');
                    img.src = url(path);
                    img.alt = '';
                    img.width = 48;
                    img.height = 48;
                    img.style.cssText = 'object-fit:contain;background:#f0f0f1;border:1px solid #dcdcde;';
                    var code = document.createElement('code');
                    code.textContent = path;
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = field + '[]';
                    hidden.value = path;
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'button-link imageserver-remove';
                    button.style.color = '#b32d2e';
                    button.textContent = 'Delete';
                    li.appendChild(img);
                    li.appendChild(code);
                    li.appendChild(hidden);
                    li.appendChild(button);
                    return li;
                }
                add.addEventListener('click', function () {
                    var path = input.value.trim();
                    if (path === '') { input.focus(); return; }
                    list.appendChild(row(path));
                    input.value = '';
                    input.focus();
                });
                input.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') { event.preventDefault(); add.click(); }
                });
                list.addEventListener('click', function (event) {
                    var button = event.target.closest('.imageserver-remove');
                    if (button) { button.closest('li').remove(); }
                });
            })();
        </script>
        <?php
    }

    private function render_path_row($path, $source, $pattern)
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', ltrim(trim($path), '/'))));
        $url = untrailingslashit($source) . strtr($pattern, [
            '{path}' => $encoded,
            '{size}' => '',
            '{width}' => '',
            '{height}' => '',
        ]);
        ?>
        <li class="imageserver-path" style="display:flex;align-items:center;gap:8px;margin:0 0 6px;">
            <img src="<?php echo esc_url($url); ?>" alt="" width="48" height="48" style="object-fit:contain;background:#f0f0f1;border:1px solid #dcdcde;">
            <code><?php echo esc_html($path); ?></code>
            <input type="hidden" name="<?php echo esc_attr(self::PRODUCT_FIELD); ?>[]" value="<?php echo esc_attr($path); ?>">
            <button type="button" class="button-link imageserver-remove" style="color:#b32d2e;">Delete</button>
        </li>
        <?php
    }

    public function save_product($post_id)
    {
        $post_id = (int) $post_id;

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            return;
        }

        if (!isset($_POST[self::NONCE_FIELD])) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD]));

        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        $raw = isset($_POST[self::PRODUCT_FIELD]) ? wp_unslash($_POST[self::PRODUCT_FIELD]) : '';
        $paths = $this->normalize_paths($raw);

        if ($paths) {
            update_post_meta($post_id, self::PRODUCT_KEY, $paths);
        } else {
            delete_post_meta($post_id, self::PRODUCT_KEY);
        }
    }

    public function render_variation_field($loop, $variation_data, $variation)
    {
        $variation_id = $this->variation_id($variation);
        $paths = $variation_id ? $this->normalize_paths(get_post_meta($variation_id, self::VARIATION_KEY, true)) : [];
        $name = self::VARIATION_FIELD . '[' . (int) $loop . ']';
        ?>
        <div class="form-row form-row-full">
            <label for="<?php echo esc_attr($name); ?>">Image server path</label>
            <input
                type="text"
                class="short"
                name="<?php echo esc_attr($name); ?>"
                id="<?php echo esc_attr($name); ?>"
                value="<?php echo esc_attr(isset($paths[0]) ? $paths[0] : ''); ?>"
                placeholder="BC/BCSB51.png"
            />
        </div>
        <?php
    }

    public function save_variation($variation_id, $loop)
    {
        $variation_id = (int) $variation_id;
        $loop = (int) $loop;

        if (!$variation_id || $loop < 0) {
            return;
        }

        $parent_id = (int) get_post_field('post_parent', $variation_id);

        if (!$parent_id || !current_user_can('edit_post', $parent_id)) {
            return;
        }

        $key = self::VARIATION_FIELD . '[' . $loop . ']';
        $raw = isset($_POST[self::VARIATION_FIELD][$loop]) ? wp_unslash($_POST[self::VARIATION_FIELD][$loop]) : '';
        $paths = $this->normalize_paths(is_string($raw) ? $raw : '');

        if ($paths) {
            update_post_meta($variation_id, self::VARIATION_KEY, $paths[0]);
        } else {
            delete_post_meta($variation_id, self::VARIATION_KEY);
        }
    }

    private function variation_id($variation)
    {
        if (is_object($variation) && isset($variation->ID)) {
            return (int) $variation->ID;
        }

        if (is_object($variation) && method_exists($variation, 'get_id')) {
            return (int) $variation->get_id();
        }

        return 0;
    }

    private function normalize_paths($value)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = preg_split('/[\r\n;,|]+/', $value);
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $paths = [];

        foreach ($value as $item) {
            if (!is_string($item) && !is_numeric($item)) {
                continue;
            }

            $path = sanitize_text_field(trim((string) $item));

            if ($path === '' || in_array($path, $paths, true)) {
                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }
}
