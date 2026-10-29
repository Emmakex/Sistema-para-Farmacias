<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Batch_Beta_Diagnostics {
    const NOTICE_PREFIX = 'spf_batch_beta_diagnostics_notice_';

    public function hooks() {
        add_action('admin_post_spf_fiscal_batch_beta_diagnostics', [$this, 'handle_diagnostics']);
        add_action('admin_post_spf_fiscal_batch_beta_evidence_export', [$this, 'handle_export']);
    }

    public static function inspect($batch_id) {
        $batch_id = absint($batch_id);
        $batch = SPF_Fiscal_Batch_Repository::get($batch_id);
        if (!$batch) {
            return new WP_Error('spf_beta_diagnostics_batch_missing', __('No se encontró el lote fiscal.', 'sistema-para-farmacias'));
        }

        $checks = [];
        $add = static function($code, $ok, $message, $severity = 'required') use (&$checks) {
            $checks[] = [
                'code'=>sanitize_key((string)$code),
                'ok'=>(bool)$ok,
                'severity'=>sanitize_key((string)$severity),
                'message'=>sanitize_text_field((string)$message),
            ];
        };

        $transport = SPF_Fiscal_Batch_Beta_Manager::transport((string)($batch['batch_type'] ?? ''));
        $add('transport', (bool)$transport, $transport ? sprintf(__('Transporte beta disponible: %s.', 'sistema-para-farmacias'), $transport->code()) : __('No hay transporte beta asíncrono para este tipo de lote.', 'sistema-para-farmacias'));

        $runtime = self::runtime_capabilities();
        foreach ($runtime as $code => $available) {
            $add('runtime_' . $code, $available, $available ? sprintf(__('Runtime disponible: %s.', 'sistema-para-farmacias'), $code) : sprintf(__('Falta el runtime requerido: %s.', 'sistema-para-farmacias'), $code));
        }

        $integrity = SPF_Fiscal_Batch_Integrity::reconstruct($batch_id);
        $integrity_ok = !is_wp_error($integrity);
        $add('snapshot_integrity', $integrity_ok, $integrity_ok ? __('El snapshot y el XML base se reconstruyen con integridad.', 'sistema-para-farmacias') : (is_wp_error($integrity) ? $integrity->get_error_message() : __('Falló la integridad del lote.', 'sistema-para-farmacias')));

        $signature_ok = sanitize_key((string)($batch['signature_status'] ?? '')) === 'verified'
            && preg_match('/^[a-f0-9]{64}$/i', (string)($batch['signature_xml_sha256'] ?? ''))
            && preg_match('/^[a-f0-9]{64}$/i', (string)($batch['signature_certificate_sha256'] ?? ''));
        $add('signature_evidence', $signature_ok, $signature_ok ? __('La evidencia de firma local está verificada y completa.', 'sistema-para-farmacias') : __('La firma local verificada o sus hashes de evidencia están incompletos.', 'sistema-para-farmacias'));

        $validation_ok = sanitize_key((string)($batch['validation_status'] ?? '')) === 'valid_local'
            && preg_match('/^[a-f0-9]{64}$/i', (string)($batch['validation_xml_sha256'] ?? ''))
            && hash_equals((string)($batch['signature_xml_sha256'] ?? ''), (string)($batch['validation_xml_sha256'] ?? ''));
        $add('validation_evidence', $validation_ok, $validation_ok ? __('La validación local corresponde al XML firmado exacto.', 'sistema-para-farmacias') : __('La validación local no corresponde al XML firmado exacto o no es valid_local.', 'sistema-para-farmacias'));

        $context = $integrity_ok ? (array)($integrity['context'] ?? []) : [];
        if ($integrity_ok) { $context['identifier'] = (string)($integrity['identifier'] ?? $batch['identifier'] ?? ''); }
        $resources = SPF_Fiscal_Batch_Lifecycle::validation_resources((string)($batch['batch_type'] ?? ''), $context);
        $xsd_ok = !empty($resources['xsd_path']) && is_file($resources['xsd_path']) && is_readable($resources['xsd_path']);
        $xsl_ok = !empty($resources['xsl_path']) && is_file($resources['xsl_path']) && is_readable($resources['xsl_path']);
        $add('xsd_resource', $xsd_ok, $xsd_ok ? __('El XSD específico del lote está disponible y es legible.', 'sistema-para-farmacias') : __('Falta el XSD externo específico del lote o no es legible.', 'sistema-para-farmacias'));
        $add('xsl_resource', $xsl_ok, $xsl_ok ? __('El XSL específico del lote está disponible y es legible.', 'sistema-para-farmacias') : __('Falta el XSL externo específico del lote o no es legible.', 'sistema-para-farmacias'));

        $signed = null;
        $local_revalidation_ok = false;
        if ($integrity_ok && $signature_ok && $xsd_ok && $xsl_ok) {
            $signed = SPF_Fiscal_Batch_Lifecycle::build_signed_payload($batch_id);
            if (!is_wp_error($signed)) {
                $profile = SPF_Fiscal_Batch_Lifecycle::validation_profile((string)($batch['batch_type'] ?? ''));
                $validator_code = sanitize_key((string)($profile['validator'] ?? ''));
                $validator = $validator_code !== '' ? SPF_Fiscal_Validator_Registry::get($validator_code) : null;
                if ($validator) {
                    $validation = $validator->validate((string)($signed['xml'] ?? ''), $resources, $context);
                    if (!is_wp_error($validation)) {
                        $checks_result = (array)($validation['checks'] ?? []);
                        $local_revalidation_ok = !empty($validation['ok'])
                            && ($checks_result['xsd'] ?? '') === 'valid'
                            && ($checks_result['xsl'] ?? '') === 'valid';
                    }
                }
            }
        }
        $add('current_local_revalidation', $local_revalidation_ok, $local_revalidation_ok ? __('El XML firmado reproducido vuelve a superar XSD y XSL sin modificar el lote.', 'sistema-para-farmacias') : __('No se pudo demostrar una revalidación XSD/XSL actual y side-effect free.', 'sistema-para-farmacias'));

        $ticket = trim((string)($batch['remote_ticket'] ?? ''));
        $recoverable = $ticket === '' ? SPF_Fiscal_Batch_Beta_Manager::recoverable_ticket($batch_id) : null;
        $remote_clean = $ticket === '' && !$recoverable;
        $add('remote_state_clean', $remote_clean, $remote_clean ? __('El lote no tiene ticket asociado ni recuperable; puede evaluarse para un primer envío.', 'sistema-para-farmacias') : ($ticket !== '' ? __('El lote ya tiene ticket remoto y no debe reenviarse.', 'sistema-para-farmacias') : __('Existe un ticket recuperable en el ledger; debe recuperarse y no reenviarse.', 'sistema-para-farmacias')), 'state');

        $transport_inspection_ok = false;
        $transport_errors = [];
        $transport_warnings = [];
        if ($transport && $integrity_ok && $signature_ok && $validation_ok && $local_revalidation_ok && $remote_clean) {
            $package = SPF_Fiscal_Batch_Beta_Manager::build_submit_package($batch_id);
            if (!is_wp_error($package)) {
                $inspection = $transport->inspect_batch($package);
                if (!is_wp_error($inspection)) {
                    $transport_inspection_ok = !empty($inspection['ok']);
                    $transport_errors = array_map('sanitize_text_field', (array)($inspection['errors'] ?? []));
                    $transport_warnings = array_map('sanitize_text_field', (array)($inspection['warnings'] ?? []));
                } else {
                    $transport_errors[] = $inspection->get_error_message();
                }
            } else {
                $transport_errors[] = $package->get_error_message();
            }
        }
        $add('transport_inspection', $transport_inspection_ok, $transport_inspection_ok ? __('El paquete supera la inspección local del transporte beta.', 'sistema-para-farmacias') : ($transport_errors ? implode(' ', $transport_errors) : __('No se pudo completar la inspección local del transporte beta.', 'sistema-para-farmacias')));

        $opt_in = $transport && $transport->batch_enabled();
        $add('beta_opt_in', $opt_in, $opt_in ? __('El doble opt-in del beta de lotes está habilitado.', 'sistema-para-farmacias') : __('El beta de lotes está bloqueado por configuración; no habrá transmisión remota.', 'sistema-para-farmacias'));

        $required_failures = 0;
        foreach ($checks as $check) {
            if ($check['severity'] === 'required' && !$check['ok']) { $required_failures++; }
        }
        $ready_for_submit = $required_failures === 0 && $remote_clean && $opt_in && $transport_inspection_ok;
        $ready_for_poll = $ticket !== '' && (bool)$transport && $opt_in && $integrity_ok;
        $ready_for_recovery = is_array($recoverable);

        return [
            'batch_id'=>$batch_id,
            'identifier'=>sanitize_text_field((string)($batch['identifier'] ?? '')),
            'checked_at'=>current_time('mysql'),
            'ready_for_submit'=>$ready_for_submit,
            'ready_for_poll'=>$ready_for_poll,
            'ready_for_recovery'=>$ready_for_recovery,
            'remote_ticket'=>$ticket,
            'recoverable_ticket'=>$ready_for_recovery ? sanitize_text_field((string)($recoverable['ticket'] ?? '')) : '',
            'checks'=>$checks,
            'transport_warnings'=>$transport_warnings,
            'network_performed'=>false,
        ];
    }

    public static function export_payload($batch_id) {
        $batch = SPF_Fiscal_Batch_Repository::get($batch_id);
        if (!$batch) { return new WP_Error('spf_beta_export_batch_missing', __('No se encontró el lote fiscal.', 'sistema-para-farmacias')); }
        $diagnostics = self::inspect($batch_id);
        if (is_wp_error($diagnostics)) { return $diagnostics; }

        $events = [];
        foreach ((array)SPF_Fiscal_Batch_Remote_Event_Repository::latest($batch_id, 100) as $row) {
            $events[] = [
                'event_uuid'=>sanitize_text_field((string)($row['event_uuid'] ?? '')),
                'operation'=>sanitize_key((string)($row['operation'] ?? '')),
                'transport'=>sanitize_key((string)($row['transport'] ?? '')),
                'environment'=>sanitize_key((string)($row['environment'] ?? '')),
                'evidence_sha256'=>self::sha((string)($row['evidence_sha256'] ?? '')),
                'ticket'=>sanitize_text_field((string)($row['ticket'] ?? '')),
                'beta_status'=>sanitize_key((string)($row['beta_status'] ?? '')),
                'status_code'=>sanitize_text_field((string)($row['status_code'] ?? '')),
                'request_xml_sha256'=>self::sha((string)($row['request_xml_sha256'] ?? '')),
                'request_zip_sha256'=>self::sha((string)($row['request_zip_sha256'] ?? '')),
                'response_zip_sha256'=>self::sha((string)($row['response_zip_sha256'] ?? '')),
                'cdr_xml_sha256'=>self::sha((string)($row['cdr_xml_sha256'] ?? '')),
                'remote_code'=>sanitize_text_field((string)($row['remote_code'] ?? '')),
                'remote_message'=>sanitize_text_field((string)($row['remote_message'] ?? '')),
                'created_at'=>sanitize_text_field((string)($row['created_at'] ?? '')),
            ];
        }
        $events = array_reverse($events);

        $payload = [
            'schema'=>'spf-fiscal-beta-evidence-v1',
            'plugin_version'=>defined('SPF_VERSION') ? SPF_VERSION : '',
            'generated_at'=>current_time('mysql'),
            'batch'=>[
                'id'=>(int)($batch['id'] ?? 0),
                'country_code'=>sanitize_key((string)($batch['country_code'] ?? '')),
                'batch_type'=>sanitize_key((string)($batch['batch_type'] ?? '')),
                'identifier'=>sanitize_text_field((string)($batch['identifier'] ?? '')),
                'reference_date'=>sanitize_text_field((string)($batch['reference_date'] ?? '')),
                'snapshot_sha256'=>self::sha((string)($batch['snapshot_sha256'] ?? '')),
                'xml_sha256'=>self::sha((string)($batch['xml_sha256'] ?? '')),
                'signature_xml_sha256'=>self::sha((string)($batch['signature_xml_sha256'] ?? '')),
                'signature_certificate_sha256'=>self::sha((string)($batch['signature_certificate_sha256'] ?? '')),
                'validation_xml_sha256'=>self::sha((string)($batch['validation_xml_sha256'] ?? '')),
                'signature_status'=>sanitize_key((string)($batch['signature_status'] ?? '')),
                'validation_status'=>sanitize_key((string)($batch['validation_status'] ?? '')),
                'beta_status'=>sanitize_key((string)($batch['beta_status'] ?? '')),
                'fiscal_status'=>sanitize_key((string)($batch['fiscal_status'] ?? '')),
                'remote_ticket'=>sanitize_text_field((string)($batch['remote_ticket'] ?? '')),
            ],
            'diagnostics'=>$diagnostics,
            'events'=>$events,
        ];
        $encoded = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payload['export_sha256'] = hash('sha256', (string)$encoded);
        return $payload;
    }

    public static function consume_notice() {
        $key = self::NOTICE_PREFIX . get_current_user_id();
        $value = get_transient($key);
        delete_transient($key);
        return is_array($value) ? $value : null;
    }

    public function handle_diagnostics() {
        if (!current_user_can(SPF_Roles::INVOICES) && !current_user_can('manage_options')) { wp_die(esc_html__('No tienes permisos para diagnosticar lotes fiscales.', 'sistema-para-farmacias')); }
        check_admin_referer('spf_fiscal_batch_beta_diagnostics');
        $batch_id = absint($_POST['batch_id'] ?? 0);
        $result = self::inspect($batch_id);
        if (is_wp_error($result)) {
            $notice = ['type'=>'error','message'=>$result->get_error_message(),'batch_id'=>$batch_id];
        } else {
            $failed = array_values(array_filter((array)$result['checks'], static function($check){ return empty($check['ok']); }));
            $notice = [
                'type'=>!empty($result['ready_for_submit']) ? 'success' : 'warning',
                'message'=>!empty($result['ready_for_submit']) ? __('Diagnóstico beta: lote listo para un envío controlado. No se realizó ninguna llamada de red.', 'sistema-para-farmacias') : sprintf(__('Diagnóstico beta completado sin red: %d comprobaciones no están listas.', 'sistema-para-farmacias'), count($failed)),
                'batch_id'=>$batch_id,
                'diagnostics'=>$result,
            ];
        }
        set_transient(self::NOTICE_PREFIX . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=spf-fiscal')); exit;
    }

    public function handle_export() {
        if (!current_user_can(SPF_Roles::INVOICES) && !current_user_can('manage_options')) { wp_die(esc_html__('No tienes permisos para exportar evidencia fiscal.', 'sistema-para-farmacias')); }
        check_admin_referer('spf_fiscal_batch_beta_evidence_export');
        $batch_id = absint($_POST['batch_id'] ?? 0);
        $payload = self::export_payload($batch_id);
        if (is_wp_error($payload)) { wp_die(esc_html($payload->get_error_message())); }
        $identifier = sanitize_file_name((string)($payload['batch']['identifier'] ?? ('batch-' . $batch_id)));
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="spf-beta-evidence-' . $identifier . '.json"');
        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function runtime_capabilities() {
        return [
            'dom'=>class_exists('DOMDocument'),
            'openssl'=>extension_loaded('openssl'),
            'soap'=>class_exists('SoapClient'),
            'xsl'=>class_exists('XSLTProcessor'),
            'zip'=>class_exists('ZipArchive'),
        ];
    }

    private static function sha($value) {
        $value = strtolower(trim((string)$value));
        return preg_match('/^[a-f0-9]{64}$/', $value) ? $value : '';
    }
}
