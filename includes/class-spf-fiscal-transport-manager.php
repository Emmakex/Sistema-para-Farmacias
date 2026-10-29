<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Transport_Manager {
    const NOTICE_PREFIX = 'spf_transport_notice_';

    public function hooks() {
        add_action('admin_post_spf_fiscal_transport_dry_run', [$this, 'handle_dry_run']);
        add_action('admin_post_spf_fiscal_beta_invoice', [$this, 'handle_beta_invoice']);
    }

    public static function statuses() {
        return [
            'not_prepared' => __('Sin preparar', 'sistema-para-farmacias'),
            'dry_run_validated' => __('Dry-run validado', 'sistema-para-farmacias'),
            'blocked_unsigned' => __('Bloqueado: sin firma', 'sistema-para-farmacias'),
            'submitted' => __('Transmitido', 'sistema-para-farmacias'),
            'response_received' => __('Respuesta recibida', 'sistema-para-farmacias'),
            'transport_error' => __('Error de transporte', 'sistema-para-farmacias'),
        ];
    }

    public static function beta_statuses() {
        return [
            'not_tested' => __('Sin prueba beta', 'sistema-para-farmacias'),
            'beta_submitted' => __('Enviado a beta', 'sistema-para-farmacias'),
            'beta_accepted' => __('Aceptado en beta', 'sistema-para-farmacias'),
            'beta_accepted_with_observation' => __('Beta con observaciones', 'sistema-para-farmacias'),
            'beta_rejected' => __('Rechazado en beta', 'sistema-para-farmacias'),
            'beta_transport_error' => __('Error beta', 'sistema-para-farmacias'),
        ];
    }

    public static function active_transport() {
        $country = SPF_Config::country_code();
        $electronic = SPF_Config::electronic_invoicing();
        $saved = sanitize_key((string) get_option('spf_fiscal_transport_' . $country, ''));
        $code = $saved ?: sanitize_key((string) ($electronic['default_transport'] ?? ''));
        if ($code !== '') {
            $transport = SPF_Fiscal_Transport_Registry::get($code);
            if ($transport) { return $transport; }
        }
        $all = SPF_Fiscal_Transport_Registry::all();
        return $all ? reset($all) : null;
    }

    public static function strategy_for_document($document_type) {
        $documents = SPF_Config::sales_documents();
        $meta = $documents[sanitize_key((string) $document_type)] ?? [];
        return sanitize_key((string) (is_array($meta) ? ($meta['fiscal_submission'] ?? 'single') : 'single'));
    }

    public static function build_package($document_id) {
        $prepared = SPF_Fiscal_Manager::build_preview($document_id);
        if (is_wp_error($prepared)) { return $prepared; }
        $context = $prepared['context'];
        $transport = self::active_transport();
        if (!$transport) { return new WP_Error('spf_transport_missing', __('No hay un transporte fiscal registrado.', 'sistema-para-farmacias')); }

        $xml = $prepared['xml'];
        $xml_sha256 = $prepared['sha256'];
        $signed = false;
        $signature_verified = false;
        $signature_error = '';
        $signature = SPF_Fiscal_Signing_Manager::build_signed_payload($document_id);
        if (is_wp_error($signature)) {
            $signature_error = $signature->get_error_message();
        } else {
            $xml = (string) ($signature['xml'] ?? $xml);
            $xml_sha256 = (string) ($signature['signed_xml_sha256'] ?? hash('sha256', $xml));
            $signed = !empty($signature['verified']);
            $signature_verified = !empty($signature['verified']);
        }

        $documents = SPF_Config::sales_documents();
        $document_meta = $documents[$context['document']['type']] ?? [];
        $strategy = self::strategy_for_document($context['document']['type']);
        $reference = strtoupper($context['document']['series']) . '-' . (string) $context['document']['correlative'];
        return [
            'country' => $context['country_code'],
            'adapter' => $prepared['adapter'],
            'transport' => $transport->code(),
            'environment' => $transport->environment(),
            'strategy' => $strategy,
            'document_id' => (int) $context['document']['id'],
            'document_type' => $context['document']['type'],
            'fiscal_type_code' => sanitize_text_field((string)(is_array($document_meta) ? ($document_meta['fiscal_type_code'] ?? '') : '')),
            'series' => strtoupper((string)$context['document']['series']),
            'correlative' => (string)$context['document']['correlative'],
            'issuer_tax_id' => sanitize_text_field((string)($context['issuer']['tax_id'] ?? '')),
            'reference' => $reference,
            'filename' => sanitize_file_name($context['country_code'] . '-' . $reference . '.xml'),
            'xml' => $xml,
            'xml_sha256' => $xml_sha256,
            'signed' => $signed,
            'signature_verified' => $signature_verified,
            'signature_error' => sanitize_text_field($signature_error),
            'validation_status' => sanitize_key($context['document']['validation_status'] ?? 'not_validated'),
            'created_at' => current_time('mysql'),
        ];
    }

    public static function inspect($document_id) {
        $package = self::build_package($document_id);
        if (is_wp_error($package)) { return $package; }
        $transport = self::active_transport();
        $result = $transport->inspect($package);
        if (is_wp_error($result)) { return $result; }
        $result = is_array($result) ? $result : [];
        $status = !empty($result['ok']) ? 'dry_run_validated' : 'transport_error';
        if (empty($package['signed']) && !empty($result['ok'])) { $status = 'blocked_unsigned'; }

        global $wpdb;
        $table = SPF_Database::table('invoices');
        $existing = $wpdb->get_var($wpdb->prepare("SELECT response_body FROM $table WHERE id=%d", absint($document_id)));
        $metadata = json_decode((string) $existing, true);
        $metadata = is_array($metadata) ? $metadata : [];
        $metadata['transport_dry_run'] = [
            'transport' => $transport->code(),
            'environment' => $transport->environment(),
            'strategy' => $package['strategy'],
            'package_filename' => $package['filename'],
            'xml_sha256' => $package['xml_sha256'],
            'signed' => !empty($package['signed']),
            'signature_verified' => !empty($package['signature_verified']),
            'signature_error' => $package['signature_error'],
            'validation_status' => $package['validation_status'],
            'transmitted' => false,
            'ok' => !empty($result['ok']),
            'errors' => array_values((array) ($result['errors'] ?? [])),
            'warnings' => array_values((array) ($result['warnings'] ?? [])),
            'inspected_at' => current_time('mysql'),
        ];
        $updated = $wpdb->update($table, [
            'transport_status' => $status,
            'response_body' => wp_json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => current_time('mysql'),
        ], ['id' => absint($document_id)]);
        if ($updated === false) { return new WP_Error('spf_transport_store', __('No se pudo guardar el resultado del dry-run.', 'sistema-para-farmacias')); }
        return ['package' => $package, 'result' => $result, 'transport_status' => $status];
    }

    public static function beta_invoice($document_id) {
        $transport = SPF_Fiscal_Transport_Registry::get('peru_beta');
        if (!$transport) { return new WP_Error('spf_beta_transport_missing', __('El transporte beta Perú no está registrado.', 'sistema-para-farmacias')); }
        $package = self::build_package($document_id);
        if (is_wp_error($package)) { return $package; }
        $inspection = $transport->inspect($package);
        if (is_wp_error($inspection)) { return $inspection; }
        if (empty($inspection['ok'])) { return new WP_Error('spf_beta_inspection_failed', implode(' ', (array)($inspection['errors'] ?? []))); }
        $result = $transport->transmit($package);
        if (is_wp_error($result)) {
            self::store_beta_result($document_id, 'beta_transport_error', ['error'=>$result->get_error_message()]);
            return $result;
        }
        $status = sanitize_key((string)($result['beta_status'] ?? 'beta_transport_error'));
        if (!array_key_exists($status, self::beta_statuses())) { $status = 'beta_transport_error'; }
        self::store_beta_result($document_id, $status, $result);
        return $result;
    }

    private static function store_beta_result($document_id, $status, array $result) {
        global $wpdb;
        $table = SPF_Database::table('invoices');
        $existing = $wpdb->get_var($wpdb->prepare("SELECT response_body FROM $table WHERE id=%d", absint($document_id)));
        $metadata = json_decode((string)$existing, true); $metadata = is_array($metadata) ? $metadata : [];
        $metadata['sunat_beta'] = [
            'status'=>sanitize_key($status),
            'remote_code'=>sanitize_text_field((string)($result['remote_code'] ?? '')),
            'remote_message'=>sanitize_text_field((string)($result['remote_message'] ?? ($result['error'] ?? ''))),
            'notes'=>array_map('sanitize_text_field',(array)($result['notes'] ?? [])),
            'request_zip_sha256'=>sanitize_text_field((string)($result['request_zip_sha256'] ?? '')),
            'cdr_zip_sha256'=>sanitize_text_field((string)($result['cdr_zip_sha256'] ?? '')),
            'cdr_xml_sha256'=>sanitize_text_field((string)($result['cdr_xml_sha256'] ?? '')),
            'environment'=>'beta',
            'production'=>false,
            'tested_at'=>current_time('mysql'),
        ];
        $wpdb->update($table, ['beta_status'=>sanitize_key($status),'response_body'=>wp_json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'updated_at'=>current_time('mysql')], ['id'=>absint($document_id)]);
    }

    public function handle_dry_run() {
        if (!current_user_can(SPF_Roles::INVOICES) && !current_user_can('manage_options')) { wp_die(esc_html__('No tienes permisos para probar el transporte fiscal.', 'sistema-para-farmacias')); }
        check_admin_referer('spf_fiscal_transport_dry_run');
        $document_id = absint($_POST['document_id'] ?? 0);
        $result = self::inspect($document_id);
        if (is_wp_error($result)) { $notice = ['type'=>'error','message'=>$result->get_error_message()]; }
        elseif ($result['transport_status'] === 'dry_run_validated') { $notice = ['type'=>'success','message'=>__('Dry-run completado con firma local verificada. El transporte sigue sin capacidad de transmisión real.', 'sistema-para-farmacias')]; }
        else { $notice = ['type'=>'warning','message'=>__('Dry-run completado sin transmisión, pero el paquete continúa bloqueado porque no dispone de una firma local verificada.', 'sistema-para-farmacias')]; }
        set_transient(self::NOTICE_PREFIX . get_current_user_id(), $notice, MINUTE_IN_SECONDS); wp_safe_redirect(admin_url('admin.php?page=spf-fiscal')); exit;
    }

    public function handle_beta_invoice() {
        if (!current_user_can(SPF_Roles::INVOICES) && !current_user_can('manage_options')) { wp_die(esc_html__('No tienes permisos para ejecutar pruebas beta fiscales.', 'sistema-para-farmacias')); }
        check_admin_referer('spf_fiscal_beta_invoice');
        $document_id = absint($_POST['document_id'] ?? 0);
        $result = self::beta_invoice($document_id);
        if (is_wp_error($result)) { $notice=['type'=>'error','message'=>$result->get_error_message()]; }
        else {
            $status=sanitize_key((string)($result['beta_status']??''));
            $notice=['type'=>$status==='beta_rejected'?'warning':'success','message'=>sprintf(__('Prueba SUNAT beta finalizada: %s. Esto no es una aceptación fiscal de producción.','sistema-para-farmacias'),self::beta_statuses()[$status]??$status)];
        }
        set_transient(self::NOTICE_PREFIX . get_current_user_id(), $notice, MINUTE_IN_SECONDS); wp_safe_redirect(admin_url('admin.php?page=spf-fiscal')); exit;
    }

    public static function consume_notice() {
        $key = self::NOTICE_PREFIX . get_current_user_id(); $notice = get_transient($key); delete_transient($key); return is_array($notice) ? $notice : null;
    }
}
