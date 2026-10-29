<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Credentials_Registry {
    private static $providers = [];

    public static function register(SPF_Fiscal_Credentials_Provider_Interface $provider) {
        $code = sanitize_key($provider->code());
        if ($code !== '') { self::$providers[$code] = $provider; }
    }

    public static function get($code) {
        $code = sanitize_key((string) $code);
        return self::$providers[$code] ?? null;
    }

    public static function all() { return self::$providers; }
}
