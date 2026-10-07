<?php
/**
 * Hide WP admin notices, update nags, and dashboard news widgets.
 */
add_action('admin_init', function () {
    remove_action('admin_notices', 'update_nag', 3);
});

add_action('admin_head', function () {
    echo '<style>.notice, .update-nag, .notice-warning { display: none !important; }</style>';
});

add_action('wp_dashboard_setup', function () {
    remove_meta_box('dashboard_primary', 'dashboard', 'side');
    remove_meta_box('dashboard_secondary', 'dashboard', 'side');
});
