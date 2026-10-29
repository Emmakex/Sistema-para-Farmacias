<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Validation_Manager {
    const NOTICE_PREFIX = 'spf_validation_notice_';

    public function hooks() { add_action('admin_post_spf_fiscal_validate_local', [$this, 'handle_validate_local']); }

    public static function statuses() {
        return [
            'not_validated' => __('Sin validar', 'sistema-para-farmacias'),
            'valid_local' => __('Validación local correcta', 'sistema-para-farmacias'),
            'partial' => __('Validación parcial', 'sistema-para-farmacias'),
            'resources_missing' => __('Recursos XSD/XSL no configurados', 'sistema-para-farmacias'),
            'validation_error' => __('Error de validación', 'sistema-para-farmacias'),
        ];
    }

    public static function profile() {
        $profile = SPF_Config::get('electronic_invoicing.validation_profile', []);
        return is_array($profile) ? $profile : [];
    }

    public static function active_validator() {
        $profile = self::profile();
        $code = sanitize_key((string) ($profile['validator'] ?? ''));
        return $code !== '' ? SPF_Fiscal_Validator_Registry::get($code) : null;
    }

    public static function resources(array $context = []) {
        $profile = self::profile();
        $xsd = defined('SPF_FISCAL_XSD_PATH') ? (string) SPF_FISCAL_XSD_PATH : (string) getenv('SPF_FISCAL_XSD_PATH');
        $xsl = defined('SPF_FISCAL_XSL_PATH') ? (string) SPF_FISCAL_XSL_PATH : (string) getenv('SPF_FISCAL_XSL_PATH');
        $xsd = apply_filters('spf_fiscal_validation_xsd_path', $xsd, $context);
        $xsl = apply_filters('spf_fiscal_validation_xsl_path', $xsl, $context);
        $reference = !empty($context['document']) ? strtoupper((string)$context['document']['series']).'-'.(string)$context['document']['correlative'] : 'document';
        return [
            'xsd_path' => $xsd,
            'xsl_path' => $xsl,
            'filename' => sanitize_file_name(($context['country_code'] ?? 'xx').'-'.$reference.'.xml'),
            'filename_parameter' => sanitize_text_field((string)($profile['filename_parameter'] ?? 'nombreArchivoEnviado')),
        ];
    }

    public static function validate_signed_payload($document_id, array $signed) {
        $validator = self::active_validator();
        if (!$validator) {
            return new WP_Error('spf_fiscal_validator_missing', __('No hay un validador fiscal local registrado.', 'sistema-para-farmacias'));
        }
        if (empty($signed['xml']) || empty($signed['verified'])) {
            return new WP_Error('spf_fiscal_validation_unsigned', __('La validación fiscal exige un XML firmado y verificado localmente.', 'sistema-para-farmacias'));
        }
        $context = SPF_Fiscal_Manager::context($document_id);
        if (is_wp_error($context)) { return $context; }
        $resources = self::resources($context);
        if ($resources['xsd_path'] === '' && $resources['xsl_path'] === '') {
            return new WP_Error('spf_fiscal_validation_resources_missing', __('Configura SPF_FISCAL_XSD_PATH y/o SPF_FISCAL_XSL_PATH con archivos oficiales locales.', 'sistema-para-farmacias'));
        }

        $result = $validator->validate((string)$signed['xml'], $resources, $context);
        if (is_wp_error($result)) { return $result; }
        $result['signed_xml_sha256'] = sanitize_text_field((string)($signed['signed_xml_sha256'] ?? hash('sha256', (string)$signed['xml'])));
        $checks = (array)($result['checks'] ?? []);
        $configured = 0; $valid = 0;
        foreach ($checks as $check) {
            if ($check !== 'not_configured') { $configured++; }
            if ($check === 'valid') { $valid++; }
        }
        $result['validation_status'] = !empty($result['ok'])
            ? (($configured >= 2 && $valid >= 2) ? 'valid_local' : 'partial')
            : 'validation_error';
        return $result;
    }

    public static function validate_document($document_id) {
        $signed = SPF_Fiscal_Signing_Manager::build_signed_payload($document_id);
        if (is_wp_error($signed)) {
            self::store_result($document_id, 'validation_error', ['errors'=>[$signed->get_error_message()]]);
            return $signed;
        }
        $result = self::validate_signed_payload($document_id, $signed);
        if (is_wp_error($result)) {
            $status = $result->get_error_code() === 'spf_fiscal_validation_resources_missing' ? 'resources_missing' : 'validation_error';
            self::store_result($document_id, $status, ['errors'=>[$result->get_error_message()]]);
            return $result;
        }
        $status = sanitize_key((string)($result['validation_status'] ?? 'validation_error'));
        self::store_result($document_id, $status, $result);
        return $result;
    }

    private static function store_result($document_id, $status, array $result) {
        global $wpdb;
        $document_id = absint($document_id);
        if (!$document_id) { return; }
        $table = SPF_Database::table('invoices');
        $existing = $wpdb->get_var($wpdb->prepare("SELECT response_body FROM $table WHERE id=%d", $document_id));
        $metadata = json_decode((string)$existing, true);
        $metadata = is_array($metadata) ? $metadata : [];
        unset($metadata['sunat_beta']);
        $validated_hash = sanitize_text_field((string)($result['signed_xml_sha256'] ?? ''));
        $metadata['local_validation'] = [
            'status' => sanitize_key($status),
            'validator' => self::active_validator() ? self::active_validator()->code() : '',
            'checks' => array_map('sanitize_key', (array)($result['checks'] ?? [])),
            'errors' => array_map('sanitize_text_field', (array)($result['errors'] ?? [])),
            'warnings' => array_map('sanitize_text_field', (array)($result['warnings'] ?? [])),
            'signed_xml_sha256' => $validated_hash,
            'remote_validated' => false,
            'validated_at' => current_time('mysql'),
        ];
        $wpdb->update($table, [
            'validation_status' => sanitize_key($status),
            'validation_xml_sha256' => $validated_hash,
            'transport_status' => 'not_prepared',
            'beta_status' => 'not_tested',
            'response_body' => wp_json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => current_time('mysql'),
        ], ['id'=>$document_id]);
    }

    public function handle_validate_local() {
        if (!current_user_can(SPF_Roles::INVOICES) && !current_user_can('manage_options')) { wp_die(esc_html__('No tienes permisos para validar comprobantes.', 'sistema-para-farmacias')); }
        check_admin_referer('spf_fiscal_validate_local');
        $document_id = absint($_POST['document_id'] ?? 0);
        $result = self::validate_document($document_id);
        if (is_wp_error($result)) { $notice = ['type'=>'error','message'=>$result->get_error_message()]; }
        elseif (($result['validation_status'] ?? '') === 'valid_local') { $notice = ['type'=>'success','message'=>__('XSD y XSL han superado la validación local. Todavía no existe aceptación fiscal de producción.', 'sistema-para-farmacias')]; }
        else { $notice = ['type'=>'warning','message'=>__('La validación local se completó parcialmente. Revisa los recursos XSD/XSL configurados.', 'sistema-para-farmacias')]; }
        set_transient(self::NOTICE_PREFIX.get_current_user_id(), $notice, MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=spf-fiscal')); exit;
    }

    public static function consume_notice() {
        $key = self::NOTICE_PREFIX.get_current_user_id(); $notice = get_transient($key); delete_transient($key); return is_array($notice) ? $notice : null;
    }
}
