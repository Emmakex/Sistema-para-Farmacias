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

        if (get_option('spf_install_version', '') === '') {
            add_option('spf_install_version', SPF_VERSION);
        } else {
            update_option('spf_install_version', SPF_VERSION);
        }
    }
}
