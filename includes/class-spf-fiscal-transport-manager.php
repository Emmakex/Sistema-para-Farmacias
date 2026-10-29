<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Transport_Manager {
    const NOTICE_PREFIX = 'spf_transport_notice_';

    public function hooks() {
        add_action('admin_post_spf_fiscal_transport_dry_run', [$this, 'handle_dry_run']);
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
        $prepared = SPF_Fiscal_Manager::prepare_preview($document_id);
        if (is_wp_error($prepared)) { return $prepared; }
        $context = SPF_Fiscal_Manager::context($document_id);
        if (is_wp_error($context)) { return $context; }
        $transport = self::active_transport();
        if (!$transport) { return new WP_Error('spf_transport_missing', __('No hay un transporte fiscal registrado.', 'sistema-para-farmacias')); }
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
            'reference' => $reference,
            'filename' => sanitize_file_name($context['country_code'] . '-' . $reference . '.xml'),
            'xml' => $prepared['xml'],
            'xml_sha256' => $prepared['sha256'],
            'signed' => false,
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
            'signed' => false,
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

    public function handle_dry_run() {
        if (!current_user_can(SPF_Roles::INVOICES) && !current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para probar el transporte fiscal.', 'sistema-para-farmacias'));
        }
        check_admin_referer('spf_fiscal_transport_dry_run');
        $document_id = absint($_POST['document_id'] ?? 0);
        $result = self::inspect($document_id);
        if (is_wp_error($result)) {
            $notice = ['type' => 'error', 'message' => $result->get_error_message()];
        } else {
            $notice = ['type' => 'success', 'message' => __('Dry-run completado. El paquete es inspeccionable, pero continúa bloqueado para transmisión porque no está firmado.', 'sistema-para-farmacias')];
        }
        set_transient(self::NOTICE_PREFIX . get_current_user_id(), $notice, MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=spf-fiscal'));
        exit;
    }

    public static function consume_notice() {
        $key = self::NOTICE_PREFIX . get_current_user_id();
        $notice = get_transient($key);
        delete_transient($key);
        return is_array($notice) ? $notice : null;
    }
}
