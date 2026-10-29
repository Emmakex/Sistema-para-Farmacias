<?php

if (!defined('ABSPATH')) {
    exit;
}

final class SPF_Activator {
    public static function activate() {
        SPF_Country_Peru::register();

        if (!SPF_Country_Registry::exists(get_option(SPF_Config::COUNTRY_OPTION, ''))) {
            update_option(SPF_Config::COUNTRY_OPTION, 'pe');
        }

        self::create_inventory_tables();
        SPF_Roles::ensure_roles();
        update_option('spf_install_version', SPF_VERSION);
    }

    private static function create_inventory_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = SPF_Database::charset_collate();
        $products = SPF_Database::table('products');
        $lots = SPF_Database::table('lots');
        $movements = SPF_Database::table('stock_movements');

        dbDelta("CREATE TABLE $products (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            barcode VARCHAR(80) NULL,
            name VARCHAR(255) NOT NULL,
            active_ingredient VARCHAR(255) NULL,
            laboratory VARCHAR(160) NULL,
            category VARCHAR(160) NULL,
            purchase_price DECIMAL(12,2) DEFAULT 0,
            sale_price DECIMAL(12,2) DEFAULT 0,
            tax_type VARCHAR(30) DEFAULT '',
            stock_min INT DEFAULT 0,
            unit VARCHAR(40) DEFAULT 'unidad',
            status VARCHAR(30) DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY barcode (barcode),
            KEY name (name(191))
        ) $charset;");

        dbDelta("CREATE TABLE $lots (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            lot_number VARCHAR(120) NULL,
            expiry_date DATE NULL,
            quantity INT DEFAULT 0,
            purchase_price DECIMAL(12,2) DEFAULT 0,
            supplier VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY expiry_date (expiry_date)
        ) $charset;");

        dbDelta("CREATE TABLE $movements (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            lot_id BIGINT UNSIGNED NULL,
            movement_type VARCHAR(40) NOT NULL,
            quantity INT NOT NULL,
            previous_stock INT DEFAULT 0,
            new_stock INT DEFAULT 0,
            reason TEXT NULL,
            user_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY lot_id (lot_id),
            KEY movement_type (movement_type)
        ) $charset;");
    }
}
