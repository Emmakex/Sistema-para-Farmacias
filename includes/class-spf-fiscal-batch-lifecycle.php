<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Batch_Lifecycle {
    const NOTICE_PREFIX = 'spf_batch_lifecycle_notice_';

    public function hooks() {
        add_action('admin_post_spf_fiscal_batch_sign_local', [$this, 'handle_sign']);
        add_action('admin_post_spf_fiscal_batch_validate_local', [$this, 'handle_validate']);
    }

    public static function signature_profile($batch_type) {
        $base = SPF_Fiscal_Signing_Manager::profile();
        $batch = SPF_Fiscal_Batch_Manager::profile($batch_type);
        $override = is_array($batch['signature_profile'] ?? null) ? $batch['signature_profile'] : [];
        return array_merge($base, $override);
    }

    public static function validation_profile($batch_type) {
        $batch = SPF_Fiscal_Batch_Manager::profile($batch_type);
        return is_array($batch['validation_profile'] ?? null) ? $batch['validation_profile'] : [];
    }

    public static function validation_resources($batch_type, array $context = []) {
        $profile = self::validation_profile($batch_type);
        $xsd_key = trim((string)($profile['xsd_path_key'] ?? ''));
        $xsl_key = trim((string)($profile['xsl_path_key'] ?? ''));
        $xsd = self::external_value($xsd_key);
        $xsl = self::external_value($xsl_key);
        $identifier = sanitize_file_name((string)($context['identifier'] ?? 'batch'));
        return [
            'xsd_path'=>apply_filters('spf_fiscal_batch_validation_xsd_path', $xsd, $batch_type, $context),
            'xsl_path'=>apply_filters('spf_fiscal_batch_validation_xsl_path', $xsl, $batch_type, $context),
            'filename'=>$identifier . '.xml',
            'filename_parameter'=>sanitize_text_field((string)($profile['filename_parameter'] ?? 'nombreArchivoEnviado')),
        ];
    }

    public static function build_signed_payload($batch_id) {
        $integrity = SPF_Fiscal_Batch_Integrity::reconstruct($batch_id);
        if (is_wp_error($integrity)) { return $integrity; }
        $batch = $integrity['batch'];
        $profile = self::signature_profile((string)$batch['batch_type']);
        $signer_code = sanitize_key((string)($profile['signer'] ?? ''));
        $provider_code = sanitize_key((string)($profile['credentials_provider'] ?? ''));
        $signer = $signer_code !== '' ? SPF_Fiscal_Signer_Registry::get($signer_code) : null;
        $provider = $provider_code !== '' ? SPF_Fiscal_Credentials_Registry::get($provider_code) : null;
        if (!$signer) { return new WP_Error('spf_batch_signer_missing', __('No hay un firmador registrado para este lote fiscal.', 'sistema-para-farmacias')); }
        if (!$provider) { return new WP_Error('spf_batch_credentials_missing', __('No hay un proveedor de credenciales registrado para este lote fiscal.', 'sistema-para-farmacias')); }
        if (!$signer->supports($profile)) { return new WP_Error('spf_batch_signature_profile', __('El firmador no soporta el perfil criptográfico del lote.', 'sistema-para-farmacias')); }

        $context = $integrity['context'];
        $context['identifier'] = $integrity['identifier'];
        $credentials = $provider->load($context);
        if (is_wp_error($credentials)) { return $credentials; }
        $result = $signer->sign($integrity['xml'], $credentials, $profile, $context);
        unset($credentials);
        if (is_wp_error($result)) { return $result; }
        if (empty($result['verified']) || empty($result['xml'])) {
            return new WP_Error('spf_batch_signature_unverified', __('La firma del lote no quedó verificada localmente.', 'sistema-para-farmacias'));
        }
        $result['batch_id'] = (int)$batch_id;
        $result['batch_type'] = sanitize_key((string)$batch['batch_type']);
        $result['identifier'] = $integrity['identifier'];
        $result['unsigned_xml_sha256'] = $integrity['xml_sha256'];
        return $result;
    }

    public static function sign_batch($batch_id) {
        $batch = SPF_Fiscal_Batch_Repository::get($batch_id);
        if (!$batch) { return new WP_Error('spf_batch_missing', __('No se encontró el lote fiscal.', 'sistema-para-farmacias')); }
        if (trim((string)($batch['remote_ticket'] ?? '')) !== '') {
            return new WP_Error('spf_batch_remote_locked', __('El lote ya tiene un ticket remoto y su evidencia local queda congelada.', 'sistema-para-farmacias'));
        }
        $result = self::build_signed_payload($batch_id);
        if (is_wp_error($result)) { self::store_signature_error($batch_id, $result->get_error_message()); return $result; }
        global $wpdb;
        $table = SPF_Database::table('fiscal_batches');
        $metadata = self::metadata($batch['response_body'] ?? '');
        $metadata['local_signature'] = [
            'status'=>'verified',
            'signature_method'=>sanitize_text_field((string)($result['signature_method'] ?? '')),
            'digest_method'=>sanitize_text_field((string)($result['digest_method'] ?? '')),
            'canonicalization_method'=>sanitize_text_field((string)($result['canonicalization_method'] ?? '')),
            'certificate_sha256'=>sanitize_text_field((string)($result['certificate_sha256'] ?? '')),
            'signed_xml_sha256'=>sanitize_text_field((string)($result['signed_xml_sha256'] ?? '')),
            'unsigned_xml_sha256'=>sanitize_text_field((string)($result['unsigned_xml_sha256'] ?? '')),
            'verified_at'=>current_time('mysql'),
        ];
        unset($metadata['local_validation'], $metadata['fiscal_beta_batch']);
        $updated = $wpdb->update($table, [
            'signature_status'=>'verified',
            'signature_method'=>sanitize_text_field((string)($result['signature_method'] ?? '')),
            'signature_certificate_sha256'=>sanitize_text_field((string)($result['certificate_sha256'] ?? '')),
            'signature_xml_sha256'=>sanitize_text_field((string)($result['signed_xml_sha256'] ?? '')),
            'validation_status'=>'not_validated',
            'validation_xml_sha256'=>'',
            'transport_status'=>'not_prepared',
            'beta_status'=>'not_tested',
            'response_body'=>wp_json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at'=>current_time('mysql'),
        ], ['id'=>absint($batch_id)]);
        return $updated === false ? new WP_Error('spf_batch_signature_store', __('No se pudo guardar la evidencia de firma del lote.', 'sistema-para-farmacias')) : $result;
    }

    public static function validate_batch($batch_id) {
        $batch = SPF_Fiscal_Batch_Repository::get($batch_id);
        if (!$batch) { return new WP_Error('spf_batch_missing', __('No se encontró el lote fiscal.', 'sistema-para-farmacias')); }
        if (trim((string)($batch['remote_ticket'] ?? '')) !== '') {
            return new WP_Error('spf_batch_remote_locked', __('El lote ya tiene un ticket remoto y su evidencia local queda congelada.', 'sistema-para-farmacias'));
        }
        if (sanitize_key((string)($batch['signature_status'] ?? '')) !== 'verified') {
            $error = new WP_Error('spf_batch_validation_unsigned', __('Firma y verifica localmente el lote antes de validarlo.', 'sistema-para-farmacias'));
            self::store_validation($batch_id, 'validation_error', ['errors'=>[$error->get_error_message()]]);
            return $error;
        }

        $signed = self::build_signed_payload($batch_id);
        if (is_wp_error($signed)) { self::store_validation($batch_id, 'validation_error', ['errors'=>[$signed->get_error_message()]]); return $signed; }
        $stored_signed_hash = trim((string)($batch['signature_xml_sha256'] ?? ''));
        $stored_certificate_hash = trim((string)($batch['signature_certificate_sha256'] ?? ''));
        $current_signed_hash = trim((string)($signed['signed_xml_sha256'] ?? ''));
        $current_certificate_hash = trim((string)($signed['certificate_sha256'] ?? ''));
        if ($stored_signed_hash === '' || $stored_certificate_hash === '' || !hash_equals($stored_signed_hash, $current_signed_hash) || !hash_equals($stored_certificate_hash, $current_certificate_hash)) {
            $error = new WP_Error('spf_batch_signature_evidence_mismatch', __('La firma reconstruida ya no coincide con la evidencia persistida. Firma de nuevo el lote antes de validarlo.', 'sistema-para-farmacias'));
            self::store_validation($batch_id, 'validation_error', ['errors'=>[$error->get_error_message()]]);
            return $error;
        }

        $profile = self::validation_profile((string)$batch['batch_type']);
        $validator_code = sanitize_key((string)($profile['validator'] ?? ''));
        $validator = $validator_code !== '' ? SPF_Fiscal_Validator_Registry::get($validator_code) : null;
        if (!$validator) {
            $error = new WP_Error('spf_batch_validator_missing', __('No hay un validador local registrado para este lote fiscal.', 'sistema-para-farmacias'));
            self::store_validation($batch_id, 'validation_error', ['errors'=>[$error->get_error_message()]]); return $error;
        }
        $integrity = SPF_Fiscal_Batch_Integrity::reconstruct($batch_id);
        if (is_wp_error($integrity)) { return $integrity; }
        $context = $integrity['context'];
        $context['identifier'] = $integrity['identifier'];
        $resources = self::validation_resources((string)$batch['batch_type'], $context);
        if ($resources['xsd_path'] === '' && $resources['xsl_path'] === '') {
            $error = new WP_Error('spf_batch_validation_resources', __('Configura los recursos XSD/XSL externos específicos del lote fiscal.', 'sistema-para-farmacias'));
            self::store_validation($batch_id, 'resources_missing', ['errors'=>[$error->get_error_message()]]); return $error;
        }
        $result = $validator->validate((string)$signed['xml'], $resources, $context);
        if (is_wp_error($result)) { self::store_validation($batch_id, 'validation_error', ['errors'=>[$result->get_error_message()]]); return $result; }
        $checks = (array)($result['checks'] ?? []);
        $configured = 0; $valid = 0;
        foreach ($checks as $check) { if ($check !== 'not_configured') { $configured++; } if ($check === 'valid') { $valid++; } }
        $status = !empty($result['ok']) ? (($configured >= 2 && $valid >= 2) ? 'valid_local' : 'partial') : 'validation_error';
        $result['signed_xml_sha256'] = $current_signed_hash;
        self::store_validation($batch_id, $status, $result);
        $result['validation_status'] = $status;
        return $result;
    }

    private static function store_signature_error($batch_id, $message) {
        global $wpdb;
        $table = SPF_Database::table('fiscal_batches');
        $batch = SPF_Fiscal_Batch_Repository::get($batch_id);
        if (!$batch || trim((string)($batch['remote_ticket'] ?? '')) !== '') { return; }
        $metadata = self::metadata($batch['response_body'] ?? '');
        $metadata['local_signature'] = ['status'=>'signature_error','message'=>sanitize_text_field((string)$message),'verified_at'=>current_time('mysql')];
        unset($metadata['local_validation'], $metadata['fiscal_beta_batch']);
        $wpdb->update($table, [
            'signature_status'=>'signature_error','signature_method'=>'','signature_certificate_sha256'=>'','signature_xml_sha256'=>'',
            'validation_status'=>'not_validated','validation_xml_sha256'=>'','transport_status'=>'not_prepared','beta_status'=>'not_tested',
            'response_body'=>wp_json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),'updated_at'=>current_time('mysql'),
        ], ['id'=>absint($batch_id)]);
    }

    private static function store_validation($batch_id, $status, array $result) {
        global $wpdb;
        $table = SPF_Database::table('fiscal_batches');
        $batch = SPF_Fiscal_Batch_Repository::get($batch_id);
        if (!$batch || trim((string)($batch['remote_ticket'] ?? '')) !== '') { return; }
        $metadata = self::metadata($batch['response_body'] ?? '');
        unset($metadata['fiscal_beta_batch']);
        $metadata['local_validation'] = [
            'status'=>sanitize_key((string)$status),
            'errors'=>array_map('sanitize_text_field',(array)($result['errors'] ?? [])),
            'warnings'=>array_map('sanitize_text_field',(array)($result['warnings'] ?? [])),
            'checks'=>array_map('sanitize_key',(array)($result['checks'] ?? [])),
            'signed_xml_sha256'=>sanitize_text_field((string)($result['signed_xml_sha256'] ?? '')),
            'validated_at'=>current_time('mysql'),
        ];
        $wpdb->update($table, [
            'validation_status'=>sanitize_key((string)$status),
            'validation_xml_sha256'=>sanitize_text_field((string)($result['signed_xml_sha256'] ?? '')),
            'transport_status'=>'not_prepared','beta_status'=>'not_tested',
            'response_body'=>wp_json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),'updated_at'=>current_time('mysql'),
        ], ['id'=>absint($batch_id)]);
    }

    private static function metadata($json) {
        $data = json_decode((string)$json, true);
        return is_array($data) ? $data : [];
    }

    private static function external_value($key) {
        if ($key === '') { return ''; }
        if (defined($key)) { return (string)constant($key); }
        $value = getenv($key);
        return $value === false ? '' : (string)$value;
    }

    public function handle_sign() {
        if (!current_user_can(SPF_Roles::INVOICES) && !current_user_can('manage_options')) { wp_die(esc_html__('No tienes permisos para firmar lotes fiscales.', 'sistema-para-farmacias')); }
        check_admin_referer('spf_fiscal_batch_sign_local');
        $result = self::sign_batch(absint($_POST['batch_id'] ?? 0));
        $notice = is_wp_error($result) ? ['type'=>'error','message'=>$result->get_error_message()] : ['type'=>'success','message'=>__('Lote reconstruido desde snapshot, firmado y verificado localmente. No se ha transmitido.', 'sistema-para-farmacias')];
        set_transient(self::NOTICE_PREFIX.get_current_user_id(), $notice, MINUTE_IN_SECONDS); wp_safe_redirect(admin_url('admin.php?page=spf-fiscal')); exit;
    }

    public function handle_validate() {
        if (!current_user_can(SPF_Roles::INVOICES) && !current_user_can('manage_options')) { wp_die(esc_html__('No tienes permisos para validar lotes fiscales.', 'sistema-para-farmacias')); }
        check_admin_referer('spf_fiscal_batch_validate_local');
        $result = self::validate_batch(absint($_POST['batch_id'] ?? 0));
        if (is_wp_error($result)) { $notice=['type'=>'error','message'=>$result->get_error_message()]; }
        elseif (($result['validation_status'] ?? '') === 'valid_local') { $notice=['type'=>'success','message'=>__('El lote firmado ha superado XSD y XSL localmente. Sigue sin transmisión remota.', 'sistema-para-farmacias')]; }
        else { $notice=['type'=>'warning','message'=>__('La validación local del lote es parcial. Revisa los recursos específicos configurados.', 'sistema-para-farmacias')]; }
        set_transient(self::NOTICE_PREFIX.get_current_user_id(), $notice, MINUTE_IN_SECONDS); wp_safe_redirect(admin_url('admin.php?page=spf-fiscal')); exit;
    }

    public static function consume_notice() { $key=self::NOTICE_PREFIX.get_current_user_id();$value=get_transient($key);delete_transient($key);return is_array($value)?$value:null; }
}
