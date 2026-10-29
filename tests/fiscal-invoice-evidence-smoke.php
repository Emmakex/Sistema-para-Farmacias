<?php
error_reporting(E_ALL);
$base = sys_get_temp_dir() . '/spf-invoice-evidence-' . bin2hex(random_bytes(4));
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
function absint($v){return abs((int)$v);}
function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function sanitize_file_name($value){return preg_replace('/[^A-Za-z0-9._-]/','-',basename((string)$value));}
function apply_filters($tag,$value){return $value;}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function current_time($type){return '2026-10-29 11:00:00';}
function wp_date($format){return $format==='Y'?'2026':($format==='m'?'10':'2026-10-29');}
function wp_mkdir_p($path){return is_dir($path) || mkdir($path,0750,true);}

final class SPF_Config {
    public static function get($key,$default=null){return $key==='electronic_invoicing.evidence_store'?'external_filesystem':$default;}
}
final class SPF_Fiscal_Manager {
    public static function context($id){
        return [
            'country_code'=>'pe',
            'document'=>[
                'id'=>(int)$id,
                'type'=>'factura',
                'series'=>'F001',
                'correlative'=>'00000001',
            ],
            'sale'=>[
                'date'=>'2026-10-29 10:45:00',
            ],
        ];
    }
}
final class SPF_Fiscal_Batch_Repository {
    public static function get($id){return null;}
}

require_once __DIR__.'/../includes/fiscal/interface-spf-fiscal-evidence-store.php';
require_once __DIR__.'/../includes/class-spf-fiscal-evidence-store-registry.php';
require_once __DIR__.'/../includes/fiscal/class-spf-fiscal-evidence-filesystem.php';
require_once __DIR__.'/../includes/class-spf-fiscal-evidence-manager.php';

$store = new SPF_Fiscal_Evidence_Filesystem();
SPF_Fiscal_Evidence_Store_Registry::register($store);

$zip = "PK\x03\x04INVOICE-CDR-ZIP";
$xml = '<?xml version="1.0"?><ApplicationResponse><ResponseCode>0</ResponseCode></ApplicationResponse>';
$result = [
    'beta_status'=>'beta_accepted',
    'remote_code'=>'0',
    'remote_message'=>'Aceptado',
    'notes'=>[],
    'cdr_zip_sha256'=>hash('sha256',$zip),
    'cdr_xml_sha256'=>hash('sha256',$xml),
    'cdr_zip_bytes'=>$zip,
    'cdr_xml_bytes'=>$xml,
    'cdr_xml_name'=>'R-20123456789-01-F001-00000001.xml',
];

$archive = SPF_Fiscal_Evidence_Manager::archive_document_cdr(9,$result);
if (is_wp_error($archive)) { fwrite(STDERR,'Archive failed: '.$archive->get_error_message()."\n"); exit(1); }
if (($archive['status']??'')!=='verified') { fwrite(STDERR,"Document archive not verified\n"); exit(1); }
$zip_locator=(string)($archive['cdr_zip']['locator']??'');
$expected_hash=hash('sha256',$zip);
if (strpos($zip_locator,'pe/2026/10/documents/F001-00000001/'.$expected_hash.'/cdr.zip')===false) {
    fwrite(STDERR,"Unexpected document evidence locator: $zip_locator\n"); exit(1);
}
foreach (['cdr_zip','cdr_xml','manifest'] as $key) {
    $record=$archive[$key]??[];
    $verified=$store->verify($record['locator']??'', $record['sha256']??'');
    if (is_wp_error($verified)) { fwrite(STDERR,"Stored invoice evidence did not verify: $key\n"); exit(1); }
}

$again=SPF_Fiscal_Evidence_Manager::archive_document_cdr(9,$result);
if (is_wp_error($again) || ($again['manifest']['sha256']??'')!==($archive['manifest']['sha256']??'')) {
    fwrite(STDERR,"Invoice archive is not idempotent\n"); exit(1);
}

$bad=$result; $bad['cdr_zip_sha256']=str_repeat('0',64);
if (!is_wp_error(SPF_Fiscal_Evidence_Manager::archive_document_cdr(9,$bad))) {
    fwrite(STDERR,"Invoice CDR ZIP hash mismatch was accepted\n"); exit(1);
}

$zip2="PK\x03\x04INVOICE-CDR-ZIP-SECOND";
$xml2='<?xml version="1.0"?><ApplicationResponse><ResponseCode>2000</ResponseCode></ApplicationResponse>';
$second=$result;
$second['beta_status']='beta_rejected';
$second['cdr_zip_bytes']=$zip2;
$second['cdr_xml_bytes']=$xml2;
$second['cdr_zip_sha256']=hash('sha256',$zip2);
$second['cdr_xml_sha256']=hash('sha256',$xml2);
$secondArchive=SPF_Fiscal_Evidence_Manager::archive_document_cdr(9,$second);
if (is_wp_error($secondArchive)) { fwrite(STDERR,"Second immutable invoice CDR could not be archived\n"); exit(1); }
if (($secondArchive['cdr_zip']['locator']??'')===($archive['cdr_zip']['locator']??'')) {
    fwrite(STDERR,"Different invoice CDR overwrote prior evidence identity\n"); exit(1);
}

function spf_remove_tree($path){if(!is_dir($path)){return;}foreach(scandir($path) as $name){if($name==='.'||$name==='..'){continue;}$item=$path.'/'.$name;if(is_dir($item)){spf_remove_tree($item);}else{@unlink($item);}}@rmdir($path);}
spf_remove_tree($base);
echo "fiscal-invoice-evidence-smoke: ok\n";
