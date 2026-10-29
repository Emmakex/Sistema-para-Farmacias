<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Batch_Beta_Manager {
    const NOTICE_PREFIX = 'spf_batch_beta_notice_';
    const LOCK = 'spf_fiscal_beta_batch_lock';
    const SUBMIT_COOLDOWN = 'spf_fiscal_beta_batch_submit_cooldown';
    const POLL_COOLDOWN = 'spf_fiscal_beta_batch_poll_cooldown';

    public function hooks() {
        add_action('admin_post_spf_fiscal_batch_beta_submit', [$this, 'handle_submit']);
        add_action('admin_post_spf_fiscal_batch_beta_poll', [$this, 'handle_poll']);
    }

    public static function statuses() {
        return [
            'not_tested'=>__('Sin prueba beta','sistema-para-farmacias'),
            'beta_batch_ticketed'=>__('Ticket beta recibido','sistema-para-farmacias'),
            'beta_batch_processing'=>__('Beta en proceso','sistema-para-farmacias'),
            'beta_batch_accepted'=>__('Lote aceptado en beta','sistema-para-farmacias'),
            'beta_batch_accepted_with_observation'=>__('Lote beta con observaciones','sistema-para-farmacias'),
            'beta_batch_rejected'=>__('Lote rechazado en beta','sistema-para-farmacias'),
            'beta_batch_transport_error'=>__('Error de transporte beta','sistema-para-farmacias'),
        ];
    }

    public static function transport($batch_type) {
        $profile = SPF_Fiscal_Batch_Manager::profile($batch_type);
        $code = sanitize_key((string)($profile['beta_transport'] ?? ''));
        if ($code === '') { return null; }
        $transport = SPF_Fiscal_Transport_Registry::get($code);
        return $transport instanceof SPF_Fiscal_Async_Batch_Transport_Interface ? $transport : null;
    }

    public static function enabled($batch_type) {
        $transport = self::transport($batch_type);
        return $transport && $transport->batch_enabled();
    }

    public static function build_submit_package($batch_id) {
        $integrity = SPF_Fiscal_Batch_Integrity::reconstruct($batch_id);
        if (is_wp_error($integrity)) { return $integrity; }
        $batch = $integrity['batch'];
        if (sanitize_key((string)($batch['signature_status'] ?? '')) !== 'verified') {
            return new WP_Error('spf_beta_batch_signature_required', __('El lote debe tener una firma local verificada antes del beta.', 'sistema-para-farmacias'));
        }
        if (sanitize_key((string)($batch['validation_status'] ?? '')) !== 'valid_local') {
            return new WP_Error('spf_beta_batch_validation_required', __('El lote debe haber superado XSD y XSL localmente antes del beta.', 'sistema-para-farmacias'));
        }
        $signed = SPF_Fiscal_Batch_Lifecycle::build_signed_payload($batch_id);
        if (is_wp_error($signed)) { return $signed; }

        $stored_signed_hash = trim((string)($batch['signature_xml_sha256'] ?? ''));
        $stored_certificate_hash = trim((string)($batch['signature_certificate_sha256'] ?? ''));
        $stored_validation_hash = trim((string)($batch['validation_xml_sha256'] ?? ''));
        $current_signed_hash = trim((string)($signed['signed_xml_sha256'] ?? ''));
        $current_certificate_hash = trim((string)($signed['certificate_sha256'] ?? ''));
        if ($stored_signed_hash === '' || $stored_certificate_hash === '' || $stored_validation_hash === '') {
            return new WP_Error('spf_beta_batch_evidence_missing', __('Falta evidencia local de firma o validación del lote.', 'sistema-para-farmacias'));
        }
        if (!hash_equals($stored_signed_hash, $current_signed_hash) || !hash_equals($stored_certificate_hash, $current_certificate_hash)) {
            return new WP_Error('spf_beta_batch_signature_mismatch', __('La firma actual del lote no coincide con la evidencia persistida. Firma y valida de nuevo.', 'sistema-para-farmacias'));
        }
        if (!hash_equals($stored_validation_hash, $current_signed_hash)) {
            return new WP_Error('spf_beta_batch_validation_mismatch', __('El XML firmado no coincide con el XML exacto que superó la validación local.', 'sistema-para-farmacias'));
        }
        $issuer = (array)($integrity['context']['issuer'] ?? []);
        return [
            'country'=>sanitize_key((string)($batch['country_code'] ?? '')),
            'batch_id'=>(int)$batch_id,
            'batch_type'=>sanitize_key((string)($batch['batch_type'] ?? '')),
            'identifier'=>sanitize_text_field((string)($batch['identifier'] ?? '')),
            'reference_date'=>sanitize_text_field((string)($batch['reference_date'] ?? '')),
            'issuer_tax_id'=>sanitize_text_field((string)($issuer['tax_id'] ?? '')),
            'xml'=>(string)($signed['xml'] ?? ''),
            'xml_sha256'=>$current_signed_hash,
            'signed'=>true,
            'signature_verified'=>true,
            'signature_certificate_sha256'=>$current_certificate_hash,
            'validation_status'=>'valid_local',
            'validation_xml_sha256'=>$stored_validation_hash,
        ];
    }

    public static function submit($batch_id) {
        if (get_transient(self::SUBMIT_COOLDOWN)) {
            return new WP_Error('spf_beta_batch_cooldown', __('Espera unos segundos antes de enviar otro lote beta.', 'sistema-para-farmacias'));
        }
        if (!self::acquire_lock()) {
            return new WP_Error('spf_beta_batch_busy', __('Ya hay una operación beta de lote en curso en esta instalación.', 'sistema-para-farmacias'));
        }
        try {
            $batch = SPF_Fiscal_Batch_Repository::get($batch_id);
            if (!$batch) { return new WP_Error('spf_beta_batch_missing', __('No se encontró el lote fiscal.', 'sistema-para-farmacias')); }
            if (trim((string)($batch['remote_ticket'] ?? '')) !== '') {
                return new WP_Error('spf_beta_batch_already_ticketed', __('Este lote ya tiene ticket beta. Consulta su estado en lugar de volver a enviarlo.', 'sistema-para-farmacias'));
            }
            $transport = self::transport((string)$batch['batch_type']);
            if (!$transport) { return new WP_Error('spf_beta_batch_transport_missing', __('El país activo no define un transporte beta asíncrono para este lote.', 'sistema-para-farmacias')); }
            if (!$transport->batch_enabled()) { return new WP_Error('spf_beta_batch_disabled', __('El transporte beta asíncrono del lote no está habilitado explícitamente.', 'sistema-para-farmacias')); }

            $revalidation = SPF_Fiscal_Batch_Lifecycle::validate_batch($batch_id);
            if (is_wp_error($revalidation)) { return $revalidation; }
            if (sanitize_key((string)($revalidation['validation_status'] ?? '')) !== 'valid_local') {
                return new WP_Error('spf_beta_batch_revalidation_required', __('El XML firmado exacto debe volver a superar XSD y XSL inmediatamente antes del envío beta.', 'sistema-para-farmacias'));
            }
            $package = self::build_submit_package($batch_id);
            if (is_wp_error($package)) { return $package; }
            $inspection = $transport->inspect_batch($package);
            if (is_wp_error($inspection)) { return $inspection; }
            if (empty($inspection['ok'])) { return new WP_Error('spf_beta_batch_inspection', implode(' ', (array)($inspection['errors'] ?? []))); }

            set_transient(self::SUBMIT_COOLDOWN, 1, 15);
            $result = $transport->submit_batch($package);
            if (is_wp_error($result)) {
                self::store_result($batch_id, 'beta_batch_transport_error', ['error'=>$result->get_error_message()], false);
                return $result;
            }
            $status = self::normalize_status($result['beta_status'] ?? '');
            $ticket = sanitize_text_field((string)($result['ticket'] ?? ''));
            if ($ticket === '') {
                $error = new WP_Error('spf_beta_batch_ticket_empty', __('El transporte beta no devolvió un ticket persistible.', 'sistema-para-farmacias'));
                self::store_result($batch_id, 'beta_batch_transport_error', ['error'=>$error->get_error_message()], false);
                return $error;
            }
            if (!self::store_result($batch_id, $status, $result, true)) {
                return new WP_Error('spf_beta_batch_ticket_store', sprintf(__('Se recibió el ticket beta %s pero no pudo persistirse. No reenvíes el lote hasta recuperar esta evidencia.', 'sistema-para-farmacias'), $ticket));
            }
            return $result;
        } finally {
            self::release_lock();
        }
    }

    public static function poll($batch_id) {
        if (get_transient(self::POLL_COOLDOWN)) {
            return new WP_Error('spf_beta_batch_poll_cooldown', __('Espera unos segundos antes de volver a consultar el ticket beta.', 'sistema-para-farmacias'));
        }
        if (!self::acquire_lock()) {
            return new WP_Error('spf_beta_batch_busy', __('Ya hay una operación beta de lote en curso en esta instalación.', 'sistema-para-farmacias'));
        }
        try {
            $batch = SPF_Fiscal_Batch_Repository::get($batch_id);
            if (!$batch) { return new WP_Error('spf_beta_batch_missing', __('No se encontró el lote fiscal.', 'sistema-para-farmacias')); }
            $ticket = trim((string)($batch['remote_ticket'] ?? ''));
            if ($ticket === '') { return new WP_Error('spf_beta_batch_ticket_missing', __('El lote todavía no tiene ticket beta.', 'sistema-para-farmacias')); }
            $transport = self::transport((string)$batch['batch_type']);
            if (!$transport) { return new WP_Error('spf_beta_batch_transport_missing', __('El país activo no define un transporte beta asíncrono para este lote.', 'sistema-para-farmacias')); }
            if (!$transport->batch_enabled()) { return new WP_Error('spf_beta_batch_disabled', __('El transporte beta asíncrono del lote no está habilitado explícitamente.', 'sistema-para-farmacias')); }
            $integrity = SPF_Fiscal_Batch_Integrity::reconstruct($batch_id);
            if (is_wp_error($integrity)) { return $integrity; }
            $issuer = (array)($integrity['context']['issuer'] ?? []);
            $package = [
                'country'=>sanitize_key((string)($batch['country_code'] ?? '')),
                'batch_id'=>(int)$batch_id,
                'batch_type'=>sanitize_key((string)($batch['batch_type'] ?? '')),
                'identifier'=>sanitize_text_field((string)($batch['identifier'] ?? '')),
                'issuer_tax_id'=>sanitize_text_field((string)($issuer['tax_id'] ?? '')),
                'ticket'=>$ticket,
            ];
            set_transient(self::POLL_COOLDOWN, 1, 5);
            $result = $transport->poll_batch($package);
            if (is_wp_error($result)) {
                self::store_result($batch_id, 'beta_batch_transport_error', ['error'=>$result->get_error_message(),'ticket'=>$ticket], false);
                return $result;
            }
            $status = self::normalize_status($result['beta_status'] ?? '');
            if (!self::store_result($batch_id, $status, $result, false)) {
                return new WP_Error('spf_beta_batch_status_store', __('La consulta beta respondió, pero no pudo persistirse su evidencia.', 'sistema-para-farmacias'));
            }
            return $result;
        } finally {
            self::release_lock();
        }
    }

    private static function acquire_lock() {
        global $wpdb;
        $name = 'spf_beta_batch_' . md5($wpdb->prefix . '|' . self::LOCK);
        return (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $name)) === 1;
    }

    private static function release_lock() {
        global $wpdb;
        $name = 'spf_beta_batch_' . md5($wpdb->prefix . '|' . self::LOCK);
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    private static function normalize_status($status) {
        $status = sanitize_key((string)$status);
        return array_key_exists($status, self::statuses()) ? $status : 'beta_batch_transport_error';
    }

    private static function store_result($batch_id, $status, array $result, $store_ticket) {
        global $wpdb;
        $table = SPF_Database::table('fiscal_batches');
        $batch = SPF_Fiscal_Batch_Repository::get($batch_id);
        if (!$batch) { return false; }
        $metadata = json_decode((string)($batch['response_body'] ?? ''), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $previous = is_array($metadata['fiscal_beta_batch'] ?? null) ? $metadata['fiscal_beta_batch'] : [];
        $ticket = sanitize_text_field((string)($result['ticket'] ?? ($batch['remote_ticket'] ?? '')));
        $evidence = [
            'status'=>sanitize_key((string)$status),
            'transport'=>sanitize_key((string)($result['transport'] ?? ($previous['transport'] ?? ''))),
            'environment'=>'beta',
            'ticket'=>$ticket,
            'status_code'=>sanitize_text_field((string)($result['status_code'] ?? '')),
            'remote_code'=>sanitize_text_field((string)($result['remote_code'] ?? '')),
            'remote_message'=>sanitize_text_field((string)($result['remote_message'] ?? ($result['error'] ?? ''))),
            'notes'=>array_map('sanitize_text_field',(array)($result['notes'] ?? [])),
            'request_zip_sha256'=>sanitize_text_field((string)($result['request_zip_sha256'] ?? ($previous['request_zip_sha256'] ?? ''))),
            'response_zip_sha256'=>sanitize_text_field((string)($result['response_zip_sha256'] ?? '')),
            'cdr_xml_sha256'=>sanitize_text_field((string)($result['cdr_xml_sha256'] ?? '')),
            'production'=>false,
            'submitted_at'=>sanitize_text_field((string)($previous['submitted_at'] ?? ($store_ticket ? current_time('mysql') : ''))),
            'checked_at'=>current_time('mysql'),
        ];
        $metadata['fiscal_beta_batch'] = $evidence;
        $data = [
            'beta_status'=>sanitize_key((string)$status),
            'response_body'=>wp_json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'updated_at'=>current_time('mysql'),
        ];
        if ($store_ticket && $ticket !== '') { $data['remote_ticket'] = $ticket; }
        return $wpdb->update($table, $data, ['id'=>absint($batch_id)]) !== false;
    }

    public function handle_submit() {
        if (!current_user_can(SPF_Roles::INVOICES) && !current_user_can('manage_options')) { wp_die(esc_html__('No tienes permisos para enviar lotes beta.', 'sistema-para-farmacias')); }
        check_admin_referer('spf_fiscal_batch_beta_submit');
        $result = self::submit(absint($_POST['batch_id'] ?? 0));
        if (is_wp_error($result)) { $notice=['type'=>'error','message'=>$result->get_error_message()]; }
        else { $notice=['type'=>'success','message'=>sprintf(__('Lote enviado al beta y ticket recibido: %s. No modifica el estado fiscal de producción.','sistema-para-farmacias'),sanitize_text_field((string)($result['ticket']??'')))]; }
        set_transient(self::NOTICE_PREFIX.get_current_user_id(),$notice,MINUTE_IN_SECONDS); wp_safe_redirect(admin_url('admin.php?page=spf-fiscal')); exit;
    }

    public function handle_poll() {
        if (!current_user_can(SPF_Roles::INVOICES) && !current_user_can('manage_options')) { wp_die(esc_html__('No tienes permisos para consultar lotes beta.', 'sistema-para-farmacias')); }
        check_admin_referer('spf_fiscal_batch_beta_poll');
        $result = self::poll(absint($_POST['batch_id'] ?? 0));
        if (is_wp_error($result)) { $notice=['type'=>'error','message'=>$result->get_error_message()]; }
        else {
            $status=sanitize_key((string)($result['beta_status']??''));
            $notice=['type'=>$status==='beta_batch_rejected'?'warning':'success','message'=>sprintf(__('Consulta beta del lote: %s. Esto no es una aceptación fiscal de producción.','sistema-para-farmacias'),self::statuses()[$status]??$status)];
        }
        set_transient(self::NOTICE_PREFIX.get_current_user_id(),$notice,MINUTE_IN_SECONDS); wp_safe_redirect(admin_url('admin.php?page=spf-fiscal')); exit;
    }

    public static function consume_notice() {
        $key=self::NOTICE_PREFIX.get_current_user_id(); $value=get_transient($key); delete_transient($key); return is_array($value)?$value:null;
    }
}
