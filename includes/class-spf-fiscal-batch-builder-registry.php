<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Batch_Builder_Registry {
    private static $builders = [];

    public static function register(SPF_Fiscal_Batch_Builder_Interface $builder) {
        self::$builders[sanitize_key((string)$builder->code())] = $builder;
    }

    public static function get($code) {
        $code = sanitize_key((string)$code);
        return self::$builders[$code] ?? null;
    }

    public static function all() { return self::$builders; }
}
