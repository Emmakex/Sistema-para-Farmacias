<?php

if (!defined('ABSPATH')) {
    exit;
}

final class SPF_Country_Peru {
    public static function register() {
        SPF_Country_Registry::register('pe', [
            'name' => __('Perú', 'sistema-para-farmacias'),
            'locale' => 'es_PE',
            'currency' => [
                'code' => 'PEN',
                'symbol' => 'S/',
                'position' => 'before',
                'decimals' => 2,
            ],
            'tax' => [
                'name' => 'IGV',
                'rate' => 18.0,
                'included_in_price' => true,
            ],
            'person_documents' => [
                'DNI' => __('DNI', 'sistema-para-farmacias'),
                'CE' => __('Carné de extranjería', 'sistema-para-farmacias'),
                'PASSPORT' => __('Pasaporte', 'sistema-para-farmacias'),
            ],
            'business_documents' => [
                'RUC' => __('RUC', 'sistema-para-farmacias'),
            ],
            'payment_methods' => [
                'cash' => __('Efectivo', 'sistema-para-farmacias'),
                'card' => __('Tarjeta', 'sistema-para-farmacias'),
                'yape' => 'Yape',
                'plin' => 'Plin',
                'transfer' => __('Transferencia', 'sistema-para-farmacias'),
                'mixed' => __('Mixto', 'sistema-para-farmacias'),
            ],
            'sales_documents' => [
                'receipt' => __('Boleta', 'sistema-para-farmacias'),
                'invoice' => __('Factura', 'sistema-para-farmacias'),
            ],
            'electronic_invoicing' => [
                'enabled' => true,
                'authority' => 'SUNAT',
                'adapter' => 'peru',
            ],
            'defaults' => [
                'receipt_series' => 'B001',
                'invoice_series' => 'F001',
            ],
        ]);
    }
}
