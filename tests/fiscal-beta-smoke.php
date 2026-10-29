<?php
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
define('SPF_VERSION', 'ci');

class WP_Error {
    private $code; private $message;
    public function __construct($code, $message) { $this->code=$code; $this->message=$message; }
    public function get_error_code(){ return $this->code; }
    public function get_error_message(){ return $this->message; }
}
function is_wp_error($value){ return $value instanceof WP_Error; }
function __($text, $domain=null){ return $text; }
function sanitize_text_field($value){ return trim(strip_tags((string)$value)); }

require_once __DIR__ . '/../includes/fiscal/interface-spf-fiscal-transport.php';
require_once __DIR__ . '/../includes/countries/peru/class-spf-fiscal-transport-peru-beta.php';

$transport = new SPF_Fiscal_Transport_Peru_Beta();
if (SPF_Fiscal_Transport_Peru_Beta::enabled()) {
    fwrite(STDERR, "Beta must be disabled by default\n"); exit(1);
}

$package = [
    'country'=>'pe',
    'document_type'=>'factura',
    'strategy'=>'single',
    'signed'=>true,
    'signature_verified'=>true,
    'validation_status'=>'valid_local',
    'issuer_tax_id'=>'20123456789',
    'fiscal_type_code'=>'01',
    'series'=>'F001',
    'correlative'=>'00000001',
    'xml'=>'<Invoice/>',
];
$inspection = $transport->inspect($package);
if (empty($inspection['ok']) || empty($inspection['warnings'])) {
    fwrite(STDERR, "Valid beta package should be inspectable but warn while disabled\n"); exit(1);
}
$disabled = $transport->transmit($package);
if (!is_wp_error($disabled) || $disabled->get_error_code() !== 'spf_beta_disabled') {
    fwrite(STDERR, "Disabled beta transport must fail closed before network access\n"); exit(1);
}

$receipt = $package;
$receipt['document_type'] = 'boleta';
$receipt['strategy'] = 'daily_summary';
$receipt['fiscal_type_code'] = '03';
$receiptInspection = $transport->inspect($receipt);
if (!empty($receiptInspection['ok'])) {
    fwrite(STDERR, "Individual receipt must remain blocked until daily summary support exists\n"); exit(1);
}

$unsigned = $package;
$unsigned['signed'] = false;
$unsigned['signature_verified'] = false;
$unsignedInspection = $transport->inspect($unsigned);
if (!empty($unsignedInspection['ok'])) {
    fwrite(STDERR, "Unsigned beta package was incorrectly accepted\n"); exit(1);
}

$unvalidated = $package;
$unvalidated['validation_status'] = 'partial';
$unvalidatedInspection = $transport->inspect($unvalidated);
if (!empty($unvalidatedInspection['ok'])) {
    fwrite(STDERR, "Partially validated beta package was incorrectly accepted\n"); exit(1);
}

echo "fiscal-beta-smoke: ok\n";
