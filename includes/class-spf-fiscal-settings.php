<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Settings {
    public static function option_key() {
        return 'spf_fiscal_issuer_' . sanitize_key(SPF_Config::country_code());
    }

    public static function issuer() {
        $stored = get_option(self::option_key(), []);
        $stored = is_array($stored) ? $stored : [];
        $defaults = [
            'tax_id' => '',
            'legal_name' => '',
            'trade_name' => '',
            'address' => '',
            'district' => '',
            'province' => '',
            'region' => '',
            'postal_code' => '',
            'country_code' => strtoupper(SPF_Config::country_code()),
        ];
        return apply_filters('spf_fiscal_issuer_identity', wp_parse_args($stored, $defaults), SPF_Config::country_code());
    }

    public static function save_issuer(array $input) {
        $clean = [
            'tax_id' => preg_replace('/[^A-Za-z0-9]/', '', (string) ($input['tax_id'] ?? '')),
            'legal_name' => sanitize_text_field($input['legal_name'] ?? ''),
            'trade_name' => sanitize_text_field($input['trade_name'] ?? ''),
            'address' => sanitize_text_field($input['address'] ?? ''),
            'district' => sanitize_text_field($input['district'] ?? ''),
            'province' => sanitize_text_field($input['province'] ?? ''),
            'region' => sanitize_text_field($input['region'] ?? ''),
            'postal_code' => sanitize_text_field($input['postal_code'] ?? ''),
            'country_code' => strtoupper(sanitize_key(SPF_Config::country_code())),
        ];
        update_option(self::option_key(), $clean, false);
        return $clean;
    }
}
