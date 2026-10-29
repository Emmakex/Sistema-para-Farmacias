<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Adapter_Registry {
    private static $adapters = [];

    public static function register(SPF_Fiscal_Adapter_Interface $adapter) {
        $code = sanitize_key((string) $adapter->code());
        if ($code === '') {
            return false;
        }
        self::$adapters[$code] = $adapter;
        return true;
    }

    public static function exists($code) {
        return isset(self::$adapters[sanitize_key((string) $code)]);
    }

    public static function get($code) {
        $code = sanitize_key((string) $code);
        return self::$adapters[$code] ?? null;
    }

    public static function all() {
        return self::$adapters;
    }
}
