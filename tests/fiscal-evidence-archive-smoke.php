<?php
error_reporting(E_ALL);
$base = sys_get_temp_dir() . '/spf-evidence-smoke-' . bin2hex(random_bytes(4));
$web = $base . '/public';
$evidence = $base . '/private-evidence';
@mkdir($web, 0750, true);
define('ABSPATH', $web . '/');
define('SPF_FISCAL_EVIDENCE_DIR', $evidence);

class WP_Error {
    private $code; private $message;
    public function __construct($code,$message){$this->code=$code;$this->message=$message;}
    public function get_error_code(){return $this->code;}
    public function get_error_message(){return $this->message;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function __($text,$domain=null){return $text;}
function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function sanitize_file_name($value){return preg_replace('/[^A-Za-z0-9._-]/','-',basename((string)$value));}
function apply_filters($tag,$value){return isset($GLOBALS['spf_evidence_root_override']) ? $GLOBALS['spf_evidence_root_override'] : $value;}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function current_time($type){return '2026-10-29 10:00:00';}
function wp_date($format){return $format==='Y'?'2026':($format==='m'?'10':'2026-10-29');}
function wp_mkdir_p($path){return is_dir($path) || mkdir($path,0750,true);}

final class SPF_Config {
    public static function get($key,$default=null){return $key==='electronic_invoicing.evidence_store'?'external_filesystem':$default;}
}
final class SPF_Fiscal_Batch_Repository {
    public static function get($id){
        return [
            'id'=>(int)$id,
            'country_code'=>'pe',
            'batch_type'=>'daily_summary',
            'reference_date'=>'2026-10-29',
            'identifier'=>'RC-20261029-00001',
            'remote_ticket'=>'TICKET-123',
        ];
    }
}

require_once __DIR__.'/../includes/fiscal/interface-spf-fiscal-evidence-store.php';
require_once __DIR__.'/../includes/class-spf-fiscal-evidence-store-registry.php';
require_once __DIR__.'/../includes/fiscal/class-spf-fiscal-evidence-filesystem.php';
require_once __DIR__.'/../includes/class-spf-fiscal-evidence-manager.php';

$store = new SPF_Fiscal_Evidence_Filesystem();
SPF_Fiscal_Evidence_Store_Registry::register($store);
if (!$store->available()) { fwrite(STDERR,"External evidence store should be available\n"); exit(1); }

$zip = "PK\x03\x04FAKE-CDR-ZIP";
$xml = '<?xml version="1.0"?><ApplicationResponse><ResponseCode>0</ResponseCode></ApplicationResponse>';
$result = [
    'ticket'=>'TICKET-123',
    'beta_status'=>'beta_batch_accepted',
    'status_code'=>'0',
    'remote_code'=>'0',
    'remote_message'=>'OK',
    'notes'=>[],
    'response_zip_sha256'=>hash('sha256',$zip),
    'cdr_xml_sha256'=>hash('sha256',$xml),
    'cdr_zip_bytes'=>$zip,
    'cdr_xml_bytes'=>$xml,
    'cdr_xml_name'=>'R-20123456789-RC-20261029-00001.xml',
];
$archive = SPF_Fiscal_Evidence_Manager::archive_batch_cdr(7,$result);
if (is_wp_error($archive)) { fwrite(STDERR,'Archive failed: '.$archive->get_error_message()."\n"); exit(1); }
if (($archive['status']??'')!=='verified') { fwrite(STDERR,"Archive not verified\n"); exit(1); }
foreach (['cdr_zip','cdr_xml','manifest'] as $key) {
    $record=$archive[$key]??[];
    if (empty($record['locator']) || !preg_match('/^[a-f0-9]{64}$/',(string)($record['sha256']??''))) { fwrite(STDERR,"Invalid archive record: $key\n"); exit(1); }
    $verified=$store->verify($record['locator'],$record['sha256']);
    if (is_wp_error($verified)) { fwrite(STDERR,"Stored evidence did not verify\n"); exit(1); }
}

$again = SPF_Fiscal_Evidence_Manager::archive_batch_cdr(7,$result);
if (is_wp_error($again) || ($again['cdr_zip']['sha256']??'')!==($archive['cdr_zip']['sha256']??'')) { fwrite(STDERR,"Idempotent archive failed\n"); exit(1); }

$bad = $result; $bad['cdr_xml_sha256']=str_repeat('0',64);
if (!is_wp_error(SPF_Fiscal_Evidence_Manager::archive_batch_cdr(7,$bad))) { fwrite(STDERR,"Hash mismatch was accepted\n"); exit(1); }
if (!is_wp_error($store->store('../escape.txt','x'))) { fwrite(STDERR,"Traversal path was accepted\n"); exit(1); }

$GLOBALS['spf_evidence_root_override']=$web.'/evidence';
if ($store->available()) { fwrite(STDERR,"Webroot evidence path was accepted\n"); exit(1); }
$GLOBALS['spf_evidence_root_override']='https://example.test/evidence';
if ($store->available()) { fwrite(STDERR,"URL evidence path was accepted\n"); exit(1); }
unset($GLOBALS['spf_evidence_root_override']);

function spf_remove_tree($path){if(!is_dir($path)){return;}foreach(scandir($path) as $name){if($name==='.'||$name==='..'){continue;}$item=$path.'/'.$name;if(is_dir($item)){spf_remove_tree($item);}else{@unlink($item);}}@rmdir($path);}
spf_remove_tree($base);
echo "fiscal-evidence-archive-smoke: ok\n";
