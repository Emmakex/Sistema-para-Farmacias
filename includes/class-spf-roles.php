<?php

if (!defined('ABSPATH')) {
    exit;
}

final class SPF_Roles {
    const ACCESS = 'farmapos_access';
    const SETTINGS = 'farmapos_manage_settings';
    const PRODUCTS = 'farmapos_manage_products';
    const INVENTORY = 'farmapos_manage_inventory';
    const SELL = 'farmapos_sell';
    const SALES = 'farmapos_view_sales';
    const REPORTS = 'farmapos_view_reports';
    const INVOICES = 'farmapos_manage_invoices';
    const CUSTOMERS = 'farmapos_manage_customers';
    const USERS = 'farmapos_manage_users';

    public static function all_caps() {
        return [self::ACCESS,self::SETTINGS,self::PRODUCTS,self::INVENTORY,self::SELL,self::SALES,self::REPORTS,self::INVOICES,self::CUSTOMERS,self::USERS];
    }

    public static function role_definitions() {
        return [
            'farmapos_admin' => ['name' => __('Administrador · Sistema para Farmacias', 'sistema-para-farmacias'), 'caps' => self::all_caps()],
            'farmapos_supervisor' => ['name' => __('Supervisor · Sistema para Farmacias', 'sistema-para-farmacias'), 'caps' => [self::ACCESS,self::PRODUCTS,self::INVENTORY,self::SELL,self::SALES,self::REPORTS,self::INVOICES,self::CUSTOMERS]],
            'farmapos_pharmacist' => ['name' => __('Responsable farmacéutico', 'sistema-para-farmacias'), 'caps' => [self::ACCESS,self::PRODUCTS,self::INVENTORY,self::SELL,self::SALES,self::INVOICES,self::CUSTOMERS]],
            'farmapos_cashier' => ['name' => __('Cajero · Sistema para Farmacias', 'sistema-para-farmacias'), 'caps' => [self::ACCESS,self::SELL,self::CUSTOMERS,self::SALES]],
            'farmapos_warehouse' => ['name' => __('Almacén · Sistema para Farmacias', 'sistema-para-farmacias'), 'caps' => [self::ACCESS,self::PRODUCTS,self::INVENTORY]],
        ];
    }

    public static function ensure_roles() {
        foreach (self::role_definitions() as $role_key => $role_data) {
            $caps = ['read' => true];
            foreach ($role_data['caps'] as $cap) { $caps[$cap] = true; }
            $role = get_role($role_key);
            if (!$role) { add_role($role_key, $role_data['name'], $caps); continue; }
            foreach ($caps as $cap => $grant) { $role->add_cap($cap, $grant); }
        }
        $legacy_manager = get_role('farmapos_manager');
        if ($legacy_manager) {
            $legacy_manager->add_cap('read', true);
            foreach (self::all_caps() as $cap) { $legacy_manager->add_cap($cap, true); }
        }
        $admin = get_role('administrator');
        if ($admin) { foreach (self::all_caps() as $cap) { $admin->add_cap($cap, true); } }
    }

    public static function can_access() {
        return current_user_can(self::ACCESS) || current_user_can('manage_options');
    }
}
