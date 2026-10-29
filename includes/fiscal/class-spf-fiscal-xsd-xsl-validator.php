<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_XSD_XSL_Validator implements SPF_Fiscal_Validator_Interface {
    public function code() { return 'xsd_xsl_local'; }

    public function validate($xml, array $resources, array $context = []) {
        if (!class_exists('DOMDocument')) {
            return new WP_Error('spf_fiscal_validation_dom_missing', __('DOM es necesario para validar XML.', 'sistema-para-farmacias'));
        }

        $xsd = (string) ($resources['xsd_path'] ?? '');
        $xsl = (string) ($resources['xsl_path'] ?? '');
        $filename = sanitize_file_name((string) ($resources['filename'] ?? 'document.xml'));
        $filename_parameter = sanitize_text_field((string) ($resources['filename_parameter'] ?? 'nombreArchivoEnviado'));
        $errors = [];
        $warnings = [];
        $checks = ['xsd'=>'not_configured','xsl'=>'not_configured'];

        $doc = $this->load_xml($xml);
        if (is_wp_error($doc)) { return $doc; }

        if ($xsd !== '') {
            if (!$this->local_readable_file($xsd)) {
                $errors[] = __('La ruta XSD configurada no es un archivo local legible.', 'sistema-para-farmacias');
                $checks['xsd'] = 'missing';
            } else {
                $validation = $this->validate_xsd($doc, $xsd);
                $checks['xsd'] = $validation['ok'] ? 'valid' : 'invalid';
                $errors = array_merge($errors, $validation['errors']);
            }
        }

        if ($xsl !== '') {
            if (!$this->local_readable_file($xsl)) {
                $errors[] = __('La ruta XSL configurada no es un archivo local legible.', 'sistema-para-farmacias');
                $checks['xsl'] = 'missing';
            } elseif (!class_exists('XSLTProcessor')) {
                $errors[] = __('La extensión XSL de PHP es necesaria para ejecutar las reglas XSL.', 'sistema-para-farmacias');
                $checks['xsl'] = 'runtime_missing';
            } else {
                $validation = $this->validate_xsl($doc, $xsl, $filename_parameter, $filename);
                $checks['xsl'] = $validation['ok'] ? 'valid' : 'invalid';
                $errors = array_merge($errors, $validation['errors']);
                $warnings = array_merge($warnings, $validation['warnings']);
            }
        }

        if ($xsd === '' && $xsl === '') {
            return new WP_Error('spf_fiscal_validation_resources_missing', __('No hay rutas XSD/XSL configuradas para la validación local.', 'sistema-para-farmacias'));
        }

        return [
            'ok' => !$errors,
            'checks' => $checks,
            'errors' => array_values(array_unique(array_filter($errors))),
            'warnings' => array_values(array_unique(array_filter($warnings))),
            'validator' => $this->code(),
            'validated_at' => current_time('mysql'),
        ];
    }

    private function validate_xsd(DOMDocument $doc, $xsd) {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $ok = $doc->schemaValidate($xsd);
        $errors = $this->libxml_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return ['ok'=>(bool)$ok,'errors'=>$errors];
    }

    private function validate_xsl(DOMDocument $doc, $xsl, $filename_parameter, $filename) {
        $style = new DOMDocument('1.0', 'UTF-8');
        $style->preserveWhiteSpace = true;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $style->load($xsl, LIBXML_NONET);
        $load_errors = $this->libxml_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) { return ['ok'=>false,'errors'=>$load_errors ?: [__('No se pudo cargar la hoja XSL.', 'sistema-para-farmacias')],'warnings'=>[]]; }

        $processor = new XSLTProcessor();
        if (method_exists($processor, 'setSecurityPrefs')) {
            $security = 0;
            foreach (['XSL_SECPREF_WRITE_FILE','XSL_SECPREF_CREATE_DIRECTORY','XSL_SECPREF_READ_NETWORK','XSL_SECPREF_WRITE_NETWORK'] as $constant) {
                if (defined($constant)) { $security |= constant($constant); }
            }
            $processor->setSecurityPrefs($security);
        }
        if (!$processor->importStylesheet($style)) {
            return ['ok'=>false,'errors'=>[__('No se pudo compilar la hoja XSL.', 'sistema-para-farmacias')],'warnings'=>[]];
        }
        if ($filename_parameter !== '') { $processor->setParameter('', $filename_parameter, $filename); }

        $messages = [];
        $handler = function($severity, $message) use (&$messages) { $messages[] = trim(strip_tags((string)$message)); return true; };
        set_error_handler($handler);
        $result = $processor->transformToXML($doc);
        restore_error_handler();

        $errors = [];
        $warnings = [];
        foreach ($messages as $message) {
            if ($message === '') { continue; }
            if (stripos($message, 'INFO') !== false || stripos($message, 'observ') !== false) { $warnings[] = $message; }
            else { $errors[] = $message; }
        }
        if ($result === false && !$errors) { $errors[] = __('La validación XSL no pudo completarse.', 'sistema-para-farmacias'); }
        return ['ok'=>!$errors,'errors'=>$errors,'warnings'=>$warnings];
    }

    private function load_xml($xml) {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $ok = $doc->loadXML((string)$xml, LIBXML_NONET);
        $errors = $this->libxml_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok ? $doc : new WP_Error('spf_fiscal_validation_xml_invalid', $errors ? implode(' ', $errors) : __('El XML no es válido.', 'sistema-para-farmacias'));
    }

    private function libxml_errors() {
        $messages = [];
        foreach (libxml_get_errors() as $error) {
            $message = trim((string)$error->message);
            if ($message !== '') { $messages[] = sprintf('%s (L%d C%d)', $message, (int)$error->line, (int)$error->column); }
        }
        return $messages;
    }

    private function local_readable_file($path) {
        if ($path === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) { return false; }
        $real = realpath($path);
        return $real !== false && is_file($real) && is_readable($real);
    }
}
