<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Admin {
    public function hooks(){ add_action('admin_menu',[$this,'menu']); add_action('admin_enqueue_scripts',[$this,'assets']); add_action('admin_init',[$this,'save_settings']); }
    public function menu(){
        add_menu_page(__('Sistema para Farmacias','sistema-para-farmacias'),__('Farmacias','sistema-para-farmacias'),SPF_Roles::ACCESS,'sistema-para-farmacias',[$this,'render_dashboard'],'dashicons-store',26);
        add_submenu_page('sistema-para-farmacias',__('Dashboard','sistema-para-farmacias'),__('Dashboard','sistema-para-farmacias'),SPF_Roles::ACCESS,'sistema-para-farmacias',[$this,'render_dashboard']);
        add_submenu_page('sistema-para-farmacias',__('Productos','sistema-para-farmacias'),__('Productos','sistema-para-farmacias'),SPF_Roles::PRODUCTS,'spf-products',[$this,'render_products']);
        add_submenu_page('sistema-para-farmacias',__('Inventario / Lotes','sistema-para-farmacias'),__('Inventario / Lotes','sistema-para-farmacias'),SPF_Roles::INVENTORY,'spf-inventory',[$this,'render_inventory']);
        add_submenu_page('sistema-para-farmacias',__('Punto de venta','sistema-para-farmacias'),__('Punto de venta','sistema-para-farmacias'),SPF_Roles::SELL,'spf-pos',[$this,'render_pos']);
        add_submenu_page('sistema-para-farmacias',__('Ventas','sistema-para-farmacias'),__('Ventas','sistema-para-farmacias'),SPF_Roles::SALES,'spf-sales',[$this,'render_sales']);
        add_submenu_page('sistema-para-farmacias',__('Configuración','sistema-para-farmacias'),__('Configuración','sistema-para-farmacias'),SPF_Roles::SETTINGS,'spf-settings',[$this,'render_settings']);
    }
    public function assets($hook){
        if(strpos((string)$hook,'sistema-para-farmacias')===false && strpos((string)$hook,'spf-')===false){ return; }
        wp_enqueue_style('spf-admin',SPF_PLUGIN_URL.'assets/css/admin.css',[],SPF_VERSION);
        wp_enqueue_script('spf-admin',SPF_PLUGIN_URL.'assets/js/admin.js',['jquery'],SPF_VERSION,true);
        wp_enqueue_script('spf-scanner',SPF_PLUGIN_URL.'assets/js/scanner.js',[],SPF_VERSION,true);
        wp_localize_script('spf-scanner','SPFAdmin',['ajax_url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('spf_nonce')]);
    }
    public function save_settings(){
        if(!isset($_POST['spf_save_country'])){ return; }
        if(!current_user_can(SPF_Roles::SETTINGS)&&!current_user_can('manage_options')){ wp_die(esc_html__('No tienes permisos para cambiar esta configuración.','sistema-para-farmacias')); }
        check_admin_referer('spf_save_country'); $country=strtolower(sanitize_key(wp_unslash($_POST['spf_country']??'')));
        if(!SPF_Country_Registry::exists($country)){ add_settings_error('spf_country','spf_invalid_country',__('El país seleccionado no está soportado.','sistema-para-farmacias')); return; }
        update_option(SPF_Config::COUNTRY_OPTION,$country); add_settings_error('spf_country','spf_country_saved',__('País guardado correctamente.','sistema-para-farmacias'),'updated');
    }
    public function render_dashboard(){ include SPF_PLUGIN_DIR.'admin/views/dashboard.php'; }
    public function render_products(){ include SPF_PLUGIN_DIR.'admin/views/products.php'; }
    public function render_inventory(){ include SPF_PLUGIN_DIR.'admin/views/inventory.php'; }
    public function render_pos(){ if(!current_user_can(SPF_Roles::SELL)&&!current_user_can('manage_options')){ wp_die(esc_html__('No tienes permisos para usar el punto de venta.','sistema-para-farmacias')); } include SPF_PLUGIN_DIR.'admin/views/pos.php'; }
    public function render_sales(){ include SPF_PLUGIN_DIR.'admin/views/sales.php'; }
    public function render_settings(){
        if(!current_user_can(SPF_Roles::SETTINGS)&&!current_user_can('manage_options')){ return; }
        $countries=SPF_Country_Registry::all(); $current=SPF_Config::country_code(); $profile=SPF_Config::country(); settings_errors('spf_country'); ?>
        <div class="wrap spf-wrap"><h1><?php esc_html_e('Configuración','sistema-para-farmacias'); ?></h1><div class="spf-panel"><h2><?php esc_html_e('País operativo','sistema-para-farmacias'); ?></h2><form method="post"><?php wp_nonce_field('spf_save_country'); ?><p><select name="spf_country" id="spf_country"><?php foreach($countries as $code=>$country): ?><option value="<?php echo esc_attr($code); ?>" <?php selected($current,$code); ?>><?php echo esc_html($country['name']); ?></option><?php endforeach; ?></select></p><?php submit_button(__('Guardar país','sistema-para-farmacias'),'primary','spf_save_country'); ?></form><p><strong><?php esc_html_e('Moneda:','sistema-para-farmacias'); ?></strong> <?php echo esc_html($profile['currency']['code'].' ('.$profile['currency']['symbol'].')'); ?></p><p><strong><?php esc_html_e('Impuesto:','sistema-para-farmacias'); ?></strong> <?php echo esc_html(SPF_Config::tax_name().' '.SPF_Config::tax_rate().'%'); ?></p><p><strong><?php esc_html_e('Autoridad fiscal:','sistema-para-farmacias'); ?></strong> <?php echo esc_html($profile['electronic_invoicing']['authority']?:'—'); ?></p><p class="description"><?php esc_html_e('Solo se muestran países con un perfil implementado y verificado.','sistema-para-farmacias'); ?></p></div></div><?php
    }
}
