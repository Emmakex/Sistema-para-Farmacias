<?php
if (!defined('ABSPATH')) { exit; }

interface SPF_Fiscal_Validator_Interface {
    public function code();
    public function validate($xml, array $resources, array $context = []);
}
