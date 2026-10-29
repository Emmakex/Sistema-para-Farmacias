<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Adapter_Peru implements SPF_Fiscal_Adapter_Interface {
    const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    public function code() { return 'peru'; }
    public function country_code() { return 'pe'; }
    public function supports($document_type) { return in_array(sanitize_key((string) $document_type), ['factura', 'boleta'], true); }

    public function validate(array $context) {
        $errors = []; $warnings = [];
        $document = $context['document'] ?? []; $sale = $context['sale'] ?? []; $issuer = $context['issuer'] ?? []; $customer = $context['customer'] ?? []; $items = $context['items'] ?? [];
        $type = sanitize_key($document['type'] ?? '');
        if (($context['country_code'] ?? '') !== 'pe') { $errors[] = __('El adaptador Perú solo puede utilizarse con el perfil de país Perú.', 'sistema-para-farmacias'); }
        if (!$this->supports($type)) { $errors[] = __('El comprobante no está soportado por el adaptador Perú.', 'sistema-para-farmacias'); }
        if (($sale['currency'] ?? '') !== 'PEN') { $errors[] = __('El preflight Perú requiere moneda PEN en esta primera versión.', 'sistema-para-farmacias'); }
        if (!preg_match('/^\d{11}$/', (string) ($issuer['tax_id'] ?? ''))) { $errors[] = __('Configura un RUC emisor de 11 dígitos.', 'sistema-para-farmacias'); }
        if (trim((string) ($issuer['legal_name'] ?? '')) === '') { $errors[] = __('Configura la razón social del emisor.', 'sistema-para-farmacias'); }
        if (trim((string) ($issuer['address'] ?? '')) === '') { $errors[] = __('Configura la dirección fiscal del emisor.', 'sistema-para-farmacias'); }
        $series = strtoupper((string) ($document['series'] ?? '')); $expected_prefix = $type === 'factura' ? 'F' : 'B';
        if (!preg_match('/^' . $expected_prefix . '[A-Z0-9]{3}$/', $series)) { $errors[] = sprintf(__('La serie de %1$s debe comenzar por %2$s y contener cuatro caracteres.', 'sistema-para-farmacias'), $type, $expected_prefix); }
        if (!preg_match('/^\d{1,8}$/', (string) ($document['correlative'] ?? ''))) { $errors[] = __('El correlativo debe ser numérico y de hasta 8 dígitos.', 'sistema-para-farmacias'); }
        if (!$items) { $errors[] = __('El comprobante no contiene líneas de venta.', 'sistema-para-farmacias'); }
        if ((float) ($sale['total'] ?? 0) <= 0) { $errors[] = __('El total del comprobante debe ser mayor que cero.', 'sistema-para-farmacias'); }
        if ($type === 'factura') {
            if (($customer['document_type'] ?? '') !== 'RUC' || !preg_match('/^\d{11}$/', (string) ($customer['document_number'] ?? ''))) { $errors[] = __('La factura requiere un cliente identificado con RUC de 11 dígitos.', 'sistema-para-farmacias'); }
            if (trim((string) (($customer['business_name'] ?? '') ?: ($customer['name'] ?? ''))) === '') { $errors[] = __('La factura requiere razón social o nombre del adquirente.', 'sistema-para-farmacias'); }
        }
        if (empty($issuer['district']) || empty($issuer['province']) || empty($issuer['region'])) { $warnings[] = __('Completa distrito, provincia y región del emisor antes de una futura emisión real.', 'sistema-para-farmacias'); }
        if ($type === 'boleta' && empty($customer['document_number'])) { $warnings[] = __('La boleta se está preparando sin documento del cliente; verifica si la operación concreta exige identificación.', 'sistema-para-farmacias'); }
        foreach ($items as $item) {
            if ((int) ($item['quantity'] ?? 0) <= 0) { $errors[] = __('Hay una línea con cantidad inválida.', 'sistema-para-farmacias'); break; }
            if (!in_array(sanitize_key($item['tax_type'] ?? ''), ['gravado', 'exonerado', 'inafecto'], true)) { $errors[] = __('Hay una línea con una afectación tributaria no mapeada para Perú.', 'sistema-para-farmacias'); break; }
        }
        return ['errors' => $errors, 'warnings' => $warnings];
    }

    public function build_preview_xml(array $context) {
        $validation = $this->validate($context);
        if (!empty($validation['errors'])) { return new WP_Error('spf_peru_preflight', implode(' ', $validation['errors'])); }
        if (!class_exists('DOMDocument')) { return new WP_Error('spf_dom_missing', __('La extensión DOM de PHP es necesaria para generar XML UBL.', 'sistema-para-farmacias')); }
        $doc = new DOMDocument('1.0', 'UTF-8'); $doc->formatOutput = true;
        $root = $doc->createElementNS(self::NS_INVOICE, 'Invoice'); $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::NS_CAC); $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::NS_CBC); $doc->appendChild($root);
        $document = $context['document']; $sale = $context['sale']; $issuer = $context['issuer']; $customer = $context['customer'];
        $this->cbc($doc,$root,'UBLVersionID','2.1'); $this->cbc($doc,$root,'CustomizationID','2.0');
        $profile=$this->cbc($doc,$root,'ProfileID','0101'); $profile->setAttribute('schemeName','SUNAT:Identificador de Tipo de Operación'); $profile->setAttribute('schemeAgencyName','PE:SUNAT'); $profile->setAttribute('schemeURI','urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo17');
        $this->cbc($doc,$root,'ID',strtoupper($document['series']).'-'.(string)$document['correlative']);
        $this->cbc($doc,$root,'IssueDate',$sale['date']?mysql2date('Y-m-d',$sale['date']):wp_date('Y-m-d'));
        $invoice_type=$this->cbc($doc,$root,'InvoiceTypeCode',$document['type']==='factura'?'01':'03'); $invoice_type->setAttribute('listAgencyName','PE:SUNAT'); $invoice_type->setAttribute('listName','SUNAT:Identificador de Tipo de Documento'); $invoice_type->setAttribute('listURI','urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo01');
        $currency=$this->cbc($doc,$root,'DocumentCurrencyCode','PEN'); $currency->setAttribute('listID','ISO 4217 Alpha'); $currency->setAttribute('listName','Currency'); $this->cbc($doc,$root,'LineCountNumeric',(string)count($context['items']));
        $supplier=$this->cac($doc,$root,'AccountingSupplierParty'); $supplier_party=$this->cac($doc,$supplier,'Party'); if(!empty($issuer['trade_name'])){$party_name=$this->cac($doc,$supplier_party,'PartyName');$this->cbc($doc,$party_name,'Name',$issuer['trade_name']);}
        $supplier_tax=$this->cac($doc,$supplier_party,'PartyTaxScheme'); $this->cbc($doc,$supplier_tax,'RegistrationName',$issuer['legal_name']); $company=$this->cbc($doc,$supplier_tax,'CompanyID',$issuer['tax_id']); $this->identity_attributes($company,'6');
        $registration=$this->cac($doc,$supplier_tax,'RegistrationAddress'); $this->cbc($doc,$registration,'AddressTypeCode','0000'); $address=$this->cac($doc,$registration,'AddressLine'); $this->cbc($doc,$address,'Line',$issuer['address']);
        $supplier_scheme=$this->cac($doc,$supplier_tax,'TaxScheme'); $this->cbc($doc,$supplier_scheme,'ID','1000'); $this->cbc($doc,$supplier_scheme,'Name','IGV'); $this->cbc($doc,$supplier_scheme,'TaxTypeCode','VAT');
        $customer_party=$this->cac($doc,$root,'AccountingCustomerParty'); $party=$this->cac($doc,$customer_party,'Party'); $party_tax=$this->cac($doc,$party,'PartyTaxScheme'); $customer_name=($customer['business_name']??'')?: (($customer['name']??'')?:'CLIENTE'); $this->cbc($doc,$party_tax,'RegistrationName',$customer_name); $company_id=$this->cbc($doc,$party_tax,'CompanyID',(string)($customer['document_number']??'')); $this->identity_attributes($company_id,$this->identity_code($customer['document_type']??'')); $customer_scheme=$this->cac($doc,$party_tax,'TaxScheme'); $this->cbc($doc,$customer_scheme,'ID','');
        $tax_total=$this->cac($doc,$root,'TaxTotal'); $tax_amount=$this->cbc($doc,$tax_total,'TaxAmount',$this->amount($sale['tax'])); $tax_amount->setAttribute('currencyID','PEN');
        $legal=$this->cac($doc,$root,'LegalMonetaryTotal'); $line_ext=$this->cbc($doc,$legal,'LineExtensionAmount',$this->amount($sale['subtotal'])); $line_ext->setAttribute('currencyID','PEN'); $tax_inc=$this->cbc($doc,$legal,'TaxInclusiveAmount',$this->amount($sale['total'])); $tax_inc->setAttribute('currencyID','PEN'); $payable=$this->cbc($doc,$legal,'PayableAmount',$this->amount($sale['total'])); $payable->setAttribute('currencyID','PEN');
        foreach($context['items'] as $index=>$item){$this->append_line($doc,$root,$item,$index+1);} return $doc->saveXML();
    }

    public function normalize_remote_status($status,$message=''){ $status=sanitize_key((string)$status);$allowed=['accepted','accepted_with_observation','rejected','error'];return['status'=>in_array($status,$allowed,true)?$status:'error','message'=>sanitize_text_field($message)]; }
    private function append_line(DOMDocument $doc,DOMElement $root,array $item,$number){$line=$this->cac($doc,$root,'InvoiceLine');$this->cbc($doc,$line,'ID',(string)$number);$qty=max(1,(int)($item['quantity']??1));$quantity=$this->cbc($doc,$line,'InvoicedQuantity',(string)$qty);$quantity->setAttribute('unitCode','NIU');$extension=$this->cbc($doc,$line,'LineExtensionAmount',$this->amount($item['subtotal']??0));$extension->setAttribute('currencyID','PEN');$tax_total=$this->cac($doc,$line,'TaxTotal');$tax_amount=$this->cbc($doc,$tax_total,'TaxAmount',$this->amount($item['igv']??0));$tax_amount->setAttribute('currencyID','PEN');$subtotal=$this->cac($doc,$tax_total,'TaxSubtotal');$taxable=$this->cbc($doc,$subtotal,'TaxableAmount',$this->amount($item['subtotal']??0));$taxable->setAttribute('currencyID','PEN');$sub_tax=$this->cbc($doc,$subtotal,'TaxAmount',$this->amount($item['igv']??0));$sub_tax->setAttribute('currencyID','PEN');$category=$this->cac($doc,$subtotal,'TaxCategory');$tax_type=sanitize_key($item['tax_type']??'gravado');$this->cbc($doc,$category,'ID',$tax_type==='gravado'?'S':($tax_type==='exonerado'?'E':'O'));$this->cbc($doc,$category,'Percent',$tax_type==='gravado'?'18.00':'0.00');$this->cbc($doc,$category,'TaxExemptionReasonCode',$tax_type==='gravado'?'10':($tax_type==='exonerado'?'20':'30'));$scheme=$this->cac($doc,$category,'TaxScheme');$this->cbc($doc,$scheme,'ID','1000');$this->cbc($doc,$scheme,'Name','IGV');$this->cbc($doc,$scheme,'TaxTypeCode','VAT');$item_node=$this->cac($doc,$line,'Item');$this->cbc($doc,$item_node,'Description',(string)($item['product_name']??'Producto'));$price=$this->cac($doc,$line,'Price');$unit_value=$qty>0?((float)($item['subtotal']??0)/$qty):0;$price_amount=$this->cbc($doc,$price,'PriceAmount',$this->amount($unit_value));$price_amount->setAttribute('currencyID','PEN');}
    private function identity_code($type){$map=['DNI'=>'1','CE'=>'4','RUC'=>'6','PASSPORT'=>'7'];return$map[strtoupper((string)$type)]??'0';}
    private function identity_attributes(DOMElement $element,$code){$element->setAttribute('schemeID',(string)$code);$element->setAttribute('schemeName','SUNAT:Identificador de Documento de Identidad');$element->setAttribute('schemeAgencyName','PE:SUNAT');$element->setAttribute('schemeURI','urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06');}
    private function cac(DOMDocument $doc,DOMElement $parent,$name){$node=$doc->createElementNS(self::NS_CAC,'cac:'.$name);$parent->appendChild($node);return$node;}
    private function cbc(DOMDocument $doc,DOMElement $parent,$name,$value){$node=$doc->createElementNS(self::NS_CBC,'cbc:'.$name);$node->appendChild($doc->createTextNode((string)$value));$parent->appendChild($node);return$node;}
    private function amount($value){return number_format((float)$value,2,'.','');}
}
