<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Validator_Registry {
    private static $validators = [];

    public static function register(SPF_Fiscal_Validator_Interface $validator) {
        $code = sanitize_key($validator->code());
        if ($code !== '') { self::$validators[$code] = $validator; }
    }

    public static function get($code) {
        $code = sanitize_key((string) $code);
        return self::$validators[$code] ?? null;
    }

    public static function all() { return self::$validators; }
}
