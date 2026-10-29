<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Transport_Registry {
    private static $transports = [];

    public static function register(SPF_Fiscal_Transport_Interface $transport) {
        $code = sanitize_key((string) $transport->code());
        if ($code === '') { return false; }
        self::$transports[$code] = $transport;
        return true;
    }

    public static function get($code) {
        $code = sanitize_key((string) $code);
        return self::$transports[$code] ?? null;
    }

    public static function all() { return self::$transports; }
}
