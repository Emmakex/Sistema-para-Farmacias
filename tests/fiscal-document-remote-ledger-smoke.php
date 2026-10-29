<?php
error_reporting(E_ALL);
define('ABSPATH', __DIR__.'/');
define('ARRAY_A','ARRAY_A');

class WP_Error {
    private $code; private $message;
    public function __construct($code,$message){$this->code=$code;$this->message=$message;}
    public function get_error_code(){return $this->code;}
    public function get_error_message(){return $this->message;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function __($text,$domain=null){return $text;}
function absint($v){return abs((int)$v);}
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_textarea_field($v){return trim(strip_tags((string)$v));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function wp_generate_uuid4(){static $i=0;$i++;return sprintf('00000000-0000-4000-8000-%012d',$i);}
function current_time($type){return '2026-10-29 12:00:00';}

final class SPF_Config { public static function country_code(){return 'pe';} }
final class SPF_Database {
    public static function table($name){return 'wp_farmapos_'.$name;}
    public static function charset_collate(){return 'CHARSET=utf8mb4';}
}

class FakeWpdb {
    public $prefix='wp_';
    public $insert_id=0;
    public $events=[];
    public $document=[
        'id'=>9,'sale_id'=>12,'document_type'=>'factura','serie'=>'F001','correlative'=>'00000001',
        'signature_xml_sha256'=>'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'signature_certificate_sha256'=>'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        'validation_xml_sha256'=>'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ];
    public function prepare($query,...$args){return ['query'=>$query,'args'=>$args];}
    public function get_row($prepared,$format=null){
        $query=is_array($prepared)?$prepared['query']:(string)$prepared;
        $args=is_array($prepared)?$prepared['args']:[];
        if (strpos($query,'FROM wp_farmapos_invoices')!==false) { return $this->document; }
        if (strpos($query,"operation='evidence_archived'")!==false) { return $this->latest((int)$args[0],(string)$args[1],'evidence_archived'); }
        if (strpos($query,"operation='response_received'")!==false) { return $this->latest((int)$args[0],(string)$args[1],'response_received'); }
        return null;
    }
    public function get_results($prepared,$format=null){
        $args=is_array($prepared)?$prepared['args']:[];
        $document=(int)($args[0]??0);$limit=(int)($args[1]??20);
        $rows=array_values(array_filter($this->events,function($r)use($document){return (int)$r['document_id']===$document;}));
        usort($rows,function($a,$b){return $b['id']<=>$a['id'];});return array_slice($rows,0,$limit);
    }
    public function insert($table,$row){$this->insert_id=count($this->events)+1;$row['id']=$this->insert_id;$this->events[]=$row;return 1;}
    private function latest($document,$evidence,$operation){
        $rows=array_values(array_filter($this->events,function($r)use($document,$evidence,$operation){return (int)$r['document_id']===$document&&$r['evidence_sha256']===$evidence&&$r['operation']===$operation;}));
        if(!$rows){return null;}usort($rows,function($a,$b){return $b['id']<=>$a['id'];});return $rows[0];
    }
}
$GLOBALS['wpdb']=new FakeWpdb();

require_once __DIR__.'/../includes/class-spf-fiscal-document-remote-event-repository.php';

$package=[
    'country'=>'pe','document_type'=>'factura','series'=>'F001','correlative'=>'00000001',
    'xml_sha256'=>str_repeat('a',64),'signature_certificate_sha256'=>str_repeat('b',64),
    'validation_xml_sha256'=>str_repeat('a',64),
];
$evidence=SPF_Fiscal_Document_Remote_Event_Repository::evidence_sha256(9,'peru_beta','beta',$package);
if(!preg_match('/^[a-f0-9]{64}$/',$evidence)){fwrite(STDERR,"Evidence hash invalid\n");exit(1);}
$again=SPF_Fiscal_Document_Remote_Event_Repository::evidence_sha256(9,'peru_beta','beta',$package);
if(!hash_equals($evidence,$again)){fwrite(STDERR,"Evidence hash not deterministic\n");exit(1);}
$changed=$package;$changed['xml_sha256']=str_repeat('c',64);
if(hash_equals($evidence,SPF_Fiscal_Document_Remote_Event_Repository::evidence_sha256(9,'peru_beta','beta',$changed))){fwrite(STDERR,"Evidence ignored signed XML change\n");exit(1);}

$intent=SPF_Fiscal_Document_Remote_Event_Repository::record(9,'submit_intent',$evidence,['transport'=>'peru_beta','environment'=>'beta','request_xml_sha256'=>$package['xml_sha256']]);
$response=SPF_Fiscal_Document_Remote_Event_Repository::record(9,'response_received',$evidence,[
    'transport'=>'peru_beta','environment'=>'beta','beta_status'=>'beta_accepted',
    'request_xml_sha256'=>$package['xml_sha256'],'request_zip_sha256'=>str_repeat('d',64),
    'response_zip_sha256'=>str_repeat('e',64),'cdr_xml_sha256'=>str_repeat('f',64),
    'remote_code'=>'0','remote_message'=>'Aceptado','metadata'=>['notes'=>['ok']],
]);
$archived=SPF_Fiscal_Document_Remote_Event_Repository::record(9,'evidence_archived',$evidence,[
    'transport'=>'peru_beta','environment'=>'beta','beta_status'=>'beta_accepted',
    'response_zip_sha256'=>str_repeat('e',64),'cdr_xml_sha256'=>str_repeat('f',64),
    'metadata'=>['remote_outcome_status'=>'beta_accepted','evidence_archive'=>['store'=>'external_filesystem']],
]);
if(is_wp_error($intent)||is_wp_error($response)||is_wp_error($archived)){fwrite(STDERR,"Could not append ledger events\n");exit(1);}
if(!SPF_Fiscal_Document_Remote_Event_Repository::has_remote_response(9,$evidence)){fwrite(STDERR,"Remote response not discoverable\n");exit(1);}
$latest=SPF_Fiscal_Document_Remote_Event_Repository::latest_archived(9,$evidence);
if(!is_array($latest)||($latest['operation']??'')!=='evidence_archived'){fwrite(STDERR,"Archived event not recoverable\n");exit(1);}
$metadata=SPF_Fiscal_Document_Remote_Event_Repository::metadata($latest);
if(($metadata['remote_outcome_status']??'')!=='beta_accepted'){fwrite(STDERR,"Archived metadata not recoverable\n");exit(1);}
if(count($GLOBALS['wpdb']->events)!==3){fwrite(STDERR,"Unexpected ledger mutation count\n");exit(1);}

echo "fiscal-document-remote-ledger-smoke: ok\n";
