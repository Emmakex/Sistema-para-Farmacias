<?php
if (!defined('ABSPATH')) { exit; }

interface SPF_Fiscal_Async_Batch_Transport_Interface {
    public function batch_enabled();
    public function inspect_batch(array $package);
    public function submit_batch(array $package);
    public function poll_batch(array $package);
}
