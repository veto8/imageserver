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
        add_action('admin_post_imageserver_fetch_patterns', [$this, 'handle_fetch_patterns']);
        add_action('admin_notices', [$this, 'render_notices']);
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

        $this->add_field('enabled', 'Enable image rewriting', 'enabled', 'Rewrites WooCommerce front-end image output when enabled.', 'checkbox');
        $this->add_field('source', 'Image server source', 'source', 'The base URL of the image server. Change it, then fetch the patterns again.', 'url');
        $this->add_field('resize_style', 'Resize style', 'resize_style', 'Which server pattern is used for resized images. Fetching the patterns fills the resized pattern below.', 'select', self::resize_styles());
        $this->add_field('original_pattern', 'Original image pattern', 'original_pattern', 'Use {path} for the source image path. Fetched from the server, editable to override.');
        $this->add_field('resize_pattern', 'Resized image pattern', 'resize_pattern', 'Use {path}, {width} and {height} for rendered image paths. WooCommerce image sizes are resolved to pixel dimensions. Fetched from the server, editable to override.');
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = wp_parse_args((array) get_option(self::OPTION, []), self::defaults());
        $fetch_url = wp_nonce_url(
            admin_url('admin-post.php?action=imageserver_fetch_patterns'),
            'imageserver_fetch_patterns'
        );
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
            <p>
                <a class="button" href="<?php echo esc_url($fetch_url); ?>">Fetch patterns from server</a>
                <?php if (!empty($settings['patterns_fetched_at'])) : ?>
                    <span class="description">
                        Last fetched from <?php echo esc_html($settings['patterns_source']); ?>
                        on <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $settings['patterns_fetched_at'])); ?>.
                    </span>
                <?php else : ?>
                    <span class="description">Patterns have not been fetched from the server yet.</span>
                <?php endif; ?>
            </p>
            <?php if (!empty($settings['patterns_examples'])) : ?>
                <h2>Server examples</h2>
                <p class="description">Live files on <?php echo esc_html(untrailingslashit($settings['source'])); ?>. If the previews load, the image server is reachable from your browser.</p>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Pattern</th>
                            <th>Example URL</th>
                            <th>Preview</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($settings['patterns_examples'] as $example_key => $example_path) : ?>
                            <?php $example_url = untrailingslashit($settings['source']) . $example_path; ?>
                            <tr>
                                <td><code><?php echo esc_html($example_key); ?></code></td>
                                <td><a href="<?php echo esc_url($example_url); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html($example_url); ?></code></a></td>
                                <td><img src="<?php echo esc_url($example_url); ?>" alt="" width="120" loading="lazy"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
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
                <?php elseif ($field['type'] === 'select') : ?>
                    <select id="imageserver-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($name); ?>">
                        <?php foreach ($field['options'] as $option_value => $option_label) : ?>
                            <option value="<?php echo esc_attr($option_value); ?>" <?php selected($value, $option_value); ?>><?php echo esc_html($option_label); ?></option>
                        <?php endforeach; ?>
                    </select>
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
        $existing = wp_parse_args((array) get_option(self::OPTION, []), $defaults);
        $source = untrailingslashit(esc_url_raw($input['source'] ?? $defaults['source']));

        if ($source === '') {
            $source = $defaults['source'];
        }

        $resize_style = sanitize_key($input['resize_style'] ?? '');

        if (!array_key_exists($resize_style, self::resize_styles())) {
            $resize_style = $defaults['resize_style'];
        }

        return [
            'enabled' => !empty($input['enabled']) ? 1 : 0,
            'source' => $source,
            'resize_style' => $resize_style,
            'original_pattern' => $this->sanitize_pattern($input['original_pattern'] ?? '', $defaults['original_pattern']),
            'resize_pattern' => $this->sanitize_pattern($input['resize_pattern'] ?? '', $defaults['resize_pattern']),
            'patterns_source' => (string) $existing['patterns_source'],
            'patterns_fetched_at' => (int) $existing['patterns_fetched_at'],
            'patterns_examples' => is_array($existing['patterns_examples']) ? $existing['patterns_examples'] : [],
        ];
    }

    public function handle_fetch_patterns()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'imageserver'));
        }

        check_admin_referer('imageserver_fetch_patterns');

        $settings = wp_parse_args((array) get_option(self::OPTION, []), self::defaults());
        $manifest = IS_Server_Api::fetch($settings['source']);

        if (is_wp_error($manifest)) {
            $this->set_fetch_notice('error', $manifest->get_error_message());
        } else {
            $settings = $this->apply_manifest($settings, $manifest);
            update_option(self::OPTION, $settings);
            $this->set_fetch_notice('success', sprintf('Patterns fetched from %s.', $settings['source']));
        }

        wp_safe_redirect(admin_url('options-general.php?page=imageserver'));
        exit;
    }

    public function render_notices()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen || $screen->id !== 'settings_page_imageserver') {
            return;
        }

        $notice = get_transient($this->notice_key());

        if (is_array($notice) && !empty($notice['message'])) {
            delete_transient($this->notice_key());
            $class = $notice['type'] === 'success' ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
        }

        $settings = wp_parse_args((array) get_option(self::OPTION, []), self::defaults());
        $source = untrailingslashit((string) $settings['source']);
        $fetched_from = untrailingslashit((string) $settings['patterns_source']);

        if ($fetched_from === '' || $source !== $fetched_from) {
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__('The image server source changed. Use "Fetch patterns from server" to update the patterns, or edit them manually.', 'imageserver');
            echo '</p></div>';
        }
    }

    public static function defaults()
    {
        return [
            'enabled' => 1,
            'source' => 'https://img.salamander-jewelry.net',
            'resize_style' => 'canvas',
            'original_pattern' => '/img/{path}',
            'resize_pattern' => '/canvas/{width}/{path}',
            'patterns_source' => '',
            'patterns_fetched_at' => 0,
            'patterns_examples' => [],
        ];
    }

    public static function resize_styles()
    {
        return [
            'canvas' => 'Canvas (white background)',
            'resize' => 'Resize (exact width x height)',
        ];
    }

    private function apply_manifest(array $settings, array $manifest)
    {
        $patterns = $manifest['patterns'];
        $roles = $manifest['roles'];
        $original_key = isset($roles['original'], $patterns[$roles['original']]) ? $roles['original'] : 'plain';
        $settings['original_pattern'] = $patterns[$original_key];

        $style = $settings['resize_style'];

        if (!isset($patterns[$style])) {
            $default_style = $manifest['defaults']['resize'] ?? '';
            $style = isset($patterns[$default_style]) ? $default_style : '';

            foreach ($manifest['resize_options'] as $candidate) {
                if ($style === '' && isset($patterns[$candidate])) {
                    $style = $candidate;
                }
            }
        }

        if ($style !== '') {
            $settings['resize_style'] = $style;
            $settings['resize_pattern'] = $patterns[$style];
        }

        $settings['patterns_source'] = $settings['source'];
        $settings['patterns_fetched_at'] = time();
        $settings['patterns_examples'] = is_array($manifest['examples']) ? $manifest['examples'] : [];

        return $settings;
    }

    private function set_fetch_notice($type, $message)
    {
        set_transient($this->notice_key(), ['type' => $type, 'message' => $message], MINUTE_IN_SECONDS);
    }

    private function notice_key()
    {
        return 'imageserver_fetch_notice_' . get_current_user_id();
    }

    private function add_field($key, $label, $field_key, $description, $type = 'text', $options = [])
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
                'options' => $options,
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
