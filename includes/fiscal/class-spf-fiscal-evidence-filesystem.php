<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Evidence_Filesystem implements SPF_Fiscal_Evidence_Store_Interface {
    public function code() { return 'external_filesystem'; }

    public function available() {
        $root = $this->root();
        return !is_wp_error($root);
    }

    public function store($relative_path, $bytes) {
        $root = $this->root();
        if (is_wp_error($root)) { return $root; }
        $relative = $this->safe_relative_path($relative_path);
        if (is_wp_error($relative)) { return $relative; }
        if (!is_string($bytes) || $bytes === '') {
            return new WP_Error('spf_evidence_empty', __('La evidencia fiscal no contiene bytes para archivar.', 'sistema-para-farmacias'));
        }
        if (!$this->ensure_directory($root)) {
            return new WP_Error('spf_evidence_root_create', __('No se pudo crear o usar el directorio externo de evidencias fiscales.', 'sistema-para-farmacias'));
        }

        $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $directory = dirname($target);
        if (!$this->ensure_directory($directory)) {
            return new WP_Error('spf_evidence_dir_create', __('No se pudo crear el subdirectorio de evidencia fiscal.', 'sistema-para-farmacias'));
        }

        $sha256 = hash('sha256', $bytes);
        if (is_file($target)) {
            $existing = hash_file('sha256', $target);
            if (is_string($existing) && hash_equals($sha256, $existing)) {
                return $this->record($relative, $target, $sha256);
            }
            return new WP_Error('spf_evidence_collision', __('Ya existe una evidencia con el mismo locator pero contenido distinto.', 'sistema-para-farmacias'));
        }

        $tmp = tempnam($directory, '.spf-evidence-');
        if ($tmp === false) {
            return new WP_Error('spf_evidence_temp', __('No se pudo crear un archivo temporal para la evidencia fiscal.', 'sistema-para-farmacias'));
        }
        $written = file_put_contents($tmp, $bytes, LOCK_EX);
        if ($written === false || $written !== strlen($bytes)) {
            @unlink($tmp);
            return new WP_Error('spf_evidence_write', __('No se pudo escribir completamente la evidencia fiscal.', 'sistema-para-farmacias'));
        }
        @chmod($tmp, 0640);
        $tmp_hash = hash_file('sha256', $tmp);
        if (!is_string($tmp_hash) || !hash_equals($sha256, $tmp_hash)) {
            @unlink($tmp);
            return new WP_Error('spf_evidence_write_hash', __('La evidencia fiscal escrita no supera la verificación SHA-256.', 'sistema-para-farmacias'));
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return new WP_Error('spf_evidence_rename', __('No se pudo finalizar de forma atómica el archivo de evidencia fiscal.', 'sistema-para-farmacias'));
        }
        @chmod($target, 0640);
        return $this->record($relative, $target, $sha256);
    }

    public function verify($relative_path, $expected_sha256) {
        $root = $this->root();
        if (is_wp_error($root)) { return $root; }
        $relative = $this->safe_relative_path($relative_path);
        if (is_wp_error($relative)) { return $relative; }
        $expected = strtolower(trim((string)$expected_sha256));
        if (!preg_match('/^[a-f0-9]{64}$/', $expected)) {
            return new WP_Error('spf_evidence_expected_hash', __('El hash esperado de la evidencia fiscal no es válido.', 'sistema-para-farmacias'));
        }
        $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($target) || !is_readable($target)) {
            return new WP_Error('spf_evidence_missing', __('La evidencia fiscal archivada no está disponible o no es legible.', 'sistema-para-farmacias'));
        }
        $actual = hash_file('sha256', $target);
        if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
            return new WP_Error('spf_evidence_hash_mismatch', __('La evidencia fiscal archivada no coincide con su SHA-256 persistido.', 'sistema-para-farmacias'));
        }
        return $this->record($relative, $target, $actual);
    }

    public function configured_root() {
        $root = $this->root();
        return is_wp_error($root) ? '' : $root;
    }

    private function record($relative, $target, $sha256) {
        return [
            'store'=>$this->code(),
            'locator'=>str_replace('\\', '/', $relative),
            'sha256'=>strtolower((string)$sha256),
            'bytes'=>(int)filesize($target),
        ];
    }

    private function root() {
        $value = defined('SPF_FISCAL_EVIDENCE_DIR') ? (string)SPF_FISCAL_EVIDENCE_DIR : (string)getenv('SPF_FISCAL_EVIDENCE_DIR');
        if (function_exists('apply_filters')) { $value = (string)apply_filters('spf_fiscal_evidence_root', $value); }
        $value = rtrim(trim($value), "\\/");
        if ($value === '') {
            return new WP_Error('spf_evidence_root_missing', __('Configura SPF_FISCAL_EVIDENCE_DIR con una ruta privada fuera del webroot.', 'sistema-para-farmacias'));
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
            return new WP_Error('spf_evidence_root_url', __('SPF_FISCAL_EVIDENCE_DIR debe ser una ruta local, no una URL.', 'sistema-para-farmacias'));
        }
        $normalized = $this->normalize_path($value);
        if (!$this->is_absolute_path($normalized)) {
            return new WP_Error('spf_evidence_root_relative', __('SPF_FISCAL_EVIDENCE_DIR debe ser una ruta absoluta.', 'sistema-para-farmacias'));
        }
        $webroot = $this->normalize_path(realpath(ABSPATH) ?: ABSPATH);
        if ($normalized === $webroot || strpos($normalized . '/', $webroot . '/') === 0) {
            return new WP_Error('spf_evidence_root_web', __('El directorio de evidencia fiscal debe estar fuera de ABSPATH/webroot.', 'sistema-para-farmacias'));
        }
        return $value;
    }

    private function safe_relative_path($value) {
        $value = str_replace('\\', '/', trim((string)$value));
        if ($value === '' || strpos($value, "\0") !== false || strpos($value, '..') !== false || $value[0] === '/') {
            return new WP_Error('spf_evidence_path', __('El locator relativo de la evidencia fiscal no es válido.', 'sistema-para-farmacias'));
        }
        $parts = array_values(array_filter(explode('/', $value), 'strlen'));
        if (!$parts) { return new WP_Error('spf_evidence_path_empty', __('El locator de evidencia fiscal está vacío.', 'sistema-para-farmacias')); }
        $safe = [];
        foreach ($parts as $part) {
            $part = sanitize_file_name($part);
            if ($part === '' || $part === '.' || $part === '..') {
                return new WP_Error('spf_evidence_path_segment', __('El locator de evidencia fiscal contiene un segmento inválido.', 'sistema-para-farmacias'));
            }
            $safe[] = $part;
        }
        return implode('/', $safe);
    }

    private function ensure_directory($path) {
        if (is_dir($path)) { return is_writable($path); }
        if (function_exists('wp_mkdir_p')) { return wp_mkdir_p($path) && is_writable($path); }
        return @mkdir($path, 0750, true) && is_writable($path);
    }

    private function normalize_path($path) {
        $path = str_replace('\\', '/', (string)$path);
        $path = preg_replace('#/+#', '/', $path);
        return rtrim($path, '/');
    }

    private function is_absolute_path($path) {
        return isset($path[0]) && ($path[0] === '/' || preg_match('/^[A-Za-z]:\//', $path));
    }
}
