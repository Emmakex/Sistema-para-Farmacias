<?php

if (!defined('ABSPATH')) {
    exit;
}

final class SPF_Tax {
    public static function classes() { return SPF_Config::tax_classes(); }
    public static function default_class() { return SPF_Config::default_tax_class(); }

    public static function calculate_from_total($total, $tax_type = null) {
        $total = round((float) $total, 2);
        $tax_type = $tax_type ?: self::default_class();
        $classes = self::classes();
        $class = isset($classes[$tax_type]) && is_array($classes[$tax_type]) ? $classes[$tax_type] : [];
        $rate = isset($class['rate']) ? (float) $class['rate'] : SPF_Config::tax_rate();
        $taxable = array_key_exists('taxable', $class) ? (bool) $class['taxable'] : ($rate > 0);
        $included = array_key_exists('included_in_price', $class) ? (bool) $class['included_in_price'] : (bool) SPF_Config::get('tax.included_in_price', true);

        if (!$taxable || $rate <= 0) {
            return ['subtotal'=>$total,'tax'=>0.00,'total'=>$total,'tax_name'=>SPF_Config::tax_name(),'tax_rate'=>0.0];
        }

        $fraction = $rate / 100;
        if ($included) {
            $subtotal = $total / (1 + $fraction);
            $tax = $total - $subtotal;
        } else {
            $subtotal = $total;
            $tax = $subtotal * $fraction;
            $total = $subtotal + $tax;
        }

        return ['subtotal'=>round($subtotal,2),'tax'=>round($tax,2),'total'=>round($total,2),'tax_name'=>SPF_Config::tax_name(),'tax_rate'=>$rate];
    }
}
