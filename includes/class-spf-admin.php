<?php

if (!defined('ABSPATH')) {
    exit;
}

final class SPF_Admin {
    public function hooks() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'save_settings']);
    }

    public function menu() {
        add_menu_page(
            __('Sistema para Farmacias', 'sistema-para-farmacias'),
            __('Sistema para Farmacias', 'sistema-para-farmacias'),
            'manage_options',
            'sistema-para-farmacias',
            [$this, 'render_settings'],
            'dashicons-store',
            26
        );
    }

    public function save_settings() {
        if (!isset($_POST['spf_save_country'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para cambiar esta configuración.', 'sistema-para-farmacias'));
        }

        check_admin_referer('spf_save_country');
        $country = strtolower(sanitize_key(wp_unslash($_POST['spf_country'] ?? '')));

        if (!SPF_Country_Registry::exists($country)) {
            add_settings_error('spf_country', 'spf_invalid_country', __('El país seleccionado no está soportado.', 'sistema-para-farmacias'));
            return;
        }

        update_option(SPF_Config::COUNTRY_OPTION, $country);
        add_settings_error('spf_country', 'spf_country_saved', __('País guardado correctamente.', 'sistema-para-farmacias'), 'updated');
    }

    public function render_settings() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $countries = SPF_Country_Registry::all();
        $current = SPF_Config::country_code();
        $profile = SPF_Config::country();
        settings_errors('spf_country');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Sistema para Farmacias', 'sistema-para-farmacias'); ?></h1>
            <p><?php echo esc_html__('Configura el país operativo. El núcleo del plugin permanece neutral y cada país aporta moneda, impuestos, documentos, pagos y facturación electrónica.', 'sistema-para-farmacias'); ?></p>

            <form method="post">
                <?php wp_nonce_field('spf_save_country'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="spf_country"><?php echo esc_html__('País', 'sistema-para-farmacias'); ?></label></th>
                        <td>
                            <select name="spf_country" id="spf_country">
                                <?php foreach ($countries as $code => $country) : ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php selected($current, $code); ?>><?php echo esc_html($country['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php echo esc_html__('Perú es la primera localización soportada. Nuevos países se añadirán como perfiles/adaptadores sin modificar el core.', 'sistema-para-farmacias'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Guardar país', 'sistema-para-farmacias'), 'primary', 'spf_save_country'); ?>
            </form>

            <hr>
            <h2><?php echo esc_html__('Perfil activo', 'sistema-para-farmacias'); ?></h2>
            <table class="widefat striped" style="max-width:760px">
                <tbody>
                    <tr><td><strong><?php echo esc_html__('País', 'sistema-para-farmacias'); ?></strong></td><td><?php echo esc_html($profile['name']); ?></td></tr>
                    <tr><td><strong><?php echo esc_html__('Moneda', 'sistema-para-farmacias'); ?></strong></td><td><?php echo esc_html($profile['currency']['code'] . ' (' . $profile['currency']['symbol'] . ')'); ?></td></tr>
                    <tr><td><strong><?php echo esc_html__('Impuesto', 'sistema-para-farmacias'); ?></strong></td><td><?php echo esc_html($profile['tax']['name'] . ' ' . $profile['tax']['rate'] . '%'); ?></td></tr>
                    <tr><td><strong><?php echo esc_html__('Autoridad fiscal', 'sistema-para-farmacias'); ?></strong></td><td><?php echo esc_html($profile['electronic_invoicing']['authority'] ?: '—'); ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}
