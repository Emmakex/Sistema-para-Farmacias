<?php
if (!defined('ABSPATH')) { exit; }

interface SPF_Fiscal_Transport_Interface {
    public function code();
    public function environment();
    public function capabilities();
    public function inspect(array $package);
    public function transmit(array $package);
}
