<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Batch_Manager {
    const NOTICE_PREFIX = 'spf_batch_notice_';
    const PREVIEW_PREFIX = 'spf_batch_preview_';

    public function hooks() {
        add_action('admin_post_spf_fiscal_daily_summary_preview', [$this, 'handle_daily_summary_preview']);
        add_action('admin_post_spf_fiscal_daily_summary_persist', [$this, 'handle_daily_summary_persist']);
    }

    public static function profile($batch_type) {
        $profile = SPF_Config::get('electronic_invoicing.batch_profiles.' . sanitize_key((string)$batch_type), []);
        return is_array($profile) ? $profile : [];
    }

    public static function active_builder($batch_type) {
        $profile = self::profile($batch_type);
        $code = sanitize_key((string)($profile['builder'] ?? ''));
        return $code !== '' ? SPF_Fiscal_Batch_Builder_Registry::get($code) : null;
    }

    public static function context_for_date($batch_type, $reference_date, $exclude_persisted = false, $lock_rows = false) {
        $batch_type = sanitize_key((string)$batch_type);
        $reference_date = self::normalize_date($reference_date);
        if ($reference_date === '') { return new WP_Error('spf_batch_date', __('La fecha del lote fiscal no es válida.', 'sistema-para-farmacias')); }

        $profile = self::profile($batch_type);
        if (!$profile) { return new WP_Error('spf_batch_profile', __('El país activo no define este tipo de lote fiscal.', 'sistema-para-farmacias')); }
        $documents = SPF_Config::sales_documents();
        $document_types = [];
        foreach ($documents as $type => $meta) {
            if (sanitize_key((string)($meta['fiscal_submission'] ?? '')) === $batch_type) { $document_types[] = sanitize_key((string)$type); }
        }
        if (!$document_types) { return new WP_Error('spf_batch_documents', __('No hay tipos de comprobante asociados a este lote fiscal.', 'sistema-para-farmacias')); }

        global $wpdb;
        $invoices = SPF_Database::table('invoices');
        $sales = SPF_Database::table('sales');
        $customers = SPF_Database::table('customers');
        $items = SPF_Database::table('sale_items');
        $batch_items = SPF_Database::table('fiscal_batch_items');
        $placeholders = implode(',', array_fill(0, count($document_types), '%s'));
        $not_persisted = $exclude_persisted
            ? " AND NOT EXISTS (SELECT 1 FROM $batch_items bi WHERE bi.document_id=i.id AND bi.condition_code='1')"
            : '';
        $for_update = $lock_rows ? ' FOR UPDATE' : '';
        $sql = "SELECT i.id document_id,i.document_type,i.serie,i.correlative,i.internal_status,s.id sale_id,s.subtotal,s.igv,s.total,s.created_at sale_date,c.document_type customer_document_type,c.document_number customer_document_number FROM $invoices i INNER JOIN $sales s ON s.id=i.sale_id LEFT JOIN $customers c ON c.id=s.customer_id WHERE i.document_type IN ($placeholders) AND DATE(s.created_at)=%s AND s.status='completed' AND i.internal_status<>'cancelled'$not_persisted ORDER BY i.id ASC$for_update";
        $params = array_merge($document_types, [$reference_date]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $lines = [];
        foreach ((array)$rows as $row) {
            $item_rows = $wpdb->get_results($wpdb->prepare("SELECT tax_type,SUM(subtotal) subtotal,SUM(igv) tax,SUM(total) total FROM $items WHERE sale_id=%d GROUP BY tax_type", (int)$row['sale_id']), ARRAY_A);
            $buckets = [];
            foreach ((array)$item_rows as $item_row) { $buckets[sanitize_key((string)$item_row['tax_type'])] = round((float)$item_row['subtotal'], 2); }
            $meta = $documents[sanitize_key((string)$row['document_type'])] ?? [];
            $lines[] = [
                'document_id'=>(int)$row['document_id'],
                'sale_id'=>(int)$row['sale_id'],
                'document_type'=>sanitize_key((string)$row['document_type']),
                'fiscal_type_code'=>sanitize_text_field((string)($meta['fiscal_type_code'] ?? '')),
                'series'=>strtoupper((string)$row['serie']),
                'correlative'=>(string)$row['correlative'],
                'customer_document_type'=>sanitize_text_field((string)($row['customer_document_type'] ?? '')),
                'customer_document_number'=>sanitize_text_field((string)($row['customer_document_number'] ?? '')),
                'subtotal'=>round((float)$row['subtotal'],2),
                'tax'=>round((float)$row['igv'],2),
                'total'=>round((float)$row['total'],2),
                'tax_buckets'=>$buckets,
                'condition_code'=>'1',
                'sale_date'=>(string)$row['sale_date'],
            ];
        }

        return [
            'batch_type'=>$batch_type,
            'country_code'=>SPF_Config::country_code(),
            'currency'=>(string)SPF_Config::get('currency.code',''),
            'issuer'=>SPF_Fiscal_Settings::issuer(),
            'reference_date'=>$reference_date,
            'issue_date'=>wp_date('Y-m-d'),
            'sequence'=>max(1,(int)($profile['preview_sequence'] ?? 1)),
            'lines'=>$lines,
        ];
    }

    public static function build_preview($batch_type, $reference_date) {
        $builder = self::active_builder($batch_type);
        if (!$builder) { return new WP_Error('spf_batch_builder_missing', __('No hay un builder fiscal registrado para este lote.', 'sistema-para-farmacias')); }
        $context = self::context_for_date($batch_type, $reference_date, true, false);
        if (is_wp_error($context)) { return $context; }
        if (!$builder->supports($batch_type)) { return new WP_Error('spf_batch_builder_unsupported', __('El builder fiscal no soporta este tipo de lote.', 'sistema-para-farmacias')); }
        if (!$context['lines']) { return new WP_Error('spf_batch_empty', __('No hay comprobantes nuevos pendientes de agrupar para la fecha seleccionada.', 'sistema-para-farmacias')); }
        $context['sequence'] = SPF_Fiscal_Batch_Repository::next_sequence($context['country_code'], $batch_type, $reference_date);
        $result = $builder->build($context);
        if (is_wp_error($result)) { return $result; }
        $result['builder'] = $builder->code();
        $result['country_code'] = $context['country_code'];
        return $result;
    }

    public function handle_daily_summary_preview() {
        if (!current_user_can(SPF_Roles::INVOICES) && !current_user_can('manage_options')) { wp_die(esc_html__('No tienes permisos para preparar lotes fiscales.', 'sistema-para-farmacias')); }
        check_admin_referer('spf_fiscal_daily_summary_preview');
        $date = sanitize_text_field(wp_unslash($_POST['reference_date'] ?? ''));
        $result = self::build_preview('daily_summary', $date);
        if (is_wp_error($result)) {
            $notice=['type'=>'error','message'=>$result->get_error_message()];
            delete_transient(self::PREVIEW_PREFIX . get_current_user_id());
        } else {
            $preview=[
                'identifier'=>sanitize_text_field((string)$result['identifier']),
                'reference_date'=>sanitize_text_field((string)$result['reference_date']),
                'issue_date'=>sanitize_text_field((string)$result['issue_date']),
                'line_count'=>(int)$result['line_count'],
                'sha256'=>sanitize_text_field((string)$result['sha256']),
                'builder'=>sanitize_key((string)$result['builder']),
                'warnings'=>array_map('sanitize_text_field',(array)($result['warnings']??[])),
            ];
            set_transient(self::PREVIEW_PREFIX . get_current_user_id(), $preview, 10 * MINUTE_IN_SECONDS);
            $notice=['type'=>'success','message'=>sprintf(__('Preview de lote fiscal generado: %1$s con %2$d comprobantes nuevos. No está firmado ni enviado.','sistema-para-farmacias'),$preview['identifier'],$preview['line_count'])];
        }
        set_transient(self::NOTICE_PREFIX . get_current_user_id(), $notice, MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=spf-fiscal')); exit;
    }

    public function handle_daily_summary_persist() {
        if (!current_user_can(SPF_Roles::INVOICES) && !current_user_can('manage_options')) { wp_die(esc_html__('No tienes permisos para persistir lotes fiscales.', 'sistema-para-farmacias')); }
        check_admin_referer('spf_fiscal_daily_summary_persist');
        $date = sanitize_text_field(wp_unslash($_POST['reference_date'] ?? ''));
        $result = SPF_Fiscal_Batch_Repository::create_or_reuse('daily_summary', $date);
        if (is_wp_error($result)) {
            $notice=['type'=>'error','message'=>$result->get_error_message()];
        } else {
            $batch=(array)($result['batch']??[]);
            $created=!empty($result['created']);
            $notice=[
                'type'=>$created?'success':'warning',
                'message'=>$created
                    ? sprintf(__('Lote fiscal %1$s persistido con %2$d comprobantes. Sigue sin firma ni envío.','sistema-para-farmacias'),$batch['identifier']??'',(int)($batch['line_count']??0))
                    : sprintf(__('No había comprobantes nuevos: se reutiliza el lote %s ya persistido.','sistema-para-farmacias'),$batch['identifier']??''),
            ];
        }
        delete_transient(self::PREVIEW_PREFIX . get_current_user_id());
        set_transient(self::NOTICE_PREFIX . get_current_user_id(), $notice, MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=spf-fiscal')); exit;
    }

    public static function consume_notice() { $key=self::NOTICE_PREFIX.get_current_user_id();$value=get_transient($key);delete_transient($key);return is_array($value)?$value:null; }
    public static function consume_preview() { $key=self::PREVIEW_PREFIX.get_current_user_id();$value=get_transient($key);delete_transient($key);return is_array($value)?$value:null; }

    private static function normalize_date($value) {
        $value = trim((string)$value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) { return ''; }
        [$year,$month,$day] = array_map('intval', explode('-', $value));
        return checkdate($month,$day,$year) ? $value : '';
    }
}
