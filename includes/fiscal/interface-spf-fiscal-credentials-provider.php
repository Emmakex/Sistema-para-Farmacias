<?php
if (!defined('ABSPATH')) { exit; }

interface SPF_Fiscal_Credentials_Provider_Interface {
    public function code();
    public function load(array $context = []);
}
