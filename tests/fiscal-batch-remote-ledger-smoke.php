<?php
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('MINUTE_IN_SECONDS', 60);

class WP_Error {
    private $code; private $message;
    public function __construct($code, $message) { $this->code=$code; $this->message=$message; }
    public function get_error_code(){ return $this->code; }
    public function get_error_message(){ return $this->message; }
}
function is_wp_error($value){ return $value instanceof WP_Error; }
function __($text,$domain=null){ return $text; }
function sanitize_key($value){ return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$value)); }
function sanitize_text_field($value){ return trim(strip_tags((string)$value)); }
function sanitize_textarea_field($value){ return trim(strip_tags((string)$value)); }
function sanitize_file_name($value){ return preg_replace('/[^A-Za-z0-9._-]/','-',(string)$value); }
function absint($value){ return abs((int)$value); }
function wp_json_encode($value,$flags=0){ return json_encode($value,$flags); }
function wp_generate_uuid4(){ static $i=0; $i++; return sprintf('00000000-0000-4000-8000-%012d',$i); }
function current_time($type){ return $type==='mysql' ? '2026-10-29 12:00:00' : time(); }
function get_option($key,$default=''){ return $default; }
function update_option($key,$value,$autoload=null){ return true; }
function get_transient($key){ return false; }
function set_transient($key,$value,$expiration){ return true; }
function delete_transient($key){ return true; }
function add_action(){ return true; }

final class SPF_Database {
    public static function table($name){ return 'wp_farmapos_' . sanitize_key($name); }
    public static function charset_collate(){ return ''; }
}

class FakeWpdb {
    public $prefix='wp_';
    public $insert_id=0;
    public $events=[];
    public function prepare($query,...$args){ return ['query'=>$query,'args'=>$args]; }
    public function insert($table,$data){
        $this->insert_id++;
        $data['id']=$this->insert_id;
        $this->events[]=$data;
        return 1;
    }
    public function get_row($prepared,$output=null){
        $args=$prepared['args']; $batch=(int)$args[0]; $evidence=(string)$args[1];
        $matches=array_values(array_filter($this->events,function($row) use($batch,$evidence){
            return (int)$row['batch_id']===$batch && $row['evidence_sha256']===$evidence && $row['operation']==='ticket_received' && $row['ticket']!=='';
        }));
        usort($matches,function($a,$b){ return $b['id']<=>$a['id']; });
        return $matches ? $matches[0] : null;
    }
    public function get_results($prepared,$output=null){
        $args=$prepared['args']; $batch=(int)$args[0]; $limit=(int)$args[1];
        $matches=array_values(array_filter($this->events,function($row) use($batch){ return (int)$row['batch_id']===$batch; }));
        usort($matches,function($a,$b){ return $b['id']<=>$a['id']; });
        return array_slice($matches,0,$limit);
    }
    public function get_var($prepared){ return 1; }
    public function update($table,$data,$where){
        global $fake_batch;
        if ($table==='wp_farmapos_fiscal_batches' && (int)$where['id']===(int)$fake_batch['id']) {
            foreach ($data as $key=>$value) { $fake_batch[$key]=$value; }
            return 1;
        }
        return false;
    }
}
$wpdb=new FakeWpdb();

interface SPF_Fiscal_Async_Batch_Transport_Interface {
    public function batch_enabled();
    public function inspect_batch(array $package);
    public function submit_batch(array $package);
    public function poll_batch(array $package);
}
class FakeTransport implements SPF_Fiscal_Async_Batch_Transport_Interface {
    public function code(){ return 'fake_beta'; }
    public function environment(){ return 'beta'; }
    public function batch_enabled(){ return true; }
    public function inspect_batch(array $package){ return ['ok'=>true]; }
    public function submit_batch(array $package){ return []; }
    public function poll_batch(array $package){ return []; }
}
final class SPF_Fiscal_Batch_Manager { public static function profile($type){ return ['beta_transport'=>'fake_beta']; } }
final class SPF_Fiscal_Transport_Registry { public static function get($code){ return $code==='fake_beta' ? new FakeTransport() : null; } }
$fake_batch=[
    'id'=>7,
    'country_code'=>'pe',
    'batch_type'=>'daily_summary',
    'identifier'=>'RC-20261029-00001',
    'snapshot_sha256'=>str_repeat('1',64),
    'xml_sha256'=>str_repeat('2',64),
    'signature_status'=>'verified',
    'signature_xml_sha256'=>str_repeat('3',64),
    'signature_certificate_sha256'=>str_repeat('4',64),
    'validation_status'=>'valid_local',
    'validation_xml_sha256'=>str_repeat('3',64),
    'remote_ticket'=>'',
    'beta_status'=>'not_tested',
    'response_body'=>'{}',
];
final class SPF_Fiscal_Batch_Repository { public static function get($id){ global $fake_batch; return (int)$id===(int)$fake_batch['id'] ? $fake_batch : null; } }
final class SPF_Fiscal_Batch_Lifecycle { public static function build_signed_payload($id){ return []; } public static function validate_batch($id){ return []; } }
final class SPF_Fiscal_Batch_Integrity { public static function reconstruct($id){ return []; } }

require_once __DIR__ . '/../includes/class-spf-fiscal-batch-remote-event-repository.php';
require_once __DIR__ . '/../includes/class-spf-fiscal-batch-beta-manager.php';

$transport=new FakeTransport();
$evidence=SPF_Fiscal_Batch_Remote_Event_Repository::evidence_sha256($fake_batch,$transport->code(),$transport->environment());
if (!preg_match('/^[a-f0-9]{64}$/',$evidence)) { fwrite(STDERR,"Evidence fingerprint invalid\n"); exit(1); }
$again=SPF_Fiscal_Batch_Remote_Event_Repository::evidence_sha256($fake_batch,$transport->code(),$transport->environment());
if (!hash_equals($evidence,$again)) { fwrite(STDERR,"Evidence fingerprint not deterministic\n"); exit(1); }
$changed=$fake_batch; $changed['signature_certificate_sha256']=str_repeat('5',64);
if (hash_equals($evidence,SPF_Fiscal_Batch_Remote_Event_Repository::evidence_sha256($changed,$transport->code(),$transport->environment()))) {
    fwrite(STDERR,"Certificate change did not change evidence fingerprint\n"); exit(1);
}

$intent=SPF_Fiscal_Batch_Remote_Event_Repository::record(7,'submit_intent',$evidence,[
    'transport'=>'fake_beta','environment'=>'beta','request_xml_sha256'=>str_repeat('3',64)
]);
if (is_wp_error($intent)) { fwrite(STDERR,"Intent record failed\n"); exit(1); }
$ticketEvent=SPF_Fiscal_Batch_Remote_Event_Repository::record(7,'ticket_received',$evidence,[
    'transport'=>'fake_beta','environment'=>'beta','ticket'=>'TICKET-123','beta_status'=>'beta_batch_ticketed',
    'request_xml_sha256'=>str_repeat('3',64),'request_zip_sha256'=>str_repeat('6',64)
]);
if (is_wp_error($ticketEvent)) { fwrite(STDERR,"Ticket event failed\n"); exit(1); }

$recoverable=SPF_Fiscal_Batch_Beta_Manager::recoverable_ticket(7);
if (!is_array($recoverable) || ($recoverable['ticket']??'')!=='TICKET-123') {
    fwrite(STDERR,"Recoverable ticket was not detected\n"); exit(1);
}
$recovered=SPF_Fiscal_Batch_Beta_Manager::recover_ticket(7);
if (is_wp_error($recovered) || ($recovered['ticket']??'')!=='TICKET-123') {
    fwrite(STDERR,"Ticket recovery failed\n"); exit(1);
}
if (($fake_batch['remote_ticket']??'')!=='TICKET-123' || ($fake_batch['beta_status']??'')!=='beta_batch_ticketed') {
    fwrite(STDERR,"Recovered ticket was not bound to batch\n"); exit(1);
}
$events=SPF_Fiscal_Batch_Remote_Event_Repository::latest(7,10);
$ops=array_column($events,'operation');
if (!in_array('ticket_recovered',$ops,true) || !in_array('ticket_received',$ops,true) || !in_array('submit_intent',$ops,true)) {
    fwrite(STDERR,"Append-only recovery event chain incomplete\n"); exit(1);
}

$invalid=SPF_Fiscal_Batch_Remote_Event_Repository::record(7,'bad','not-a-sha',[]);
if (!is_wp_error($invalid)) { fwrite(STDERR,"Invalid evidence hash was accepted\n"); exit(1); }

echo "fiscal-batch-remote-ledger-smoke: ok\n";
