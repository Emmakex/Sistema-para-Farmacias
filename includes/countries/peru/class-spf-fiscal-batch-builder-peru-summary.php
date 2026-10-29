<?php
if (!defined('ABSPATH')) { exit; }

final class SPF_Fiscal_Batch_Builder_Peru_Summary implements SPF_Fiscal_Batch_Builder_Interface {
    const NS_SUMMARY = 'urn:sunat:names:specification:ubl:peru:schema:xsd:SummaryDocuments-1';
    const NS_SAC = 'urn:sunat:names:specification:ubl:peru:schema:xsd:SunatAggregateComponents-1';
    const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    public function code() { return 'peru_summary_documents'; }
    public function country_code() { return 'pe'; }
    public function supports($batch_type) { return sanitize_key((string)$batch_type) === 'daily_summary'; }

    public function build(array $context) {
        $validation = $this->validate($context);
        if (!empty($validation['errors'])) {
            return new WP_Error('spf_peru_summary_invalid', implode(' ', $validation['errors']));
        }
        if (!class_exists('DOMDocument')) {
            return new WP_Error('spf_peru_summary_dom_missing', __('La extensión DOM de PHP es necesaria para construir el resumen diario.', 'sistema-para-farmacias'));
        }

        $reference_date = (string)$context['reference_date'];
        $sequence = max(1, (int)($context['sequence'] ?? 1));
        $identifier = 'RC-' . str_replace('-', '', $reference_date) . '-' . str_pad((string)$sequence, 5, '0', STR_PAD_LEFT);
        $issuer = (array)$context['issuer'];

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElementNS(self::NS_SUMMARY, 'p:SummaryDocuments');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sac', self::NS_SAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::NS_CAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::NS_CBC);
        $doc->appendChild($root);

        $this->cbc($doc, $root, 'UBLVersionID', '2.0');
        $this->cbc($doc, $root, 'CustomizationID', '1.1');
        $this->cbc($doc, $root, 'ID', $identifier);
        $this->cbc($doc, $root, 'ReferenceDate', $reference_date);
        $this->cbc($doc, $root, 'IssueDate', (string)($context['issue_date'] ?? wp_date('Y-m-d')));
        $this->cbc($doc, $root, 'Note', 'CONSOLIDADO DE BOLETAS DE VENTA');

        $supplier = $this->cac($doc, $root, 'AccountingSupplierParty');
        $this->cbc($doc, $supplier, 'CustomerAssignedAccountID', (string)$issuer['tax_id']);
        $this->cbc($doc, $supplier, 'AdditionalAccountID', '6');
        $party = $this->cac($doc, $supplier, 'Party');
        $legal = $this->cac($doc, $party, 'PartyLegalEntity');
        $this->cbc($doc, $legal, 'RegistrationName', (string)$issuer['legal_name']);

        foreach (array_values((array)$context['lines']) as $index => $line) {
            $this->append_line($doc, $root, $line, $index + 1);
        }

        $xml = $doc->saveXML();
        if (!$xml) { return new WP_Error('spf_peru_summary_serialize', __('No se pudo serializar el resumen diario.', 'sistema-para-farmacias')); }
        return [
            'xml' => $xml,
            'identifier' => $identifier,
            'reference_date' => $reference_date,
            'issue_date' => (string)($context['issue_date'] ?? wp_date('Y-m-d')),
            'line_count' => count((array)$context['lines']),
            'sha256' => hash('sha256', $xml),
            'warnings' => $validation['warnings'],
        ];
    }

    private function validate(array $context) {
        $errors = []; $warnings = [];
        if (($context['country_code'] ?? '') !== 'pe') { $errors[] = __('El builder de resumen Perú solo admite el perfil Perú.', 'sistema-para-farmacias'); }
        if (($context['batch_type'] ?? '') !== 'daily_summary') { $errors[] = __('El builder Perú solo admite resumen diario.', 'sistema-para-farmacias'); }
        if (($context['currency'] ?? '') !== 'PEN') { $errors[] = __('El resumen diario Perú requiere moneda PEN.', 'sistema-para-farmacias'); }
        if (!preg_match('/^\d{11}$/', (string)($context['issuer']['tax_id'] ?? ''))) { $errors[] = __('El resumen diario requiere RUC emisor de 11 dígitos.', 'sistema-para-farmacias'); }
        if (trim((string)($context['issuer']['legal_name'] ?? '')) === '') { $errors[] = __('El resumen diario requiere razón social del emisor.', 'sistema-para-farmacias'); }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($context['reference_date'] ?? ''))) { $errors[] = __('La fecha de referencia del resumen no es válida.', 'sistema-para-farmacias'); }
        $lines = (array)($context['lines'] ?? []);
        if (!$lines) { $errors[] = __('No hay boletas para construir el resumen diario.', 'sistema-para-farmacias'); }
        foreach ($lines as $line) {
            if (($line['fiscal_type_code'] ?? '') !== '03') { $errors[] = __('El primer resumen diario solo admite boletas código 03.', 'sistema-para-farmacias'); break; }
            if (!preg_match('/^B[A-Z0-9]{3}$/', strtoupper((string)($line['series'] ?? '')))) { $errors[] = __('Hay una boleta con serie inválida para el resumen diario.', 'sistema-para-farmacias'); break; }
            if (!preg_match('/^\d{1,8}$/', (string)($line['correlative'] ?? ''))) { $errors[] = __('Hay una boleta con correlativo inválido para el resumen diario.', 'sistema-para-farmacias'); break; }
        }
        $reference_ts = strtotime((string)($context['reference_date'] ?? ''));
        $issue_ts = strtotime((string)($context['issue_date'] ?? ''));
        if ($reference_ts && $issue_ts && $issue_ts > $reference_ts + (7 * DAY_IN_SECONDS)) {
            $warnings[] = __('La fecha de generación está fuera de la ventana ordinaria de siete días del resumen diario; revisa el caso antes de cualquier envío.', 'sistema-para-farmacias');
        }
        return ['errors'=>$errors,'warnings'=>$warnings];
    }

    private function append_line(DOMDocument $doc, DOMElement $root, array $line, $line_number) {
        $node = $doc->createElementNS(self::NS_SAC, 'sac:SummaryDocumentsLine');
        $root->appendChild($node);
        $this->cbc($doc, $node, 'LineID', (string)$line_number);
        $this->cbc($doc, $node, 'DocumentTypeCode', '03');
        $this->cbc($doc, $node, 'ID', strtoupper((string)$line['series']) . '-' . (string)$line['correlative']);

        $customer = $this->cac($doc, $node, 'AccountingCustomerParty');
        $document_number = trim((string)($line['customer_document_number'] ?? ''));
        $document_type = $this->identity_code((string)($line['customer_document_type'] ?? ''));
        if ($document_number === '') { $document_number = '-'; $document_type = '-'; }
        $this->cbc($doc, $customer, 'CustomerAssignedAccountID', $document_number);
        $this->cbc($doc, $customer, 'AdditionalAccountID', $document_type);

        $status = $this->cac($doc, $node, 'Status');
        $this->cbc($doc, $status, 'ConditionCode', (string)($line['condition_code'] ?? '1'));

        $total = $this->sac($doc, $node, 'TotalAmount', $this->amount($line['total'] ?? 0));
        $total->setAttribute('currencyID', 'PEN');
        $buckets = (array)($line['tax_buckets'] ?? []);
        $this->billing_payment($doc, $node, (float)($buckets['gravado'] ?? 0), '01');
        $this->billing_payment($doc, $node, (float)($buckets['exonerado'] ?? 0), '02');
        $this->billing_payment($doc, $node, (float)($buckets['inafecto'] ?? 0), '03');

        $tax_total = $this->cac($doc, $node, 'TaxTotal');
        $tax_amount = $this->cbc($doc, $tax_total, 'TaxAmount', $this->amount($line['tax'] ?? 0));
        $tax_amount->setAttribute('currencyID', 'PEN');
        $subtotal = $this->cac($doc, $tax_total, 'TaxSubtotal');
        $sub_amount = $this->cbc($doc, $subtotal, 'TaxAmount', $this->amount($line['tax'] ?? 0));
        $sub_amount->setAttribute('currencyID', 'PEN');
        $category = $this->cac($doc, $subtotal, 'TaxCategory');
        $scheme = $this->cac($doc, $category, 'TaxScheme');
        $this->cbc($doc, $scheme, 'ID', '1000');
        $this->cbc($doc, $scheme, 'Name', 'IGV');
        $this->cbc($doc, $scheme, 'TaxTypeCode', 'VAT');
    }

    private function billing_payment(DOMDocument $doc, DOMElement $parent, $amount, $instruction) {
        $payment = $doc->createElementNS(self::NS_SAC, 'sac:BillingPayment');
        $parent->appendChild($payment);
        $paid = $this->cbc($doc, $payment, 'PaidAmount', $this->amount($amount));
        $paid->setAttribute('currencyID', 'PEN');
        $this->cbc($doc, $payment, 'InstructionID', $instruction);
    }

    private function identity_code($type) {
        $map = ['DNI'=>'1','CE'=>'4','RUC'=>'6','PASSPORT'=>'7'];
        return $map[strtoupper((string)$type)] ?? '-';
    }

    private function cac(DOMDocument $doc, DOMElement $parent, $name) { $node=$doc->createElementNS(self::NS_CAC,'cac:'.$name);$parent->appendChild($node);return$node; }
    private function cbc(DOMDocument $doc, DOMElement $parent, $name, $value) { $node=$doc->createElementNS(self::NS_CBC,'cbc:'.$name);$node->appendChild($doc->createTextNode((string)$value));$parent->appendChild($node);return$node; }
    private function sac(DOMDocument $doc, DOMElement $parent, $name, $value) { $node=$doc->createElementNS(self::NS_SAC,'sac:'.$name);$node->appendChild($doc->createTextNode((string)$value));$parent->appendChild($node);return$node; }
    private function amount($value) { return number_format((float)$value, 2, '.', ''); }
}
