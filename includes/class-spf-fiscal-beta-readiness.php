<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Beta_Readiness {
    public function hooks() {
        add_action('admin_notices', [$this, 'render']);
    }

    public static function report() {
        $issuer = SPF_Fiscal_Settings::issuer();
        $signer = SPF_Fiscal_Signing_Manager::active_signer();
        $provider = SPF_Fiscal_Signing_Manager::active_credentials_provider();
        $validator = SPF_Fiscal_Validation_Manager::active_validator();
        $invoice_resources = SPF_Fiscal_Validation_Manager::resources();
        $batch_resources = SPF_Fiscal_Batch_Lifecycle::validation_resources('daily_summary', ['identifier'=>'readiness']);
        $evidence = SPF_Fiscal_Evidence_Manager::status();
        $invoice_transport = SPF_Fiscal_Transport_Manager::beta_transport();
        $batch_transport = SPF_Fiscal_Batch_Beta_Manager::transport('daily_summary');
        $batch_builder = SPF_Fiscal_Batch_Manager::active_builder('daily_summary');
        $beta_profile = SPF_Fiscal_Transport_Manager::beta_profile();
        $readiness_profile = SPF_Config::get('electronic_invoicing.readiness_profile', []);
        $readiness_profile = is_array($readiness_profile) ? $readiness_profile : [];
        $issuer_profile = is_array($readiness_profile['issuer'] ?? null) ? $readiness_profile['issuer'] : [];
        $transport_runtime = is_array($readiness_profile['transport_runtime'] ?? null) ? $readiness_profile['transport_runtime'] : [];

        $checks = [];
        $checks['runtime_openssl'] = self::check('OpenSSL', extension_loaded('openssl') && function_exists('openssl_pkcs12_read'), __('Extensión OpenSSL disponible.', 'sistema-para-farmacias'), __('OpenSSL/PKCS#12 no está disponible.', 'sistema-para-farmacias'));
        $checks['runtime_dom'] = self::check('DOM', class_exists('DOMDocument') && class_exists('DOMXPath'), __('DOM/XML disponible.', 'sistema-para-farmacias'), __('DOM/XML no está disponible.', 'sistema-para-farmacias'));
        $checks['runtime_zip'] = self::check('ZIP', class_exists('ZipArchive'), __('ZipArchive disponible.', 'sistema-para-farmacias'), __('La extensión ZIP de PHP no está disponible.', 'sistema-para-farmacias'));
        $runtime_extension = sanitize_key((string)($transport_runtime['extension'] ?? ''));
        $runtime_remote_ready = $runtime_extension !== '' && extension_loaded($runtime_extension);
        $checks['runtime_remote_transport'] = self::check(
            __('Runtime del transporte beta', 'sistema-para-farmacias'),
            $runtime_remote_ready,
            __('La dependencia runtime declarada por el transporte del país activo está disponible.', 'sistema-para-farmacias'),
            __('Falta la dependencia runtime declarada para el transporte beta del país activo.', 'sistema-para-farmacias')
        );

        $required_fields = array_values(array_filter(array_map('sanitize_key', (array)($issuer_profile['required_fields'] ?? []))));
        $tax_id_pattern = trim((string)($issuer_profile['tax_id_pattern'] ?? ''));
        $pattern_valid = $tax_id_pattern !== '' && @preg_match($tax_id_pattern, '') !== false;
        $issuer_profile_ok = !empty($required_fields) && $pattern_valid;
        $checks['issuer_profile'] = self::check(
            __('Perfil de identidad fiscal', 'sistema-para-farmacias'),
            $issuer_profile_ok,
            __('El país activo define los campos y formato requeridos para el emisor.', 'sistema-para-farmacias'),
            __('El país activo no define un perfil de identidad fiscal completo para readiness.', 'sistema-para-farmacias')
        );

        $issuer_ok = $issuer_profile_ok;
        foreach ($required_fields as $field) {
            if (trim((string)($issuer[$field] ?? '')) === '') { $issuer_ok = false; break; }
        }
        if ($issuer_ok && @preg_match($tax_id_pattern, (string)($issuer['tax_id'] ?? '')) !== 1) { $issuer_ok = false; }
        $checks['issuer'] = self::check(
            __('Emisor', 'sistema-para-farmacias'),
            $issuer_ok,
            __('Identidad fiscal mínima del emisor configurada según el país activo.', 'sistema-para-farmacias'),
            __('Completa la identidad fiscal requerida por el perfil del país activo.', 'sistema-para-farmacias')
        );

        $checks['signer'] = self::check(__('Firmador', 'sistema-para-farmacias'), (bool)$signer, __('Firmador XMLDSig registrado.', 'sistema-para-farmacias'), __('No hay firmador XMLDSig activo.', 'sistema-para-farmacias'));
        $checks['credentials_provider'] = self::check(__('Proveedor de certificado', 'sistema-para-farmacias'), (bool)$provider, __('Proveedor externo de credenciales registrado.', 'sistema-para-farmacias'), __('No hay proveedor externo de credenciales registrado.', 'sistema-para-farmacias'));
        $checks['certificate'] = self::certificate_check($provider, $issuer);
        $checks['validator'] = self::check(__('Validador', 'sistema-para-farmacias'), (bool)$validator, __('Validador XSD/XSL registrado.', 'sistema-para-farmacias'), __('No hay validador XSD/XSL activo.', 'sistema-para-farmacias'));

        $checks['invoice_xsd'] = self::resource_check(__('XSD comprobante', 'sistema-para-farmacias'), (string)($invoice_resources['xsd_path'] ?? ''));
        $checks['invoice_xsl'] = self::resource_check(__('XSL comprobante', 'sistema-para-farmacias'), (string)($invoice_resources['xsl_path'] ?? ''));
        $checks['batch_xsd'] = self::resource_check(__('XSD Resumen Diario', 'sistema-para-farmacias'), (string)($batch_resources['xsd_path'] ?? ''));
        $checks['batch_xsl'] = self::resource_check(__('XSL Resumen Diario', 'sistema-para-farmacias'), (string)($batch_resources['xsl_path'] ?? ''));

        $checks['evidence_store'] = self::check(__('Evidence Store', 'sistema-para-farmacias'), !empty($evidence['configured']) && !empty($evidence['available']), __('Almacén privado de evidencia disponible.', 'sistema-para-farmacias'), __('Configura un Evidence Store privado disponible fuera del webroot.', 'sistema-para-farmacias'));
        $checks['document_ledger'] = self::table_check(__('Ledger de factura', 'sistema-para-farmacias'), SPF_Fiscal_Document_Remote_Event_Repository::table());
        $checks['batch_ledger'] = self::table_check(__('Ledger de lote', 'sistema-para-farmacias'), SPF_Fiscal_Batch_Remote_Event_Repository::table());

        $invoice_transport_ok = $invoice_transport && $invoice_transport instanceof SPF_Fiscal_Transport_Interface;
        $batch_transport_ok = $batch_transport && $batch_transport instanceof SPF_Fiscal_Async_Batch_Transport_Interface;
        $checks['invoice_transport'] = self::check(__('Transporte beta de factura', 'sistema-para-farmacias'), (bool)$invoice_transport_ok, __('Transporte beta individual registrado.', 'sistema-para-farmacias'), __('No hay transporte beta individual registrado.', 'sistema-para-farmacias'));
        $checks['batch_transport'] = self::check(__('Transporte beta de lote', 'sistema-para-farmacias'), (bool)$batch_transport_ok, __('Transporte beta asíncrono registrado.', 'sistema-para-farmacias'), __('No hay transporte beta asíncrono registrado.', 'sistema-para-farmacias'));
        $checks['batch_builder'] = self::check(__('Builder Resumen Diario', 'sistema-para-farmacias'), (bool)$batch_builder, __('Builder de Resumen Diario registrado.', 'sistema-para-farmacias'), __('No hay builder de Resumen Diario activo.', 'sistema-para-farmacias'));

        $invoice_enabled = $invoice_transport && method_exists($invoice_transport, 'enabled') && (bool)call_user_func([$invoice_transport, 'enabled']);
        $batch_enabled = SPF_Fiscal_Batch_Beta_Manager::enabled('daily_summary');
        $checks['invoice_optin'] = self::check(__('Opt-in factura beta', 'sistema-para-farmacias'), (bool)$invoice_enabled, __('Opt-in de factura beta habilitado explícitamente.', 'sistema-para-farmacias'), __('Activa los flags externos de factura beta solo en el entorno de pruebas.', 'sistema-para-farmacias'));
        $checks['batch_optin'] = self::check(__('Opt-in Resumen Diario beta', 'sistema-para-farmacias'), (bool)$batch_enabled, __('Opt-in de Resumen Diario beta habilitado explícitamente.', 'sistema-para-farmacias'), __('Activa los flags externos de Resumen Diario beta solo en el entorno de pruebas.', 'sistema-para-farmacias'));

        $production_blocked = empty($beta_profile['production_enabled']);
        $checks['production_guard'] = self::check(__('Producción', 'sistema-para-farmacias'), (bool)$production_blocked, __('Producción permanece bloqueada.', 'sistema-para-farmacias'), __('El perfil beta no debe habilitar producción.', 'sistema-para-farmacias'));

        $invoice_required = [
            'runtime_openssl','runtime_dom','runtime_zip','runtime_remote_transport','issuer_profile','issuer','signer','credentials_provider','certificate','validator',
            'invoice_xsd','invoice_xsl','evidence_store','document_ledger','invoice_transport','invoice_optin','production_guard',
        ];
        $batch_required = [
            'runtime_openssl','runtime_dom','runtime_zip','runtime_remote_transport','issuer_profile','issuer','signer','credentials_provider','certificate','validator',
            'batch_xsd','batch_xsl','evidence_store','batch_ledger','batch_transport','batch_builder','batch_optin','production_guard',
        ];

        return self::summarize($checks, $invoice_required, $batch_required);
    }

    public static function summarize(array $checks, array $invoice_required, array $batch_required) {
        $scope = static function(array $required) use ($checks) {
            $failed = [];
            foreach ($required as $key) {
                if (empty($checks[$key]['ready'])) { $failed[] = $key; }
            }
            return ['ready'=>!$failed,'failed'=>$failed,'required'=>$required];
        };
        $invoice = $scope($invoice_required);
        $batch = $scope($batch_required);
        return [
            'checks'=>$checks,
            'invoice'=>$invoice,
            'batch'=>$batch,
            'ready'=>$invoice['ready'] && $batch['ready'],
            'generated_at'=>current_time('mysql'),
        ];
    }

    private static function certificate_check($provider, array $issuer) {
        if (!$provider) {
            return self::check(__('Certificado', 'sistema-para-farmacias'), false, '', __('No se puede comprobar el certificado sin proveedor activo.', 'sistema-para-farmacias'));
        }
        $credentials = $provider->load(['country_code'=>SPF_Config::country_code(),'issuer'=>$issuer]);
        if (is_wp_error($credentials)) {
            return self::check(__('Certificado', 'sistema-para-farmacias'), false, '', $credentials->get_error_message());
        }
        $ok = !empty($credentials['certificate_pem']) && !empty($credentials['private_key_pem']) && is_array($credentials['certificate'] ?? null);
        if (isset($credentials['private_key_pem'])) { $credentials['private_key_pem'] = ''; }
        unset($credentials);
        return self::check(__('Certificado', 'sistema-para-farmacias'), $ok, __('PKCS#12 legible, vigente y con clave privada.', 'sistema-para-farmacias'), __('El certificado externo no supera la comprobación local.', 'sistema-para-farmacias'));
    }

    private static function resource_check($label, $path) {
        $configured = trim((string)$path) !== '';
        $ready = $configured && is_file($path) && is_readable($path);
        if ($ready) { return self::check($label, true, __('Recurso local configurado y legible.', 'sistema-para-farmacias'), ''); }
        $message = $configured ? __('El recurso configurado no es un archivo local legible.', 'sistema-para-farmacias') : __('Recurso externo no configurado.', 'sistema-para-farmacias');
        return self::check($label, false, '', $message);
    }

    private static function table_check($label, $table) {
        global $wpdb;
        $like = $wpdb->esc_like((string)$table);
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        return self::check($label, $found === $table, __('Tabla de evidencia disponible.', 'sistema-para-farmacias'), __('La tabla de evidencia no está instalada.', 'sistema-para-farmacias'));
    }

    private static function check($label, $ready, $ok_message, $error_message) {
        return [
            'label'=>sanitize_text_field((string)$label),
            'ready'=>(bool)$ready,
            'message'=>sanitize_text_field((string)($ready ? $ok_message : $error_message)),
        ];
    }

    public function render() {
        if (!$this->is_fiscal_screen()) { return; }
        if (!current_user_can(SPF_Roles::INVOICES) && !current_user_can('manage_options')) { return; }
        $report = self::report();
        $checks = (array)$report['checks'];
        $invoice = (array)$report['invoice'];
        $batch = (array)$report['batch'];
        ?>
        <div class="notice <?php echo esc_attr(!empty($report['ready']) ? 'notice-success' : 'notice-info'); ?> spf-fiscal-readiness">
            <h2><?php esc_html_e('Preparación para aceptación beta fiscal', 'sistema-para-farmacias'); ?></h2>
            <p><?php esc_html_e('Diagnóstico local sin transmisión. No muestra contraseñas, claves privadas, contenido del certificado ni rutas completas de recursos.', 'sistema-para-farmacias'); ?></p>
            <p>
                <strong><?php esc_html_e('Factura beta:', 'sistema-para-farmacias'); ?></strong>
                <?php echo esc_html(!empty($invoice['ready']) ? __('LISTA', 'sistema-para-farmacias') : __('BLOQUEADA', 'sistema-para-farmacias')); ?>
                &nbsp;·&nbsp;
                <strong><?php esc_html_e('Resumen Diario beta:', 'sistema-para-farmacias'); ?></strong>
                <?php echo esc_html(!empty($batch['ready']) ? __('LISTO', 'sistema-para-farmacias') : __('BLOQUEADO', 'sistema-para-farmacias')); ?>
            </p>
            <?php if (empty($report['ready'])): ?>
                <details>
                    <summary><strong><?php esc_html_e('Ver comprobaciones y bloqueos', 'sistema-para-farmacias'); ?></strong></summary>
                    <div style="overflow:auto;max-width:100%;margin:10px 0 14px">
                        <table class="widefat striped" style="min-width:720px">
                            <thead><tr><th><?php esc_html_e('Comprobación', 'sistema-para-farmacias'); ?></th><th><?php esc_html_e('Estado', 'sistema-para-farmacias'); ?></th><th><?php esc_html_e('Detalle', 'sistema-para-farmacias'); ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($checks as $check): ?>
                                <tr>
                                    <td><strong><?php echo esc_html((string)$check['label']); ?></strong></td>
                                    <td><?php echo esc_html(!empty($check['ready']) ? __('OK', 'sistema-para-farmacias') : __('PENDIENTE', 'sistema-para-farmacias')); ?></td>
                                    <td><?php echo esc_html((string)$check['message']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            <?php else: ?>
                <p><strong><?php esc_html_e('Las precondiciones locales y los opt-ins beta están completos. Esto no habilita producción ni ejecuta ninguna operación remota.', 'sistema-para-farmacias'); ?></strong></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function is_fiscal_screen() {
        if (!is_admin()) { return false; }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return $page === 'spf-fiscal';
    }
}
