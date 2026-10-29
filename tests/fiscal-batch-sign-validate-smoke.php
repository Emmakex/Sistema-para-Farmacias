<?php
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);

class WP_Error {
    private $code; private $message;
    public function __construct($code, $message) { $this->code=$code; $this->message=$message; }
    public function get_error_code(){ return $this->code; }
    public function get_error_message(){ return $this->message; }
}
function is_wp_error($value){ return $value instanceof WP_Error; }
function __($text, $domain=null){ return $text; }
function sanitize_key($key){ return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$key)); }
function sanitize_html_class($class){ return preg_replace('/[^A-Za-z0-9_-]/','',(string)$class); }
function sanitize_file_name($name){ return preg_replace('/[^A-Za-z0-9._-]/','-',(string)$name); }
function sanitize_text_field($value){ return trim(strip_tags((string)$value)); }
function current_time($type){ return $type === 'mysql' ? '2026-10-29 10:00:00' : time(); }
function wp_date($format){ return date($format, strtotime('2026-10-29 10:00:00 UTC')); }

require_once __DIR__ . '/../includes/fiscal/interface-spf-fiscal-batch-builder.php';
require_once __DIR__ . '/../includes/countries/peru/class-spf-fiscal-batch-builder-peru-summary.php';
require_once __DIR__ . '/../includes/fiscal/interface-spf-fiscal-signer.php';
require_once __DIR__ . '/../includes/fiscal/class-spf-fiscal-xmldsig-signer.php';
require_once __DIR__ . '/../includes/fiscal/interface-spf-fiscal-validator.php';
require_once __DIR__ . '/../includes/fiscal/class-spf-fiscal-xsd-xsl-validator.php';

if (!extension_loaded('openssl') || !class_exists('DOMDocument') || !class_exists('XSLTProcessor')) {
    fwrite(STDERR, "OpenSSL, DOM and XSL are required\n"); exit(1);
}

$context = [
    'batch_type'=>'daily_summary',
    'country_code'=>'pe',
    'currency'=>'PEN',
    'reference_date'=>'2026-10-29',
    'issue_date'=>'2026-10-29',
    'sequence'=>1,
    'issuer'=>['tax_id'=>'20123456789','legal_name'=>'CI FARMACIA'],
    'lines'=>[
        [
            'document_id'=>10,'sale_id'=>20,'document_type'=>'boleta','fiscal_type_code'=>'03',
            'series'=>'B001','correlative'=>'00000001','customer_document_type'=>'DNI','customer_document_number'=>'12345678',
            'subtotal'=>100.00,'tax'=>18.00,'total'=>118.00,'tax_buckets'=>['gravado'=>100.00],'condition_code'=>'1',
        ],
        [
            'document_id'=>11,'sale_id'=>21,'document_type'=>'boleta','fiscal_type_code'=>'03',
            'series'=>'B001','correlative'=>'00000002','customer_document_type'=>'','customer_document_number'=>'',
            'subtotal'=>50.00,'tax'=>0.00,'total'=>50.00,'tax_buckets'=>['exonerado'=>30.00,'inafecto'=>20.00],'condition_code'=>'1',
        ],
    ],
];

$builder = new SPF_Fiscal_Batch_Builder_Peru_Summary();
$built = $builder->build($context);
if (is_wp_error($built)) { fwrite(STDERR, 'Build failed: '.$built->get_error_message()."\n"); exit(1); }
if (($built['identifier'] ?? '') !== 'RC-20261029-00001') { fwrite(STDERR, "Unexpected batch identifier\n"); exit(1); }
if (strpos($built['xml'], 'SummaryDocuments') === false || strpos($built['xml'], '<cbc:CustomizationID>1.1</cbc:CustomizationID>') === false) {
    fwrite(STDERR, "SummaryDocuments contract missing\n"); exit(1);
}

$key = openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA,'digest_alg'=>'sha256']);
if (!$key) { fwrite(STDERR, "Cannot create key\n"); exit(1); }
$csr = openssl_csr_new(['commonName'=>'Sistema para Farmacias Batch CI'], $key, ['digest_alg'=>'sha256']);
$cert = openssl_csr_sign($csr, null, $key, 2, ['digest_alg'=>'sha256']);
openssl_pkey_export($key, $privatePem);
openssl_x509_export($cert, $certPem);

$profile = [
    'signature_method'=>'rsa_sha256',
    'digest_method'=>'sha256',
    'canonicalization_with_comments'=>true,
    'signature_id'=>'signatureSPFBatch',
];
$credentials = ['certificate_pem'=>$certPem,'private_key_pem'=>$privatePem];
$signer = new SPF_Fiscal_XMLDSig_Signer();
$signed = $signer->sign($built['xml'], $credentials, $profile, $context);
if (is_wp_error($signed)) { fwrite(STDERR, 'Sign failed: '.$signed->get_error_message()."\n"); exit(1); }
if (empty($signed['verified']) || strpos($signed['xml'], '<ext:UBLExtensions') === false || strpos($signed['xml'], '<cac:Signature>') === false) {
    fwrite(STDERR, "Signed SummaryDocuments containers missing\n"); exit(1);
}
$verified = $signer->verify($signed['xml'], $profile);
if (is_wp_error($verified) || empty($verified['ok'])) { fwrite(STDERR, "Batch signature verification failed\n"); exit(1); }

$tampered = str_replace('118.00', '119.00', $signed['xml']);
if (!is_wp_error($signer->verify($tampered, $profile))) { fwrite(STDERR, "Tampered batch was accepted\n"); exit(1); }

$xsdPath = tempnam(sys_get_temp_dir(), 'spf-xsd-');
$xslPath = tempnam(sys_get_temp_dir(), 'spf-xsl-');
$xsd = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="urn:sunat:names:specification:ubl:peru:schema:xsd:SummaryDocuments-1" elementFormDefault="qualified">'
    . '<xs:element name="SummaryDocuments"><xs:complexType><xs:sequence><xs:any minOccurs="0" maxOccurs="unbounded" processContents="lax"/></xs:sequence></xs:complexType></xs:element>'
    . '</xs:schema>';
$xsl = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">'
    . '<xsl:param name="nombreArchivoEnviado"/><xsl:output method="text"/><xsl:template match="/">OK</xsl:template>'
    . '</xsl:stylesheet>';
file_put_contents($xsdPath, $xsd);
file_put_contents($xslPath, $xsl);

$validator = new SPF_Fiscal_XSD_XSL_Validator();
$validation = $validator->validate($signed['xml'], [
    'xsd_path'=>$xsdPath,
    'xsl_path'=>$xslPath,
    'filename'=>'RC-20261029-00001.xml',
    'filename_parameter'=>'nombreArchivoEnviado',
], $context);
@unlink($xsdPath); @unlink($xslPath);
if (is_wp_error($validation)) { fwrite(STDERR, 'Validation failed: '.$validation->get_error_message()."\n"); exit(1); }
if (empty($validation['ok']) || ($validation['checks']['xsd'] ?? '') !== 'valid' || ($validation['checks']['xsl'] ?? '') !== 'valid') {
    fwrite(STDERR, "Signed batch did not pass local XSD/XSL smoke\n"); exit(1);
}

echo "fiscal-batch-sign-validate-smoke: ok\n";
