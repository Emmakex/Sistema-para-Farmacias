<?php
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);

class WP_Error {
    private $code; private $message;
    public function __construct($code,$message){$this->code=$code;$this->message=$message;}
    public function get_error_code(){return $this->code;}
    public function get_error_message(){return $this->message;}
}
function is_wp_error($value){return $value instanceof WP_Error;}
function __($text,$domain=null){return $text;}
function sanitize_key($key){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$key));}
function wp_date($format){return date($format);}

require_once __DIR__.'/../includes/fiscal/interface-spf-fiscal-batch-builder.php';
require_once __DIR__.'/../includes/countries/peru/class-spf-fiscal-batch-builder-peru-summary.php';

$builder = new SPF_Fiscal_Batch_Builder_Peru_Summary();
$context = [
    'batch_type'=>'daily_summary',
    'country_code'=>'pe',
    'currency'=>'PEN',
    'issuer'=>['tax_id'=>'20123456789','legal_name'=>'FARMACIA DEMO SAC'],
    'reference_date'=>date('Y-m-d'),
    'issue_date'=>date('Y-m-d'),
    'sequence'=>1,
    'lines'=>[
        [
            'fiscal_type_code'=>'03','series'=>'B001','correlative'=>'00000001',
            'customer_document_type'=>'DNI','customer_document_number'=>'12345678',
            'total'=>118.00,'tax'=>18.00,'tax_buckets'=>['gravado'=>100.00],'condition_code'=>'1',
        ],
        [
            'fiscal_type_code'=>'03','series'=>'B001','correlative'=>'00000002',
            'customer_document_type'=>'','customer_document_number'=>'',
            'total'=>50.00,'tax'=>0.00,'tax_buckets'=>['exonerado'=>30.00,'inafecto'=>20.00],'condition_code'=>'1',
        ],
    ],
];
$result = $builder->build($context);
if (is_wp_error($result)) { fwrite(STDERR,'Build failed: '.$result->get_error_message()."\n"); exit(1); }
$xml = (string)$result['xml'];
$required = [
    'urn:sunat:names:specification:ubl:peru:schema:xsd:SummaryDocuments-1',
    '<cbc:UBLVersionID>2.0</cbc:UBLVersionID>',
    '<cbc:CustomizationID>1.1</cbc:CustomizationID>',
    '<cbc:DocumentTypeCode>03</cbc:DocumentTypeCode>',
    '<cbc:ConditionCode>1</cbc:ConditionCode>',
    '<cbc:InstructionID>01</cbc:InstructionID>',
    '<cbc:InstructionID>02</cbc:InstructionID>',
    '<cbc:InstructionID>03</cbc:InstructionID>',
    '<cbc:CustomerAssignedAccountID>-</cbc:CustomerAssignedAccountID>',
    '<cbc:AdditionalAccountID>-</cbc:AdditionalAccountID>',
    '<cbc:ID>1000</cbc:ID>',
    '<cbc:Name>IGV</cbc:Name>',
];
foreach ($required as $needle) { if (strpos($xml,$needle)===false) { fwrite(STDERR,"Missing XML fragment: $needle\n"); exit(1); } }
if (($result['line_count']??0)!==2) { fwrite(STDERR,"Unexpected line count\n"); exit(1); }
if (!preg_match('/^RC-\d{8}-00001$/',(string)$result['identifier'])) { fwrite(STDERR,"Unexpected summary identifier\n"); exit(1); }
if (strpos($xml,'<cbc:PaidAmount currencyID="PEN">100.00</cbc:PaidAmount>')===false) { fwrite(STDERR,"Taxable bucket missing\n"); exit(1); }
if (strpos($xml,'<cbc:TaxAmount currencyID="PEN">18.00</cbc:TaxAmount>')===false) { fwrite(STDERR,"IGV total missing\n"); exit(1); }

echo "fiscal-daily-summary-smoke: ok\n";
