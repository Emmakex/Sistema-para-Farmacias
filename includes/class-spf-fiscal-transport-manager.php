<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Transport_Manager {
    const NOTICE_PREFIX = 'spf_transport_notice_';
    const BETA_LOCK = 'spf_fiscal_beta_lock';
    const BETA_COOLDOWN = 'spf_fiscal_beta_cooldown';

    public function hooks() {
        add_action('admin_post_spf_fiscal_transport_dry_run', [$this, 'handle_dry_run']);
        add_action('admin_post_spf_fiscal_beta_invoice', [$this, 'handle_beta_invoice']);
        add_action('admin_post_spf_fiscal_beta_invoice_recover', [$this, 'handle_beta_invoice_recover']);
    }

    public static function statuses() {
        return [
            'not_prepared'=>__('Sin preparar','sistema-para-farmacias'),
            'dry_run_validated'=>__('Dry-run validado','sistema-para-farmacias'),
            'blocked_unsigned'=>__('Bloqueado: sin firma','sistema-para-farmacias'),
            'submitted'=>__('Transmitido','sistema-para-farmacias'),
            'response_received'=>__('Respuesta recibida','sistema-para-farmacias'),
            'transport_error'=>__('Error de transporte','sistema-para-farmacias'),
        ];
    }

    public static function beta_statuses() {
        return [
            'not_tested'=>__('Sin prueba beta','sistema-para-farmacias'),
            'beta_submitted'=>__('Enviado a beta','sistema-para-farmacias'),
            'beta_accepted'=>__('Aceptado en beta','sistema-para-farmacias'),
            'beta_accepted_with_observation'=>__('Beta con observaciones','sistema-para-farmacias'),
            'beta_rejected'=>__('Rechazado en beta','sistema-para-farmacias'),
            'beta_evidence_error'=>__('Error de conservación de evidencia','sistema-para-farmacias'),
            'beta_transport_error'=>__('Error beta','sistema-para-farmacias'),
        ];
    }

    public static function beta_profile() {
        $profile = SPF_Config::get('electronic_invoicing.beta_profile', []);
        return is_array($profile) ? $profile : [];
    }

    public static function beta_transport() {
        $profile = self::beta_profile();
        $code = sanitize_key((string)($profile['transport'] ?? ''));
        return $code !== '' ? SPF_Fiscal_Transport_Registry::get($code) : null;
    }

    public static function active_transport() {
        $country = SPF_Config::country_code();
        $electronic = SPF_Config::electronic_invoicing();
        $saved = sanitize_key((string)get_option('spf_fiscal_transport_'.$country, ''));
        $code = $saved ?: sanitize_key((string)($electronic['default_transport'] ?? ''));
        if ($code !== '') {
            $transport = SPF_Fiscal_Transport_Registry::get($code);
            if ($transport) { return $transport; }
        }
        $all = SPF_Fiscal_Transport_Registry::all();
        return $all ? reset($all) : null;
    }

    public static function strategy_for_document($document_type) {
        $documents = SPF_Config::sales_documents();
        $meta = $documents[sanitize_key((string)$document_type)] ?? [];
        return sanitize_key((string)(is_array($meta) ? ($meta['fiscal_submission'] ?? 'single') : 'single'));
    }

    public static function build_package($document_id) {
        $prepared = SPF_Fiscal_Manager::build_preview($document_id);
        if (is_wp_error($prepared)) { return $prepared; }
        $context = $prepared['context'];
        $transport = self::active_transport();
        if (!$transport) { return new WP_Error('spf_transport_missing', __('No hay un transporte fiscal registrado.','sistema-para-farmacias')); }

        $xml = $prepared['xml'];
        $xml_sha256 = $prepared['sha256'];
        $signed = false;
        $signature_verified = false;
        $signature_error = '';
        $signature_certificate_sha256 = '';
        $signature = SPF_Fiscal_Signing_Manager::build_signed_payload($document_id);
        if (is_wp_error($signature)) {
            $signature_error = $signature->get_error_message();
        } else {
            $xml = (string)($signature['xml'] ?? $xml);
            $xml_sha256 = (string)($signature['signed_xml_sha256'] ?? hash('sha256',$xml));
            $signed = !empty($signature['verified']);
            $signature_verified = !empty($signature['verified']);
            $signature_certificate_sha256 = sanitize_text_field((string)($signature['certificate_sha256'] ?? ''));
        }

        $documents = SPF_Config::sales_documents();
        $document_meta = $documents[$context['document']['type']] ?? [];
        $strategy = self::strategy_for_document($context['document']['type']);
        $reference = strtoupper($context['document']['series']).'-'.(string)$context['document']['correlative'];
        return [
            'country'=>$context['country_code'],
            'adapter'=>$prepared['adapter'],
            'transport'=>$transport->code(),
            'environment'=>$transport->environment(),
            'strategy'=>$strategy,
            'document_id'=>(int)$context['document']['id'],
            'document_type'=>$context['document']['type'],
            'fiscal_type_code'=>sanitize_text_field((string)(is_array($document_meta) ? ($document_meta['fiscal_type_code'] ?? '') : '')),
            'series'=>strtoupper((string)$context['document']['series']),
            'correlative'=>(string)$context['document']['correlative'],
            'issuer_tax_id'=>sanitize_text_field((string)($context['issuer']['tax_id'] ?? '')),
            'reference'=>$reference,
            'filename'=>sanitize_file_name($context['country_code'].'-'.$reference.'.xml'),
            'xml'=>$xml,
            'xml_sha256'=>$xml_sha256,
            'signed'=>$signed,
            'signature_verified'=>$signature_verified,
            'signature_error'=>sanitize_text_field($signature_error),
            'signature_certificate_sha256'=>$signature_certificate_sha256,
            'validation_status'=>sanitize_key($context['document']['validation_status'] ?? 'not_validated'),
            'created_at'=>current_time('mysql'),
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
        $metadata = json_decode((string)$existing, true);
        $metadata = is_array($metadata) ? $metadata : [];
        $metadata['transport_dry_run'] = [
            'transport'=>$transport->code(),
            'environment'=>$transport->environment(),
            'strategy'=>$package['strategy'],
            'package_filename'=>$package['filename'],
            'xml_sha256'=>$package['xml_sha256'],
            'signed'=>!empty($package['signed']),
            'signature_verified'=>!empty($package['signature_verified']),
            'signature_error'=>$package['signature_error'],
            'validation_status'=>$package['validation_status'],
            'transmitted'=>false,
            'ok'=>!empty($result['ok']),
            'errors'=>array_values((array)($result['errors'] ?? [])),
            'warnings'=>array_values((array)($result['warnings'] ?? [])),
            'inspected_at'=>current_time('mysql'),
        ];
        $updated = $wpdb->update($table, [
            'transport_status'=>$status,
            'response_body'=>wp_json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'updated_at'=>current_time('mysql'),
        ], ['id'=>absint($document_id)]);
        if ($updated === false) { return new WP_Error('spf_transport_store', __('No se pudo guardar el resultado del dry-run.','sistema-para-farmacias')); }
        return ['package'=>$package,'result'=>$result,'transport_status'=>$status];
    }

    public static function beta_invoice($document_id) {
        if (get_transient(self::BETA_LOCK)) { return new WP_Error('spf_beta_busy', __('Ya hay una prueba beta en curso en esta instalación.','sistema-para-farmacias')); }
        if (get_transient(self::BETA_COOLDOWN)) { return new WP_Error('spf_beta_cooldown', __('Espera unos segundos antes de ejecutar otra prueba beta.','sistema-para-farmacias')); }

        $transport = self::beta_transport();
        if (!$transport) { return new WP_Error('spf_beta_transport_missing', __('El país activo no tiene un transporte beta registrado.','sistema-para-farmacias')); }
        $package = self::build_package($document_id);
        if (is_wp_error($package)) { return $package; }
        if (empty($package['signature_verified'])) { return new WP_Error('spf_beta_signature_required', __('El comprobante debe tener una firma XMLDSig verificada antes de probar el beta.','sistema-para-farmacias')); }

        $validation = SPF_Fiscal_Validation_Manager::validate_signed_payload($document_id, [
            'xml'=>$package['xml'],
            'verified'=>true,
            'signed_xml_sha256'=>$package['xml_sha256'],
        ]);
        if (is_wp_error($validation)) { return $validation; }
        if (($validation['validation_status'] ?? '') !== 'valid_local') { return new WP_Error('spf_beta_validation_required', __('El XML firmado exacto debe superar XSD y XSL antes del envío beta.','sistema-para-farmacias')); }
        $package['validation_status'] = 'valid_local';
        $package['validation_xml_sha256'] = sanitize_text_field((string)($validation['signed_xml_sha256'] ?? $package['xml_sha256']));

        $inspection = $transport->inspect($package);
        if (is_wp_error($inspection)) { return $inspection; }
        if (empty($inspection['ok'])) { return new WP_Error('spf_beta_inspection_failed', implode(' ',(array)($inspection['errors'] ?? []))); }

        $evidence = SPF_Fiscal_Document_Remote_Event_Repository::evidence_sha256($document_id,$transport->code(),$transport->environment(),$package);
        if (!preg_match('/^[a-f0-9]{64}$/',(string)$evidence)) { return new WP_Error('spf_beta_document_evidence', __('No se pudo construir la evidencia criptográfica del comprobante que se enviará.','sistema-para-farmacias')); }
        if (SPF_Fiscal_Document_Remote_Event_Repository::latest_archived($document_id,$evidence)) {
            return new WP_Error('spf_beta_invoice_recovery_required', __('Este mismo XML ya tiene una respuesta beta archivada en el ledger. Recupera la proyección local en lugar de reenviarlo.','sistema-para-farmacias'));
        }
        if (SPF_Fiscal_Document_Remote_Event_Repository::has_remote_response($document_id,$evidence)) {
            return new WP_Error('spf_beta_invoice_remote_response_exists', __('Ya existe una respuesta remota para esta misma evidencia, pero no hay archivo final recuperable. No reenvíes el comprobante; requiere revisión técnica.','sistema-para-farmacias'));
        }

        $intent = SPF_Fiscal_Document_Remote_Event_Repository::record($document_id,'submit_intent',$evidence,[
            'transport'=>$transport->code(),
            'environment'=>$transport->environment(),
            'request_xml_sha256'=>$package['xml_sha256'],
            'beta_status'=>'not_tested',
            'metadata'=>[
                'reference'=>sanitize_text_field((string)($package['reference'] ?? '')),
                'validation_xml_sha256'=>$package['validation_xml_sha256'],
                'signature_certificate_sha256'=>sanitize_text_field((string)($package['signature_certificate_sha256'] ?? '')),
            ],
        ]);
        if (is_wp_error($intent)) { return $intent; }

        set_transient(self::BETA_LOCK,1,MINUTE_IN_SECONDS);
        set_transient(self::BETA_COOLDOWN,1,15);
        try { $result = $transport->transmit($package); }
        finally { delete_transient(self::BETA_LOCK); }

        if (is_wp_error($result)) {
            SPF_Fiscal_Document_Remote_Event_Repository::record($document_id,'submit_error',$evidence,[
                'transport'=>$transport->code(),
                'environment'=>$transport->environment(),
                'request_xml_sha256'=>$package['xml_sha256'],
                'beta_status'=>'beta_transport_error',
                'remote_message'=>$result->get_error_message(),
            ]);
            self::store_beta_result($document_id,'beta_transport_error',[
                'error'=>$result->get_error_message(),
                'validation_xml_sha256'=>$package['validation_xml_sha256'],
            ]);
            return $result;
        }

        $status = sanitize_key((string)($result['beta_status'] ?? 'beta_transport_error'));
        if (!array_key_exists($status,self::beta_statuses())) { $status='beta_transport_error'; }
        $result['validation_xml_sha256']=$package['validation_xml_sha256'];

        $response_event = SPF_Fiscal_Document_Remote_Event_Repository::record($document_id,'response_received',$evidence,[
            'transport'=>$transport->code(),
            'environment'=>$transport->environment(),
            'beta_status'=>$status,
            'request_xml_sha256'=>$package['xml_sha256'],
            'request_zip_sha256'=>$result['request_zip_sha256'] ?? '',
            'response_zip_sha256'=>$result['cdr_zip_sha256'] ?? '',
            'cdr_xml_sha256'=>$result['cdr_xml_sha256'] ?? '',
            'remote_code'=>$result['remote_code'] ?? '',
            'remote_message'=>$result['remote_message'] ?? '',
            'metadata'=>['notes'=>array_values((array)($result['notes'] ?? []))],
        ]);
        if (is_wp_error($response_event)) {
            return new WP_Error('spf_beta_invoice_response_ledger', __('La autoridad beta respondió, pero no pudo registrarse la respuesta en el ledger append-only. El comprobante no se actualizará y no debe reenviarse hasta revisión técnica.','sistema-para-farmacias'));
        }

        if (self::is_final_beta_status($status)) {
            $archive = SPF_Fiscal_Evidence_Manager::archive_document_cdr($document_id,$result);
            if (is_wp_error($archive)) {
                SPF_Fiscal_Document_Remote_Event_Repository::record($document_id,'evidence_error',$evidence,[
                    'transport'=>$transport->code(),
                    'environment'=>$transport->environment(),
                    'beta_status'=>'beta_evidence_error',
                    'request_xml_sha256'=>$package['xml_sha256'],
                    'request_zip_sha256'=>$result['request_zip_sha256'] ?? '',
                    'response_zip_sha256'=>$result['cdr_zip_sha256'] ?? '',
                    'cdr_xml_sha256'=>$result['cdr_xml_sha256'] ?? '',
                    'remote_code'=>$result['remote_code'] ?? '',
                    'remote_message'=>$result['remote_message'] ?? '',
                    'metadata'=>['remote_outcome_status'=>$status,'evidence_error'=>$archive->get_error_message()],
                ]);
                $failed=$result;
                $failed['remote_outcome_status']=$status;
                $failed['evidence_error']=$archive->get_error_message();
                self::store_beta_result($document_id,'beta_evidence_error',$failed);
                return new WP_Error('spf_beta_invoice_evidence_archive', sprintf(__('La autoridad beta respondió, pero la CDR de la factura no pudo conservarse de forma verificable: %s','sistema-para-farmacias'),$archive->get_error_message()));
            }
            $result['evidence_archive']=$archive;
            $archived_event = SPF_Fiscal_Document_Remote_Event_Repository::record($document_id,'evidence_archived',$evidence,[
                'transport'=>$transport->code(),
                'environment'=>$transport->environment(),
                'beta_status'=>$status,
                'request_xml_sha256'=>$package['xml_sha256'],
                'request_zip_sha256'=>$result['request_zip_sha256'] ?? '',
                'response_zip_sha256'=>$result['cdr_zip_sha256'] ?? '',
                'cdr_xml_sha256'=>$result['cdr_xml_sha256'] ?? '',
                'remote_code'=>$result['remote_code'] ?? '',
                'remote_message'=>$result['remote_message'] ?? '',
                'metadata'=>[
                    'notes'=>array_values((array)($result['notes'] ?? [])),
                    'remote_outcome_status'=>$status,
                    'evidence_archive'=>self::sanitize_archive($archive),
                ],
            ]);
            if (is_wp_error($archived_event)) {
                $failed=$result;
                $failed['remote_outcome_status']=$status;
                $failed['evidence_error']=$archived_event->get_error_message();
                self::store_beta_result($document_id,'beta_evidence_error',$failed);
                return new WP_Error('spf_beta_invoice_evidence_ledger', __('La CDR fue archivada, pero no pudo registrarse el evento append-only de archivo. No reenvíes el comprobante; requiere revisión técnica.','sistema-para-farmacias'));
            }
        }

        if (!self::store_beta_result($document_id,$status,$result)) {
            return new WP_Error('spf_beta_invoice_projection_store', __('La respuesta y la evidencia quedaron preservadas en el ledger, pero no pudo actualizarse la proyección local. Usa la recuperación antes de cualquier nuevo envío.','sistema-para-farmacias'));
        }
        return $result;
    }

    public static function recoverable_beta_invoice($document_id) {
        $transport=self::beta_transport();
        if (!$transport) { return null; }
        $package=self::build_package($document_id);
        if (is_wp_error($package) || empty($package['signature_verified'])) { return null; }
        $validation=SPF_Fiscal_Validation_Manager::validate_signed_payload($document_id,[
            'xml'=>$package['xml'],'verified'=>true,'signed_xml_sha256'=>$package['xml_sha256'],
        ]);
        if (is_wp_error($validation) || ($validation['validation_status'] ?? '') !== 'valid_local') { return null; }
        $package['validation_xml_sha256']=sanitize_text_field((string)($validation['signed_xml_sha256'] ?? $package['xml_sha256']));
        $evidence=SPF_Fiscal_Document_Remote_Event_Repository::evidence_sha256($document_id,$transport->code(),$transport->environment(),$package);
        if (!preg_match('/^[a-f0-9]{64}$/',(string)$evidence)) { return null; }
        $event=SPF_Fiscal_Document_Remote_Event_Repository::latest_archived($document_id,$evidence);
        return is_array($event) ? ['event'=>$event,'evidence_sha256'=>$evidence,'package'=>$package] : null;
    }

    public static function recover_beta_invoice($document_id) {
        $recoverable=self::recoverable_beta_invoice($document_id);
        if (!$recoverable) { return new WP_Error('spf_beta_invoice_no_recovery', __('No existe una respuesta beta archivada recuperable para la evidencia criptográfica actual del comprobante.','sistema-para-farmacias')); }
        $event=$recoverable['event'];
        $metadata=SPF_Fiscal_Document_Remote_Event_Repository::metadata($event);
        $status=sanitize_key((string)($metadata['remote_outcome_status'] ?? $event['beta_status'] ?? ''));
        if (!self::is_final_beta_status($status)) { return new WP_Error('spf_beta_invoice_recovery_status', __('El evento archivado no contiene un estado beta final recuperable.','sistema-para-farmacias')); }
        $result=[
            'beta_status'=>$status,
            'remote_code'=>sanitize_text_field((string)($event['remote_code'] ?? '')),
            'remote_message'=>sanitize_text_field((string)($event['remote_message'] ?? '')),
            'notes'=>array_map('sanitize_text_field',(array)($metadata['notes'] ?? [])),
            'request_zip_sha256'=>sanitize_text_field((string)($event['request_zip_sha256'] ?? '')),
            'cdr_zip_sha256'=>sanitize_text_field((string)($event['response_zip_sha256'] ?? '')),
            'cdr_xml_sha256'=>sanitize_text_field((string)($event['cdr_xml_sha256'] ?? '')),
            'validation_xml_sha256'=>sanitize_text_field((string)($recoverable['package']['validation_xml_sha256'] ?? '')),
            'evidence_archive'=>is_array($metadata['evidence_archive'] ?? null) ? $metadata['evidence_archive'] : [],
        ];
        if (!self::store_beta_result($document_id,$status,$result)) { return new WP_Error('spf_beta_invoice_recovery_store', __('No se pudo reconstruir la proyección local desde el ledger remoto.','sistema-para-farmacias')); }
        SPF_Fiscal_Document_Remote_Event_Repository::record($document_id,'projection_recovered',$recoverable['evidence_sha256'],[
            'transport'=>sanitize_key((string)($event['transport'] ?? '')),
            'environment'=>sanitize_key((string)($event['environment'] ?? 'beta')),
            'beta_status'=>$status,
            'request_xml_sha256'=>$recoverable['package']['xml_sha256'],
            'request_zip_sha256'=>$event['request_zip_sha256'] ?? '',
            'response_zip_sha256'=>$event['response_zip_sha256'] ?? '',
            'cdr_xml_sha256'=>$event['cdr_xml_sha256'] ?? '',
            'remote_code'=>$event['remote_code'] ?? '',
            'remote_message'=>$event['remote_message'] ?? '',
            'metadata'=>['source_event_id'=>(int)($event['id'] ?? 0)],
        ]);
        return $result;
    }

    private static function is_final_beta_status($status) {
        return in_array(sanitize_key((string)$status),['beta_accepted','beta_accepted_with_observation','beta_rejected'],true);
    }

    private static function sanitize_archive(array $archive) {
        $clean=[
            'store'=>sanitize_key((string)($archive['store'] ?? '')),
            'status'=>sanitize_key((string)($archive['status'] ?? '')),
            'archived_at'=>sanitize_text_field((string)($archive['archived_at'] ?? '')),
        ];
        foreach (['cdr_zip','cdr_xml','manifest'] as $key) {
            $record=is_array($archive[$key] ?? null) ? $archive[$key] : [];
            $clean[$key]=[
                'locator'=>sanitize_text_field((string)($record['locator'] ?? '')),
                'sha256'=>sanitize_text_field((string)($record['sha256'] ?? '')),
                'bytes'=>(int)($record['bytes'] ?? 0),
            ];
        }
        return $clean;
    }

    private static function store_beta_result($document_id,$status,array $result) {
        global $wpdb;
        $table=SPF_Database::table('invoices');
        $existing=$wpdb->get_var($wpdb->prepare("SELECT response_body FROM $table WHERE id=%d",absint($document_id)));
        $metadata=json_decode((string)$existing,true);
        $metadata=is_array($metadata)?$metadata:[];
        $previous=is_array($metadata['fiscal_beta'] ?? null)?$metadata['fiscal_beta']:[];
        $archive=is_array($result['evidence_archive'] ?? null)
            ? self::sanitize_archive($result['evidence_archive'])
            : (is_array($previous['evidence_archive'] ?? null)?$previous['evidence_archive']:[]);
        $metadata['fiscal_beta']=[
            'status'=>sanitize_key($status),
            'remote_outcome_status'=>sanitize_key((string)($result['remote_outcome_status'] ?? ($previous['remote_outcome_status'] ?? ''))),
            'remote_code'=>sanitize_text_field((string)($result['remote_code'] ?? ($previous['remote_code'] ?? ''))),
            'remote_message'=>sanitize_text_field((string)($result['remote_message'] ?? ($result['error'] ?? ($previous['remote_message'] ?? '')))),
            'notes'=>array_map('sanitize_text_field',(array)($result['notes'] ?? ($previous['notes'] ?? []))),
            'request_zip_sha256'=>sanitize_text_field((string)($result['request_zip_sha256'] ?? ($previous['request_zip_sha256'] ?? ''))),
            'cdr_zip_sha256'=>sanitize_text_field((string)($result['cdr_zip_sha256'] ?? ($previous['cdr_zip_sha256'] ?? ''))),
            'cdr_xml_sha256'=>sanitize_text_field((string)($result['cdr_xml_sha256'] ?? ($previous['cdr_xml_sha256'] ?? ''))),
            'validation_xml_sha256'=>sanitize_text_field((string)($result['validation_xml_sha256'] ?? ($previous['validation_xml_sha256'] ?? ''))),
            'evidence_error'=>sanitize_text_field((string)($result['evidence_error'] ?? '')),
            'evidence_archive'=>$archive,
            'environment'=>'beta',
            'production'=>false,
            'tested_at'=>current_time('mysql'),
        ];
        return $wpdb->update($table,[
            'beta_status'=>sanitize_key($status),
            'response_body'=>wp_json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'updated_at'=>current_time('mysql'),
        ],['id'=>absint($document_id)]) !== false;
    }

    public function handle_dry_run() {
        if (!current_user_can(SPF_Roles::INVOICES) && !current_user_can('manage_options')) { wp_die(esc_html__('No tienes permisos para probar el transporte fiscal.','sistema-para-farmacias')); }
        check_admin_referer('spf_fiscal_transport_dry_run');
        $result=self::inspect(absint($_POST['document_id'] ?? 0));
        if (is_wp_error($result)) { $notice=['type'=>'error','message'=>$result->get_error_message()]; }
        elseif ($result['transport_status']==='dry_run_validated') { $notice=['type'=>'success','message'=>__('Dry-run completado con firma local verificada. El transporte sigue sin capacidad de transmisión real.','sistema-para-farmacias')]; }
        else { $notice=['type'=>'warning','message'=>__('Dry-run completado sin transmisión, pero el paquete continúa bloqueado porque no dispone de una firma local verificada.','sistema-para-farmacias')]; }
        set_transient(self::NOTICE_PREFIX.get_current_user_id(),$notice,MINUTE_IN_SECONDS); wp_safe_redirect(admin_url('admin.php?page=spf-fiscal')); exit;
    }

    public function handle_beta_invoice() {
        if (!current_user_can(SPF_Roles::INVOICES) && !current_user_can('manage_options')) { wp_die(esc_html__('No tienes permisos para ejecutar pruebas beta fiscales.','sistema-para-farmacias')); }
        check_admin_referer('spf_fiscal_beta_invoice');
        $result=self::beta_invoice(absint($_POST['document_id'] ?? 0));
        if (is_wp_error($result)) { $notice=['type'=>'error','message'=>$result->get_error_message()]; }
        else {
            $status=sanitize_key((string)($result['beta_status'] ?? ''));
            $notice=['type'=>$status==='beta_rejected'?'warning':'success','message'=>sprintf(__('Prueba beta fiscal finalizada: %s. Esto no es una aceptación fiscal de producción.','sistema-para-farmacias'),self::beta_statuses()[$status] ?? $status)];
        }
        set_transient(self::NOTICE_PREFIX.get_current_user_id(),$notice,MINUTE_IN_SECONDS); wp_safe_redirect(admin_url('admin.php?page=spf-fiscal')); exit;
    }

    public function handle_beta_invoice_recover() {
        if (!current_user_can(SPF_Roles::INVOICES) && !current_user_can('manage_options')) { wp_die(esc_html__('No tienes permisos para recuperar resultados beta fiscales.','sistema-para-farmacias')); }
        check_admin_referer('spf_fiscal_beta_invoice_recover');
        $result=self::recover_beta_invoice(absint($_POST['document_id'] ?? 0));
        if (is_wp_error($result)) { $notice=['type'=>'error','message'=>$result->get_error_message()]; }
        else {
            $status=sanitize_key((string)($result['beta_status'] ?? ''));
            $notice=['type'=>'success','message'=>sprintf(__('Resultado beta recuperado desde el ledger append-only: %s.','sistema-para-farmacias'),self::beta_statuses()[$status] ?? $status)];
        }
        set_transient(self::NOTICE_PREFIX.get_current_user_id(),$notice,MINUTE_IN_SECONDS); wp_safe_redirect(admin_url('admin.php?page=spf-fiscal')); exit;
    }

    public static function consume_notice() {
        $key=self::NOTICE_PREFIX.get_current_user_id(); $notice=get_transient($key); delete_transient($key); return is_array($notice)?$notice:null;
    }
}
