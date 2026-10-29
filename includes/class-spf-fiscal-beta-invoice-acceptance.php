<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Beta_Invoice_Acceptance {
    public function hooks() {
        add_action('admin_notices', [$this, 'render']);
    }

    public static function report($document_id = 0) {
        global $wpdb;
        $table = SPF_Database::table('invoices');
        $document_id = absint($document_id);
        if ($document_id < 1) {
            $document_id = (int) $wpdb->get_var(
                "SELECT id FROM $table WHERE beta_status IS NOT NULL AND beta_status<>'' AND beta_status<>'not_tested' ORDER BY updated_at DESC,id DESC LIMIT 1"
            );
        }
        if ($document_id < 1) { return null; }

        $document = $wpdb->get_row($wpdb->prepare(
            "SELECT id,sale_id,document_type,serie,correlative,signature_status,signature_xml_sha256,signature_certificate_sha256,validation_status,validation_xml_sha256,beta_status,fiscal_status,updated_at FROM $table WHERE id=%d LIMIT 1",
            $document_id
        ), ARRAY_A);
        if (!$document) { return new WP_Error('spf_beta_acceptance_document_missing', __('No se encontró el comprobante beta que se quiere verificar.', 'sistema-para-farmacias')); }

        $events = SPF_Fiscal_Document_Remote_Event_Repository::latest($document_id, 100);
        $anchor = null;
        foreach (['evidence_archived','response_received','submit_intent'] as $operation) {
            foreach ((array)$events as $event) {
                if (($event['operation'] ?? '') === $operation) { $anchor = $event; break 2; }
            }
        }
        $current_evidence = '';
        if ($anchor) {
            $current_evidence = SPF_Fiscal_Document_Remote_Event_Repository::evidence_sha256_from_row(
                $document,
                (string)($anchor['transport'] ?? ''),
                (string)($anchor['environment'] ?? 'beta')
            );
        }
        return self::assess($document, (array)$events, $current_evidence);
    }

    public static function assess(array $document, array $events, $current_evidence_sha256 = '') {
        $sha = static function($value) {
            $value = strtolower(trim((string)$value));
            return preg_match('/^[a-f0-9]{64}$/', $value) ? $value : '';
        };
        $status = strtolower(trim((string)($document['beta_status'] ?? '')));
        $signature_hash = $sha($document['signature_xml_sha256'] ?? '');
        $certificate_hash = $sha($document['signature_certificate_sha256'] ?? '');
        $validation_hash = $sha($document['validation_xml_sha256'] ?? '');
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

        $by_operation = [];
        foreach ($stream as $event) {
            $operation = strtolower(trim((string)($event['operation'] ?? '')));
            if ($operation !== '') { $by_operation[$operation] = $event; }
        }
        $intent = $by_operation['submit_intent'] ?? null;
        $response = $by_operation['response_received'] ?? null;
        $archived = $by_operation['evidence_archived'] ?? null;
        $archive_meta = $archived ? json_decode((string)($archived['metadata_body'] ?? ''), true) : [];
        $archive_meta = is_array($archive_meta) ? $archive_meta : [];
        $archive = is_array($archive_meta['evidence_archive'] ?? null) ? $archive_meta['evidence_archive'] : [];
        $remote_outcome = strtolower(trim((string)($archive_meta['remote_outcome_status'] ?? ($archived['beta_status'] ?? ''))));

        $response_request = $response ? $sha($response['request_xml_sha256'] ?? '') : '';
        $archive_request = $archived ? $sha($archived['request_xml_sha256'] ?? '') : '';
        $response_zip = $response ? $sha($response['response_zip_sha256'] ?? '') : '';
        $response_xml = $response ? $sha($response['cdr_xml_sha256'] ?? '') : '';
        $archive_zip = $archived ? $sha($archived['response_zip_sha256'] ?? '') : '';
        $archive_xml = $archived ? $sha($archived['cdr_xml_sha256'] ?? '') : '';

        $stored_zip = is_array($archive['cdr_zip'] ?? null) ? $archive['cdr_zip'] : [];
        $stored_xml = is_array($archive['cdr_xml'] ?? null) ? $archive['cdr_xml'] : [];
        $stored_manifest = is_array($archive['manifest'] ?? null) ? $archive['manifest'] : [];
        $archive_records_ok = ($archive['status'] ?? '') === 'verified'
            && $sha($stored_zip['sha256'] ?? '') !== ''
            && $sha($stored_xml['sha256'] ?? '') !== ''
            && $sha($stored_manifest['sha256'] ?? '') !== ''
            && trim((string)($stored_zip['locator'] ?? '')) !== ''
            && trim((string)($stored_xml['locator'] ?? '')) !== ''
            && trim((string)($stored_manifest['locator'] ?? '')) !== ''
            && $response_zip !== '' && hash_equals($response_zip, $sha($stored_zip['sha256'] ?? ''))
            && $response_xml !== '' && hash_equals($response_xml, $sha($stored_xml['sha256'] ?? ''));

        $ordered = $intent && $response && $archived
            && (int)($intent['id'] ?? 0) < (int)($response['id'] ?? 0)
            && (int)($response['id'] ?? 0) < (int)($archived['id'] ?? 0);

        $accepted = in_array($status, ['beta_accepted','beta_accepted_with_observation'], true);
        $checks = [
            'remote_outcome'=>['ready'=>$accepted,'label'=>'Resultado beta aceptado'],
            'signature'=>['ready'=>($document['signature_status'] ?? '') === 'verified' && $signature_hash !== '' && $certificate_hash !== '','label'=>'Firma exacta verificable'],
            'validation'=>['ready'=>($document['validation_status'] ?? '') === 'valid_local' && $validation_hash !== '' && $signature_hash !== '' && hash_equals($signature_hash, $validation_hash),'label'=>'Validación del XML firmado exacto'],
            'fingerprint'=>['ready'=>$anchor_evidence !== '' && $current_evidence_sha256 !== '' && hash_equals($anchor_evidence, $current_evidence_sha256),'label'=>'Fingerprint actual coincide'],
            'submit_intent'=>['ready'=>(bool)$intent,'label'=>'submit_intent presente'],
            'response_received'=>['ready'=>(bool)$response,'label'=>'response_received presente'],
            'evidence_archived'=>['ready'=>(bool)$archived,'label'=>'evidence_archived presente'],
            'event_order'=>['ready'=>(bool)$ordered,'label'=>'Orden remoto correcto'],
            'request_hash'=>['ready'=>$signature_hash !== '' && $response_request !== '' && $archive_request !== '' && hash_equals($signature_hash,$response_request) && hash_equals($signature_hash,$archive_request),'label'=>'Hash de request ligado al XML firmado'],
            'cdr_hashes'=>['ready'=>$response_zip !== '' && $response_xml !== '' && $archive_zip !== '' && $archive_xml !== '' && hash_equals($response_zip,$archive_zip) && hash_equals($response_xml,$archive_xml),'label'=>'Hashes CDR coherentes'],
            'archive_manifest'=>['ready'=>$archive_records_ok,'label'=>'ZIP/XML/manifest archivados y verificados'],
            'projection'=>['ready'=>$remote_outcome !== '' && $status !== '' && hash_equals($status,$remote_outcome),'label'=>'Proyección beta alineada con ledger'],
            'production_isolated'=>['ready'=>($document['fiscal_status'] ?? '') === 'not_sent','label'=>'Producción permanece sin envío'],
        ];
        $failed = [];
        foreach ($checks as $key => $check) { if (empty($check['ready'])) { $failed[] = $key; } }

        return [
            'document_id'=>(int)($document['id'] ?? 0),
            'reference'=>strtoupper(trim((string)($document['serie'] ?? ''))).'-'.trim((string)($document['correlative'] ?? '')),
            'certified'=>!$failed,
            'failed'=>$failed,
            'checks'=>$checks,
            'beta_status'=>$status,
            'fiscal_status'=>strtolower(trim((string)($document['fiscal_status'] ?? ''))),
            'evidence_sha256'=>$anchor_evidence,
            'remote_code'=>$response ? trim((string)($response['remote_code'] ?? '')) : '',
            'remote_message'=>$response ? trim((string)($response['remote_message'] ?? '')) : '',
            'archived_at'=>$archived ? trim((string)($archived['created_at'] ?? '')) : '',
        ];
    }

    public function render() {
        if (!$this->is_fiscal_screen()) { return; }
        if (!current_user_can(SPF_Roles::INVOICES) && !current_user_can('manage_options')) { return; }
        $requested = isset($_GET['spf_beta_acceptance_document']) ? absint($_GET['spf_beta_acceptance_document']) : 0;
        $report = self::report($requested);
        if ($report === null) { return; }
        if (is_wp_error($report)) {
            echo '<div class="notice notice-warning"><p>'.esc_html($report->get_error_message()).'</p></div>';
            return;
        }
        $certified = !empty($report['certified']);
        ?>
        <div class="notice <?php echo esc_attr($certified ? 'notice-success' : 'notice-warning'); ?> spf-beta-acceptance">
            <h2><?php esc_html_e('Aceptación beta real · Factura', 'sistema-para-farmacias'); ?></h2>
            <p>
                <strong><?php echo esc_html((string)$report['reference']); ?></strong>
                &nbsp;·&nbsp;
                <strong><?php echo esc_html($certified ? __('CERTIFICADA', 'sistema-para-farmacias') : __('BLOQUEADA', 'sistema-para-farmacias')); ?></strong>
            </p>
            <p><?php esc_html_e('Verificación post-prueba de la cadena remota y del archivo de evidencia. No transmite, no modifica estados y no expone XML, ZIP, certificados ni rutas privadas.', 'sistema-para-farmacias'); ?></p>
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
