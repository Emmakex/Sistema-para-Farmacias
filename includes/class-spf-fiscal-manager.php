<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Manager {
    const NOTICE_PREFIX = 'spf_fiscal_notice_';

    public function hooks() { add_action('admin_post_spf_prepare_fiscal_document', [$this, 'handle_prepare']); }

    public static function active_adapter() {
        $config = SPF_Config::electronic_invoicing();
        if (empty($config['enabled']) || empty($config['adapter'])) { return null; }
        return SPF_Fiscal_Adapter_Registry::get($config['adapter']);
    }

    public static function context($document_id) {
        global $wpdb;
        $document_id = absint($document_id);
        if (!$document_id) { return new WP_Error('spf_fiscal_document', __('Comprobante inválido.', 'sistema-para-farmacias')); }
        $documents=SPF_Database::table('invoices');$sales=SPF_Database::table('sales');$customers=SPF_Database::table('customers');$items=SPF_Database::table('sale_items');
        $row=$wpdb->get_row($wpdb->prepare("SELECT i.*, s.sale_number, s.customer_id, s.payment_method, s.subtotal, s.igv, s.total, s.created_at AS sale_date, c.document_type AS customer_document_type, c.document_number AS customer_document_number, c.name AS customer_name, c.business_name AS customer_business_name, c.address AS customer_address, c.email AS customer_email, c.phone AS customer_phone FROM $documents i INNER JOIN $sales s ON s.id=i.sale_id LEFT JOIN $customers c ON c.id=s.customer_id WHERE i.id=%d LIMIT 1",$document_id),ARRAY_A);
        if(!$row){return new WP_Error('spf_fiscal_document_missing',__('No se encontró el comprobante.','sistema-para-farmacias'));}
        $lines=$wpdb->get_results($wpdb->prepare("SELECT id, product_id, product_name, quantity, unit_price, subtotal, igv, total, tax_type FROM $items WHERE sale_id=%d ORDER BY id ASC",(int)$row['sale_id']),ARRAY_A);
        return ['country_code'=>SPF_Config::country_code(),'country'=>SPF_Config::country(),'issuer'=>SPF_Fiscal_Settings::issuer(),'document'=>['id'=>(int)$row['id'],'type'=>sanitize_key($row['document_type']),'series'=>sanitize_text_field($row['serie']),'correlative'=>sanitize_text_field($row['correlative']),'internal_status'=>sanitize_key($row['internal_status']??$row['sunat_status']??'draft'),'transport_status'=>sanitize_key($row['transport_status']??'not_prepared'),'fiscal_status'=>sanitize_key($row['fiscal_status']??'not_sent'),'created_at'=>$row['created_at']],'sale'=>['id'=>(int)$row['sale_id'],'number'=>sanitize_text_field($row['sale_number']),'date'=>$row['sale_date'],'payment_method'=>sanitize_key($row['payment_method']),'subtotal'=>(float)$row['subtotal'],'tax'=>(float)$row['igv'],'total'=>(float)$row['total'],'currency'=>(string)SPF_Config::get('currency.code','')],'customer'=>['id'=>(int)($row['customer_id']??0),'document_type'=>sanitize_text_field($row['customer_document_type']??''),'document_number'=>sanitize_text_field($row['customer_document_number']??''),'name'=>sanitize_text_field($row['customer_name']??''),'business_name'=>sanitize_text_field($row['customer_business_name']??''),'address'=>sanitize_text_field($row['customer_address']??''),'email'=>sanitize_email($row['customer_email']??''),'phone'=>sanitize_text_field($row['customer_phone']??'')],'items'=>is_array($lines)?$lines:[]];
    }

    public static function preflight($document_id) {
        $adapter=self::active_adapter();if(!$adapter){return new WP_Error('spf_fiscal_adapter_missing',__('El país activo no tiene un adaptador fiscal disponible.','sistema-para-farmacias'));}
        $context=self::context($document_id);if(is_wp_error($context)){return $context;}
        if(!$adapter->supports($context['document']['type'])){return new WP_Error('spf_fiscal_unsupported_document',__('El adaptador fiscal no soporta este tipo de comprobante.','sistema-para-farmacias'));}
        $validation=$adapter->validate($context);$validation=is_array($validation)?$validation:[];
        return ['adapter'=>$adapter,'context'=>$context,'errors'=>array_values(array_filter((array)($validation['errors']??[]))),'warnings'=>array_values(array_filter((array)($validation['warnings']??[])))];
    }

    public static function prepare_preview($document_id) {
        $preflight=self::preflight($document_id);if(is_wp_error($preflight)){return $preflight;}if($preflight['errors']){return new WP_Error('spf_fiscal_preflight_failed',implode(' ',$preflight['errors']));}
        $xml=$preflight['adapter']->build_preview_xml($preflight['context']);if(is_wp_error($xml)){return $xml;}$xml=(string)$xml;if($xml===''){return new WP_Error('spf_fiscal_preview_empty',__('El adaptador no pudo generar la previsualización XML.','sistema-para-farmacias'));}
        global $wpdb;$now=current_time('mysql');$metadata=['mode'=>'preview_only','signed'=>false,'sent'=>false,'adapter'=>$preflight['adapter']->code(),'country'=>$preflight['context']['country_code'],'ubl_sha256'=>hash('sha256',$xml),'warnings'=>$preflight['warnings'],'prepared_at'=>$now,'message'=>__('XML preliminar generado. No está firmado ni ha sido enviado a la autoridad fiscal.','sistema-para-farmacias')];
        $updated=$wpdb->update(SPF_Database::table('invoices'),['internal_status'=>'prepared','sunat_status'=>'prepared','transport_status'=>'not_prepared','fiscal_status'=>'not_sent','fiscal_adapter'=>sanitize_key($preflight['adapter']->code()),'response_body'=>wp_json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'updated_at'=>$now],['id'=>absint($document_id)]);
        if($updated===false){return new WP_Error('spf_fiscal_prepare_store',__('No se pudo guardar el resultado del preflight.','sistema-para-farmacias'));}
        return ['xml'=>$xml,'sha256'=>$metadata['ubl_sha256'],'warnings'=>$preflight['warnings'],'adapter'=>$metadata['adapter']];
    }

    public function handle_prepare() {
        if(!current_user_can(SPF_Roles::INVOICES)&&!current_user_can('manage_options')){wp_die(esc_html__('No tienes permisos para preparar comprobantes fiscales.','sistema-para-farmacias'));}
        check_admin_referer('spf_prepare_fiscal_document');$document_id=absint($_POST['document_id']??0);$result=self::prepare_preview($document_id);
        $notice=is_wp_error($result)?['type'=>'error','message'=>$result->get_error_message()]:['type'=>'success','message'=>__('Preflight correcto. XML UBL preliminar generado sin firma y sin envío.','sistema-para-farmacias')];
        set_transient(self::NOTICE_PREFIX.get_current_user_id(),$notice,MINUTE_IN_SECONDS);wp_safe_redirect(admin_url('admin.php?page=spf-fiscal'));exit;
    }

    public static function consume_notice(){$key=self::NOTICE_PREFIX.get_current_user_id();$notice=get_transient($key);delete_transient($key);return is_array($notice)?$notice:null;}
}
