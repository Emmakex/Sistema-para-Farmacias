<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Document_Remote_Event_Repository {
    const SCHEMA_VERSION = '1';
    const SCHEMA_OPTION = 'spf_fiscal_document_remote_events_schema';

    public static function maybe_install() {
        if ((string)get_option(self::SCHEMA_OPTION, '') === self::SCHEMA_VERSION) { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $c = SPF_Database::charset_collate();
        dbDelta("CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_uuid VARCHAR(36) NOT NULL,
            document_id BIGINT UNSIGNED NOT NULL,
            operation VARCHAR(40) NOT NULL,
            transport VARCHAR(80) NULL,
            environment VARCHAR(30) NULL,
            evidence_sha256 VARCHAR(64) NOT NULL,
            beta_status VARCHAR(40) NULL,
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
            KEY document_operation (document_id,operation),
            KEY document_evidence (document_id,evidence_sha256),
            KEY evidence_sha256 (evidence_sha256)
        ) $c;");
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
    }

    public static function table() {
        return SPF_Database::table('fiscal_document_remote_events');
    }

    public static function evidence_sha256($document_id, $transport, $environment = 'beta', array $package = []) {
        global $wpdb;
        $table = SPF_Database::table('invoices');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id,sale_id,document_type,serie,correlative,signature_xml_sha256,signature_certificate_sha256,validation_xml_sha256 FROM $table WHERE id=%d LIMIT 1",
            absint($document_id)
        ), ARRAY_A);
        if (!$row) { return ''; }
        $signed_hash = self::sha((string)($package['xml_sha256'] ?? $row['signature_xml_sha256'] ?? ''));
        $certificate_hash = self::sha((string)($package['signature_certificate_sha256'] ?? $row['signature_certificate_sha256'] ?? ''));
        $validation_hash = self::sha((string)($package['validation_xml_sha256'] ?? $row['validation_xml_sha256'] ?? ''));
        if ($signed_hash === '' || $certificate_hash === '' || $validation_hash === '') { return ''; }
        $evidence = [
            'document_id'=>(int)$row['id'],
            'sale_id'=>(int)$row['sale_id'],
            'country_code'=>sanitize_key((string)($package['country'] ?? SPF_Config::country_code())),
            'document_type'=>sanitize_key((string)($package['document_type'] ?? $row['document_type'])),
            'series'=>strtoupper(sanitize_text_field((string)($package['series'] ?? $row['serie']))),
            'correlative'=>sanitize_text_field((string)($package['correlative'] ?? $row['correlative'])),
            'signature_xml_sha256'=>$signed_hash,
            'signature_certificate_sha256'=>$certificate_hash,
            'validation_xml_sha256'=>$validation_hash,
            'transport'=>sanitize_key((string)$transport),
            'environment'=>sanitize_key((string)$environment),
        ];
        return hash('sha256', wp_json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function record($document_id, $operation, $evidence_sha256, array $data = []) {
        global $wpdb;
        $operation = sanitize_key((string)$operation);
        $evidence_sha256 = strtolower(trim((string)$evidence_sha256));
        if (absint($document_id) < 1 || $operation === '' || !preg_match('/^[a-f0-9]{64}$/', $evidence_sha256)) {
            return new WP_Error('spf_document_remote_event_invalid', __('No se puede registrar un evento remoto sin comprobante, operación y evidencia SHA-256 válidos.', 'sistema-para-farmacias'));
        }
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $inserted = $wpdb->insert(self::table(), [
            'event_uuid'=>wp_generate_uuid4(),
            'document_id'=>absint($document_id),
            'operation'=>$operation,
            'transport'=>sanitize_key((string)($data['transport'] ?? '')),
            'environment'=>sanitize_key((string)($data['environment'] ?? 'beta')),
            'evidence_sha256'=>$evidence_sha256,
            'beta_status'=>sanitize_key((string)($data['beta_status'] ?? '')),
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
            return new WP_Error('spf_document_remote_event_store', __('No se pudo guardar la evidencia append-only de la operación remota del comprobante.', 'sistema-para-farmacias'));
        }
        return (int)$wpdb->insert_id;
    }

    public static function latest_archived($document_id, $evidence_sha256) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE document_id=%d AND evidence_sha256=%s AND operation='evidence_archived' ORDER BY id DESC LIMIT 1",
            absint($document_id),
            (string)$evidence_sha256
        ), ARRAY_A);
    }

    public static function latest_response($document_id, $evidence_sha256) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE document_id=%d AND evidence_sha256=%s AND operation='response_received' ORDER BY id DESC LIMIT 1",
            absint($document_id),
            (string)$evidence_sha256
        ), ARRAY_A);
    }

    public static function has_remote_response($document_id, $evidence_sha256) {
        return is_array(self::latest_response($document_id, $evidence_sha256));
    }

    public static function latest($document_id, $limit = 20) {
        global $wpdb;
        $table = self::table();
        $limit = max(1, min(100, absint($limit)));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE document_id=%d ORDER BY id DESC LIMIT %d",
            absint($document_id),
            $limit
        ), ARRAY_A);
    }

    public static function metadata(array $event) {
        $data = json_decode((string)($event['metadata_body'] ?? ''), true);
        return is_array($data) ? $data : [];
    }

    private static function sha($value) {
        $value = strtolower(trim((string)$value));
        return preg_match('/^[a-f0-9]{64}$/', $value) ? $value : '';
    }
}
