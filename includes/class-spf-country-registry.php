<?php

if (!defined('ABSPATH')) {
    exit;
}

final class SPF_Country_Registry {
    /** @var array<string,array<string,mixed>> */
    private static $countries = [];

    public static function register($code, array $profile) {
        $code = strtolower(sanitize_key($code));
        if ($code === '') {
            return;
        }

        $defaults = [
            'code' => $code,
            'name' => strtoupper($code),
            'locale' => 'es_ES',
            'currency' => [
                'code' => 'USD',
                'symbol' => '$',
                'position' => 'before',
                'decimals' => 2,
            ],
            'tax' => [
                'name' => __('Impuesto', 'sistema-para-farmacias'),
                'rate' => 0,
                'included_in_price' => true,
            ],
            'person_documents' => [],
            'business_documents' => [],
            'payment_methods' => [],
            'electronic_invoicing' => [
                'enabled' => false,
                'authority' => '',
                'adapter' => '',
            ],
        ];

        self::$countries[$code] = array_replace_recursive($defaults, $profile);
    }

    public static function all() {
        $countries = apply_filters('spf_countries', self::$countries);
        return is_array($countries) ? $countries : self::$countries;
    }

    public static function get($code) {
        $countries = self::all();
        $code = strtolower(sanitize_key($code));
        return isset($countries[$code]) ? $countries[$code] : null;
    }

    public static function exists($code) {
        return self::get($code) !== null;
    }
}
