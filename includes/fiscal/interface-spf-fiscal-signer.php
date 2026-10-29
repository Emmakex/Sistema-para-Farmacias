<?php
if (!defined('ABSPATH')) { exit; }

interface SPF_Fiscal_Signer_Interface {
    public function code();
    public function supports(array $profile);
    public function sign($xml, array $credentials, array $profile, array $context = []);
    public function verify($signed_xml, array $profile = []);
}
