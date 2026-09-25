<?php

namespace Salamander\Imageserver;

class IS_Admin
{
    public const OPTION = 'imageserver_settings';

    public static function activate()
    {
        add_option(self::OPTION, self::defaults());
    }

    public static function deactivate()
    {
    }

    public function register()
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_settings_page()
    {
        add_options_page(
            'Image Server',
            'Image Server',
            'manage_options',
            'imageserver',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings()
    {
        register_setting(
            self::OPTION,
            self::OPTION,
            [
                'type' => 'array',
                'default' => self::defaults(),
                'sanitize_callback' => [$this, 'sanitize'],
            ]
        );

        add_settings_section(
            'imageserver_source_section',
            'Image server',
            [$this, 'render_section'],
            'imageserver'
        );

        $this->add_field('enabled', 'Enable image rewriting', 'enabled', 'Rewrites WooCommerce front-end image output when enabled.');
        $this->add_field('source', 'Image server source', 'source', 'The base URL of the image server.', 'url');
        $this->add_field('original_pattern', 'Original image pattern', 'original_pattern', 'Use {path} for the source image path.');
        $this->add_field('resize_pattern', 'Resized image pattern', 'resize_pattern', 'Use {path} and {size} for rendered image paths.');
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Image Server</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION);
                do_settings_sections('imageserver');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function render_section()
    {
        echo '<p>Configure the source and URL patterns used for product images.</p>';
    }

    public function render_field($field)
    {
        $settings = wp_parse_args((array) get_option(self::OPTION, []), self::defaults());
        $key = $field['key'];
        $name = self::OPTION . '[' . $key . ']';
        $value = $settings[$key] ?? '';
        ?>
        <tr>
            <th scope="row"><label for="imageserver-<?php echo esc_attr($key); ?>"><?php echo esc_html($field['label']); ?></label></th>
            <td>
                <?php if ($field['type'] === 'checkbox') : ?>
                    <input
                        type="checkbox"
                        id="imageserver-<?php echo esc_attr($key); ?>"
                        name="<?php echo esc_attr($name); ?>"
                        value="1"
                        <?php checked(!empty($settings[$key])); ?>
                    >
                <?php else : ?>
                    <input
                        type="<?php echo esc_attr($field['type'] ?? 'text'); ?>"
                        id="imageserver-<?php echo esc_attr($key); ?>"
                        name="<?php echo esc_attr($name); ?>"
                        value="<?php echo esc_attr($value); ?>"
                        class="regular-text"
                    >
                <?php endif; ?>
                <p class="description"><?php echo esc_html($field['description']); ?></p>
            </td>
        </tr>
        <?php
    }

    public function sanitize($input)
    {
        $input = is_array($input) ? $input : [];
        $defaults = self::defaults();
        $source = untrailingslashit(esc_url_raw($input['source'] ?? $defaults['source']));

        if ($source === '') {
            $source = $defaults['source'];
        }

        return [
            'enabled' => !empty($input['enabled']) ? 1 : 0,
            'source' => $source,
            'original_pattern' => $this->sanitize_pattern($input['original_pattern'] ?? '', $defaults['original_pattern']),
            'resize_pattern' => $this->sanitize_pattern($input['resize_pattern'] ?? '', $defaults['resize_pattern']),
        ];
    }

    public static function defaults()
    {
        return [
            'enabled' => 1,
            'source' => 'https://img.salamander-jewelry.net',
            'original_pattern' => '/img/{path}',
            'resize_pattern' => '/canvas/{size}/{path}',
        ];
    }

    private function add_field($key, $label, $field_key, $description, $type = 'text')
    {
        add_settings_field(
            $key,
            $label,
            [$this, 'render_field'],
            'imageserver',
            'imageserver_source_section',
            [
                'key' => $field_key,
                'label' => $label,
                'description' => $description,
                'type' => $type,
            ]
        );
    }

    private function sanitize_pattern($value, $default)
    {
        $value = trim(sanitize_text_field($value));

        if ($value === '') {
            return $default;
        }

        if (strpos($value, '{path}') === false) {
            $value = rtrim($value, '/') . '/{path}';
        }

        return '/' . ltrim($value, '/');
    }
}
