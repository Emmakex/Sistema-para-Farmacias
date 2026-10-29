<?php
if (!defined('ABSPATH')) { exit; }

interface SPF_Fiscal_Batch_Builder_Interface {
    public function code();
    public function country_code();
    public function supports($batch_type);
    public function build(array $context);
}
