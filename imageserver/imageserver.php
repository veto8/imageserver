<?php
/**
 * Plugin Name: Image Server
 * Description: Replaces WooCommerce product media URLs with configured image server URLs.
 * Version: 1.0.0
 * Requires at least: 6.7
 * Requires PHP: 8.2
 * License: GPL-3.0-or-later
 * Text Domain: imageserver
 */

defined('ABSPATH') || exit;

define('IMAGESERVER_PLUGIN_FILE', __FILE__);
define('IMAGESERVER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('IMAGESERVER_PLUGIN_URL', plugin_dir_url(__FILE__));

spl_autoload_register(function ($class_name) {
    $prefix = 'Salamander\\Imageserver\\';

    if (strpos($class_name, $prefix) !== 0) {
        return;
    }

    $relative_class = substr($class_name, strlen($prefix));
    $file = IMAGESERVER_PLUGIN_DIR . 'src/' . str_replace('\\', '/', $relative_class) . '.php';

    if (is_readable($file)) {
        require_once $file;
    }
});

register_activation_hook(__FILE__, ['Salamander\\Imageserver\\IS_Admin', 'activate']);
register_deactivation_hook(__FILE__, ['Salamander\\Imageserver\\IS_Admin', 'deactivate']);

add_action('plugins_loaded', 'imageserver_init');

function imageserver_init()
{
    $admin = new Salamander\Imageserver\IS_Admin();
    $admin->register();

    $frontend = new Salamander\Imageserver\IS_Frontend();
    $frontend->register();
}
