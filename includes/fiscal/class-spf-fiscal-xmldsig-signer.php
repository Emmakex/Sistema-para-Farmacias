<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_XMLDSig_Signer implements SPF_Fiscal_Signer_Interface {
    const DS_NS = 'http://www.w3.org/2000/09/xmldsig#';
    const EXT_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';
    const CAC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    const CBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    const ENVELOPED_URI = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';
    const C14N_URI = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
    const C14N_COMMENTS_URI = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315#WithComments';
    const RSA_SHA256_URI = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
    const RSA_SHA1_URI = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';
    const SHA256_URI = 'http://www.w3.org/2001/04/xmlenc#sha256';
    const SHA1_URI = 'http://www.w3.org/2000/09/xmldsig#sha1';

    public function code() { return 'xmldsig'; }

    public function supports(array $profile) {
        $signature = sanitize_key((string) ($profile['signature_method'] ?? 'rsa_sha256'));
        $digest = sanitize_key((string) ($profile['digest_method'] ?? 'sha256'));
        return in_array($signature, ['rsa_sha256', 'rsa_sha1_legacy'], true)
            && in_array($digest, ['sha256', 'sha1_legacy'], true);
    }

    public function sign($xml, array $credentials, array $profile, array $context = []) {
        if (!$this->supports($profile)) {
            return new WP_Error('spf_fiscal_signature_profile', __('El perfil criptográfico solicitado no está soportado.', 'sistema-para-farmacias'));
        }
        if (!class_exists('DOMDocument') || !extension_loaded('openssl')) {
            return new WP_Error('spf_fiscal_signature_runtime', __('DOM y OpenSSL son necesarios para firmar XML.', 'sistema-para-farmacias'));
        }
        $certificate = (string) ($credentials['certificate_pem'] ?? '');
        $private_key_pem = (string) ($credentials['private_key_pem'] ?? '');
        if ($certificate === '' || $private_key_pem === '') {
            return new WP_Error('spf_fiscal_signature_credentials', __('Faltan certificado o clave privada para firmar.', 'sistema-para-farmacias'));
        }

        $doc = $this->load_xml($xml);
        if (is_wp_error($doc)) { return $doc; }
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('ds', self::DS_NS);
        if ($xpath->query('//ds:Signature')->length > 0) {
            return new WP_Error('spf_fiscal_already_signed', __('El XML ya contiene una firma digital.', 'sistema-para-farmacias'));
        }

        $signature_id = sanitize_html_class((string) ($profile['signature_id'] ?? 'signatureSPF'));
        if ($signature_id === '') { $signature_id = 'signatureSPF'; }
        $canonical_uri = !empty($profile['canonicalization_with_comments']) ? self::C14N_COMMENTS_URI : self::C14N_URI;
        $with_comments = $canonical_uri === self::C14N_COMMENTS_URI;
        $signature_uri = $this->signature_uri($profile['signature_method'] ?? 'rsa_sha256');
        $digest_uri = $this->digest_uri($profile['digest_method'] ?? 'sha256');
        $digest_algo = $this->digest_algorithm_from_uri($digest_uri);
        $openssl_algo = $this->openssl_algorithm_from_uri($signature_uri);
        if (!$signature_uri || !$digest_uri || !$digest_algo || !$openssl_algo) {
            return new WP_Error('spf_fiscal_signature_algorithm', __('El perfil de algoritmos de firma es inválido.', 'sistema-para-farmacias'));
        }

        $root = $doc->documentElement;
        if (!$root) { return new WP_Error('spf_fiscal_signature_root', __('El XML no tiene nodo raíz.', 'sistema-para-farmacias')); }

        $extension_content = $this->insert_ubl_extensions($doc, $root);
        if (is_wp_error($extension_content)) { return $extension_content; }
        $this->insert_ubl_signature_metadata($doc, $root, $signature_id, $context);

        $signature = $doc->createElementNS(self::DS_NS, 'ds:Signature');
        $signature->setAttribute('Id', $signature_id);
        $extension_content->appendChild($signature);

        $signed_info = $doc->createElementNS(self::DS_NS, 'ds:SignedInfo');
        $signature->appendChild($signed_info);
        $canonicalization = $doc->createElementNS(self::DS_NS, 'ds:CanonicalizationMethod');
        $canonicalization->setAttribute('Algorithm', $canonical_uri);
        $signed_info->appendChild($canonicalization);
        $signature_method = $doc->createElementNS(self::DS_NS, 'ds:SignatureMethod');
        $signature_method->setAttribute('Algorithm', $signature_uri);
        $signed_info->appendChild($signature_method);
        $reference = $doc->createElementNS(self::DS_NS, 'ds:Reference');
        $reference->setAttribute('URI', '');
        $signed_info->appendChild($reference);
        $transforms = $doc->createElementNS(self::DS_NS, 'ds:Transforms');
        $reference->appendChild($transforms);
        $transform = $doc->createElementNS(self::DS_NS, 'ds:Transform');
        $transform->setAttribute('Algorithm', self::ENVELOPED_URI);
        $transforms->appendChild($transform);
        $digest_method = $doc->createElementNS(self::DS_NS, 'ds:DigestMethod');
        $digest_method->setAttribute('Algorithm', $digest_uri);
        $reference->appendChild($digest_method);
        $digest_value = $doc->createElementNS(self::DS_NS, 'ds:DigestValue');
        $reference->appendChild($digest_value);

        $digest_input = $this->canonical_document_without_signature($doc, false);
        if (is_wp_error($digest_input)) { return $digest_input; }
        $digest_value->appendChild($doc->createTextNode(base64_encode(hash($digest_algo, $digest_input, true))));

        $signed_info_c14n = $signed_info->C14N(false, $with_comments);
        if ($signed_info_c14n === false) {
            return new WP_Error('spf_fiscal_signature_c14n', __('No se pudo canonicalizar SignedInfo.', 'sistema-para-farmacias'));
        }
        $private_key = openssl_pkey_get_private($private_key_pem);
        if (!$private_key) {
            return new WP_Error('spf_fiscal_private_key', __('La clave privada del certificado no es válida.', 'sistema-para-farmacias'));
        }
        $raw_signature = '';
        $signed = openssl_sign($signed_info_c14n, $raw_signature, $private_key, $openssl_algo);
        if (!$signed) {
            return new WP_Error('spf_fiscal_openssl_sign', __('OpenSSL no pudo generar la firma digital.', 'sistema-para-farmacias'));
        }

        $signature_value = $doc->createElementNS(self::DS_NS, 'ds:SignatureValue');
        $signature_value->appendChild($doc->createTextNode(base64_encode($raw_signature)));
        $signature->appendChild($signature_value);
        $key_info = $doc->createElementNS(self::DS_NS, 'ds:KeyInfo');
        $x509_data = $doc->createElementNS(self::DS_NS, 'ds:X509Data');
        $x509_cert = $doc->createElementNS(self::DS_NS, 'ds:X509Certificate');
        $x509_cert->appendChild($doc->createTextNode($this->certificate_base64($certificate)));
        $x509_data->appendChild($x509_cert);
        $key_info->appendChild($x509_data);
        $signature->appendChild($key_info);

        $signed_xml = $doc->saveXML();
        if (!$signed_xml) { return new WP_Error('spf_fiscal_signature_serialize', __('No se pudo serializar el XML firmado.', 'sistema-para-farmacias')); }
        $verification = $this->verify($signed_xml, $profile);
        if (is_wp_error($verification)) { return $verification; }

        return [
            'xml' => $signed_xml,
            'verified' => true,
            'signature_id' => $signature_id,
            'signature_method' => $signature_uri,
            'digest_method' => $digest_uri,
            'canonicalization_method' => $canonical_uri,
            'certificate_sha256' => hash('sha256', base64_decode($this->certificate_base64($certificate))),
            'signed_xml_sha256' => hash('sha256', $signed_xml),
            'verification' => $verification,
        ];
    }

    public function verify($signed_xml, array $profile = []) {
        $doc = $this->load_xml($signed_xml);
        if (is_wp_error($doc)) { return $doc; }
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('ds', self::DS_NS);
        $signatures = $xpath->query('//ds:Signature');
        if ($signatures->length !== 1) {
            return new WP_Error('spf_fiscal_signature_count', __('El XML debe contener exactamente una firma XMLDSig.', 'sistema-para-farmacias'));
        }
        $signature = $signatures->item(0);
        $signed_info = $xpath->query('./ds:SignedInfo', $signature)->item(0);
        $signature_value = $xpath->query('./ds:SignatureValue', $signature)->item(0);
        $certificate_node = $xpath->query('./ds:KeyInfo/ds:X509Data/ds:X509Certificate', $signature)->item(0);
        $digest_node = $xpath->query('./ds:SignedInfo/ds:Reference/ds:DigestValue', $signature)->item(0);
        $canonical_node = $xpath->query('./ds:SignedInfo/ds:CanonicalizationMethod', $signature)->item(0);
        $signature_method_node = $xpath->query('./ds:SignedInfo/ds:SignatureMethod', $signature)->item(0);
        $digest_method_node = $xpath->query('./ds:SignedInfo/ds:Reference/ds:DigestMethod', $signature)->item(0);
        if (!$signed_info || !$signature_value || !$certificate_node || !$digest_node || !$canonical_node || !$signature_method_node || !$digest_method_node) {
            return new WP_Error('spf_fiscal_signature_structure', __('La firma XMLDSig está incompleta.', 'sistema-para-farmacias'));
        }

        $canonical_uri = (string) $canonical_node->getAttribute('Algorithm');
        $with_comments = $canonical_uri === self::C14N_COMMENTS_URI;
        if (!in_array($canonical_uri, [self::C14N_URI, self::C14N_COMMENTS_URI], true)) {
            return new WP_Error('spf_fiscal_signature_c14n_algorithm', __('El algoritmo de canonicalización no está soportado.', 'sistema-para-farmacias'));
        }
        $signature_uri = (string) $signature_method_node->getAttribute('Algorithm');
        $digest_uri = (string) $digest_method_node->getAttribute('Algorithm');
        $openssl_algo = $this->openssl_algorithm_from_uri($signature_uri);
        $digest_algo = $this->digest_algorithm_from_uri($digest_uri);
        if (!$openssl_algo || !$digest_algo) {
            return new WP_Error('spf_fiscal_signature_algorithm_verify', __('La firma usa algoritmos no soportados por el verificador local.', 'sistema-para-farmacias'));
        }

        $digest_input = $this->canonical_document_without_signature($doc, false);
        if (is_wp_error($digest_input)) { return $digest_input; }
        $expected_digest = base64_encode(hash($digest_algo, $digest_input, true));
        if (!hash_equals(trim((string) $digest_node->textContent), $expected_digest)) {
            return new WP_Error('spf_fiscal_digest_mismatch', __('El digest del XML firmado no coincide con el documento.', 'sistema-para-farmacias'));
        }

        $signed_info_c14n = $signed_info->C14N(false, $with_comments);
        if ($signed_info_c14n === false) {
            return new WP_Error('spf_fiscal_verify_c14n', __('No se pudo canonicalizar SignedInfo para verificarlo.', 'sistema-para-farmacias'));
        }
        $certificate_pem = $this->certificate_pem_from_base64($certificate_node->textContent);
        $parsed = openssl_x509_parse($certificate_pem, false);
        if (!is_array($parsed)) { return new WP_Error('spf_fiscal_verify_certificate', __('El certificado incrustado no es válido.', 'sistema-para-farmacias')); }
        $now = time();
        if (!empty($parsed['validFrom_time_t']) && $now < (int) $parsed['validFrom_time_t']) {
            return new WP_Error('spf_fiscal_verify_not_yet_valid', __('El certificado incrustado todavía no es válido.', 'sistema-para-farmacias'));
        }
        if (!empty($parsed['validTo_time_t']) && $now > (int) $parsed['validTo_time_t']) {
            return new WP_Error('spf_fiscal_verify_expired', __('El certificado incrustado está vencido.', 'sistema-para-farmacias'));
        }
        $public_key = openssl_pkey_get_public($certificate_pem);
        if (!$public_key) { return new WP_Error('spf_fiscal_verify_public_key', __('No se pudo extraer la clave pública del certificado.', 'sistema-para-farmacias')); }
        $raw_signature = base64_decode(trim((string) $signature_value->textContent), true);
        if ($raw_signature === false) { return new WP_Error('spf_fiscal_verify_signature_encoding', __('SignatureValue no contiene Base64 válido.', 'sistema-para-farmacias')); }
        $verified = openssl_verify($signed_info_c14n, $raw_signature, $public_key, $openssl_algo);
        if ($verified !== 1) {
            return new WP_Error('spf_fiscal_signature_invalid', __('La firma criptográfica no supera la verificación local.', 'sistema-para-farmacias'));
        }

        return [
            'ok' => true,
            'signature_method' => $signature_uri,
            'digest_method' => $digest_uri,
            'canonicalization_method' => $canonical_uri,
            'certificate_sha256' => hash('sha256', base64_decode(preg_replace('/\s+/', '', (string) $certificate_node->textContent))),
            'certificate_valid_to' => (int) ($parsed['validTo_time_t'] ?? 0),
        ];
    }

    private function insert_ubl_extensions(DOMDocument $doc, DOMElement $root) {
        $extensions = $doc->createElementNS(self::EXT_NS, 'ext:UBLExtensions');
        $extension = $doc->createElementNS(self::EXT_NS, 'ext:UBLExtension');
        $content = $doc->createElementNS(self::EXT_NS, 'ext:ExtensionContent');
        $extension->appendChild($content);
        $extensions->appendChild($extension);
        $first = $root->firstChild;
        if ($first) { $root->insertBefore($extensions, $first); } else { $root->appendChild($extensions); }
        return $content;
    }

    private function insert_ubl_signature_metadata(DOMDocument $doc, DOMElement $root, $signature_id, array $context) {
        $signature = $doc->createElementNS(self::CAC_NS, 'cac:Signature');
        $id = $doc->createElementNS(self::CBC_NS, 'cbc:ID'); $id->appendChild($doc->createTextNode($signature_id)); $signature->appendChild($id);
        $party = $doc->createElementNS(self::CAC_NS, 'cac:SignatoryParty');
        $party_identification = $doc->createElementNS(self::CAC_NS, 'cac:PartyIdentification');
        $party_id = $doc->createElementNS(self::CBC_NS, 'cbc:ID'); $party_id->appendChild($doc->createTextNode((string) ($context['issuer']['tax_id'] ?? ''))); $party_identification->appendChild($party_id);
        $party->appendChild($party_identification);
        $party_name = $doc->createElementNS(self::CAC_NS, 'cac:PartyName');
        $name = $doc->createElementNS(self::CBC_NS, 'cbc:Name'); $name->appendChild($doc->createTextNode((string) ($context['issuer']['legal_name'] ?? ''))); $party_name->appendChild($name);
        $party->appendChild($party_name); $signature->appendChild($party);
        $attachment = $doc->createElementNS(self::CAC_NS, 'cac:DigitalSignatureAttachment');
        $external = $doc->createElementNS(self::CAC_NS, 'cac:ExternalReference');
        $uri = $doc->createElementNS(self::CBC_NS, 'cbc:URI'); $uri->appendChild($doc->createTextNode('#' . $signature_id)); $external->appendChild($uri);
        $attachment->appendChild($external); $signature->appendChild($attachment);

        $before = null;
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement && $child->namespaceURI === self::CAC_NS && $child->localName === 'AccountingSupplierParty') { $before = $child; break; }
        }
        if ($before) { $root->insertBefore($signature, $before); } else { $root->appendChild($signature); }
    }

    private function canonical_document_without_signature(DOMDocument $doc, $with_comments) {
        $clone = $doc->cloneNode(true);
        if (!$clone instanceof DOMDocument || !$clone->documentElement) {
            return new WP_Error('spf_fiscal_digest_clone', __('No se pudo preparar el XML para calcular el digest.', 'sistema-para-farmacias'));
        }
        $xpath = new DOMXPath($clone); $xpath->registerNamespace('ds', self::DS_NS);
        $nodes = $xpath->query('//ds:Signature');
        for ($i = $nodes->length - 1; $i >= 0; $i--) { $node = $nodes->item($i); if ($node && $node->parentNode) { $node->parentNode->removeChild($node); } }
        $canonical = $clone->documentElement->C14N(false, (bool) $with_comments);
        return $canonical === false ? new WP_Error('spf_fiscal_digest_c14n', __('No se pudo canonicalizar el documento.', 'sistema-para-farmacias')) : $canonical;
    }

    private function load_xml($xml) {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false; $doc->formatOutput = false;
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML((string) $xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        return $ok ? $doc : new WP_Error('spf_fiscal_xml_invalid', __('El XML no es válido y no puede firmarse.', 'sistema-para-farmacias'));
    }

    private function signature_uri($method) {
        $method = sanitize_key((string) $method);
        if ($method === 'rsa_sha256') { return self::RSA_SHA256_URI; }
        if ($method === 'rsa_sha1_legacy') { return self::RSA_SHA1_URI; }
        return '';
    }
    private function digest_uri($method) {
        $method = sanitize_key((string) $method);
        if ($method === 'sha256') { return self::SHA256_URI; }
        if ($method === 'sha1_legacy') { return self::SHA1_URI; }
        return '';
    }
    private function openssl_algorithm_from_uri($uri) {
        if ($uri === self::RSA_SHA256_URI) { return OPENSSL_ALGO_SHA256; }
        if ($uri === self::RSA_SHA1_URI) { return OPENSSL_ALGO_SHA1; }
        return null;
    }
    private function digest_algorithm_from_uri($uri) {
        if ($uri === self::SHA256_URI) { return 'sha256'; }
        if ($uri === self::SHA1_URI) { return 'sha1'; }
        return null;
    }
    private function certificate_base64($pem) { return preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', (string) $pem); }
    private function certificate_pem_from_base64($base64) {
        $clean = preg_replace('/\s+/', '', (string) $base64);
        return "-----BEGIN CERTIFICATE-----\n" . chunk_split($clean, 64, "\n") . "-----END CERTIFICATE-----\n";
    }
}
