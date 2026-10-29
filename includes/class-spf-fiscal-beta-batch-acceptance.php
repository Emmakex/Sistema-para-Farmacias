<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Beta_Batch_Acceptance {
    public function hooks() {
        add_action('admin_notices', [$this, 'render']);
    }

    public static function report($batch_id = 0) {
        global $wpdb;
        $table = SPF_Database::table('fiscal_batches');
        $batch_id = absint($batch_id);
        if ($batch_id < 1) {
            $batch_id = (int) $wpdb->get_var(
                "SELECT id FROM $table WHERE beta_status IS NOT NULL AND beta_status<>'' AND beta_status<>'not_tested' ORDER BY updated_at DESC,id DESC LIMIT 1"
            );
        }
        if ($batch_id < 1) { return null; }

        $batch = SPF_Fiscal_Batch_Repository::get($batch_id);
        if (!$batch) {
            return new WP_Error('spf_beta_batch_acceptance_missing', __('No se encontró el lote beta que se quiere verificar.', 'sistema-para-farmacias'));
        }

        $events = SPF_Fiscal_Batch_Remote_Event_Repository::latest($batch_id, 100);
        $anchor = null;
        foreach (['evidence_archived','poll_result','ticket_received','submit_intent'] as $operation) {
            foreach ((array)$events as $event) {
                if (($event['operation'] ?? '') === $operation) { $anchor = $event; break 2; }
            }
        }

        $current_evidence = '';
        if ($anchor) {
            $current_evidence = SPF_Fiscal_Batch_Remote_Event_Repository::evidence_sha256(
                $batch,
                (string)($anchor['transport'] ?? ''),
                (string)($anchor['environment'] ?? 'beta')
            );
        }

        return self::assess($batch, (array)$events, $current_evidence);
    }

    public static function assess(array $batch, array $events, $current_evidence_sha256 = '') {
        $sha = static function($value) {
            $value = strtolower(trim((string)$value));
            return preg_match('/^[a-f0-9]{64}$/', $value) ? $value : '';
        };
        $event_id = static function($event) { return (int)($event['id'] ?? 0); };
        $ticket_of = static function($event) { return trim((string)($event['ticket'] ?? '')); };

        $status = sanitize_key((string)($batch['beta_status'] ?? ''));
        $signature_hash = $sha($batch['signature_xml_sha256'] ?? '');
        $certificate_hash = $sha($batch['signature_certificate_sha256'] ?? '');
        $validation_hash = $sha($batch['validation_xml_sha256'] ?? '');
        $current_evidence_sha256 = $sha($current_evidence_sha256);

        $anchor_evidence = $current_evidence_sha256;
        if ($anchor_evidence === '') {
            foreach ($events as $event) {
                $candidate = $sha($event['evidence_sha256'] ?? '');
                if ($candidate !== '') { $anchor_evidence = $candidate; break; }
            }
        }

        $stream = [];
        foreach ($events as $event) {
            if ($sha($event['evidence_sha256'] ?? '') !== $anchor_evidence) { continue; }
            $stream[] = $event;
        }
        usort($stream, static function($a, $b) { return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0); });

        $submit = null;
        $ticket_event = null;
        $archive_event = null;
        foreach ($stream as $event) {
            $operation = sanitize_key((string)($event['operation'] ?? ''));
            if ($operation === 'submit_intent' && !$submit) { $submit = $event; }
            if ($operation === 'ticket_received' && !$ticket_event) { $ticket_event = $event; }
            if ($operation === 'evidence_archived') { $archive_event = $event; }
        }

        $archive_status = $archive_event ? sanitize_key((string)($archive_event['beta_status'] ?? '')) : '';
        $archive_ticket = $archive_event ? $ticket_of($archive_event) : '';
        $final_statuses = ['beta_batch_accepted','beta_batch_accepted_with_observation','beta_batch_rejected'];
        $final_poll = null;
        if ($archive_event) {
            foreach ($stream as $event) {
                if (sanitize_key((string)($event['operation'] ?? '')) !== 'poll_result') { continue; }
                if ($event_id($event) >= $event_id($archive_event)) { continue; }
                $event_status = sanitize_key((string)($event['beta_status'] ?? ''));
                if (!in_array($event_status, $final_statuses, true)) { continue; }
                if ($archive_status !== '' && $event_status !== $archive_status) { continue; }
                if ($archive_ticket !== '' && $ticket_of($event) !== $archive_ticket) { continue; }
                $final_poll = $event;
            }
        }

        $final_poll_intent = null;
        if ($ticket_event && $final_poll) {
            $previous_poll_event = null;
            foreach ($stream as $event) {
                if ($event_id($event) <= $event_id($ticket_event) || $event_id($event) >= $event_id($final_poll)) { continue; }
                if ($ticket_of($event) !== $ticket_of($final_poll)) { continue; }
                $operation = sanitize_key((string)($event['operation'] ?? ''));
                if (!in_array($operation, ['poll_intent','poll_result','poll_error'], true)) { continue; }
                $previous_poll_event = $event;
            }
            if ($previous_poll_event && sanitize_key((string)($previous_poll_event['operation'] ?? '')) === 'poll_intent') {
                $final_poll_intent = $previous_poll_event;
            }
        }

        $metadata = $archive_event ? json_decode((string)($archive_event['metadata_body'] ?? ''), true) : [];
        $archive = is_array($metadata) ? $metadata : [];
        $stored_zip = is_array($archive['cdr_zip'] ?? null) ? $archive['cdr_zip'] : [];
        $stored_xml = is_array($archive['cdr_xml'] ?? null) ? $archive['cdr_xml'] : [];
        $stored_manifest = is_array($archive['manifest'] ?? null) ? $archive['manifest'] : [];

        $poll_zip = $final_poll ? $sha($final_poll['response_zip_sha256'] ?? '') : '';
        $poll_xml = $final_poll ? $sha($final_poll['cdr_xml_sha256'] ?? '') : '';
        $archive_zip = $archive_event ? $sha($archive_event['response_zip_sha256'] ?? '') : '';
        $archive_xml = $archive_event ? $sha($archive_event['cdr_xml_sha256'] ?? '') : '';
        $archive_records_ok = ($archive['status'] ?? '') === 'verified'
            && $sha($stored_zip['sha256'] ?? '') !== ''
            && $sha($stored_xml['sha256'] ?? '') !== ''
            && $sha($stored_manifest['sha256'] ?? '') !== ''
            && trim((string)($stored_zip['locator'] ?? '')) !== ''
            && trim((string)($stored_xml['locator'] ?? '')) !== ''
            && trim((string)($stored_manifest['locator'] ?? '')) !== ''
            && $poll_zip !== '' && hash_equals($poll_zip, $sha($stored_zip['sha256'] ?? ''))
            && $poll_xml !== '' && hash_equals($poll_xml, $sha($stored_xml['sha256'] ?? ''));

        $ticket = trim((string)($batch['remote_ticket'] ?? ''));
        $ticket_consistent = $ticket !== '' && $ticket_event && $final_poll_intent && $final_poll && $archive_event
            && hash_equals($ticket, $ticket_of($ticket_event))
            && hash_equals($ticket, $ticket_of($final_poll_intent))
            && hash_equals($ticket, $ticket_of($final_poll))
            && hash_equals($ticket, $ticket_of($archive_event));

        $ordered = $submit && $ticket_event && $final_poll_intent && $final_poll && $archive_event
            && $event_id($submit) < $event_id($ticket_event)
            && $event_id($ticket_event) < $event_id($final_poll_intent)
            && $event_id($final_poll_intent) < $event_id($final_poll)
            && $event_id($final_poll) < $event_id($archive_event);

        $submit_request = $submit ? $sha($submit['request_xml_sha256'] ?? '') : '';
        $ticket_request = $ticket_event ? $sha($ticket_event['request_xml_sha256'] ?? '') : '';
        $request_zip = $ticket_event ? $sha($ticket_event['request_zip_sha256'] ?? '') : '';

        $projection_body = json_decode((string)($batch['response_body'] ?? ''), true);
        $projection_body = is_array($projection_body) ? $projection_body : [];
        $projection = is_array($projection_body['fiscal_beta_batch'] ?? null) ? $projection_body['fiscal_beta_batch'] : [];
        $projection_status = sanitize_key((string)($projection['status'] ?? ''));
        $projection_ticket = trim((string)($projection['ticket'] ?? ''));
        $projection_zip = $sha($projection['response_zip_sha256'] ?? '');
        $projection_xml = $sha($projection['cdr_xml_sha256'] ?? '');
        $projection_ok = $archive_status !== '' && $status !== '' && $projection_status !== ''
            && hash_equals($status, $archive_status)
            && hash_equals($status, $projection_status)
            && $ticket !== '' && $projection_ticket !== '' && hash_equals($ticket, $projection_ticket)
            && $archive_zip !== '' && $projection_zip !== '' && hash_equals($archive_zip, $projection_zip)
            && $archive_xml !== '' && $projection_xml !== '' && hash_equals($archive_xml, $projection_xml);

        $accepted = in_array($status, ['beta_batch_accepted','beta_batch_accepted_with_observation'], true);
        $checks = [
            'remote_outcome'=>['ready'=>$accepted,'label'=>'Resultado beta del lote aceptado'],
            'signature'=>['ready'=>sanitize_key((string)($batch['signature_status'] ?? '')) === 'verified' && $signature_hash !== '' && $certificate_hash !== '','label'=>'Firma exacta verificable'],
            'validation'=>['ready'=>sanitize_key((string)($batch['validation_status'] ?? '')) === 'valid_local' && $validation_hash !== '' && $signature_hash !== '' && hash_equals($signature_hash, $validation_hash),'label'=>'Validación del XML firmado exacto'],
            'fingerprint'=>['ready'=>$anchor_evidence !== '' && $current_evidence_sha256 !== '' && hash_equals($anchor_evidence, $current_evidence_sha256),'label'=>'Fingerprint actual coincide'],
            'submit_intent'=>['ready'=>(bool)$submit,'label'=>'submit_intent presente'],
            'ticket_received'=>['ready'=>(bool)$ticket_event,'label'=>'ticket_received presente'],
            'poll_intent'=>['ready'=>(bool)$final_poll_intent,'label'=>'poll_intent final presente'],
            'poll_result'=>['ready'=>(bool)$final_poll,'label'=>'poll_result final presente'],
            'evidence_archived'=>['ready'=>(bool)$archive_event,'label'=>'evidence_archived presente'],
            'event_order'=>['ready'=>(bool)$ordered,'label'=>'Orden asíncrono correcto'],
            'ticket_consistency'=>['ready'=>(bool)$ticket_consistent,'label'=>'Ticket consistente en toda la cadena'],
            'request_hash'=>['ready'=>$signature_hash !== '' && $submit_request !== '' && $ticket_request !== '' && hash_equals($signature_hash,$submit_request) && hash_equals($signature_hash,$ticket_request),'label'=>'Hash de request ligado al XML firmado'],
            'request_zip'=>['ready'=>$request_zip !== '','label'=>'ZIP de envío identificado por SHA-256'],
            'cdr_hashes'=>['ready'=>$poll_zip !== '' && $poll_xml !== '' && $archive_zip !== '' && $archive_xml !== '' && hash_equals($poll_zip,$archive_zip) && hash_equals($poll_xml,$archive_xml),'label'=>'Hashes CDR coherentes'],
            'archive_manifest'=>['ready'=>$archive_records_ok,'label'=>'ZIP/XML/manifest archivados y verificados'],
            'projection'=>['ready'=>$projection_ok,'label'=>'Proyección beta alineada con ledger'],
            'production_isolated'=>['ready'=>sanitize_key((string)($batch['fiscal_status'] ?? '')) === 'not_sent','label'=>'Producción permanece sin envío'],
        ];

        $failed = [];
        foreach ($checks as $key => $check) { if (empty($check['ready'])) { $failed[] = $key; } }

        return [
            'batch_id'=>(int)($batch['id'] ?? 0),
            'reference'=>sanitize_text_field((string)($batch['identifier'] ?? '')),
            'certified'=>!$failed,
            'failed'=>$failed,
            'checks'=>$checks,
            'beta_status'=>$status,
            'fiscal_status'=>sanitize_key((string)($batch['fiscal_status'] ?? '')),
            'ticket'=>$ticket,
            'evidence_sha256'=>$anchor_evidence,
            'remote_code'=>$final_poll ? sanitize_text_field((string)($final_poll['remote_code'] ?? '')) : '',
            'remote_message'=>$final_poll ? sanitize_text_field((string)($final_poll['remote_message'] ?? '')) : '',
            'archived_at'=>$archive_event ? sanitize_text_field((string)($archive_event['created_at'] ?? '')) : '',
        ];
    }

    public function render() {
        if (!$this->is_fiscal_screen()) { return; }
        if (!current_user_can(SPF_Roles::INVOICES) && !current_user_can('manage_options')) { return; }
        $requested = isset($_GET['spf_beta_batch_acceptance']) ? absint($_GET['spf_beta_batch_acceptance']) : 0;
        $report = self::report($requested);
        if ($report === null) { return; }
        if (is_wp_error($report)) {
            echo '<div class="notice notice-warning"><p>'.esc_html($report->get_error_message()).'</p></div>';
            return;
        }
        $certified = !empty($report['certified']);
        ?>
        <div class="notice <?php echo esc_attr($certified ? 'notice-success' : 'notice-warning'); ?> spf-beta-batch-acceptance">
            <h2><?php esc_html_e('Aceptación beta real · Lote fiscal', 'sistema-para-farmacias'); ?></h2>
            <p>
                <strong><?php echo esc_html((string)$report['reference']); ?></strong>
                &nbsp;·&nbsp;
                <strong><?php echo esc_html($certified ? __('CERTIFICADA', 'sistema-para-farmacias') : __('BLOQUEADA', 'sistema-para-farmacias')); ?></strong>
            </p>
            <p><?php esc_html_e('Verificación post-prueba del ticket, polling, ledger y archivo de evidencia. No transmite, no modifica estados y no expone XML, ZIP, certificados ni rutas privadas.', 'sistema-para-farmacias'); ?></p>
            <details <?php echo $certified ? '' : 'open'; ?>>
                <summary><strong><?php esc_html_e('Ver comprobaciones de aceptación', 'sistema-para-farmacias'); ?></strong></summary>
                <div style="overflow:auto;max-width:100%;margin:10px 0 14px">
                    <table class="widefat striped" style="min-width:620px">
                        <thead><tr><th><?php esc_html_e('Comprobación', 'sistema-para-farmacias'); ?></th><th><?php esc_html_e('Estado', 'sistema-para-farmacias'); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ((array)$report['checks'] as $check): ?>
                            <tr><td><?php echo esc_html((string)$check['label']); ?></td><td><?php echo esc_html(!empty($check['ready']) ? __('OK', 'sistema-para-farmacias') : __('BLOQUEO', 'sistema-para-farmacias')); ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
            <?php if ($report['ticket'] !== ''): ?><p><strong><?php esc_html_e('Ticket beta:', 'sistema-para-farmacias'); ?></strong> <?php echo esc_html((string)$report['ticket']); ?></p><?php endif; ?>
            <?php if ($report['remote_code'] !== '' || $report['remote_message'] !== ''): ?>
                <p><strong><?php esc_html_e('Respuesta remota:', 'sistema-para-farmacias'); ?></strong> <?php echo esc_html(trim($report['remote_code'].' '.$report['remote_message'])); ?></p>
            <?php endif; ?>
            <?php if ($report['archived_at'] !== ''): ?><p><small><?php echo esc_html(sprintf(__('Evidencia archivada: %s', 'sistema-para-farmacias'), $report['archived_at'])); ?></small></p><?php endif; ?>
        </div>
        <?php
    }

    private function is_fiscal_screen() {
        if (!is_admin()) { return false; }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return $page === 'spf-fiscal';
    }
}
