<?php
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');

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

require_once __DIR__ . '/../includes/fiscal/interface-spf-fiscal-signer.php';
require_once __DIR__ . '/../includes/fiscal/class-spf-fiscal-xmldsig-signer.php';

if (!extension_loaded('openssl') || !class_exists('DOMDocument')) {
    fwrite(STDERR, "OpenSSL and DOM are required\n"); exit(1);
}

$key = openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA,'digest_alg'=>'sha256']);
if (!$key) { fwrite(STDERR, "Cannot create key\n"); exit(1); }
$csr = openssl_csr_new(['commonName'=>'Sistema para Farmacias CI'], $key, ['digest_alg'=>'sha256']);
$cert = openssl_csr_sign($csr, null, $key, 2, ['digest_alg'=>'sha256']);
openssl_pkey_export($key, $privatePem);
openssl_x509_export($cert, $certPem);

$xml = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" '
    . 'xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" '
    . 'xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
    . '<cbc:UBLVersionID>2.1</cbc:UBLVersionID>'
    . '<cbc:CustomizationID>2.0</cbc:CustomizationID>'
    . '<cbc:ID>F001-00000001</cbc:ID>'
    . '<cbc:DocumentCurrencyCode>PEN</cbc:DocumentCurrencyCode>'
    . '<cac:AccountingSupplierParty><cac:Party><cac:PartyTaxScheme><cbc:RegistrationName>CI EMISOR</cbc:RegistrationName></cac:PartyTaxScheme></cac:Party></cac:AccountingSupplierParty>'
    . '<cac:LegalMonetaryTotal><cbc:PayableAmount currencyID="PEN">100.00</cbc:PayableAmount></cac:LegalMonetaryTotal>'
    . '</Invoice>';

$profile = [
    'signature_method'=>'rsa_sha256',
    'digest_method'=>'sha256',
    'canonicalization_with_comments'=>true,
    'signature_id'=>'signatureSPF',
];
$credentials = ['certificate_pem'=>$certPem,'private_key_pem'=>$privatePem];
$context = ['issuer'=>['tax_id'=>'20123456789','legal_name'=>'CI EMISOR']];
$signer = new SPF_Fiscal_XMLDSig_Signer();
$result = $signer->sign($xml, $credentials, $profile, $context);
if (is_wp_error($result)) { fwrite(STDERR, 'Sign failed: '.$result->get_error_message()."\n"); exit(1); }
if (empty($result['verified'])) { fwrite(STDERR, "Signature not verified\n"); exit(1); }
if (($result['signature_method'] ?? '') !== SPF_Fiscal_XMLDSig_Signer::RSA_SHA256_URI) { fwrite(STDERR, "Unexpected signature method\n"); exit(1); }
if (($result['digest_method'] ?? '') !== SPF_Fiscal_XMLDSig_Signer::SHA256_URI) { fwrite(STDERR, "Unexpected digest method\n"); exit(1); }
if (strpos($result['xml'], '<ext:UBLExtensions') === false || strpos($result['xml'], '<cac:Signature>') === false) { fwrite(STDERR, "UBL signature containers missing\n"); exit(1); }

$verified = $signer->verify($result['xml'], $profile);
if (is_wp_error($verified) || empty($verified['ok'])) { fwrite(STDERR, "Independent verification failed\n"); exit(1); }

$tampered = str_replace('100.00', '101.00', $result['xml']);
$tamperedResult = $signer->verify($tampered, $profile);
if (!is_wp_error($tamperedResult)) { fwrite(STDERR, "Tampered XML was incorrectly accepted\n"); exit(1); }

echo "fiscal-signature-smoke: ok\n";
