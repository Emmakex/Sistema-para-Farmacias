<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Signer_Registry {
    private static $signers = [];

    public static function register(SPF_Fiscal_Signer_Interface $signer) {
        $code = sanitize_key($signer->code());
        if ($code !== '') { self::$signers[$code] = $signer; }
    }

    public static function get($code) {
        $code = sanitize_key((string) $code);
        return self::$signers[$code] ?? null;
    }

    public static function all() { return self::$signers; }
}
