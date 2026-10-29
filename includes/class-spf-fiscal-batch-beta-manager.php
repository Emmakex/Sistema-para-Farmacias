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
        add_action('admin_post_spf_fiscal_batch_beta_recover_ticket', [$this, 'handle_recover_ticket']);
    }

    public static function statuses() {
        return [
            'not_tested'=>__('Sin prueba beta','sistema-para-farmacias'),
            'beta_batch_ticketed'=>__('Ticket beta recibido','sistema-para-farmacias'),
            'beta_batch_processing'=>__('Beta en proceso','sistema-para-farmacias'),
            'beta_batch_accepted'=>__('Lote aceptado en beta','sistema-para-farmacias'),
            'beta_batch_accepted_with_observation'=>__('Lote beta con observaciones','sistema-para-farmacias'),
            'beta_batch_rejected'=>__('Lote rechazado en beta','sistema-para-farmacias'),
            'beta_batch_evidence_error'=>__('Error de conservación de evidencia','sistema-para-farmacias'),
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

    public static function recoverable_ticket($batch_id) {
        $batch = SPF_Fiscal_Batch_Repository::get($batch_id);
        if (!$batch || trim((string)($batch['remote_ticket'] ?? '')) !== '') { return null; }
        $transport = self::transport((string)($batch['batch_type'] ?? ''));
        if (!$transport) { return null; }
        $evidence = SPF_Fiscal_Batch_Remote_Event_Repository::evidence_sha256($batch, $transport->code(), $transport->environment());
        $event = SPF_Fiscal_Batch_Remote_Event_Repository::latest_ticket($batch_id, $evidence);
        return is_array($event) ? $event : null;
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
            $batch = SPF_Fiscal_Batch_Repository::get($batch_id);
            $package = self::build_submit_package($batch_id);
            if (is_wp_error($package)) { return $package; }
            $inspection = $transport->inspect_batch($package);
            if (is_wp_error($inspection)) { return $inspection; }
            if (empty($inspection['ok'])) { return new WP_Error('spf_beta_batch_inspection', implode(' ', (array)($inspection['errors'] ?? []))); }

            $evidence = SPF_Fiscal_Batch_Remote_Event_Repository::evidence_sha256($batch, $transport->code(), $transport->environment());
            $existing_ticket = SPF_Fiscal_Batch_Remote_Event_Repository::latest_ticket($batch_id, $evidence);
            if (is_array($existing_ticket)) {
                return new WP_Error('spf_beta_batch_recovery_required', __('Ya existe un ticket remoto registrado para esta misma evidencia. Recupéralo en lugar de reenviar el lote.', 'sistema-para-farmacias'));
            }
            $intent = SPF_Fiscal_Batch_Remote_Event_Repository::record($batch_id, 'submit_intent', $evidence, [
                'transport'=>$transport->code(),
                'environment'=>$transport->environment(),
                'request_xml_sha256'=>$package['xml_sha256'],
                'beta_status'=>'not_tested',
                'metadata'=>['identifier'=>$package['identifier'],'reference_date'=>$package['reference_date']],
            ]);
            if (is_wp_error($intent)) { return $intent; }

            set_transient(self::SUBMIT_COOLDOWN, 1, 15);
            $result = $transport->submit_batch($package);
            if (is_wp_error($result)) {
                SPF_Fiscal_Batch_Remote_Event_Repository::record($batch_id, 'submit_error', $evidence, [
                    'transport'=>$transport->code(),
                    'environment'=>$transport->environment(),
                    'request_xml_sha256'=>$package['xml_sha256'],
                    'beta_status'=>'beta_batch_transport_error',
                    'remote_message'=>$result->get_error_message(),
                ]);
                self::store_result($batch_id, 'beta_batch_transport_error', ['error'=>$result->get_error_message()], false);
                return $result;
            }
            $status = self::normalize_status($result['beta_status'] ?? '');
            $ticket = sanitize_text_field((string)($result['ticket'] ?? ''));
            if ($ticket === '') {
                $error = new WP_Error('spf_beta_batch_ticket_empty', __('El transporte beta no devolvió un ticket persistible.', 'sistema-para-farmacias'));
                SPF_Fiscal_Batch_Remote_Event_Repository::record($batch_id, 'submit_error', $evidence, [
                    'transport'=>$transport->code(),
                    'environment'=>$transport->environment(),
                    'request_xml_sha256'=>$package['xml_sha256'],
                    'request_zip_sha256'=>$result['request_zip_sha256'] ?? '',
                    'beta_status'=>'beta_batch_transport_error',
                    'remote_message'=>$error->get_error_message(),
                ]);
                self::store_result($batch_id, 'beta_batch_transport_error', ['error'=>$error->get_error_message()], false);
                return $error;
            }

            $ticket_event = SPF_Fiscal_Batch_Remote_Event_Repository::record($batch_id, 'ticket_received', $evidence, [
                'transport'=>$transport->code(),
                'environment'=>$transport->environment(),
                'ticket'=>$ticket,
                'beta_status'=>$status,
                'request_xml_sha256'=>$package['xml_sha256'],
                'request_zip_sha256'=>$result['request_zip_sha256'] ?? '',
                'metadata'=>['zip_name'=>sanitize_file_name((string)($result['zip_name'] ?? ''))],
            ]);
            if (is_wp_error($ticket_event)) {
                $stored = self::store_result($batch_id, $status, $result, true);
                if ($stored) {
                    return new WP_Error('spf_beta_batch_ledger_store', __('El ticket quedó asociado al lote, pero falló el registro append-only de evidencia. No reenvíes este lote y revisa la base de datos.', 'sistema-para-farmacias'));
                }
                return new WP_Error('spf_beta_batch_ticket_unrecoverable', sprintf(__('Se recibió el ticket beta %s pero falló tanto el ledger como la asociación al lote. No reenvíes el lote hasta intervención técnica.', 'sistema-para-farmacias'), $ticket));
            }
            if (!self::store_result($batch_id, $status, $result, true)) {
                return new WP_Error('spf_beta_batch_ticket_store', __('El ticket remoto quedó preservado en el ledger, pero no pudo asociarse al lote. Usa la acción de recuperación y no reenvíes.', 'sistema-para-farmacias'));
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
            $evidence = SPF_Fiscal_Batch_Remote_Event_Repository::evidence_sha256($batch, $transport->code(), $transport->environment());
            $intent = SPF_Fiscal_Batch_Remote_Event_Repository::record($batch_id, 'poll_intent', $evidence, [
                'transport'=>$transport->code(),
                'environment'=>$transport->environment(),
                'ticket'=>$ticket,
                'beta_status'=>sanitize_key((string)($batch['beta_status'] ?? '')),
            ]);
            if (is_wp_error($intent)) { return $intent; }

            set_transient(self::POLL_COOLDOWN, 1, 5);
            $result = $transport->poll_batch($package);
            if (is_wp_error($result)) {
                SPF_Fiscal_Batch_Remote_Event_Repository::record($batch_id, 'poll_error', $evidence, [
                    'transport'=>$transport->code(),
                    'environment'=>$transport->environment(),
                    'ticket'=>$ticket,
                    'beta_status'=>'beta_batch_transport_error',
                    'remote_message'=>$result->get_error_message(),
                ]);
                self::store_result($batch_id, 'beta_batch_transport_error', ['error'=>$result->get_error_message(),'ticket'=>$ticket], false);
                return $result;
            }

            $status = self::normalize_status($result['beta_status'] ?? '');
            $poll_event = SPF_Fiscal_Batch_Remote_Event_Repository::record($batch_id, 'poll_result', $evidence, [
                'transport'=>$transport->code(),
                'environment'=>$transport->environment(),
                'ticket'=>$ticket,
                'beta_status'=>$status,
                'status_code'=>$result['status_code'] ?? '',
                'response_zip_sha256'=>$result['response_zip_sha256'] ?? '',
                'cdr_xml_sha256'=>$result['cdr_xml_sha256'] ?? '',
                'remote_code'=>$result['remote_code'] ?? '',
                'remote_message'=>$result['remote_message'] ?? '',
                'metadata'=>['notes'=>array_values((array)($result['notes'] ?? []))],
            ]);
            if (is_wp_error($poll_event)) {
                return new WP_Error('spf_beta_batch_poll_ledger', __('La consulta remota respondió, pero no pudo preservarse en el ledger append-only. El lote no se actualizará con ese resultado.', 'sistema-para-farmacias'));
            }

            if (self::is_final_status($status)) {
                $archive = SPF_Fiscal_Evidence_Manager::archive_batch_cdr($batch_id, $result);
                if (is_wp_error($archive)) {
                    SPF_Fiscal_Batch_Remote_Event_Repository::record($batch_id, 'evidence_error', $evidence, [
                        'transport'=>$transport->code(),
                        'environment'=>$transport->environment(),
                        'ticket'=>$ticket,
                        'beta_status'=>'beta_batch_evidence_error',
                        'status_code'=>$result['status_code'] ?? '',
                        'response_zip_sha256'=>$result['response_zip_sha256'] ?? '',
                        'cdr_xml_sha256'=>$result['cdr_xml_sha256'] ?? '',
                        'remote_code'=>$result['remote_code'] ?? '',
                        'remote_message'=>$result['remote_message'] ?? '',
                        'metadata'=>[
                            'remote_outcome_status'=>$status,
                            'evidence_error'=>$archive->get_error_message(),
                        ],
                    ]);
                    $failed = $result;
                    $failed['remote_outcome_status'] = $status;
                    $failed['evidence_error'] = $archive->get_error_message();
                    self::store_result($batch_id, 'beta_batch_evidence_error', $failed, false);
                    return new WP_Error('spf_beta_batch_evidence_archive', sprintf(__('La autoridad beta respondió, pero la CDR no pudo conservarse de forma verificable: %s', 'sistema-para-farmacias'), $archive->get_error_message()));
                }
                $archive_metadata = self::sanitize_archive($archive);
                $archive_event = SPF_Fiscal_Batch_Remote_Event_Repository::record($batch_id, 'evidence_archived', $evidence, [
                    'transport'=>$transport->code(),
                    'environment'=>$transport->environment(),
                    'ticket'=>$ticket,
                    'beta_status'=>$status,
                    'status_code'=>$result['status_code'] ?? '',
                    'response_zip_sha256'=>$result['response_zip_sha256'] ?? '',
                    'cdr_xml_sha256'=>$result['cdr_xml_sha256'] ?? '',
                    'remote_code'=>$result['remote_code'] ?? '',
                    'remote_message'=>$result['remote_message'] ?? '',
                    'metadata'=>$archive_metadata,
                ]);
                if (is_wp_error($archive_event)) {
                    $failed = $result;
                    $failed['remote_outcome_status'] = $status;
                    $failed['evidence_archive'] = $archive;
                    $failed['evidence_error'] = $archive_event->get_error_message();
                    self::store_result($batch_id, 'beta_batch_evidence_error', $failed, false);
                    return new WP_Error('spf_beta_batch_evidence_ledger', __('La CDR fue archivada, pero no pudo registrarse la evidencia append-only del archivo. El resultado beta no se marcará como final.', 'sistema-para-farmacias'));
                }
                $result['evidence_archive'] = $archive;
            }

            if (!self::store_result($batch_id, $status, $result, false)) {
                return new WP_Error('spf_beta_batch_status_store', __('La consulta beta quedó preservada en el ledger, pero no pudo reflejarse en el lote principal.', 'sistema-para-farmacias'));
            }
            return $result;
        } finally {
            self::release_lock();
        }
    }

    public static function recover_ticket($batch_id) {
        if (!self::acquire_lock()) {
            return new WP_Error('spf_beta_batch_busy', __('Ya hay una operación beta de lote en curso en esta instalación.', 'sistema-para-farmacias'));
        }
        try {
            $batch = SPF_Fiscal_Batch_Repository::get($batch_id);
            if (!$batch) { return new WP_Error('spf_beta_batch_missing', __('No se encontró el lote fiscal.', 'sistema-para-farmacias')); }
            if (trim((string)($batch['remote_ticket'] ?? '')) !== '') {
                return new WP_Error('spf_beta_batch_ticket_exists', __('El lote ya tiene un ticket asociado y no necesita recuperación.', 'sistema-para-farmacias'));
            }
            $transport = self::transport((string)($batch['batch_type'] ?? ''));
            if (!$transport) { return new WP_Error('spf_beta_batch_transport_missing', __('No existe el transporte asociado a este lote.', 'sistema-para-farmacias')); }
            $evidence = SPF_Fiscal_Batch_Remote_Event_Repository::evidence_sha256($batch, $transport->code(), $transport->environment());
            $event = SPF_Fiscal_Batch_Remote_Event_Repository::latest_ticket($batch_id, $evidence);
            if (!$event) { return new WP_Error('spf_beta_batch_no_recoverable_ticket', __('No existe un ticket recuperable que coincida con la evidencia criptográfica actual del lote.', 'sistema-para-farmacias')); }
            $ticket = sanitize_text_field((string)($event['ticket'] ?? ''));
            if ($ticket === '') { return new WP_Error('spf_beta_batch_no_recoverable_ticket', __('El evento remoto no contiene un ticket recuperable.', 'sistema-para-farmacias')); }
            $result = [
                'transport'=>sanitize_key((string)($event['transport'] ?? $transport->code())),
                'environment'=>sanitize_key((string)($event['environment'] ?? $transport->environment())),
                'ticket'=>$ticket,
                'beta_status'=>sanitize_key((string)($event['beta_status'] ?? 'beta_batch_ticketed')),
                'request_zip_sha256'=>sanitize_text_field((string)($event['request_zip_sha256'] ?? '')),
            ];
            $status = self::normalize_status($result['beta_status']);
            if (!self::store_result($batch_id, $status, $result, true)) {
                return new WP_Error('spf_beta_batch_recovery_store', __('No se pudo asociar al lote el ticket preservado en el ledger.', 'sistema-para-farmacias'));
            }
            SPF_Fiscal_Batch_Remote_Event_Repository::record($batch_id, 'ticket_recovered', $evidence, [
                'transport'=>$result['transport'],
                'environment'=>$result['environment'],
                'ticket'=>$ticket,
                'beta_status'=>$status,
                'request_zip_sha256'=>$result['request_zip_sha256'],
                'metadata'=>['source_event_id'=>(int)($event['id'] ?? 0)],
            ]);
            return ['ticket'=>$ticket,'beta_status'=>$status];
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

    private static function is_final_status($status) {
        return in_array(sanitize_key((string)$status), [
            'beta_batch_accepted',
            'beta_batch_accepted_with_observation',
            'beta_batch_rejected',
        ], true);
    }

    private static function sanitize_archive(array $archive) {
        $clean = [
            'store'=>sanitize_key((string)($archive['store'] ?? '')),
            'status'=>sanitize_key((string)($archive['status'] ?? '')),
            'archived_at'=>sanitize_text_field((string)($archive['archived_at'] ?? '')),
        ];
        foreach (['cdr_zip','cdr_xml','manifest'] as $key) {
            $record = is_array($archive[$key] ?? null) ? $archive[$key] : [];
            $clean[$key] = [
                'locator'=>sanitize_text_field((string)($record['locator'] ?? '')),
                'sha256'=>sanitize_text_field((string)($record['sha256'] ?? '')),
                'bytes'=>(int)($record['bytes'] ?? 0),
            ];
        }
        return $clean;
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
        $archive = is_array($result['evidence_archive'] ?? null)
            ? self::sanitize_archive($result['evidence_archive'])
            : (is_array($previous['evidence_archive'] ?? null) ? $previous['evidence_archive'] : []);
        $evidence = [
            'status'=>sanitize_key((string)$status),
            'remote_outcome_status'=>sanitize_key((string)($result['remote_outcome_status'] ?? ($previous['remote_outcome_status'] ?? ''))),
            'transport'=>sanitize_key((string)($result['transport'] ?? ($previous['transport'] ?? ''))),
            'environment'=>'beta',
            'ticket'=>$ticket,
            'status_code'=>sanitize_text_field((string)($result['status_code'] ?? ($previous['status_code'] ?? ''))),
            'remote_code'=>sanitize_text_field((string)($result['remote_code'] ?? ($previous['remote_code'] ?? ''))),
            'remote_message'=>sanitize_text_field((string)($result['remote_message'] ?? ($result['error'] ?? ($previous['remote_message'] ?? '')))),
            'notes'=>array_map('sanitize_text_field',(array)($result['notes'] ?? ($previous['notes'] ?? []))),
            'request_zip_sha256'=>sanitize_text_field((string)($result['request_zip_sha256'] ?? ($previous['request_zip_sha256'] ?? ''))),
            'response_zip_sha256'=>sanitize_text_field((string)($result['response_zip_sha256'] ?? ($previous['response_zip_sha256'] ?? ''))),
            'cdr_xml_sha256'=>sanitize_text_field((string)($result['cdr_xml_sha256'] ?? ($previous['cdr_xml_sha256'] ?? ''))),
            'evidence_error'=>sanitize_text_field((string)($result['evidence_error'] ?? '')),
            'evidence_archive'=>$archive,
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

    public function handle_recover_ticket() {
        if (!current_user_can(SPF_Roles::INVOICES) && !current_user_can('manage_options')) { wp_die(esc_html__('No tienes permisos para recuperar tickets beta.', 'sistema-para-farmacias')); }
        check_admin_referer('spf_fiscal_batch_beta_recover_ticket');
        $result = self::recover_ticket(absint($_POST['batch_id'] ?? 0));
        if (is_wp_error($result)) { $notice=['type'=>'error','message'=>$result->get_error_message()]; }
        else { $notice=['type'=>'success','message'=>sprintf(__('Ticket beta recuperado desde el ledger append-only: %s.','sistema-para-farmacias'),sanitize_text_field((string)($result['ticket']??'')))]; }
        set_transient(self::NOTICE_PREFIX.get_current_user_id(),$notice,MINUTE_IN_SECONDS); wp_safe_redirect(admin_url('admin.php?page=spf-fiscal')); exit;
    }

    public static function consume_notice() {
        $key=self::NOTICE_PREFIX.get_current_user_id(); $value=get_transient($key); delete_transient($key); return is_array($value)?$value:null;
    }
}
