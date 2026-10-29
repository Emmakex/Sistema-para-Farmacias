<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Evidence_Manager {
    public static function active_store() {
        $code = sanitize_key((string)SPF_Config::get('electronic_invoicing.evidence_store', ''));
        return $code !== '' ? SPF_Fiscal_Evidence_Store_Registry::get($code) : null;
    }

    public static function available() {
        $store = self::active_store();
        return $store && $store->available();
    }

    public static function status() {
        $store = self::active_store();
        if (!$store) { return ['configured'=>false,'available'=>false,'store'=>'']; }
        return ['configured'=>true,'available'=>(bool)$store->available(),'store'=>sanitize_key((string)$store->code())];
    }

    public static function archive_batch_cdr($batch_id, array $result) {
        $batch = SPF_Fiscal_Batch_Repository::get($batch_id);
        if (!$batch) { return new WP_Error('spf_evidence_batch_missing', __('No se encontró el lote asociado a la evidencia fiscal.', 'sistema-para-farmacias')); }
        $reference_date = sanitize_text_field((string)($batch['reference_date'] ?? ''));
        $date = self::date_parts($reference_date);
        $country = sanitize_key((string)($batch['country_code'] ?? 'xx'));
        $identifier = sanitize_file_name((string)($batch['identifier'] ?? ('batch-'.$batch_id)));
        $ticket = sanitize_file_name((string)($result['ticket'] ?? ($batch['remote_ticket'] ?? 'ticket')));
        if ($ticket === '') { $ticket = 'ticket'; }
        return self::archive([
            'country'=>$country,
            'base'=>implode('/', [$country,$date[0],$date[1],$identifier,$ticket]),
            'entity'=>[
                'kind'=>'batch',
                'batch_id'=>(int)$batch_id,
                'batch_type'=>sanitize_key((string)($batch['batch_type'] ?? '')),
                'identifier'=>sanitize_text_field((string)($batch['identifier'] ?? '')),
                'reference_date'=>$reference_date,
                'ticket'=>sanitize_text_field((string)($result['ticket'] ?? ($batch['remote_ticket'] ?? ''))),
            ],
            'expected_zip_hash'=>sanitize_text_field((string)($result['response_zip_sha256'] ?? '')),
        ], $result);
    }

    public static function archive_document_cdr($document_id, array $result) {
        $context = SPF_Fiscal_Manager::context($document_id);
        if (is_wp_error($context)) { return $context; }
        $document = (array)($context['document'] ?? []);
        $sale = (array)($context['sale'] ?? []);
        $country = sanitize_key((string)($context['country_code'] ?? 'xx'));
        $reference = sanitize_file_name(strtoupper((string)($document['series'] ?? '')) . '-' . (string)($document['correlative'] ?? ''));
        if ($reference === '-' || $reference === '') { $reference = 'document-' . absint($document_id); }
        $sale_date = substr((string)($sale['date'] ?? ''), 0, 10);
        $date = self::date_parts($sale_date);
        $zip_hash = strtolower(trim((string)($result['cdr_zip_sha256'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $zip_hash)) {
            return new WP_Error('spf_evidence_document_zip_hash', __('La factura beta no contiene un SHA-256 CDR válido para construir su archivo inmutable.', 'sistema-para-farmacias'));
        }
        return self::archive([
            'country'=>$country,
            'base'=>implode('/', [$country,$date[0],$date[1],'documents',$reference,$zip_hash]),
            'entity'=>[
                'kind'=>'document',
                'document_id'=>(int)$document_id,
                'document_type'=>sanitize_key((string)($document['type'] ?? '')),
                'identifier'=>sanitize_text_field($reference),
                'reference_date'=>$sale_date,
            ],
            'expected_zip_hash'=>$zip_hash,
        ], $result);
    }

    private static function archive(array $identity, array $result) {
        $store = self::active_store();
        if (!$store) {
            return new WP_Error('spf_evidence_store_missing', __('No hay un almacén de evidencia fiscal configurado para el país activo.', 'sistema-para-farmacias'));
        }
        if (!$store->available()) {
            return new WP_Error('spf_evidence_store_unavailable', __('El almacén externo de evidencia fiscal no está disponible. Revisa SPF_FISCAL_EVIDENCE_DIR.', 'sistema-para-farmacias'));
        }
        $zip = $result['cdr_zip_bytes'] ?? '';
        $xml = $result['cdr_xml_bytes'] ?? '';
        if (!is_string($zip) || $zip === '' || !is_string($xml) || $xml === '') {
            return new WP_Error('spf_evidence_payload_missing', __('La respuesta final no contiene CDR ZIP/XML suficientes para conservar evidencia.', 'sistema-para-farmacias'));
        }
        $zip_hash = hash('sha256', $zip);
        $xml_hash = hash('sha256', $xml);
        $expected_zip = strtolower(trim((string)($identity['expected_zip_hash'] ?? '')));
        $expected_xml = strtolower(trim((string)($result['cdr_xml_sha256'] ?? '')));
        if ($expected_zip === '' || !hash_equals($expected_zip, $zip_hash)) {
            return new WP_Error('spf_evidence_zip_hash', __('El ZIP CDR recibido no coincide con el SHA-256 reportado por el transporte.', 'sistema-para-farmacias'));
        }
        if ($expected_xml === '' || !hash_equals($expected_xml, $xml_hash)) {
            return new WP_Error('spf_evidence_xml_hash', __('El XML CDR recibido no coincide con el SHA-256 reportado por el transporte.', 'sistema-para-farmacias'));
        }

        $base = trim((string)($identity['base'] ?? ''), '/');
        if ($base === '') { return new WP_Error('spf_evidence_base', __('No se pudo construir la identidad del archivo fiscal.', 'sistema-para-farmacias')); }
        $xml_name = sanitize_file_name((string)($result['cdr_xml_name'] ?? 'cdr.xml'));
        if ($xml_name === '' || !preg_match('/\.xml$/i', $xml_name)) { $xml_name = 'cdr.xml'; }
        $zip_record = $store->store($base.'/cdr.zip', $zip);
        if (is_wp_error($zip_record)) { return $zip_record; }
        $xml_record = $store->store($base.'/'.$xml_name, $xml);
        if (is_wp_error($xml_record)) { return $xml_record; }
        $zip_verified = $store->verify($zip_record['locator'], $zip_hash);
        if (is_wp_error($zip_verified)) { return $zip_verified; }
        $xml_verified = $store->verify($xml_record['locator'], $xml_hash);
        if (is_wp_error($xml_verified)) { return $xml_verified; }

        $manifest = [
            'version'=>1,
            'environment'=>'beta',
            'country'=>sanitize_key((string)($identity['country'] ?? '')),
            'entity'=>(array)($identity['entity'] ?? []),
            'beta_status'=>sanitize_key((string)($result['beta_status'] ?? '')),
            'status_code'=>sanitize_text_field((string)($result['status_code'] ?? '')),
            'remote_code'=>sanitize_text_field((string)($result['remote_code'] ?? '')),
            'remote_message'=>sanitize_text_field((string)($result['remote_message'] ?? '')),
            'notes'=>array_map('sanitize_text_field',(array)($result['notes'] ?? [])),
            'cdr_zip'=>['locator'=>$zip_record['locator'],'sha256'=>$zip_hash,'bytes'=>(int)$zip_record['bytes']],
            'cdr_xml'=>['locator'=>$xml_record['locator'],'sha256'=>$xml_hash,'bytes'=>(int)$xml_record['bytes']],
        ];
        $manifest_bytes = wp_json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($manifest_bytes) || $manifest_bytes === '') {
            return new WP_Error('spf_evidence_manifest_encode', __('No se pudo serializar el manifest de evidencia fiscal.', 'sistema-para-farmacias'));
        }
        $manifest_record = $store->store($base.'/manifest.json', $manifest_bytes);
        if (is_wp_error($manifest_record)) { return $manifest_record; }
        $manifest_verified = $store->verify($manifest_record['locator'], $manifest_record['sha256']);
        if (is_wp_error($manifest_verified)) { return $manifest_verified; }

        return [
            'store'=>sanitize_key((string)$store->code()),
            'status'=>'verified',
            'cdr_zip'=>$zip_record,
            'cdr_xml'=>$xml_record,
            'manifest'=>$manifest_record,
            'archived_at'=>current_time('mysql'),
        ];
    }

    private static function date_parts($value) {
        if (preg_match('/^(\d{4})-(\d{2})-\d{2}$/', trim((string)$value), $matches)) { return [$matches[1],$matches[2]]; }
        return [wp_date('Y'),wp_date('m')];
    }
}
