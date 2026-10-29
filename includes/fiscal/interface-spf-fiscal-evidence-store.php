<?php
if (!defined('ABSPATH')) { exit; }

interface SPF_Fiscal_Evidence_Store_Interface {
    public function code();
    public function available();
    public function store($relative_path, $bytes);
    public function verify($relative_path, $expected_sha256);
}
