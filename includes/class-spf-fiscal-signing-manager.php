<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Signing_Manager {
    const NOTICE_PREFIX = 'spf_signature_notice_';

    public function hooks() { add_action('admin_post_spf_fiscal_sign_local', [$this, 'handle_sign_local']); }

    public static function statuses() {
        return [
            'not_signed' => __('Sin firmar', 'sistema-para-farmacias'),
            'verified' => __('Firma local verificada', 'sistema-para-farmacias'),
            'signature_error' => __('Error de firma', 'sistema-para-farmacias'),
        ];
    }

    public static function profile() {
        $profile = SPF_Config::get('electronic_invoicing.signature_profile', []);
        return is_array($profile) ? $profile : [];
    }

    public static function active_signer() {
        $profile = self::profile();
        $code = sanitize_key((string) ($profile['signer'] ?? ''));
        return $code !== '' ? SPF_Fiscal_Signer_Registry::get($code) : null;
    }

    public static function active_credentials_provider() {
        $profile = self::profile();
        $code = sanitize_key((string) ($profile['credentials_provider'] ?? ''));
        return $code !== '' ? SPF_Fiscal_Credentials_Registry::get($code) : null;
    }

    public static function build_signed_payload($document_id) {
        $preview = SPF_Fiscal_Manager::build_preview($document_id);
        if (is_wp_error($preview)) { return $preview; }
        $context = $preview['context'];
        $profile = self::profile();
        $signer = self::active_signer();
        $provider = self::active_credentials_provider();
        if (!$signer) { return new WP_Error('spf_fiscal_signer_missing', __('No hay un firmador fiscal registrado para el país activo.', 'sistema-para-farmacias')); }
        if (!$provider) { return new WP_Error('spf_fiscal_credentials_provider_missing', __('No hay un proveedor de credenciales fiscales registrado.', 'sistema-para-farmacias')); }
        if (!$signer->supports($profile)) { return new WP_Error('spf_fiscal_signature_profile_unsupported', __('El firmador no soporta el perfil criptográfico configurado.', 'sistema-para-farmacias')); }

        $credentials = $provider->load($context);
        if (is_wp_error($credentials)) { return $credentials; }
        $result = $signer->sign($preview['xml'], $credentials, $profile, $context);
        unset($credentials);
        if (is_wp_error($result)) { return $result; }
        if (empty($result['verified']) || empty($result['xml'])) {
            return new WP_Error('spf_fiscal_signature_not_verified', __('La firma no quedó verificada localmente.', 'sistema-para-farmacias'));
        }
        $result['preview_sha256'] = $preview['sha256'];
        $result['adapter'] = $preview['adapter'];
        return $result;
    }

    public static function sign_document($document_id) {
        $result = self::build_signed_payload($document_id);
        if (is_wp_error($result)) { self::store_error($document_id, $result->get_error_message()); return $result; }

        $signer = self::active_signer();
        $provider = self::active_credentials_provider();
        global $wpdb;
        $table = SPF_Database::table('invoices');
        $existing = $wpdb->get_var($wpdb->prepare("SELECT response_body FROM $table WHERE id=%d", absint($document_id)));
        $metadata = json_decode((string) $existing, true);
        $metadata = is_array($metadata) ? $metadata : [];
        $metadata['local_signature'] = [
            'status' => 'verified',
            'signer' => $signer ? $signer->code() : '',
            'credentials_provider' => $provider ? $provider->code() : '',
            'signature_method' => (string) ($result['signature_method'] ?? ''),
            'digest_method' => (string) ($result['digest_method'] ?? ''),
            'canonicalization_method' => (string) ($result['canonicalization_method'] ?? ''),
            'certificate_sha256' => (string) ($result['certificate_sha256'] ?? ''),
            'signed_xml_sha256' => (string) ($result['signed_xml_sha256'] ?? ''),
            'remote_validated' => false,
            'verified_at' => current_time('mysql'),
        ];
        unset($metadata['local_validation']);
        $updated = $wpdb->update($table, [
            'signature_status' => 'verified',
            'signature_method' => sanitize_text_field((string) ($result['signature_method'] ?? '')),
            'signature_certificate_sha256' => sanitize_text_field((string) ($result['certificate_sha256'] ?? '')),
            'signature_xml_sha256' => sanitize_text_field((string) ($result['signed_xml_sha256'] ?? '')),
            'validation_status' => 'not_validated',
            'transport_status' => 'not_prepared',
            'response_body' => wp_json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => current_time('mysql'),
        ], ['id' => absint($document_id)]);
        if ($updated === false) { return new WP_Error('spf_fiscal_signature_store', __('No se pudo guardar la evidencia local de la firma.', 'sistema-para-farmacias')); }
        return $result;
    }

    private static function store_error($document_id, $message) {
        global $wpdb;
        $document_id = absint($document_id);
        if (!$document_id) { return; }
        $table = SPF_Database::table('invoices');
        $existing = $wpdb->get_var($wpdb->prepare("SELECT response_body FROM $table WHERE id=%d", $document_id));
        $metadata = json_decode((string) $existing, true);
        $metadata = is_array($metadata) ? $metadata : [];
        $metadata['local_signature'] = ['status'=>'signature_error','message'=>sanitize_text_field((string)$message),'verified_at'=>current_time('mysql')];
        unset($metadata['local_validation']);
        $wpdb->update($table, [
            'signature_status' => 'signature_error',
            'validation_status' => 'not_validated',
            'transport_status' => 'not_prepared',
            'response_body' => wp_json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => current_time('mysql'),
        ], ['id' => $document_id]);
    }

    public function handle_sign_local() {
        if (!current_user_can(SPF_Roles::INVOICES) && !current_user_can('manage_options')) { wp_die(esc_html__('No tienes permisos para firmar comprobantes.', 'sistema-para-farmacias')); }
        check_admin_referer('spf_fiscal_sign_local');
        $document_id = absint($_POST['document_id'] ?? 0);
        $result = self::sign_document($document_id);
        $notice = is_wp_error($result)
            ? ['type'=>'error','message'=>$result->get_error_message()]
            : ['type'=>'success','message'=>__('Firma XMLDSig generada y verificada localmente. El comprobante todavía no ha sido transmitido.', 'sistema-para-farmacias')];
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
