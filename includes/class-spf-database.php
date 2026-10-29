<?php

if (!defined('ABSPATH')) {
    exit;
}

final class SPF_Database {
    /**
     * Storage keeps the historical farmapos_ prefix during the compatibility
     * window so existing installations can adopt Sistema para Farmacias
     * without copying or losing operational data.
     */
    const STORAGE_PREFIX = 'farmapos_';

    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . self::STORAGE_PREFIX . sanitize_key($name);
    }

    public static function charset_collate() {
        global $wpdb;
        return $wpdb->get_charset_collate();
    }
}
