<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Batch_Remote_Event_Repository {
    const SCHEMA_VERSION = '1';
    const SCHEMA_OPTION = 'spf_fiscal_batch_remote_events_schema';

    public static function maybe_install() {
        if ((string)get_option(self::SCHEMA_OPTION, '') === self::SCHEMA_VERSION) { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $c = SPF_Database::charset_collate();
        dbDelta("CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_uuid VARCHAR(36) NOT NULL,
            batch_id BIGINT UNSIGNED NOT NULL,
            operation VARCHAR(40) NOT NULL,
            transport VARCHAR(80) NULL,
            environment VARCHAR(30) NULL,
            evidence_sha256 VARCHAR(64) NOT NULL,
            ticket VARCHAR(190) NULL,
            beta_status VARCHAR(40) NULL,
            status_code VARCHAR(30) NULL,
            request_xml_sha256 VARCHAR(64) NULL,
            request_zip_sha256 VARCHAR(64) NULL,
            response_zip_sha256 VARCHAR(64) NULL,
            cdr_xml_sha256 VARCHAR(64) NULL,
            remote_code VARCHAR(80) NULL,
            remote_message TEXT NULL,
            metadata_body LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_uuid (event_uuid),
            KEY batch_operation (batch_id,operation),
            KEY batch_ticket (batch_id,ticket),
            KEY evidence_sha256 (evidence_sha256)
        ) $c;");
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
    }

    public static function table() {
        return SPF_Database::table('fiscal_batch_remote_events');
    }

    public static function evidence_sha256(array $batch, $transport, $environment = 'beta') {
        $evidence = [
            'batch_id'=>(int)($batch['id'] ?? 0),
            'country_code'=>sanitize_key((string)($batch['country_code'] ?? '')),
            'batch_type'=>sanitize_key((string)($batch['batch_type'] ?? '')),
            'identifier'=>sanitize_text_field((string)($batch['identifier'] ?? '')),
            'snapshot_sha256'=>sanitize_text_field((string)($batch['snapshot_sha256'] ?? '')),
            'xml_sha256'=>sanitize_text_field((string)($batch['xml_sha256'] ?? '')),
            'signature_xml_sha256'=>sanitize_text_field((string)($batch['signature_xml_sha256'] ?? '')),
            'signature_certificate_sha256'=>sanitize_text_field((string)($batch['signature_certificate_sha256'] ?? '')),
            'validation_xml_sha256'=>sanitize_text_field((string)($batch['validation_xml_sha256'] ?? '')),
            'transport'=>sanitize_key((string)$transport),
            'environment'=>sanitize_key((string)$environment),
        ];
        return hash('sha256', wp_json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function record($batch_id, $operation, $evidence_sha256, array $data = []) {
        global $wpdb;
        $operation = sanitize_key((string)$operation);
        $evidence_sha256 = strtolower(trim((string)$evidence_sha256));
        if (absint($batch_id) < 1 || $operation === '' || !preg_match('/^[a-f0-9]{64}$/', $evidence_sha256)) {
            return new WP_Error('spf_remote_event_invalid', __('No se puede registrar un evento remoto sin lote, operación y evidencia SHA-256 válidos.', 'sistema-para-farmacias'));
        }
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $inserted = $wpdb->insert(self::table(), [
            'event_uuid'=>wp_generate_uuid4(),
            'batch_id'=>absint($batch_id),
            'operation'=>$operation,
            'transport'=>sanitize_key((string)($data['transport'] ?? '')),
            'environment'=>sanitize_key((string)($data['environment'] ?? 'beta')),
            'evidence_sha256'=>$evidence_sha256,
            'ticket'=>sanitize_text_field((string)($data['ticket'] ?? '')),
            'beta_status'=>sanitize_key((string)($data['beta_status'] ?? '')),
            'status_code'=>sanitize_text_field((string)($data['status_code'] ?? '')),
            'request_xml_sha256'=>self::sha((string)($data['request_xml_sha256'] ?? '')),
            'request_zip_sha256'=>self::sha((string)($data['request_zip_sha256'] ?? '')),
            'response_zip_sha256'=>self::sha((string)($data['response_zip_sha256'] ?? '')),
            'cdr_xml_sha256'=>self::sha((string)($data['cdr_xml_sha256'] ?? '')),
            'remote_code'=>sanitize_text_field((string)($data['remote_code'] ?? '')),
            'remote_message'=>sanitize_textarea_field((string)($data['remote_message'] ?? '')),
            'metadata_body'=>$metadata ? wp_json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
            'created_at'=>current_time('mysql'),
        ]);
        if ($inserted === false) {
            return new WP_Error('spf_remote_event_store', __('No se pudo guardar la evidencia append-only de la operación remota.', 'sistema-para-farmacias'));
        }
        return (int)$wpdb->insert_id;
    }

    public static function latest_ticket($batch_id, $evidence_sha256) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE batch_id=%d AND evidence_sha256=%s AND operation='ticket_received' AND ticket<>'' ORDER BY id DESC LIMIT 1",
            absint($batch_id),
            (string)$evidence_sha256
        ), ARRAY_A);
    }

    public static function has_ticket($batch_id, $evidence_sha256) {
        return is_array(self::latest_ticket($batch_id, $evidence_sha256));
    }

    public static function latest($batch_id, $limit = 20) {
        global $wpdb;
        $table = self::table();
        $limit = max(1, min(100, absint($limit)));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE batch_id=%d ORDER BY id DESC LIMIT %d",
            absint($batch_id),
            $limit
        ), ARRAY_A);
    }

    private static function sha($value) {
        $value = strtolower(trim((string)$value));
        return preg_match('/^[a-f0-9]{64}$/', $value) ? $value : '';
    }
}
