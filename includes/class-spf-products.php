<?php

if (!defined('ABSPATH')) { exit; }

final class SPF_Products {
    public function hooks() {
        foreach (['spf_create_product','farmapos_create_product','spf_update_product','farmapos_update_product'] as $action) { add_action('wp_ajax_' . $action, [$this, 'ajax_save_product']); }
        foreach (['spf_delete_product','farmapos_delete_product'] as $action) { add_action('wp_ajax_' . $action, [$this, 'ajax_delete_product']); }
        foreach (['spf_search_product','farmapos_search_product'] as $action) { add_action('wp_ajax_' . $action, [$this, 'ajax_search_product']); }
    }

    private function verify_nonce() {
        $nonce = sanitize_text_field(wp_unslash($_REQUEST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'spf_nonce') && !wp_verify_nonce($nonce, 'farmapos_nonce')) { wp_send_json_error(__('Sesión caducada. Recarga la página e inténtalo de nuevo.', 'sistema-para-farmacias'), 403); }
    }

    public function ajax_save_product() {
        $this->verify_nonce();
        if (!current_user_can(SPF_Roles::PRODUCTS) && !current_user_can('manage_options')) { wp_send_json_error(__('Sin permisos.', 'sistema-para-farmacias'), 403); }
        global $wpdb;
        $table = SPF_Database::table('products'); $lots = SPF_Database::table('lots'); $movements = SPF_Database::table('stock_movements'); $now = current_time('mysql');
        $product_id = absint($_POST['product_id'] ?? 0); $barcode = sanitize_text_field(wp_unslash($_POST['barcode'] ?? '')); $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        if ($name === '') { wp_send_json_error(__('El nombre es obligatorio.', 'sistema-para-farmacias')); }
        if ($barcode !== '') {
            $exists = $product_id > 0 ? $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE barcode = %s AND id <> %d LIMIT 1", $barcode, $product_id)) : $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE barcode = %s LIMIT 1", $barcode));
            if ($exists) { wp_send_json_error(__('Ya existe un producto con ese código de barras.', 'sistema-para-farmacias')); }
        }
        $tax_type = sanitize_key(wp_unslash($_POST['tax_type'] ?? SPF_Tax::default_class()));
        if (!array_key_exists($tax_type, SPF_Tax::classes())) { $tax_type = SPF_Tax::default_class(); }
        $data = [
            'barcode'=>$barcode,'name'=>$name,'active_ingredient'=>sanitize_text_field(wp_unslash($_POST['active_ingredient'] ?? '')),
            'laboratory'=>sanitize_text_field(wp_unslash($_POST['laboratory'] ?? '')),'category'=>sanitize_text_field(wp_unslash($_POST['category'] ?? '')),
            'purchase_price'=>(float)($_POST['purchase_price'] ?? 0),'sale_price'=>(float)($_POST['sale_price'] ?? 0),'tax_type'=>$tax_type,
            'stock_min'=>(int)($_POST['stock_min'] ?? 0),'unit'=>sanitize_text_field(wp_unslash($_POST['unit'] ?? __('unidad', 'sistema-para-farmacias'))),'status'=>'active','updated_at'=>$now,
        ];
        if ($product_id > 0) { $wpdb->update($table, $data, ['id'=>$product_id]); $message = __('Producto actualizado.', 'sistema-para-farmacias'); }
        else { $data['created_at']=$now; $wpdb->insert($table,$data); $product_id=(int)$wpdb->insert_id; if(!$product_id){ wp_send_json_error(__('No se pudo crear el producto.', 'sistema-para-farmacias')); } $message=__('Producto creado.', 'sistema-para-farmacias'); }

        $initial_qty = (int)($_POST['initial_quantity'] ?? 0);
        if ($initial_qty > 0) {
            $lot_number = sanitize_text_field(wp_unslash($_POST['initial_lot_number'] ?? '')); if($lot_number===''){ $lot_number='SIN-LOTE-'.wp_date('YmdHis'); }
            $expiry_date=sanitize_text_field(wp_unslash($_POST['initial_expiry_date'] ?? '')); $supplier=sanitize_text_field(wp_unslash($_POST['initial_supplier'] ?? ''));
            $wpdb->insert($lots,['product_id'=>$product_id,'lot_number'=>$lot_number,'expiry_date'=>$expiry_date?:null,'quantity'=>$initial_qty,'purchase_price'=>(float)($_POST['purchase_price']??0),'supplier'=>$supplier,'created_at'=>$now,'updated_at'=>$now]);
            $lot_id=(int)$wpdb->insert_id;
            if($lot_id){ $wpdb->insert($movements,['product_id'=>$product_id,'lot_id'=>$lot_id,'movement_type'=>'purchase','quantity'=>$initial_qty,'previous_stock'=>0,'new_stock'=>$initial_qty,'reason'=>__('Stock inicial al crear producto','sistema-para-farmacias'),'user_id'=>get_current_user_id(),'created_at'=>$now]); $message.=' '.__('Lote y stock inicial registrados.','sistema-para-farmacias'); }
        }
        wp_send_json_success(['id'=>$product_id,'message'=>$message]);
    }

    public function ajax_delete_product() {
        $this->verify_nonce();
        if (!current_user_can(SPF_Roles::PRODUCTS) && !current_user_can('manage_options')) { wp_send_json_error(__('Sin permisos.', 'sistema-para-farmacias'), 403); }
        global $wpdb; $product_id=absint($_POST['product_id']??0); if($product_id<=0){ wp_send_json_error(__('Producto inválido.','sistema-para-farmacias')); }
        $wpdb->update(SPF_Database::table('products'),['status'=>'deleted','updated_at'=>current_time('mysql')],['id'=>$product_id]);
        wp_send_json_success(['message'=>__('Producto eliminado del catálogo. El histórico se conserva.','sistema-para-farmacias')]);
    }

    public function ajax_search_product() {
        $this->verify_nonce(); if(!SPF_Roles::can_access()){ wp_send_json_error(__('Sin permisos.','sistema-para-farmacias'),403); }
        global $wpdb; $products=SPF_Database::table('products'); $lots=SPF_Database::table('lots'); $q=sanitize_text_field(wp_unslash($_REQUEST['q']??'')); if($q===''){ wp_send_json_success([]); }
        $like='%'.$wpdb->esc_like($q).'%';
        $rows=$wpdb->get_results($wpdb->prepare("SELECT p.*, COALESCE(SUM(l.quantity),0) AS stock_current FROM $products p LEFT JOIN $lots l ON l.product_id=p.id WHERE p.status='active' AND (p.barcode=%s OR p.name LIKE %s OR p.active_ingredient LIKE %s) GROUP BY p.id ORDER BY CASE WHEN p.barcode=%s THEN 0 ELSE 1 END, p.name ASC LIMIT 20",$q,$like,$like,$q),ARRAY_A);
        wp_send_json_success($rows);
    }
}
