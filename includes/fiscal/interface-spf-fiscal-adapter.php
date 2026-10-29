<?php
if (!defined('ABSPATH')) { exit; }

interface SPF_Fiscal_Adapter_Interface {
    public function code();
    public function country_code();
    public function supports($document_type);
    public function validate(array $context);
    public function build_preview_xml(array $context);
    public function normalize_remote_status($status, $message = '');
}
