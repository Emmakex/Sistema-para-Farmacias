<?php
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
define('SPF_VERSION', 'ci-readiness');
define('MINUTE_IN_SECONDS', 60);

class WP_Error {
    private $code; private $message;
    public function __construct($code,$message){$this->code=$code;$this->message=$message;}
    public function get_error_code(){return $this->code;}
    public function get_error_message(){return $this->message;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function __($t,$d=null){return $t;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_file_name($v){return preg_replace('/[^A-Za-z0-9._-]/','-',(string)$v);}
function absint($v){return abs((int)$v);}
function wp_json_encode($v,$f=0){return json_encode($v,$f);}
function current_time($type){return $type==='mysql'?'2026-10-29 13:00:00':time();}
function add_action(){return true;}
function get_current_user_id(){return 1;}
function get_transient($k){return false;}
function delete_transient($k){return true;}
function set_transient($k,$v,$e){return true;}
if (!class_exists('DOMDocument')) { class DOMDocument {} }
if (!class_exists('XSLTProcessor')) { class XSLTProcessor {} }
if (!class_exists('SoapClient')) { class SoapClient {} }
if (!class_exists('ZipArchive')) { class ZipArchive {} }

$xsd=tempnam(sys_get_temp_dir(),'spf-ready-xsd-');
$xsl=tempnam(sys_get_temp_dir(),'spf-ready-xsl-');
file_put_contents($xsd,'xsd'); file_put_contents($xsl,'xsl');

$batch=[
    'id'=>9,'country_code'=>'pe','batch_type'=>'daily_summary','identifier'=>'RC-20261029-00001','reference_date'=>'2026-10-29',
    'snapshot_sha256'=>str_repeat('1',64),'xml_sha256'=>str_repeat('2',64),
    'signature_status'=>'verified','signature_xml_sha256'=>str_repeat('3',64),'signature_certificate_sha256'=>str_repeat('4',64),
    'validation_status'=>'valid_local','validation_xml_sha256'=>str_repeat('3',64),'beta_status'=>'not_tested','fiscal_status'=>'not_sent','remote_ticket'=>'',
];
final class SPF_Fiscal_Batch_Repository {
    public static function get($id){global $batch;return (int)$id===9?$batch:null;}
    public static function latest($limit=20){global $batch;return [$batch];}
}
final class SPF_Fiscal_Batch_Integrity {
    public static function reconstruct($id){return ['context'=>['issuer'=>['tax_id'=>'20123456789']],'identifier'=>'RC-20261029-00001','xml'=>'<SummaryDocuments/>'];}
}
class FakeValidator { public function validate($xml,$resources,$context){return ['ok'=>true,'checks'=>['xsd'=>'valid','xsl'=>'valid'],'errors'=>[],'warnings'=>[]];} }
final class SPF_Fiscal_Validator_Registry { public static function get($code){return $code==='fake_validator'?new FakeValidator():null;} }
final class SPF_Fiscal_Batch_Lifecycle {
    public static function validation_resources($type,array $context=[]){global $xsd,$xsl;return ['xsd_path'=>$xsd,'xsl_path'=>$xsl,'filename'=>'RC.xml','filename_parameter'=>'nombreArchivoEnviado'];}
    public static function build_signed_payload($id){return ['xml'=>'<SummaryDocuments/>','signed_xml_sha256'=>str_repeat('3',64),'certificate_sha256'=>str_repeat('4',64)];}
    public static function validation_profile($type){return ['validator'=>'fake_validator'];}
}
class FakeReadyTransport {
    public static $submitCalls=0; public static $pollCalls=0;
    public function code(){return 'fake_beta';}
    public function environment(){return 'beta';}
    public function batch_enabled(){return true;}
    public function inspect_batch(array $package){return ['ok'=>true,'errors'=>[],'warnings'=>['inspection-only']];}
    public function submit_batch(array $package){self::$submitCalls++;return [];}
    public function poll_batch(array $package){self::$pollCalls++;return [];}
}
final class SPF_Fiscal_Batch_Beta_Manager {
    public static function transport($type){return new FakeReadyTransport();}
    public static function recoverable_ticket($id){return null;}
    public static function build_submit_package($id){return ['country'=>'pe','batch_type'=>'daily_summary','identifier'=>'RC-20261029-00001','reference_date'=>'2026-10-29','issuer_tax_id'=>'20123456789','xml'=>'<SummaryDocuments/>','xml_sha256'=>str_repeat('3',64),'signed'=>true,'signature_verified'=>true,'validation_status'=>'valid_local','validation_xml_sha256'=>str_repeat('3',64)];}
}
final class SPF_Fiscal_Batch_Remote_Event_Repository {
    public static function latest($id,$limit=20){return [[
        'event_uuid'=>'11111111-1111-4111-8111-111111111111','operation'=>'ticket_received','transport'=>'fake_beta','environment'=>'beta',
        'evidence_sha256'=>str_repeat('5',64),'ticket'=>'TICKET-XYZ','beta_status'=>'beta_batch_ticketed','status_code'=>'',
        'request_xml_sha256'=>str_repeat('3',64),'request_zip_sha256'=>str_repeat('6',64),'response_zip_sha256'=>'','cdr_xml_sha256'=>'',
        'remote_code'=>'','remote_message'=>'ok','metadata_body'=>'{"private_key":"MUST_NOT_EXPORT","xml_body":"MUST_NOT_EXPORT"}','created_at'=>'2026-10-29 13:01:00'
    ]];}
}

require_once __DIR__ . '/../includes/class-spf-fiscal-batch-beta-diagnostics.php';

$diagnostics=SPF_Fiscal_Batch_Beta_Diagnostics::inspect(9);
if (is_wp_error($diagnostics)) {fwrite(STDERR,"Diagnostics returned error\n");exit(1);}
if (empty($diagnostics['ready_for_submit'])) {fwrite(STDERR,"Ready fixture was not marked ready\n");exit(1);}
if (!array_key_exists('network_performed',$diagnostics) || $diagnostics['network_performed']!==false) {fwrite(STDERR,"Diagnostics did not assert network_performed=false\n");exit(1);}
if (FakeReadyTransport::$submitCalls!==0 || FakeReadyTransport::$pollCalls!==0) {fwrite(STDERR,"Diagnostics performed remote transport\n");exit(1);}
$codes=array_column($diagnostics['checks'],'code');
foreach (['snapshot_integrity','signature_evidence','validation_evidence','current_local_revalidation','transport_inspection','beta_opt_in'] as $required) {
    if (!in_array($required,$codes,true)) {fwrite(STDERR,"Missing readiness check: $required\n");exit(1);}
}

$export=SPF_Fiscal_Batch_Beta_Diagnostics::export_payload(9);
if (is_wp_error($export)) {fwrite(STDERR,"Export returned error\n");exit(1);}
if (($export['schema']??'')!=='spf-fiscal-beta-evidence-v1' || !preg_match('/^[a-f0-9]{64}$/',$export['export_sha256']??'')) {fwrite(STDERR,"Evidence export schema/hash invalid\n");exit(1);}
$json=wp_json_encode($export,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
foreach (['MUST_NOT_EXPORT','private_key','xml_body','metadata_body'] as $forbidden) {
    if (strpos($json,$forbidden)!==false) {fwrite(STDERR,"Sensitive or body metadata leaked into export: $forbidden\n");exit(1);}
}
if (FakeReadyTransport::$submitCalls!==0 || FakeReadyTransport::$pollCalls!==0) {fwrite(STDERR,"Export performed remote transport\n");exit(1);}

@unlink($xsd); @unlink($xsl);
echo "fiscal-beta-readiness-smoke: ok\n";
