<?php
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');

function sanitize_key($key){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$key));}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}

require_once __DIR__.'/../includes/class-spf-fiscal-batch-repository.php';

$lineA = [
    'document_id'=>10,'sale_id'=>20,'document_type'=>'boleta','fiscal_type_code'=>'03',
    'series'=>'B001','correlative'=>'00000001','customer_document_type'=>'DNI','customer_document_number'=>'12345678',
    'subtotal'=>100.00,'tax'=>18.00,'total'=>118.00,'tax_buckets'=>['gravado'=>100.00],'condition_code'=>'1',
];
$lineB = [
    'document_id'=>11,'sale_id'=>21,'document_type'=>'boleta','fiscal_type_code'=>'03',
    'series'=>'B001','correlative'=>'00000002','customer_document_type'=>'','customer_document_number'=>'',
    'subtotal'=>50.00,'tax'=>0.00,'total'=>50.00,'tax_buckets'=>['exonerado'=>30.00,'inafecto'=>20.00],'condition_code'=>'1',
];

$hash1 = SPF_Fiscal_Batch_Repository::snapshot_hash([$lineA,$lineB]);
$hash2 = SPF_Fiscal_Batch_Repository::snapshot_hash([$lineB,$lineA]);
if (!hash_equals($hash1,$hash2)) { fwrite(STDERR,"Snapshot hash depends on line ordering\n"); exit(1); }

$changed = $lineB;
$changed['total'] = 51.00;
$hash3 = SPF_Fiscal_Batch_Repository::snapshot_hash([$lineA,$changed]);
if (hash_equals($hash1,$hash3)) { fwrite(STDERR,"Snapshot hash ignored fiscal data change\n"); exit(1); }

$changedBucket = $lineB;
$changedBucket['tax_buckets']['inafecto'] = 21.00;
$hash4 = SPF_Fiscal_Batch_Repository::snapshot_hash([$lineA,$changedBucket]);
if (hash_equals($hash1,$hash4)) { fwrite(STDERR,"Snapshot hash ignored tax bucket change\n"); exit(1); }

if (!preg_match('/^[a-f0-9]{64}$/',$hash1)) { fwrite(STDERR,"Snapshot hash is not SHA-256\n"); exit(1); }

echo "fiscal-batch-persistence-smoke: ok\n";
