<?php
if (!defined('ABSPATH')) { exit; }

if (class_exists('SoapClient') && !class_exists('SPF_Fiscal_Peru_Beta_Soap_Client')) {
    final class SPF_Fiscal_Peru_Beta_Soap_Client extends SoapClient {
        private $spf_username;
        private $spf_password;

        public function spf_set_credentials($username, $password) {
            $this->spf_username = (string) $username;
            $this->spf_password = (string) $password;
        }

        public function __doRequest($request, $location, $action, $version, $one_way = false): ?string {
            $doc = new DOMDocument('1.0', 'UTF-8');
            $doc->preserveWhiteSpace = false;
            if (!$doc->loadXML((string) $request, LIBXML_NONET)) {
                throw new RuntimeException('SOAP request XML could not be parsed.');
            }
            $envelope = $doc->documentElement;
            $soap_ns = $envelope ? $envelope->namespaceURI : 'http://schemas.xmlsoap.org/soap/envelope/';
            $header = null;
            foreach ($envelope->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'Header' && $child->namespaceURI === $soap_ns) { $header = $child; break; }
            }
            if (!$header) {
                $header = $doc->createElementNS($soap_ns, 'soapenv:Header');
                $body = null;
                foreach ($envelope->childNodes as $child) {
                    if ($child instanceof DOMElement && $child->localName === 'Body' && $child->namespaceURI === $soap_ns) { $body = $child; break; }
                }
                if ($body) { $envelope->insertBefore($header, $body); } else { $envelope->appendChild($header); }
            }
            $wsse = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
            $security = $doc->createElementNS($wsse, 'wsse:Security');
            $token = $doc->createElementNS($wsse, 'wsse:UsernameToken');
            $username = $doc->createElementNS($wsse, 'wsse:Username');
            $username->appendChild($doc->createTextNode($this->spf_username));
            $password = $doc->createElementNS($wsse, 'wsse:Password');
            $password->appendChild($doc->createTextNode($this->spf_password));
            $token->appendChild($username); $token->appendChild($password); $security->appendChild($token); $header->appendChild($security);
            return parent::__doRequest($doc->saveXML(), $location, $action, $version, $one_way);
        }
    }
}

final class SPF_Fiscal_Transport_Peru_Beta implements SPF_Fiscal_Transport_Interface, SPF_Fiscal_Async_Batch_Transport_Interface {
    const WSDL = 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService?wsdl';
    const TEST_USER = 'MODDATOS';
    const TEST_PASSWORD = 'MODDATOS';

    public function code() { return 'peru_beta'; }
    public function environment() { return 'beta'; }
    public function capabilities() { return ['single','async_batch']; }

    public static function enabled() {
        $enabled = defined('SPF_FISCAL_BETA_ENABLED') && SPF_FISCAL_BETA_ENABLED === true;
        $confirmation = defined('SPF_FISCAL_BETA_CONFIRMATION') ? (string) SPF_FISCAL_BETA_CONFIRMATION : '';
        return $enabled && hash_equals('SUNAT_TEST_ONLY', $confirmation);
    }

    public function batch_enabled() {
        $enabled = defined('SPF_FISCAL_BETA_SUMMARY_ENABLED') && SPF_FISCAL_BETA_SUMMARY_ENABLED === true;
        $confirmation = defined('SPF_FISCAL_BETA_SUMMARY_CONFIRMATION') ? (string) SPF_FISCAL_BETA_SUMMARY_CONFIRMATION : '';
        return $enabled && hash_equals('SUNAT_SUMMARY_TEST_ONLY', $confirmation);
    }

    public function inspect(array $package) {
        $errors = [];
        $warnings = [];
        if (($package['country'] ?? '') !== 'pe') { $errors[] = __('El transporte beta Perú solo admite el perfil Perú.', 'sistema-para-farmacias'); }
        if (($package['document_type'] ?? '') !== 'factura') { $errors[] = __('El transporte beta individual solo admite facturas. Las boletas se procesan mediante Resumen Diario.', 'sistema-para-farmacias'); }
        if (($package['strategy'] ?? '') !== 'single') { $errors[] = __('El transporte beta de factura requiere estrategia individual.', 'sistema-para-farmacias'); }
        if (empty($package['signed']) || empty($package['signature_verified'])) { $errors[] = __('La factura debe tener una firma XMLDSig verificada localmente.', 'sistema-para-farmacias'); }
        if (($package['validation_status'] ?? '') !== 'valid_local') { $errors[] = __('La factura debe haber superado XSD y XSL localmente antes del beta.', 'sistema-para-farmacias'); }
        if (!preg_match('/^\d{11}$/', (string)($package['issuer_tax_id'] ?? ''))) { $errors[] = __('El RUC del emisor debe tener 11 dígitos.', 'sistema-para-farmacias'); }
        if (($package['fiscal_type_code'] ?? '') !== '01') { $errors[] = __('El código fiscal del documento debe ser 01 para factura.', 'sistema-para-farmacias'); }
        if (!self::enabled()) { $warnings[] = __('El beta está bloqueado. Define SPF_FISCAL_BETA_ENABLED=true y SPF_FISCAL_BETA_CONFIRMATION=SUNAT_TEST_ONLY fuera del plugin para habilitarlo.', 'sistema-para-farmacias'); }
        return ['ok'=>!$errors,'errors'=>$errors,'warnings'=>$warnings,'transport'=>$this->code(),'environment'=>$this->environment(),'transmitted'=>false];
    }

    public function transmit(array $package) {
        if (!self::enabled()) { return new WP_Error('spf_beta_disabled', __('El transporte beta SUNAT no está habilitado explícitamente.', 'sistema-para-farmacias')); }
        $inspection = $this->inspect($package);
        if (empty($inspection['ok'])) { return new WP_Error('spf_beta_preconditions', implode(' ', (array)$inspection['errors'])); }
        if (!class_exists('ZipArchive')) { return new WP_Error('spf_beta_zip_missing', __('La extensión ZIP de PHP es necesaria para empaquetar la factura.', 'sistema-para-farmacias')); }

        $ruc = (string)$package['issuer_tax_id'];
        $base = $ruc . '-01-' . strtoupper((string)$package['series']) . '-' . (string)$package['correlative'];
        $xml_name = $base . '.XML';
        $zip_name = $base . '.ZIP';
        $zip_bytes = $this->zip_xml($xml_name, (string)$package['xml']);
        if (is_wp_error($zip_bytes)) { return $zip_bytes; }
        $client = $this->soap_client($ruc);
        if (is_wp_error($client)) { return $client; }

        try {
            $response = $client->__soapCall('sendBill', [['fileName'=>$zip_name,'contentFile'=>$zip_bytes]]);
        } catch (Throwable $e) {
            return new WP_Error('spf_beta_soap_error', sanitize_text_field($e->getMessage()));
        }

        $cdr = $this->extract_cdr_bytes($response);
        if (is_wp_error($cdr)) { return $cdr; }
        $parsed = $this->parse_cdr_zip($cdr);
        if (is_wp_error($parsed)) { return $parsed; }
        $parsed['ok'] = true;
        $parsed['transport'] = $this->code();
        $parsed['environment'] = $this->environment();
        $parsed['transmitted'] = true;
        $parsed['zip_name'] = $zip_name;
        $parsed['request_zip_sha256'] = hash('sha256', $zip_bytes);
        $parsed['cdr_zip_sha256'] = hash('sha256', $cdr);
        $parsed['cdr_zip_bytes'] = $cdr;
        return $parsed;
    }

    public function inspect_batch(array $package) {
        $errors = [];
        $warnings = [];
        if (($package['country'] ?? '') !== 'pe') { $errors[] = __('El transporte beta de lote Perú solo admite el perfil Perú.', 'sistema-para-farmacias'); }
        if (($package['batch_type'] ?? '') !== 'daily_summary') { $errors[] = __('El transporte beta asíncrono solo admite el Resumen Diario configurado.', 'sistema-para-farmacias'); }
        if (empty($package['signed']) || empty($package['signature_verified'])) { $errors[] = __('El lote debe tener una firma XMLDSig verificada localmente.', 'sistema-para-farmacias'); }
        if (($package['validation_status'] ?? '') !== 'valid_local') { $errors[] = __('El lote debe haber superado XSD y XSL localmente antes del beta.', 'sistema-para-farmacias'); }
        $signed_hash = trim((string)($package['xml_sha256'] ?? ''));
        $validated_hash = trim((string)($package['validation_xml_sha256'] ?? ''));
        if ($signed_hash === '' || $validated_hash === '' || !hash_equals($signed_hash, $validated_hash)) { $errors[] = __('El XML firmado exacto no coincide con el XML que superó la validación local.', 'sistema-para-farmacias'); }
        if (!preg_match('/^\d{11}$/', (string)($package['issuer_tax_id'] ?? ''))) { $errors[] = __('El RUC del emisor debe tener 11 dígitos.', 'sistema-para-farmacias'); }
        if (!preg_match('/^RC-\d{8}-\d{5}$/', strtoupper((string)($package['identifier'] ?? '')))) { $errors[] = __('El identificador del Resumen Diario no tiene el formato esperado.', 'sistema-para-farmacias'); }
        $reference_date = $this->valid_date((string)($package['reference_date'] ?? ''));
        $today = wp_date('Y-m-d');
        if ($reference_date === '') { $errors[] = __('La fecha de referencia del Resumen Diario no es válida.', 'sistema-para-farmacias'); }
        elseif ($reference_date > $today) { $errors[] = __('No se puede enviar un Resumen Diario de una fecha futura.', 'sistema-para-farmacias'); }
        elseif (strtotime($today) > strtotime($reference_date) + (7 * DAY_IN_SECONDS)) { $errors[] = __('El Resumen Diario está fuera de la ventana beta de siete días calendario.', 'sistema-para-farmacias'); }
        if (!$this->batch_enabled()) { $warnings[] = __('El beta de Resumen Diario está bloqueado. Requiere SPF_FISCAL_BETA_SUMMARY_ENABLED=true y SPF_FISCAL_BETA_SUMMARY_CONFIRMATION=SUNAT_SUMMARY_TEST_ONLY fuera del plugin.', 'sistema-para-farmacias'); }
        return ['ok'=>!$errors,'errors'=>$errors,'warnings'=>$warnings,'transport'=>$this->code(),'environment'=>$this->environment(),'transmitted'=>false];
    }

    public function submit_batch(array $package) {
        if (!$this->batch_enabled()) { return new WP_Error('spf_beta_summary_disabled', __('El transporte beta del Resumen Diario no está habilitado explícitamente.', 'sistema-para-farmacias')); }
        $inspection = $this->inspect_batch($package);
        if (empty($inspection['ok'])) { return new WP_Error('spf_beta_summary_preconditions', implode(' ', (array)$inspection['errors'])); }
        if (!class_exists('ZipArchive')) { return new WP_Error('spf_beta_summary_zip_missing', __('La extensión ZIP de PHP es necesaria para empaquetar el Resumen Diario.', 'sistema-para-farmacias')); }

        $ruc = (string)$package['issuer_tax_id'];
        $base = $ruc . '-' . strtoupper((string)$package['identifier']);
        $xml_name = $base . '.XML';
        $zip_name = $base . '.ZIP';
        $zip_bytes = $this->zip_xml($xml_name, (string)$package['xml']);
        if (is_wp_error($zip_bytes)) { return $zip_bytes; }
        $client = $this->soap_client($ruc);
        if (is_wp_error($client)) { return $client; }

        try {
            $response = $client->__soapCall('sendSummary', [['fileName'=>$zip_name,'contentFile'=>$zip_bytes]]);
        } catch (Throwable $e) {
            return new WP_Error('spf_beta_summary_soap_error', sanitize_text_field($e->getMessage()));
        }
        $ticket = $this->extract_ticket($response);
        if (is_wp_error($ticket)) { return $ticket; }
        return [
            'ok'=>true,
            'transport'=>$this->code(),
            'environment'=>$this->environment(),
            'transmitted'=>true,
            'beta_status'=>'beta_batch_ticketed',
            'ticket'=>$ticket,
            'zip_name'=>$zip_name,
            'request_zip_sha256'=>hash('sha256', $zip_bytes),
        ];
    }

    public function poll_batch(array $package) {
        if (!$this->batch_enabled()) { return new WP_Error('spf_beta_summary_disabled', __('El transporte beta del Resumen Diario no está habilitado explícitamente.', 'sistema-para-farmacias')); }
        $ticket = trim((string)($package['ticket'] ?? ''));
        if ($ticket === '') { return new WP_Error('spf_beta_summary_ticket_missing', __('El lote no tiene un ticket beta para consultar.', 'sistema-para-farmacias')); }
        if (!preg_match('/^\d{11}$/', (string)($package['issuer_tax_id'] ?? ''))) { return new WP_Error('spf_beta_summary_ruc', __('El RUC del emisor debe tener 11 dígitos.', 'sistema-para-farmacias')); }
        $client = $this->soap_client((string)$package['issuer_tax_id']);
        if (is_wp_error($client)) { return $client; }
        try {
            $response = $client->__soapCall('getStatus', [['ticket'=>$ticket]]);
        } catch (Throwable $e) {
            return new WP_Error('spf_beta_summary_status_soap_error', sanitize_text_field($e->getMessage()));
        }
        $status = $this->extract_status_response($response);
        if (is_wp_error($status)) { return $status; }
        $code = (string)$status['status_code'];
        $result = [
            'ok'=>true,
            'transport'=>$this->code(),
            'environment'=>$this->environment(),
            'transmitted'=>true,
            'ticket'=>$ticket,
            'status_code'=>$code,
        ];
        if ($code === '98') {
            $result['beta_status'] = 'beta_batch_processing';
            return $result;
        }
        $content = (string)($status['content'] ?? '');
        if ($content === '') {
            return new WP_Error('spf_beta_summary_status_content', __('La consulta beta finalizó sin la constancia ZIP esperada.', 'sistema-para-farmacias'));
        }
        $result['response_zip_sha256'] = hash('sha256', $content);
        $parsed = $this->parse_cdr_zip($content);
        if (is_wp_error($parsed)) { return $parsed; }
        $result['remote_code'] = sanitize_text_field((string)($parsed['remote_code'] ?? ''));
        $result['remote_message'] = sanitize_text_field((string)($parsed['remote_message'] ?? ''));
        $result['notes'] = array_values((array)($parsed['notes'] ?? []));
        $result['cdr_xml_sha256'] = sanitize_text_field((string)($parsed['cdr_xml_sha256'] ?? ''));
        $result['cdr_zip_bytes'] = $content;
        $result['cdr_xml_bytes'] = (string)($parsed['cdr_xml_bytes'] ?? '');
        $result['cdr_xml_name'] = sanitize_file_name((string)($parsed['cdr_xml_name'] ?? 'cdr.xml'));
        if ($code === '0') {
            $result['beta_status'] = ($parsed['beta_status'] ?? '') === 'beta_accepted_with_observation' ? 'beta_batch_accepted_with_observation' : (($parsed['beta_status'] ?? '') === 'beta_accepted' ? 'beta_batch_accepted' : 'beta_batch_rejected');
        } elseif ($code === '99') {
            $result['beta_status'] = 'beta_batch_rejected';
        } else {
            return new WP_Error('spf_beta_summary_status_unknown', sprintf(__('SUNAT beta devolvió un statusCode no soportado: %s.', 'sistema-para-farmacias'), $code));
        }
        return $result;
    }

    private function soap_client($ruc) {
        if (!class_exists('SoapClient') || !class_exists('SPF_Fiscal_Peru_Beta_Soap_Client')) { return new WP_Error('spf_beta_soap_missing', __('La extensión SOAP de PHP es necesaria para el beta SUNAT.', 'sistema-para-farmacias')); }
        $context = stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]]);
        try {
            $client = new SPF_Fiscal_Peru_Beta_Soap_Client(self::WSDL, [
                'exceptions'=>true,
                'trace'=>false,
                'cache_wsdl'=>WSDL_CACHE_NONE,
                'connection_timeout'=>20,
                'stream_context'=>$context,
                'user_agent'=>'Sistema-para-Farmacias/' . (defined('SPF_VERSION') ? SPF_VERSION : 'dev'),
            ]);
            $client->spf_set_credentials((string)$ruc . self::TEST_USER, self::TEST_PASSWORD);
            return $client;
        } catch (Throwable $e) {
            return new WP_Error('spf_beta_soap_client', sanitize_text_field($e->getMessage()));
        }
    }

    private function zip_xml($xml_name, $xml) {
        $tmp = tempnam(sys_get_temp_dir(), 'spf-beta-');
        if ($tmp === false) { return new WP_Error('spf_beta_temp', __('No se pudo crear un archivo temporal para el ZIP.', 'sistema-para-farmacias')); }
        @unlink($tmp); $zip_path = $tmp . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { return new WP_Error('spf_beta_zip_open', __('No se pudo crear el ZIP fiscal.', 'sistema-para-farmacias')); }
        $zip->addFromString($xml_name, $xml); $zip->close();
        $bytes = file_get_contents($zip_path); @unlink($zip_path);
        return $bytes === false ? new WP_Error('spf_beta_zip_read', __('No se pudo leer el ZIP fiscal.', 'sistema-para-farmacias')) : $bytes;
    }

    private function extract_ticket($response) {
        if (is_string($response) && trim($response) !== '') { return trim($response); }
        if (is_object($response)) {
            foreach (['ticket','return'] as $key) { if (isset($response->{$key}) && is_string($response->{$key}) && trim($response->{$key}) !== '') { return trim($response->{$key}); } }
        }
        if (is_array($response)) {
            foreach (['ticket','return'] as $key) { if (!empty($response[$key]) && is_string($response[$key])) { return trim($response[$key]); } }
        }
        return new WP_Error('spf_beta_summary_ticket_invalid', __('SUNAT beta no devolvió un ticket interpretable para el Resumen Diario.', 'sistema-para-farmacias'));
    }

    private function extract_status_response($response) {
        $node = $response;
        if (is_object($node) && isset($node->status)) { $node = $node->status; }
        elseif (is_array($node) && isset($node['status'])) { $node = $node['status']; }
        elseif (is_object($node) && isset($node->return)) { $node = $node->return; }
        elseif (is_array($node) && isset($node['return'])) { $node = $node['return']; }
        if (is_object($node)) {
            $code = isset($node->statusCode) ? trim((string)$node->statusCode) : '';
            $content = isset($node->content) && is_string($node->content) ? $node->content : '';
        } elseif (is_array($node)) {
            $code = isset($node['statusCode']) ? trim((string)$node['statusCode']) : '';
            $content = isset($node['content']) && is_string($node['content']) ? $node['content'] : '';
        } else {
            $code = ''; $content = '';
        }
        if ($code === '') { return new WP_Error('spf_beta_summary_status_invalid', __('SUNAT beta no devolvió un estado interpretable para el ticket.', 'sistema-para-farmacias')); }
        return ['status_code'=>$code,'content'=>$content];
    }

    private function extract_cdr_bytes($response) {
        if (is_string($response) && $response !== '') { return $response; }
        if (is_object($response)) {
            foreach (['applicationResponse','return'] as $key) { if (isset($response->{$key}) && is_string($response->{$key}) && $response->{$key} !== '') { return $response->{$key}; } }
        }
        if (is_array($response)) {
            foreach (['applicationResponse','return'] as $key) { if (!empty($response[$key]) && is_string($response[$key])) { return $response[$key]; } }
        }
        return new WP_Error('spf_beta_cdr_missing', __('SUNAT beta no devolvió una CDR interpretable.', 'sistema-para-farmacias'));
    }

    private function parse_cdr_zip($zip_bytes) {
        $tmp = tempnam(sys_get_temp_dir(), 'spf-cdr-');
        if ($tmp === false || file_put_contents($tmp, $zip_bytes) === false) { return new WP_Error('spf_beta_cdr_temp', __('No se pudo preparar la CDR temporal.', 'sistema-para-farmacias')); }
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) { @unlink($tmp); return new WP_Error('spf_beta_cdr_zip', __('La respuesta beta no contiene un ZIP CDR válido.', 'sistema-para-farmacias')); }
        $xml = '';
        $name = '';
        for ($i=0; $i<$zip->numFiles; $i++) {
            $candidate = (string)$zip->getNameIndex($i);
            if (preg_match('/\.xml$/i', $candidate)) { $name=$candidate; $xml=(string)$zip->getFromIndex($i); break; }
        }
        $zip->close(); @unlink($tmp);
        if ($xml === '') { return new WP_Error('spf_beta_cdr_xml', __('La CDR beta no contiene XML.', 'sistema-para-farmacias')); }
        $doc = new DOMDocument('1.0','UTF-8');
        if (!$doc->loadXML($xml, LIBXML_NONET)) { return new WP_Error('spf_beta_cdr_parse', __('No se pudo interpretar el XML de la CDR beta.', 'sistema-para-farmacias')); }
        $xpath = new DOMXPath($doc); $xpath->registerNamespace('cbc','urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $code = trim((string)$xpath->evaluate('string(//cbc:ResponseCode[1])'));
        $message = trim((string)$xpath->evaluate('string(//cbc:Description[1])'));
        $notes = []; foreach ($xpath->query('//cbc:Note') as $note) { $text=trim((string)$note->textContent); if($text!==''){$notes[]=$text;} }
        $status = $code === '0' ? ($notes ? 'beta_accepted_with_observation' : 'beta_accepted') : 'beta_rejected';
        return [
            'beta_status'=>$status,
            'remote_code'=>$code,
            'remote_message'=>$message,
            'notes'=>$notes,
            'cdr_xml_sha256'=>hash('sha256',$xml),
            'cdr_xml_name'=>sanitize_file_name($name ?: 'cdr.xml'),
            'cdr_xml_bytes'=>$xml,
        ];
    }

    private function valid_date($value) {
        $value = trim((string)$value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) { return ''; }
        $parts = array_map('intval', explode('-', $value));
        return count($parts) === 3 && checkdate($parts[1], $parts[2], $parts[0]) ? $value : '';
    }
}
