<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Batch_Integrity {
    public static function reconstruct($batch_id) {
        $batch = SPF_Fiscal_Batch_Repository::get($batch_id);
        if (!$batch) { return new WP_Error('spf_batch_missing', __('No se encontró el lote fiscal.', 'sistema-para-farmacias')); }

        $header = json_decode((string)($batch['snapshot_body'] ?? ''), true);
        if (!is_array($header)) { return new WP_Error('spf_batch_snapshot_header', __('El snapshot de cabecera del lote no es válido.', 'sistema-para-farmacias')); }
        $checks = [
            'country_code'=>sanitize_key((string)($batch['country_code'] ?? '')),
            'batch_type'=>sanitize_key((string)($batch['batch_type'] ?? '')),
            'reference_date'=>(string)($batch['reference_date'] ?? ''),
            'issue_date'=>(string)($batch['issue_date'] ?? ''),
            'sequence'=>(int)($batch['sequence'] ?? 0),
        ];
        foreach ($checks as $key => $expected) {
            $actual = $key === 'sequence' ? (int)($header[$key] ?? 0) : ($key === 'country_code' || $key === 'batch_type' ? sanitize_key((string)($header[$key] ?? '')) : (string)($header[$key] ?? ''));
            if ($actual !== $expected) { return new WP_Error('spf_batch_snapshot_header_mismatch', __('El snapshot de cabecera no coincide con la identidad persistida del lote.', 'sistema-para-farmacias')); }
        }

        $items = SPF_Fiscal_Batch_Repository::items($batch_id);
        if (count($items) !== (int)($batch['line_count'] ?? 0)) { return new WP_Error('spf_batch_snapshot_count', __('La cantidad de miembros del lote no coincide con su snapshot.', 'sistema-para-farmacias')); }
        $lines = [];
        foreach ($items as $item) {
            $line = json_decode((string)($item['snapshot_body'] ?? ''), true);
            if (!is_array($line)) { return new WP_Error('spf_batch_line_snapshot', __('Una línea del lote no contiene un snapshot válido.', 'sistema-para-farmacias')); }
            $encoded = wp_json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!hash_equals((string)($item['snapshot_sha256'] ?? ''), hash('sha256', $encoded))) {
                return new WP_Error('spf_batch_line_hash', __('Una línea del lote no supera la verificación SHA-256.', 'sistema-para-farmacias'));
            }
            if ((int)($line['document_id'] ?? 0) !== (int)($item['document_id'] ?? 0) || (int)($line['sale_id'] ?? 0) !== (int)($item['sale_id'] ?? 0)) {
                return new WP_Error('spf_batch_line_identity', __('Una línea del snapshot no coincide con su miembro persistido.', 'sistema-para-farmacias'));
            }
            $lines[] = $line;
        }

        $snapshot_hash = SPF_Fiscal_Batch_Repository::snapshot_hash($lines, $header);
        if (!hash_equals((string)($batch['snapshot_sha256'] ?? ''), $snapshot_hash)) {
            return new WP_Error('spf_batch_snapshot_hash', __('El lote completo no supera la verificación de integridad SHA-256.', 'sistema-para-farmacias'));
        }

        $builder = SPF_Fiscal_Batch_Builder_Registry::get((string)($batch['builder'] ?? ''));
        if (!$builder || !$builder->supports((string)$batch['batch_type'])) {
            return new WP_Error('spf_batch_builder_integrity', __('El builder original del lote no está disponible.', 'sistema-para-farmacias'));
        }
        $context = $header;
        $context['lines'] = $lines;
        $rebuilt = $builder->build($context);
        if (is_wp_error($rebuilt)) { return $rebuilt; }
        if (!hash_equals((string)($batch['identifier'] ?? ''), (string)($rebuilt['identifier'] ?? ''))) {
            return new WP_Error('spf_batch_identifier_integrity', __('El identificador reconstruido del lote no coincide.', 'sistema-para-farmacias'));
        }
        if (!hash_equals((string)($batch['xml_sha256'] ?? ''), (string)($rebuilt['sha256'] ?? ''))) {
            return new WP_Error('spf_batch_xml_integrity', __('El XML reconstruido no coincide con el hash persistido del lote.', 'sistema-para-farmacias'));
        }

        return [
            'batch'=>$batch,
            'context'=>$context,
            'lines'=>$lines,
            'xml'=>(string)$rebuilt['xml'],
            'xml_sha256'=>(string)$rebuilt['sha256'],
            'identifier'=>(string)$rebuilt['identifier'],
            'builder'=>$builder,
        ];
    }
}
