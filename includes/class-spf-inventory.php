<?php

if (!defined('ABSPATH')) { exit; }

final class SPF_Inventory {
    public function hooks() { add_action('wp_ajax_spf_add_lot',[$this,'ajax_add_lot']); add_action('wp_ajax_farmapos_add_lot',[$this,'ajax_add_lot']); }
    private function verify_nonce() { $nonce=sanitize_text_field(wp_unslash($_REQUEST['nonce']??'')); if(!wp_verify_nonce($nonce,'spf_nonce')&&!wp_verify_nonce($nonce,'farmapos_nonce')){ wp_send_json_error(__('Sesión caducada. Recarga la página e inténtalo de nuevo.','sistema-para-farmacias'),403); } }
    public function ajax_add_lot() {
        $this->verify_nonce(); if(!current_user_can(SPF_Roles::INVENTORY)&&!current_user_can('manage_options')){ wp_send_json_error(__('Sin permisos.','sistema-para-farmacias'),403); }
        global $wpdb; $products=SPF_Database::table('products'); $lots=SPF_Database::table('lots'); $movements=SPF_Database::table('stock_movements'); $now=current_time('mysql');
        $product_id=absint($_POST['product_id']??0); $quantity=(int)($_POST['quantity']??0); if($product_id<=0||$quantity<=0){ wp_send_json_error(__('Producto y cantidad son obligatorios.','sistema-para-farmacias')); }
        $active=$wpdb->get_var($wpdb->prepare("SELECT id FROM $products WHERE id=%d AND status='active' LIMIT 1",$product_id)); if(!$active){ wp_send_json_error(__('El producto no existe o está eliminado del catálogo.','sistema-para-farmacias')); }
        $expiry=sanitize_text_field(wp_unslash($_POST['expiry_date']??''));
        $wpdb->insert($lots,['product_id'=>$product_id,'lot_number'=>sanitize_text_field(wp_unslash($_POST['lot_number']??'')),'expiry_date'=>$expiry?:null,'quantity'=>$quantity,'purchase_price'=>(float)($_POST['purchase_price']??0),'supplier'=>sanitize_text_field(wp_unslash($_POST['supplier']??'')),'created_at'=>$now,'updated_at'=>$now]);
        $lot_id=(int)$wpdb->insert_id; if(!$lot_id){ wp_send_json_error(__('No se pudo registrar el lote.','sistema-para-farmacias')); }
        $wpdb->insert($movements,['product_id'=>$product_id,'lot_id'=>$lot_id,'movement_type'=>'purchase','quantity'=>$quantity,'previous_stock'=>0,'new_stock'=>$quantity,'reason'=>__('Entrada de lote','sistema-para-farmacias'),'user_id'=>get_current_user_id(),'created_at'=>$now]);
        wp_send_json_success(['id'=>$lot_id,'message'=>__('Lote añadido.','sistema-para-farmacias')]);
    }
}
