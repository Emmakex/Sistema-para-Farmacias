<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Credentials_Environment implements SPF_Fiscal_Credentials_Provider_Interface {
    public function code() { return 'environment_p12'; }

    public function load(array $context = []) {
        if (!extension_loaded('openssl') || !function_exists('openssl_pkcs12_read')) {
            return new WP_Error('spf_fiscal_openssl_missing', __('OpenSSL es necesario para cargar el certificado fiscal.', 'sistema-para-farmacias'));
        }

        $path = defined('SPF_FISCAL_P12_PATH') ? (string) SPF_FISCAL_P12_PATH : (string) getenv('SPF_FISCAL_P12_PATH');
        $password = defined('SPF_FISCAL_P12_PASSWORD') ? (string) SPF_FISCAL_P12_PASSWORD : (string) getenv('SPF_FISCAL_P12_PASSWORD');
        $path = apply_filters('spf_fiscal_p12_path', $path, $context);
        $password = apply_filters('spf_fiscal_p12_password', $password, $context);

        if ($path === '') {
            return new WP_Error('spf_fiscal_p12_path_missing', __('No hay una ruta de certificado PKCS#12 configurada fuera de la base de datos.', 'sistema-para-farmacias'));
        }
        if (!is_readable($path) || !is_file($path)) {
            return new WP_Error('spf_fiscal_p12_unreadable', __('El archivo PKCS#12 configurado no es legible.', 'sistema-para-farmacias'));
        }

        $binary = file_get_contents($path);
        if ($binary === false || $binary === '') {
            return new WP_Error('spf_fiscal_p12_empty', __('No se pudo leer el certificado PKCS#12.', 'sistema-para-farmacias'));
        }

        $certs = [];
        if (!openssl_pkcs12_read($binary, $certs, $password)) {
            return new WP_Error('spf_fiscal_p12_invalid', __('No se pudo abrir el PKCS#12. Revisa el archivo y la contraseña configurados en el entorno seguro.', 'sistema-para-farmacias'));
        }
        unset($binary, $password);

        $certificate = (string) ($certs['cert'] ?? '');
        $private_key = (string) ($certs['pkey'] ?? '');
        if ($certificate === '' || $private_key === '') {
            return new WP_Error('spf_fiscal_p12_incomplete', __('El PKCS#12 debe contener certificado X.509 y clave privada.', 'sistema-para-farmacias'));
        }

        $parsed = openssl_x509_parse($certificate, false);
        if (!is_array($parsed)) {
            return new WP_Error('spf_fiscal_certificate_invalid', __('El certificado X.509 no se pudo interpretar.', 'sistema-para-farmacias'));
        }
        $now = time();
        if (!empty($parsed['validFrom_time_t']) && $now < (int) $parsed['validFrom_time_t']) {
            return new WP_Error('spf_fiscal_certificate_not_yet_valid', __('El certificado fiscal todavía no es válido.', 'sistema-para-farmacias'));
        }
        if (!empty($parsed['validTo_time_t']) && $now > (int) $parsed['validTo_time_t']) {
            return new WP_Error('spf_fiscal_certificate_expired', __('El certificado fiscal está vencido.', 'sistema-para-farmacias'));
        }

        return [
            'provider' => $this->code(),
            'certificate_pem' => $certificate,
            'private_key_pem' => $private_key,
            'certificate' => $parsed,
            'source' => 'external_path',
        ];
    }
}
