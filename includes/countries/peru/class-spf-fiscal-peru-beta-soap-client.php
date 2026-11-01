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

        /**
         * Compatible with SoapClient::__doRequest() on PHP 7.4 through PHP 8.5+.
         * PHP 8.5 added the optional $uri_parser_class parameter.
         */
        public function __doRequest($request, $location, $action, $version, $one_way = false, $uri_parser_class = null): ?string {
            $doc = new DOMDocument('1.0', 'UTF-8');
            $doc->preserveWhiteSpace = false;
            if (!$doc->loadXML((string) $request, LIBXML_NONET)) {
                throw new RuntimeException('SOAP request XML could not be parsed.');
            }
            $envelope = $doc->documentElement;
            $soap_ns = $envelope ? $envelope->namespaceURI : 'http://schemas.xmlsoap.org/soap/envelope/';
            $header = null;
            foreach ($envelope->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'Header' && $child->namespaceURI === $soap_ns) {
                    $header = $child;
                    break;
                }
            }
            if (!$header) {
                $header = $doc->createElementNS($soap_ns, 'soapenv:Header');
                $body = null;
                foreach ($envelope->childNodes as $child) {
                    if ($child instanceof DOMElement && $child->localName === 'Body' && $child->namespaceURI === $soap_ns) {
                        $body = $child;
                        break;
                    }
                }
                if ($body) {
                    $envelope->insertBefore($header, $body);
                } else {
                    $envelope->appendChild($header);
                }
            }

            $wsse = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
            $security = $doc->createElementNS($wsse, 'wsse:Security');
            $token = $doc->createElementNS($wsse, 'wsse:UsernameToken');
            $username = $doc->createElementNS($wsse, 'wsse:Username');
            $username->appendChild($doc->createTextNode($this->spf_username));
            $password = $doc->createElementNS($wsse, 'wsse:Password');
            $password->appendChild($doc->createTextNode($this->spf_password));
            $token->appendChild($username);
            $token->appendChild($password);
            $security->appendChild($token);
            $header->appendChild($security);

            $xml = $doc->saveXML();
            if (PHP_VERSION_ID >= 80500) {
                return parent::__doRequest($xml, $location, $action, $version, $one_way, $uri_parser_class);
            }
            return parent::__doRequest($xml, $location, $action, $version, $one_way);
        }
    }
}
