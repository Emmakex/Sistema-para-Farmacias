<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Transport_Dry_Run implements SPF_Fiscal_Transport_Interface {
    public function code() { return 'dry_run'; }
    public function environment() { return 'local'; }
    public function capabilities() { return ['single', 'daily_summary', 'status_query']; }

    public function inspect(array $package) {
        $errors = [];
        $warnings = [];
        $strategy = sanitize_key($package['strategy'] ?? '');
        $capabilities = $this->capabilities();
        if ($strategy === '' || !in_array($strategy, $capabilities, true)) {
            $errors[] = __('La estrategia fiscal no está soportada por el transporte dry-run.', 'sistema-para-farmacias');
        }
        if (empty($package['xml']) || !is_string($package['xml'])) {
            $errors[] = __('El paquete no contiene XML para inspeccionar.', 'sistema-para-farmacias');
        }
        if (!empty($package['signed'])) {
            $warnings[] = __('El dry-run no verifica criptográficamente firmas. La firma real se validará en un corte posterior.', 'sistema-para-farmacias');
        } else {
            $warnings[] = __('El XML sigue sin firma digital; por diseño no puede transmitirse.', 'sistema-para-farmacias');
        }
        if (empty($package['filename'])) {
            $errors[] = __('El paquete fiscal no tiene nombre de archivo.', 'sistema-para-farmacias');
        }
        return [
            'ok' => !$errors,
            'errors' => $errors,
            'warnings' => $warnings,
            'transport' => $this->code(),
            'environment' => $this->environment(),
            'strategy' => $strategy,
            'transmitted' => false,
        ];
    }

    public function transmit(array $package) {
        return new WP_Error(
            'spf_fiscal_dry_run_only',
            __('El transporte dry-run nunca transmite documentos. Configura un transporte fiscal real en un corte posterior.', 'sistema-para-farmacias')
        );
    }
}
