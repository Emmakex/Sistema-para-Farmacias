<?php
/**
 * Plugin Name: Sistema para Farmacias
 * Plugin URI: https://kairoseth.com/
 * Description: Sistema WordPress para farmacias con inventario por lotes, punto de venta, clientes, comprobantes, dashboard y arquitectura multi-país.
 * Version: 0.17.0
 * Author: Kairoseth
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: sistema-para-farmacias
 */
if (!defined('ABSPATH')) { exit; }
define('SPF_VERSION','0.17.0'); define('SPF_PLUGIN_FILE',__FILE__); define('SPF_PLUGIN_DIR',plugin_dir_path(__FILE__)); define('SPF_PLUGIN_URL',plugin_dir_url(__FILE__));
require_once SPF_PLUGIN_DIR.'includes/class-spf-country-registry.php';
require_once SPF_PLUGIN_DIR.'includes/countries/class-spf-country-peru.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-config.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-database.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-roles.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-tax.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-products.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-inventory.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-customers.php';
require_once SPF_PLUGIN_DIR.'includes/fiscal/interface-spf-fiscal-adapter.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-fiscal-adapter-registry.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-fiscal-settings.php';
require_once SPF_PLUGIN_DIR.'includes/countries/peru/class-spf-fiscal-adapter-peru.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-fiscal-manager.php';
require_once SPF_PLUGIN_DIR.'includes/fiscal/interface-spf-fiscal-signer.php';
require_once SPF_PLUGIN_DIR.'includes/fiscal/interface-spf-fiscal-credentials-provider.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-fiscal-signer-registry.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-fiscal-credentials-registry.php';
require_once SPF_PLUGIN_DIR.'includes/fiscal/class-spf-fiscal-credentials-environment.php';
require_once SPF_PLUGIN_DIR.'includes/fiscal/class-spf-fiscal-xmldsig-signer.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-fiscal-signing-manager.php';
require_once SPF_PLUGIN_DIR.'includes/fiscal/interface-spf-fiscal-validator.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-fiscal-validator-registry.php';
require_once SPF_PLUGIN_DIR.'includes/fiscal/class-spf-fiscal-xsd-xsl-validator.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-fiscal-validation-manager.php';
require_once SPF_PLUGIN_DIR.'includes/fiscal/interface-spf-fiscal-evidence-store.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-fiscal-evidence-store-registry.php';
require_once SPF_PLUGIN_DIR.'includes/fiscal/class-spf-fiscal-evidence-filesystem.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-fiscal-evidence-manager.php';
require_once SPF_PLUGIN_DIR.'includes/fiscal/interface-spf-fiscal-batch-builder.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-fiscal-batch-builder-registry.php';
require_once SPF_PLUGIN_DIR.'includes/countries/peru/class-spf-fiscal-batch-builder-peru-summary.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-fiscal-batch-repository.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-fiscal-batch-manager.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-fiscal-batch-integrity.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-fiscal-batch-lifecycle.php';
require_once SPF_PLUGIN_DIR.'includes/fiscal/interface-spf-fiscal-transport.php';
require_once SPF_PLUGIN_DIR.'includes/fiscal/interface-spf-fiscal-async-batch-transport.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-fiscal-transport-registry.php';
require_once SPF_PLUGIN_DIR.'includes/fiscal/class-spf-fiscal-transport-dry-run.php';
require_once SPF_PLUGIN_DIR.'includes/countries/peru/class-spf-fiscal-transport-peru-beta.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-fiscal-transport-manager.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-fiscal-batch-remote-event-repository.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-fiscal-batch-beta-manager.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-documents.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-sales.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-data-tools.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-login-branding.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-activator.php';
require_once SPF_PLUGIN_DIR.'includes/class-spf-admin.php';
register_activation_hook(__FILE__,['SPF_Activator','activate']);
function spf_bootstrap(){
    SPF_Country_Peru::register();
    SPF_Fiscal_Adapter_Registry::register(new SPF_Fiscal_Adapter_Peru());
    SPF_Fiscal_Signer_Registry::register(new SPF_Fiscal_XMLDSig_Signer());
    SPF_Fiscal_Credentials_Registry::register(new SPF_Fiscal_Credentials_Environment());
    SPF_Fiscal_Validator_Registry::register(new SPF_Fiscal_XSD_XSL_Validator());
    SPF_Fiscal_Evidence_Store_Registry::register(new SPF_Fiscal_Evidence_Filesystem());
    SPF_Fiscal_Batch_Builder_Registry::register(new SPF_Fiscal_Batch_Builder_Peru_Summary());
    SPF_Fiscal_Transport_Registry::register(new SPF_Fiscal_Transport_Dry_Run());
    SPF_Fiscal_Transport_Registry::register(new SPF_Fiscal_Transport_Peru_Beta());
    SPF_Activator::maybe_upgrade();
    SPF_Fiscal_Batch_Remote_Event_Repository::maybe_install();
    SPF_Roles::ensure_roles();
    $admin=new SPF_Admin();$admin->hooks();
    $products=new SPF_Products();$products->hooks();
    $inventory=new SPF_Inventory();$inventory->hooks();
    $customers=new SPF_Customers();$customers->hooks();
    $documents=new SPF_Documents();$documents->hooks();
    $fiscal=new SPF_Fiscal_Manager();$fiscal->hooks();
    $signing=new SPF_Fiscal_Signing_Manager();$signing->hooks();
    $validation=new SPF_Fiscal_Validation_Manager();$validation->hooks();
    $batch=new SPF_Fiscal_Batch_Manager();$batch->hooks();
    $batch_lifecycle=new SPF_Fiscal_Batch_Lifecycle();$batch_lifecycle->hooks();
    $transport=new SPF_Fiscal_Transport_Manager();$transport->hooks();
    $batch_beta=new SPF_Fiscal_Batch_Beta_Manager();$batch_beta->hooks();
    $sales=new SPF_Sales();$sales->hooks();
    $tools=new SPF_Data_Tools();$tools->hooks();
    $login=new SPF_Login_Branding();$login->hooks();
    do_action('spf_loaded');
}
add_action('plugins_loaded','spf_bootstrap',20);
function spf_pos_shortcode(){ if(!is_user_logged_in()){return '<div class="spf-error">'.esc_html__('Debes iniciar sesión para usar el punto de venta.','sistema-para-farmacias').'</div>';} if(!current_user_can(SPF_Roles::SELL)&&!current_user_can('manage_options')){return '<div class="spf-error">'.esc_html__('Tu usuario no tiene permiso para usar el punto de venta.','sistema-para-farmacias').'</div>';} ob_start();include SPF_PLUGIN_DIR.'public/views/pos-app.php';return ob_get_clean(); }
function spf_home_shortcode(){ if(!is_user_logged_in()){return '<div class="spf-error"><a href="'.esc_url(wp_login_url(get_permalink())).'">'.esc_html__('Iniciar sesión','sistema-para-farmacias').'</a></div>';} ob_start();include SPF_PLUGIN_DIR.'public/views/home-app.php';return ob_get_clean(); }
add_shortcode('spf_pos','spf_pos_shortcode'); add_shortcode('farmapos_pos','spf_pos_shortcode'); add_shortcode('spf_home','spf_home_shortcode'); add_shortcode('farmapos_home','spf_home_shortcode');
function spf_app_page_ids(){ return array_values(array_filter(array_map('absint',[get_option('spf_home_page_id',0),get_option('spf_pos_page_id',0)]))); }
function spf_protect_app_pages(){ $ids=spf_app_page_ids(); if($ids&&is_page($ids)&&!is_user_logged_in()){ auth_redirect(); } }
add_action('template_redirect','spf_protect_app_pages');
function spf_app_body_class($classes){ $ids=spf_app_page_ids(); if($ids&&is_page($ids)){$classes[]='spf-app-page';} return $classes; }
add_filter('body_class','spf_app_body_class');
