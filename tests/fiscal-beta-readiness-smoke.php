<?php
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!function_exists('current_time')) { function current_time($type) { return $type === 'mysql' ? '2026-10-29 12:00:00' : time(); } }

require_once dirname(__DIR__) . '/includes/class-spf-fiscal-beta-readiness.php';

function spf_ready_check($ready) {
    return ['label'=>'x','ready'=>(bool)$ready,'message'=>''];
}

$checks = [
    'common'=>spf_ready_check(true),
    'invoice_only'=>spf_ready_check(true),
    'batch_only'=>spf_ready_check(false),
];
$report = SPF_Fiscal_Beta_Readiness::summarize($checks, ['common','invoice_only'], ['common','batch_only']);
if (empty($report['invoice']['ready'])) { fwrite(STDERR, "Invoice scope should be ready\n"); exit(1); }
if (!empty($report['batch']['ready'])) { fwrite(STDERR, "Batch scope should be blocked\n"); exit(1); }
if (!empty($report['ready'])) { fwrite(STDERR, "Global readiness should be false while batch is blocked\n"); exit(1); }
if (($report['batch']['failed'] ?? []) !== ['batch_only']) { fwrite(STDERR, "Batch failure set is not deterministic\n"); exit(1); }

$checks['batch_only'] = spf_ready_check(true);
$checks['invoice_only'] = spf_ready_check(false);
$report = SPF_Fiscal_Beta_Readiness::summarize($checks, ['common','invoice_only'], ['common','batch_only']);
if (!empty($report['invoice']['ready'])) { fwrite(STDERR, "Invoice scope should be blocked independently\n"); exit(1); }
if (empty($report['batch']['ready'])) { fwrite(STDERR, "Batch scope should remain ready independently\n"); exit(1); }

$checks['invoice_only'] = spf_ready_check(true);
$report = SPF_Fiscal_Beta_Readiness::summarize($checks, ['common','invoice_only'], ['common','batch_only']);
if (empty($report['ready']) || empty($report['invoice']['ready']) || empty($report['batch']['ready'])) {
    fwrite(STDERR, "All scopes should be ready when every required check passes\n");
    exit(1);
}

echo "Fiscal beta readiness smoke OK\n";
