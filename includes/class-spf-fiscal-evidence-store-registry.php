<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Evidence_Store_Registry {
    private static $stores = [];

    public static function register(SPF_Fiscal_Evidence_Store_Interface $store) {
        self::$stores[sanitize_key((string)$store->code())] = $store;
    }

    public static function get($code) {
        $code = sanitize_key((string)$code);
        return self::$stores[$code] ?? null;
    }

    public static function all() { return self::$stores; }
}
