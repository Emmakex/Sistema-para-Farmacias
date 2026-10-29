<?php
define('ABSPATH', __DIR__.'/../');
require_once __DIR__.'/../includes/class-spf-fiscal-beta-batch-acceptance.php';

function fail_batch_acceptance($message) { fwrite(STDERR, $message."\n"); exit(1); }
function assert_batch_acceptance($condition, $message) { if (!$condition) { fail_batch_acceptance($message); } }
function batch_event($id, $operation, $evidence, $ticket = '', array $extra = []) {
    return array_merge([
        'id'=>$id,
        'operation'=>$operation,
        'evidence_sha256'=>$evidence,
        'transport'=>'beta_transport',
        'environment'=>'beta',
        'ticket'=>$ticket,
        'beta_status'=>'',
        'status_code'=>'',
        'request_xml_sha256'=>'',
        'request_zip_sha256'=>'',
        'response_zip_sha256'=>'',
        'cdr_xml_sha256'=>'',
        'remote_code'=>'',
        'remote_message'=>'',
        'metadata_body'=>'',
        'created_at'=>'2026-10-29 13:00:00',
    ], $extra);
}

$xml = str_repeat('a',64);
$cert = str_repeat('b',64);
$request_zip = str_repeat('c',64);
$response_zip = str_repeat('d',64);
$cdr_xml = str_repeat('e',64);
$manifest = str_repeat('f',64);
$evidence = str_repeat('1',64);
$ticket = 'TICKET-ABC-123';
$archive = [
    'store'=>'external_filesystem',
    'status'=>'verified',
    'archived_at'=>'2026-10-29 13:04:00',
    'cdr_zip'=>['locator'=>'pe/2026/10/batch/cdr.zip','sha256'=>$response_zip,'bytes'=>120],
    'cdr_xml'=>['locator'=>'pe/2026/10/batch/cdr.xml','sha256'=>$cdr_xml,'bytes'=>100],
    'manifest'=>['locator'=>'pe/2026/10/batch/manifest.json','sha256'=>$manifest,'bytes'=>320],
];
$projection = [
    'fiscal_beta_batch'=>[
        'status'=>'beta_batch_accepted',
        'transport'=>'beta_transport',
        'environment'=>'beta',
        'ticket'=>$ticket,
        'status_code'=>'0',
        'remote_code'=>'0',
        'remote_message'=>'Aceptado',
        'request_zip_sha256'=>$request_zip,
        'response_zip_sha256'=>$response_zip,
        'cdr_xml_sha256'=>$cdr_xml,
        'evidence_archive'=>$archive,
        'production'=>false,
    ],
];
$batch = [
    'id'=>9,
    'identifier'=>'RC-20261029-00001',
    'signature_status'=>'verified',
    'signature_xml_sha256'=>$xml,
    'signature_certificate_sha256'=>$cert,
    'validation_status'=>'valid_local',
    'validation_xml_sha256'=>$xml,
    'beta_status'=>'beta_batch_accepted',
    'fiscal_status'=>'not_sent',
    'remote_ticket'=>$ticket,
    'response_body'=>json_encode($projection),
];
$events = [
    batch_event(8,'evidence_archived',$evidence,$ticket,[
        'beta_status'=>'beta_batch_accepted',
        'status_code'=>'0',
        'response_zip_sha256'=>$response_zip,
        'cdr_xml_sha256'=>$cdr_xml,
        'remote_code'=>'0',
        'remote_message'=>'Aceptado',
        'metadata_body'=>json_encode($archive),
    ]),
    batch_event(7,'poll_result',$evidence,$ticket,[
        'beta_status'=>'beta_batch_accepted',
        'status_code'=>'0',
        'response_zip_sha256'=>$response_zip,
        'cdr_xml_sha256'=>$cdr_xml,
        'remote_code'=>'0',
        'remote_message'=>'Aceptado',
    ]),
    batch_event(6,'poll_intent',$evidence,$ticket,['beta_status'=>'beta_batch_processing']),
    batch_event(5,'poll_result',$evidence,$ticket,['beta_status'=>'beta_batch_processing','status_code'=>'98']),
    batch_event(4,'poll_intent',$evidence,$ticket,['beta_status'=>'beta_batch_ticketed']),
    batch_event(3,'ticket_received',$evidence,$ticket,[
        'beta_status'=>'beta_batch_ticketed',
        'request_xml_sha256'=>$xml,
        'request_zip_sha256'=>$request_zip,
    ]),
    batch_event(2,'ticket_recovered',$evidence,$ticket,['beta_status'=>'beta_batch_ticketed']),
    batch_event(1,'submit_intent',$evidence,'',['beta_status'=>'not_tested','request_xml_sha256'=>$xml]),
];

$ok = SPF_Fiscal_Beta_Batch_Acceptance::assess($batch,$events,$evidence);
assert_batch_acceptance(!empty($ok['certified']), 'complete async batch chain with intermediate processing poll must certify');
assert_batch_acceptance(empty($ok['failed']), 'certified batch chain must have no failed checks');

$production = $batch;
$production['fiscal_status'] = 'accepted';
$result = SPF_Fiscal_Beta_Batch_Acceptance::assess($production,$events,$evidence);
assert_batch_acceptance(empty($result['certified']) && in_array('production_isolated',$result['failed'],true), 'production promotion must block batch acceptance');

$wrong_ticket = $events;
$wrong_ticket[0]['ticket'] = 'OTHER-TICKET';
$result = SPF_Fiscal_Beta_Batch_Acceptance::assess($batch,$wrong_ticket,$evidence);
assert_batch_acceptance(empty($result['certified']) && in_array('ticket_consistency',$result['failed'],true), 'ticket mismatch must block certification');

$missing_final_intent = array_values(array_filter($events, static function($event) { return (int)$event['id'] !== 6; }));
$result = SPF_Fiscal_Beta_Batch_Acceptance::assess($batch,$missing_final_intent,$evidence);
assert_batch_acceptance(empty($result['certified']) && in_array('event_order',$result['failed'],true), 'final poll without its preceding intent must block certification');

$bad_manifest = $events;
$bad_archive = $archive;
$bad_archive['manifest']['sha256'] = 'broken';
$bad_manifest[0]['metadata_body'] = json_encode($bad_archive);
$result = SPF_Fiscal_Beta_Batch_Acceptance::assess($batch,$bad_manifest,$evidence);
assert_batch_acceptance(empty($result['certified']) && in_array('archive_manifest',$result['failed'],true), 'invalid archived manifest must block batch certification');

$projection_mismatch = $batch;
$bad_projection = $projection;
$bad_projection['fiscal_beta_batch']['ticket'] = 'WRONG';
$projection_mismatch['response_body'] = json_encode($bad_projection);
$result = SPF_Fiscal_Beta_Batch_Acceptance::assess($projection_mismatch,$events,$evidence);
assert_batch_acceptance(empty($result['certified']) && in_array('projection',$result['failed'],true), 'mutable batch projection must match the append-only chain');

$rejected_batch = $batch;
$rejected_batch['beta_status'] = 'beta_batch_rejected';
$rejected_projection = $projection;
$rejected_projection['fiscal_beta_batch']['status'] = 'beta_batch_rejected';
$rejected_batch['response_body'] = json_encode($rejected_projection);
$rejected_events = $events;
$rejected_events[0]['beta_status'] = 'beta_batch_rejected';
$rejected_events[1]['beta_status'] = 'beta_batch_rejected';
$result = SPF_Fiscal_Beta_Batch_Acceptance::assess($rejected_batch,$rejected_events,$evidence);
assert_batch_acceptance(empty($result['certified']) && in_array('remote_outcome',$result['failed'],true), 'rejected batch response must not certify acceptance');

echo "fiscal-beta-batch-acceptance-smoke: ok\n";
