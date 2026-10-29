<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Config {
    const COUNTRY_OPTION = 'spf_country';

    public static function country_code() {
        $code = strtolower(sanitize_key((string) get_option(self::COUNTRY_OPTION, 'pe')));
        return SPF_Country_Registry::exists($code) ? $code : 'pe';
    }
    public static function country() {
        $country = SPF_Country_Registry::get(self::country_code());
        return $country ?: SPF_Country_Registry::get('pe');
    }
    public static function get($path, $default = null) {
        $value = self::country();
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) { return $default; }
            $value = $value[$segment];
        }
        return $value;
    }
    public static function money($amount) {
        $symbol = (string) self::get('currency.symbol', '$');
        $position = (string) self::get('currency.position', 'before');
        $decimals = (int) self::get('currency.decimals', 2);
        $formatted = number_format_i18n((float) $amount, $decimals);
        return $position === 'after' ? $formatted . ' ' . $symbol : $symbol . ' ' . $formatted;
    }
    public static function tax_rate() { return (float) self::get('tax.rate', 0); }
    public static function tax_name() { return (string) self::get('tax.name', __('Impuesto', 'sistema-para-farmacias')); }
    public static function tax_classes() { $v=self::get('tax_classes',[]); return is_array($v)?$v:[]; }
    public static function default_tax_class() {
        $default=sanitize_key((string)self::get('default_tax_class','')); $classes=self::tax_classes();
        if($default && array_key_exists($default,$classes)){ return $default; }
        return $classes ? (string)array_key_first($classes) : 'standard';
    }
    public static function sales_documents() { $v=self::get('sales_documents',[]); return is_array($v)?$v:[]; }
    public static function default_sales_document() { $v=self::sales_documents(); return $v?(string)array_key_first($v):'sale'; }
    public static function payment_methods() { $v=self::get('payment_methods',[]); return is_array($v)?$v:[]; }
    public static function default_payment_method() { $v=self::payment_methods(); return $v?(string)array_key_first($v):'cash'; }
    public static function person_documents() { $v=self::get('person_documents',[]); return is_array($v)?$v:[]; }
    public static function business_documents() { $v=self::get('business_documents',[]); return is_array($v)?$v:[]; }
}
