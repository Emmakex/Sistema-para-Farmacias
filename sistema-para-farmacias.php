<?php
/**
 * Plugin Name: Sistema para Farmacias
 * Plugin URI: https://kairoseth.com/
 * Description: Sistema WordPress para farmacias con inventario por lotes, punto de venta, clientes, comprobantes y arquitectura multi-país.
 * Version: 0.4.0
 * Author: Kairoseth
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: sistema-para-farmacias
 */
if (!defined('ABSPATH')) { exit; }
define('SPF_VERSION','0.4.0'); define('SPF_PLUGIN_FILE',__FILE__); define('SPF_PLUGIN_DIR',plugin_dir_path(__FILE__)); define('SPF_PLUGIN_URL',plugin_dir_url(__FILE__));
require_once SPF_PLUGIN_DIR.'includes/class-spf-country-registry.php'; require_once SPF_PLUGIN_DIR.'includes/countries/class-spf-country-peru.php'; require_once SPF_PLUGIN_DIR.'includes/class-spf-config.php'; require_once SPF_PLUGIN_DIR.'includes/class-spf-database.php'; require_once SPF_PLUGIN_DIR.'includes/class-spf-roles.php'; require_once SPF_PLUGIN_DIR.'includes/class-spf-tax.php'; require_once SPF_PLUGIN_DIR.'includes/class-spf-products.php'; require_once SPF_PLUGIN_DIR.'includes/class-spf-inventory.php'; require_once SPF_PLUGIN_DIR.'includes/class-spf-customers.php'; require_once SPF_PLUGIN_DIR.'includes/class-spf-documents.php'; require_once SPF_PLUGIN_DIR.'includes/class-spf-sales.php'; require_once SPF_PLUGIN_DIR.'includes/class-spf-activator.php'; require_once SPF_PLUGIN_DIR.'includes/class-spf-admin.php';
register_activation_hook(__FILE__,['SPF_Activator','activate']);
function spf_bootstrap(){ SPF_Country_Peru::register(); SPF_Roles::ensure_roles(); $admin=new SPF_Admin();$admin->hooks(); $products=new SPF_Products();$products->hooks(); $inventory=new SPF_Inventory();$inventory->hooks(); $customers=new SPF_Customers();$customers->hooks(); $documents=new SPF_Documents();$documents->hooks(); $sales=new SPF_Sales();$sales->hooks(); do_action('spf_loaded'); }
add_action('plugins_loaded','spf_bootstrap',20);
function spf_pos_shortcode(){ if(!is_user_logged_in()){return '<div class="spf-error">'.esc_html__('Debes iniciar sesión para usar el punto de venta.','sistema-para-farmacias').'</div>';} if(!current_user_can(SPF_Roles::SELL)&&!current_user_can('manage_options')){return '<div class="spf-error">'.esc_html__('Tu usuario no tiene permiso para usar el punto de venta.','sistema-para-farmacias').'</div>';} ob_start();include SPF_PLUGIN_DIR.'public/views/pos-app.php';return ob_get_clean(); }
add_shortcode('spf_pos','spf_pos_shortcode'); add_shortcode('farmapos_pos','spf_pos_shortcode');
