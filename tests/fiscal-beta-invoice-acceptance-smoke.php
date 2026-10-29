<?php
define('ABSPATH', __DIR__.'/../');
require_once __DIR__.'/../includes/class-spf-fiscal-beta-invoice-acceptance.php';

function fail_case($message) { fwrite(STDERR, $message."\n"); exit(1); }
function assert_true($condition, $message) { if (!$condition) { fail_case($message); } }
function event_row($id, $operation, $evidence, array $extra = []) {
    return array_merge([
        'id'=>$id,
        'operation'=>$operation,
        'evidence_sha256'=>$evidence,
        'transport'=>'beta_transport',
        'environment'=>'beta',
        'beta_status'=>'beta_accepted',
        'request_xml_sha256'=>str_repeat('a',64),
        'response_zip_sha256'=>'',
        'cdr_xml_sha256'=>'',
        'remote_code'=>'0',
        'remote_message'=>'Aceptado',
        'metadata_body'=>'',
        'created_at'=>'2026-10-29 12:00:00',
    ], $extra);
}

$xml = str_repeat('a',64);
$cert = str_repeat('b',64);
$zip = str_repeat('c',64);
$cdr = str_repeat('d',64);
$manifest = str_repeat('e',64);
$evidence = str_repeat('f',64);
$document = [
    'id'=>7,
    'serie'=>'F001',
    'correlative'=>'00000001',
    'signature_status'=>'verified',
    'signature_xml_sha256'=>$xml,
    'signature_certificate_sha256'=>$cert,
    'validation_status'=>'valid_local',
    'validation_xml_sha256'=>$xml,
    'beta_status'=>'beta_accepted',
    'fiscal_status'=>'not_sent',
];
$archive = [
    'status'=>'verified',
    'cdr_zip'=>['locator'=>'pe/2026/10/doc/cdr.zip','sha256'=>$zip,'bytes'=>100],
    'cdr_xml'=>['locator'=>'pe/2026/10/doc/cdr.xml','sha256'=>$cdr,'bytes'=>90],
    'manifest'=>['locator'=>'pe/2026/10/doc/manifest.json','sha256'=>$manifest,'bytes'=>300],
];
$events = [
    event_row(3,'evidence_archived',$evidence,[
        'response_zip_sha256'=>$zip,
        'cdr_xml_sha256'=>$cdr,
        'metadata_body'=>json_encode(['remote_outcome_status'=>'beta_accepted','evidence_archive'=>$archive]),
    ]),
    event_row(2,'response_received',$evidence,['response_zip_sha256'=>$zip,'cdr_xml_sha256'=>$cdr]),
    event_row(1,'submit_intent',$evidence),
];

$ok = SPF_Fiscal_Beta_Invoice_Acceptance::assess($document,$events,$evidence);
assert_true(!empty($ok['certified']), 'complete beta acceptance chain must certify');
assert_true(empty($ok['failed']), 'certified chain must have no failed checks');

$production = $document;
$production['fiscal_status'] = 'accepted';
$result = SPF_Fiscal_Beta_Invoice_Acceptance::assess($production,$events,$evidence);
assert_true(empty($result['certified']) && in_array('production_isolated',$result['failed'],true), 'production promotion must block beta acceptance certification');

$bad_manifest = $events;
$bad_archive = $archive;
$bad_archive['manifest']['sha256'] = 'broken';
$bad_manifest[0]['metadata_body'] = json_encode(['remote_outcome_status'=>'beta_accepted','evidence_archive'=>$bad_archive]);
$result = SPF_Fiscal_Beta_Invoice_Acceptance::assess($document,$bad_manifest,$evidence);
assert_true(empty($result['certified']) && in_array('archive_manifest',$result['failed'],true), 'invalid manifest evidence must block certification');

$bad_order = $events;
$bad_order[0]['id'] = 1;
$bad_order[2]['id'] = 3;
$result = SPF_Fiscal_Beta_Invoice_Acceptance::assess($document,$bad_order,$evidence);
assert_true(empty($result['certified']) && in_array('event_order',$result['failed'],true), 'broken remote event ordering must block certification');

$rejected = $document;
$rejected['beta_status'] = 'beta_rejected';
$rejected_events = $events;
$rejected_events[0]['beta_status'] = 'beta_rejected';
$rejected_events[0]['metadata_body'] = json_encode(['remote_outcome_status'=>'beta_rejected','evidence_archive'=>$archive]);
$rejected_events[1]['beta_status'] = 'beta_rejected';
$result = SPF_Fiscal_Beta_Invoice_Acceptance::assess($rejected,$rejected_events,$evidence);
assert_true(empty($result['certified']) && in_array('remote_outcome',$result['failed'],true), 'rejected beta outcome must not certify acceptance');

echo "fiscal-beta-invoice-acceptance-smoke: ok\n";
