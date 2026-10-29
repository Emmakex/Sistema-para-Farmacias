<?php
/**
 * Plugin Name: Sistema para Farmacias
 * Plugin URI: https://kairoseth.com/
 * Description: Sistema WordPress para farmacias con arquitectura multi-país. Perú es la primera localización soportada.
 * Version: 0.1.0
 * Author: Kairoseth
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: sistema-para-farmacias
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SPF_VERSION', '0.1.0');
define('SPF_PLUGIN_FILE', __FILE__);
define('SPF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SPF_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once SPF_PLUGIN_DIR . 'includes/class-spf-country-registry.php';
require_once SPF_PLUGIN_DIR . 'includes/countries/class-spf-country-peru.php';
require_once SPF_PLUGIN_DIR . 'includes/class-spf-config.php';
require_once SPF_PLUGIN_DIR . 'includes/class-spf-activator.php';
require_once SPF_PLUGIN_DIR . 'includes/class-spf-admin.php';

register_activation_hook(__FILE__, ['SPF_Activator', 'activate']);

function spf_bootstrap() {
    SPF_Country_Peru::register();

    $admin = new SPF_Admin();
    $admin->hooks();

    /**
     * Fires after Sistema para Farmacias has registered its core services.
     *
     * Third-party country packs may register additional country profiles on
     * `plugins_loaded` before this action or through the `spf_countries` filter.
     */
    do_action('spf_loaded');
}
add_action('plugins_loaded', 'spf_bootstrap', 20);
