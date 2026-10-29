<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Sales {
    public function hooks(){ add_action('wp_ajax_spf_create_sale',[$this,'ajax_create_sale']); add_action('wp_ajax_farmapos_create_sale',[$this,'ajax_create_sale']); }
    private function verify_nonce(){ $nonce=sanitize_text_field(wp_unslash($_REQUEST['nonce']??'')); if(!wp_verify_nonce($nonce,'spf_nonce')&&!wp_verify_nonce($nonce,'farmapos_nonce')){ wp_send_json_error(__('Sesión caducada. Recarga la página e inténtalo de nuevo.','sistema-para-farmacias'),403); } }
    private function upsert_customer($payload){
        global $wpdb; $customers=SPF_Database::table('customers'); $now=current_time('mysql');
        $document_type=sanitize_text_field($payload['document_type']??''); $document_number=sanitize_text_field($payload['document_number']??''); $name=sanitize_text_field($payload['name']??''); $business_name=sanitize_text_field($payload['business_name']??''); $address=sanitize_textarea_field($payload['address']??''); $email=sanitize_email($payload['email']??''); $phone=sanitize_text_field($payload['phone']??'');
        if($document_number===''&&$name===''&&$business_name===''){ return null; }
        $display_name=$business_name?:$name; if($display_name===''){ $display_name=__('Cliente','sistema-para-farmacias').($document_number?' '.$document_number:''); }
        $existing_id=$document_number!==''?$wpdb->get_var($wpdb->prepare("SELECT id FROM $customers WHERE document_number=%s LIMIT 1",$document_number)):null;
        $data=['document_type'=>$document_type,'document_number'=>$document_number,'name'=>$name?:$display_name,'business_name'=>$business_name,'address'=>$address,'email'=>$email,'phone'=>$phone,'updated_at'=>$now];
        if($existing_id){ $wpdb->update($customers,$data,['id'=>(int)$existing_id]); return (int)$existing_id; }
        $data['created_at']=$now; $wpdb->insert($customers,$data); return $wpdb->insert_id?(int)$wpdb->insert_id:null;
    }
    public function ajax_create_sale(){
        $this->verify_nonce(); if(!current_user_can(SPF_Roles::SELL)&&!current_user_can('manage_options')){ wp_send_json_error(__('Sin permisos.','sistema-para-farmacias'),403); }
        $items=json_decode(wp_unslash($_POST['items']??'[]'),true); if(!$items||!is_array($items)){ wp_send_json_error(__('La venta no tiene productos.','sistema-para-farmacias')); }
        $documents=SPF_Config::sales_documents(); $document_type=sanitize_key(wp_unslash($_POST['document_type']??SPF_Config::default_sales_document())); if(!isset($documents[$document_type])){ $document_type=SPF_Config::default_sales_document(); } $document_profile=$documents[$document_type]??[];
        $payment_methods=SPF_Config::payment_methods(); $payment_method=sanitize_key(wp_unslash($_POST['payment_method']??SPF_Config::default_payment_method())); if(!isset($payment_methods[$payment_method])){ $payment_method=SPF_Config::default_payment_method(); }
        $person_documents=SPF_Config::person_documents(); $business_documents=SPF_Config::business_documents();
        $customer_document_type=sanitize_text_field(wp_unslash($_POST['customer_document_type']??'')); $customer_document_number=sanitize_text_field(wp_unslash($_POST['customer_document_number']??'')); $customer_name=sanitize_text_field(wp_unslash($_POST['customer_name']??'')); $customer_business_name=sanitize_text_field(wp_unslash($_POST['customer_business_name']??'')); $customer_address=sanitize_textarea_field(wp_unslash($_POST['customer_address']??'')); $customer_email=sanitize_email(wp_unslash($_POST['customer_email']??'')); $customer_phone=sanitize_text_field(wp_unslash($_POST['customer_phone']??''));
        if(!isset($person_documents[$customer_document_type])&&!isset($business_documents[$customer_document_type])){ $customer_document_type=(string)array_key_first($person_documents?:$business_documents?:['GENERIC'=>'GENERIC']); }
        if(!empty($document_profile['requires_business_document'])){ $required_type=sanitize_text_field($document_profile['business_document_type']??array_key_first($business_documents)); if($required_type!==''){ $customer_document_type=$required_type; } if($customer_document_number===''||$customer_business_name===''){ wp_send_json_error(sprintf(__('Para %s debes ingresar documento fiscal y razón social del cliente.','sistema-para-farmacias'),$document_profile['label']??$document_type)); } }
        $requested=[]; foreach($items as $item){ $product_id=absint($item['product_id']??0); $qty=(int)($item['quantity']??0); if($product_id<=0||$qty<=0){ wp_send_json_error(__('Hay productos con cantidad inválida.','sistema-para-farmacias')); } $requested[$product_id]=($requested[$product_id]??0)+$qty; }
        global $wpdb; $products=SPF_Database::table('products'); $sales=SPF_Database::table('sales'); $sale_items=SPF_Database::table('sale_items'); $lots=SPF_Database::table('lots'); $movements=SPF_Database::table('stock_movements'); $now=current_time('mysql'); $sale_number='V-'.wp_date('Ymd-His').'-'.wp_rand(100,999);
        $wpdb->query('START TRANSACTION');
        try{
            $product_ids=array_map('absint',array_keys($requested)); $id_list=implode(',',$product_ids); if($id_list===''){ throw new Exception(__('La venta no tiene productos válidos.','sistema-para-farmacias')); }
            $product_rows=$wpdb->get_results("SELECT id,name,sale_price,tax_type FROM $products WHERE status='active' AND id IN ($id_list) FOR UPDATE",ARRAY_A); $product_map=[]; foreach($product_rows as $product){ $product_map[(int)$product['id']]=$product; } if(count($product_map)!==count($requested)){ throw new Exception(__('Uno o más productos ya no están disponibles.','sistema-para-farmacias')); }
            $normalized_items=[]; $locked_lots=[]; $subtotal=0.0; $tax_total=0.0; $total=0.0;
            foreach($requested as $product_id=>$qty){
                $product=$product_map[$product_id]; $unit_price=(float)$product['sale_price']; $tax_type=sanitize_key($product['tax_type']?:SPF_Tax::default_class()); $tax=SPF_Tax::calculate_from_total($unit_price*$qty,$tax_type);
                $lot_rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $lots WHERE product_id=%d AND quantity>0 ORDER BY CASE WHEN expiry_date IS NULL OR expiry_date='0000-00-00' THEN 1 ELSE 0 END,expiry_date ASC,id ASC FOR UPDATE",$product_id),ARRAY_A); $stock=0; foreach($lot_rows as $lot){ $stock+=(int)$lot['quantity']; }
                if($stock<$qty){ throw new Exception(sprintf(__('Stock insuficiente para %1$s. Disponible: %2$d, solicitado: %3$d.','sistema-para-farmacias'),$product['name'],$stock,$qty)); }
                $locked_lots[$product_id]=$lot_rows; $normalized_items[]=['product_id'=>$product_id,'name'=>$product['name'],'quantity'=>$qty,'unit_price'=>$unit_price,'tax_type'=>$tax_type,'tax'=>$tax]; $subtotal+=$tax['subtotal']; $tax_total+=$tax['tax']; $total+=$tax['total'];
            }
            $customer_id=$this->upsert_customer(['document_type'=>$customer_document_type,'document_number'=>$customer_document_number,'name'=>$customer_name,'business_name'=>$customer_business_name,'address'=>$customer_address,'email'=>$customer_email,'phone'=>$customer_phone]);
            $wpdb->insert($sales,['sale_number'=>$sale_number,'customer_id'=>$customer_id,'document_type'=>$document_type,'payment_method'=>$payment_method,'subtotal'=>round($subtotal,2),'igv'=>round($tax_total,2),'total'=>round($total,2),'status'=>'completed','user_id'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]); if(!$wpdb->insert_id){ throw new Exception(__('No se pudo crear la venta.','sistema-para-farmacias')); } $sale_id=(int)$wpdb->insert_id;
            foreach($normalized_items as $item){
                $product_id=$item['product_id']; $qty=$item['quantity']; $remaining=$qty; $first_lot_id=null;
                foreach($locked_lots[$product_id] as $lot){ if($remaining<=0){ break; } $available=(int)$lot['quantity']; $take=min($available,$remaining); $new_qty=$available-$take; $lot_id=(int)$lot['id']; if($first_lot_id===null){ $first_lot_id=$lot_id; } $updated=$wpdb->update($lots,['quantity'=>$new_qty,'updated_at'=>$now],['id'=>$lot_id]); if($updated===false){ throw new Exception(__('No se pudo actualizar el inventario.','sistema-para-farmacias')); } $wpdb->insert($movements,['product_id'=>$product_id,'lot_id'=>$lot_id,'movement_type'=>'sale','quantity'=>-$take,'previous_stock'=>$available,'new_stock'=>$new_qty,'reason'=>__('Venta','sistema-para-farmacias').' '.$sale_number,'user_id'=>get_current_user_id(),'created_at'=>$now]); $remaining-=$take; }
                if($remaining>0){ throw new Exception(__('Stock insuficiente durante el descuento de lotes.','sistema-para-farmacias')); }
                $tax=$item['tax']; $inserted=$wpdb->insert($sale_items,['sale_id'=>$sale_id,'product_id'=>$product_id,'lot_id'=>$first_lot_id,'product_name'=>$item['name'],'quantity'=>$qty,'unit_price'=>$item['unit_price'],'subtotal'=>$tax['subtotal'],'igv'=>$tax['tax'],'total'=>$tax['total'],'tax_type'=>$item['tax_type']]); if($inserted===false){ throw new Exception(__('No se pudo guardar el detalle de la venta.','sistema-para-farmacias')); }
            }
            $wpdb->query('COMMIT'); wp_send_json_success(['sale_id'=>$sale_id,'sale_number'=>$sale_number,'total'=>round($total,2)]);
        }catch(Throwable $e){ $wpdb->query('ROLLBACK'); wp_send_json_error($e->getMessage()); }
    }
}
