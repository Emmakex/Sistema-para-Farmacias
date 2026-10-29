<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Batch_Repository {
    const LOCK_TIMEOUT = 5;

    public static function create_or_reuse($batch_type, $reference_date) {
        $batch_type = sanitize_key((string)$batch_type);
        $reference_date = self::normalize_date($reference_date);
        if ($reference_date === '') {
            return new WP_Error('spf_batch_date', __('La fecha del lote fiscal no es válida.', 'sistema-para-farmacias'));
        }

        $profile = SPF_Fiscal_Batch_Manager::profile($batch_type);
        $builder = SPF_Fiscal_Batch_Manager::active_builder($batch_type);
        if (!$profile || !$builder) {
            return new WP_Error('spf_batch_profile', __('El país activo no tiene configurado este tipo de lote fiscal.', 'sistema-para-farmacias'));
        }

        global $wpdb;
        $batches = SPF_Database::table('fiscal_batches');
        $items = SPF_Database::table('fiscal_batch_items');
        $country = SPF_Config::country_code();
        $lock_name = 'spf_batch_' . md5($wpdb->prefix . '|' . $country . '|' . $batch_type . '|' . $reference_date);
        $locked = (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)', $lock_name, self::LOCK_TIMEOUT));
        if ($locked !== 1) {
            return new WP_Error('spf_batch_lock', __('No se pudo bloquear temporalmente la creación del lote fiscal. Inténtalo de nuevo.', 'sistema-para-farmacias'));
        }

        $wpdb->query('START TRANSACTION');
        try {
            $context = SPF_Fiscal_Batch_Manager::context_for_date($batch_type, $reference_date, true, true);
            if (is_wp_error($context)) { throw new RuntimeException($context->get_error_message()); }

            if (empty($context['lines'])) {
                $latest = self::latest_for_date($country, $batch_type, $reference_date);
                if ($latest) {
                    $wpdb->query('COMMIT');
                    return ['batch'=>$latest,'created'=>false,'reused'=>true,'reason'=>'no_new_documents'];
                }
                throw new RuntimeException(__('No hay comprobantes elegibles para crear el lote fiscal.', 'sistema-para-farmacias'));
            }

            $sequence = self::next_sequence($country, $batch_type, $reference_date);
            $context['sequence'] = $sequence;
            $built = $builder->build($context);
            if (is_wp_error($built)) { throw new RuntimeException($built->get_error_message()); }

            $batch_snapshot = self::batch_snapshot($context);
            $snapshot_hash = self::snapshot_hash($context['lines'], $batch_snapshot);
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $batches WHERE country_code=%s AND batch_type=%s AND reference_date=%s AND snapshot_sha256=%s LIMIT 1",
                $country, $batch_type, $reference_date, $snapshot_hash
            ), ARRAY_A);
            if ($existing) {
                $wpdb->query('COMMIT');
                return ['batch'=>$existing,'created'=>false,'reused'=>true,'reason'=>'same_snapshot'];
            }

            $now = current_time('mysql');
            $inserted = $wpdb->insert($batches, [
                'country_code'=>$country,
                'batch_type'=>$batch_type,
                'reference_date'=>$reference_date,
                'issue_date'=>sanitize_text_field((string)$built['issue_date']),
                'sequence'=>$sequence,
                'identifier'=>sanitize_text_field((string)$built['identifier']),
                'builder'=>sanitize_key((string)$builder->code()),
                'internal_status'=>'prepared',
                'signature_status'=>'not_signed',
                'validation_status'=>'not_validated',
                'transport_status'=>'not_prepared',
                'beta_status'=>'not_tested',
                'fiscal_status'=>'not_sent',
                'line_count'=>(int)$built['line_count'],
                'snapshot_sha256'=>$snapshot_hash,
                'snapshot_body'=>wp_json_encode($batch_snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'xml_sha256'=>sanitize_text_field((string)$built['sha256']),
                'remote_ticket'=>'',
                'response_body'=>wp_json_encode([
                    'mode'=>'local_batch_snapshot',
                    'warnings'=>array_values((array)($built['warnings']??[])),
                    'message'=>__('Lote fiscal persistido localmente. No está firmado ni transmitido.', 'sistema-para-farmacias'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at'=>$now,
                'updated_at'=>$now,
            ]);
            if ($inserted === false || !$wpdb->insert_id) {
                throw new RuntimeException(__('No se pudo persistir el lote fiscal.', 'sistema-para-farmacias'));
            }
            $batch_id = (int)$wpdb->insert_id;

            foreach (array_values($context['lines']) as $index => $line) {
                $line_snapshot = self::line_snapshot($line);
                $line_json = wp_json_encode($line_snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $ok = $wpdb->insert($items, [
                    'batch_id'=>$batch_id,
                    'document_id'=>(int)$line['document_id'],
                    'sale_id'=>(int)$line['sale_id'],
                    'line_number'=>$index + 1,
                    'condition_code'=>sanitize_text_field((string)($line['condition_code']??'1')),
                    'snapshot_sha256'=>hash('sha256', $line_json),
                    'snapshot_body'=>$line_json,
                    'created_at'=>$now,
                ]);
                if ($ok === false) {
                    throw new RuntimeException(__('No se pudo asociar un comprobante al lote fiscal.', 'sistema-para-farmacias'));
                }
            }

            $batch = self::get($batch_id);
            $wpdb->query('COMMIT');
            return ['batch'=>$batch,'created'=>true,'reused'=>false,'xml'=>$built['xml']];
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('spf_batch_persist', sanitize_text_field($e->getMessage()));
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    public static function next_sequence($country, $batch_type, $reference_date) {
        global $wpdb;
        $table = SPF_Database::table('fiscal_batches');
        $date = self::normalize_date($reference_date);
        if ($date === '') { return 1; }
        $max = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(sequence),0) FROM $table WHERE country_code=%s AND batch_type=%s AND reference_date=%s",
            sanitize_key((string)$country), sanitize_key((string)$batch_type), $date
        ));
        return $max + 1;
    }

    public static function get($batch_id) {
        global $wpdb;
        $table = SPF_Database::table('fiscal_batches');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", absint($batch_id)), ARRAY_A);
    }

    public static function latest($limit = 20) {
        global $wpdb;
        $table = SPF_Database::table('fiscal_batches');
        $limit = max(1, min(100, (int)$limit));
        return $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT $limit", ARRAY_A);
    }

    public static function items($batch_id) {
        global $wpdb;
        $items = SPF_Database::table('fiscal_batch_items');
        $documents = SPF_Database::table('invoices');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT bi.*,i.document_type,i.serie,i.correlative FROM $items bi INNER JOIN $documents i ON i.id=bi.document_id WHERE bi.batch_id=%d ORDER BY bi.line_number ASC",
            absint($batch_id)
        ), ARRAY_A);
    }

    public static function snapshot_hash(array $lines, array $context_snapshot = []) {
        $snapshots = [];
        foreach ($lines as $line) { $snapshots[] = self::line_snapshot($line); }
        usort($snapshots, function($a,$b){ return ((int)$a['document_id']) <=> ((int)$b['document_id']); });
        $identity_context = $context_snapshot;
        unset($identity_context['sequence'], $identity_context['issue_date']);
        ksort($identity_context);
        $payload = ['context'=>$identity_context,'lines'=>$snapshots];
        return hash('sha256', wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function latest_for_date($country, $batch_type, $reference_date) {
        global $wpdb;
        $table = SPF_Database::table('fiscal_batches');
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE country_code=%s AND batch_type=%s AND reference_date=%s ORDER BY sequence DESC,id DESC LIMIT 1",
            sanitize_key((string)$country), sanitize_key((string)$batch_type), $reference_date
        ), ARRAY_A);
    }

    private static function batch_snapshot(array $context) {
        $issuer = (array)($context['issuer'] ?? []);
        ksort($issuer);
        return [
            'batch_type'=>sanitize_key((string)($context['batch_type']??'')),
            'country_code'=>sanitize_key((string)($context['country_code']??'')),
            'currency'=>sanitize_text_field((string)($context['currency']??'')),
            'reference_date'=>sanitize_text_field((string)($context['reference_date']??'')),
            'issue_date'=>sanitize_text_field((string)($context['issue_date']??'')),
            'sequence'=>(int)($context['sequence']??1),
            'issuer'=>$issuer,
        ];
    }

    private static function line_snapshot(array $line) {
        $tax_buckets = array_map(function($v){ return round((float)$v,2); }, (array)($line['tax_buckets']??[]));
        ksort($tax_buckets);
        return [
            'document_id'=>(int)($line['document_id']??0),
            'sale_id'=>(int)($line['sale_id']??0),
            'document_type'=>sanitize_key((string)($line['document_type']??'')),
            'fiscal_type_code'=>sanitize_text_field((string)($line['fiscal_type_code']??'')),
            'series'=>strtoupper((string)($line['series']??'')),
            'correlative'=>(string)($line['correlative']??''),
            'customer_document_type'=>sanitize_text_field((string)($line['customer_document_type']??'')),
            'customer_document_number'=>sanitize_text_field((string)($line['customer_document_number']??'')),
            'subtotal'=>round((float)($line['subtotal']??0),2),
            'tax'=>round((float)($line['tax']??0),2),
            'total'=>round((float)($line['total']??0),2),
            'tax_buckets'=>$tax_buckets,
            'condition_code'=>sanitize_text_field((string)($line['condition_code']??'1')),
        ];
    }

    private static function normalize_date($value) {
        $value = trim((string)$value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) { return ''; }
        [$year,$month,$day] = array_map('intval', explode('-', $value));
        return checkdate($month,$day,$year) ? $value : '';
    }
}
