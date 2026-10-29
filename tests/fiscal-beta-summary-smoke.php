<?php
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
define('SPF_VERSION', 'ci');
define('DAY_IN_SECONDS', 86400);

class WP_Error {
    private $code; private $message;
    public function __construct($code, $message) { $this->code=$code; $this->message=$message; }
    public function get_error_code(){ return $this->code; }
    public function get_error_message(){ return $this->message; }
}
function is_wp_error($value){ return $value instanceof WP_Error; }
function __($text, $domain=null){ return $text; }
function sanitize_text_field($value){ return trim(strip_tags((string)$value)); }
function wp_date($format){ return date($format, strtotime('2026-10-29 10:00:00 UTC')); }

require_once __DIR__ . '/../includes/fiscal/interface-spf-fiscal-transport.php';
require_once __DIR__ . '/../includes/fiscal/interface-spf-fiscal-async-batch-transport.php';
require_once __DIR__ . '/../includes/countries/peru/class-spf-fiscal-transport-peru-beta.php';

$transport = new SPF_Fiscal_Transport_Peru_Beta();
if ($transport->batch_enabled()) {
    fwrite(STDERR, "Summary beta must be disabled by default\n"); exit(1);
}
if (!in_array('async_batch', $transport->capabilities(), true)) {
    fwrite(STDERR, "Async batch capability missing\n"); exit(1);
}

$package = [
    'country'=>'pe',
    'batch_type'=>'daily_summary',
    'identifier'=>'RC-20261029-00001',
    'reference_date'=>'2026-10-29',
    'issuer_tax_id'=>'20123456789',
    'xml'=>'<SummaryDocuments/>',
    'xml_sha256'=>str_repeat('a',64),
    'signed'=>true,
    'signature_verified'=>true,
    'validation_status'=>'valid_local',
    'validation_xml_sha256'=>str_repeat('a',64),
];
$inspection = $transport->inspect_batch($package);
if (empty($inspection['ok']) || empty($inspection['warnings'])) {
    fwrite(STDERR, "Valid daily summary should be inspectable but warn while disabled\n"); exit(1);
}

$disabled = $transport->submit_batch($package);
if (!is_wp_error($disabled) || $disabled->get_error_code() !== 'spf_beta_summary_disabled') {
    fwrite(STDERR, "Disabled summary beta must fail closed before network access\n"); exit(1);
}
$pollDisabled = $transport->poll_batch(['issuer_tax_id'=>'20123456789','ticket'=>'TEST-TICKET']);
if (!is_wp_error($pollDisabled) || $pollDisabled->get_error_code() !== 'spf_beta_summary_disabled') {
    fwrite(STDERR, "Disabled summary polling must fail closed before network access\n"); exit(1);
}

$mismatch = $package;
$mismatch['validation_xml_sha256'] = str_repeat('b',64);
if (!empty($transport->inspect_batch($mismatch)['ok'])) {
    fwrite(STDERR, "Signed/validated hash mismatch was accepted\n"); exit(1);
}

$old = $package;
$old['reference_date'] = '2026-10-21';
if (!empty($transport->inspect_batch($old)['ok'])) {
    fwrite(STDERR, "Daily summary outside seven-day window was accepted\n"); exit(1);
}

$future = $package;
$future['reference_date'] = '2026-10-30';
if (!empty($transport->inspect_batch($future)['ok'])) {
    fwrite(STDERR, "Future daily summary was accepted\n"); exit(1);
}

$invalidIdentifier = $package;
$invalidIdentifier['identifier'] = 'RC-BAD';
if (!empty($transport->inspect_batch($invalidIdentifier)['ok'])) {
    fwrite(STDERR, "Invalid daily summary identifier was accepted\n"); exit(1);
}

echo "fiscal-beta-summary-smoke: ok\n";
