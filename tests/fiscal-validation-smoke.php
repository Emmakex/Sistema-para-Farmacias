<?php
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');

class WP_Error {
    private $code; private $message;
    public function __construct($code, $message) { $this->code=$code; $this->message=$message; }
    public function get_error_code(){ return $this->code; }
    public function get_error_message(){ return $this->message; }
}
function is_wp_error($value){ return $value instanceof WP_Error; }
function __($text, $domain=null){ return $text; }
function sanitize_key($key){ return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$key)); }
function sanitize_file_name($name){ return preg_replace('/[^A-Za-z0-9._-]/','-',(string)$name); }
function sanitize_text_field($value){ return trim(strip_tags((string)$value)); }
function current_time($type){ return date('Y-m-d H:i:s'); }

require_once __DIR__ . '/../includes/fiscal/interface-spf-fiscal-validator.php';
require_once __DIR__ . '/../includes/fiscal/class-spf-fiscal-xsd-xsl-validator.php';

if (!class_exists('DOMDocument') || !class_exists('XSLTProcessor')) {
    fwrite(STDERR, "DOM and XSL extensions are required\n"); exit(1);
}

$dir = sys_get_temp_dir() . '/spf-validation-' . bin2hex(random_bytes(6));
if (!mkdir($dir, 0700, true)) { fwrite(STDERR, "Cannot create temp dir\n"); exit(1); }
$xsd = $dir . '/test.xsd';
$xsl = $dir . '/test.xsl';

file_put_contents($xsd, <<<'XSD'
<?xml version="1.0" encoding="UTF-8"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">
  <xs:element name="Test">
    <xs:complexType>
      <xs:sequence>
        <xs:element name="Value" type="xs:string"/>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
</xs:schema>
XSD
);
file_put_contents($xsl, <<<'XSL'
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
  <xsl:param name="nombreArchivoEnviado"/>
  <xsl:output method="text"/>
  <xsl:template match="/">
    <xsl:if test="Test/Value != 'OK'">
      <xsl:message terminate="yes">ERROR 9999: regla XSL de prueba rechazada</xsl:message>
    </xsl:if>
    <xsl:text>OK:</xsl:text><xsl:value-of select="$nombreArchivoEnviado"/>
  </xsl:template>
</xsl:stylesheet>
XSL
);

$validator = new SPF_Fiscal_XSD_XSL_Validator();
$resources = ['xsd_path'=>$xsd,'xsl_path'=>$xsl,'filename'=>'PE-TEST.xml','filename_parameter'=>'nombreArchivoEnviado'];

$valid = $validator->validate('<?xml version="1.0"?><Test><Value>OK</Value></Test>', $resources);
if (is_wp_error($valid) || empty($valid['ok']) || ($valid['checks']['xsd'] ?? '') !== 'valid' || ($valid['checks']['xsl'] ?? '') !== 'valid') {
    fwrite(STDERR, "Valid XML did not pass XSD/XSL\n"); exit(1);
}

$invalidXsd = $validator->validate('<?xml version="1.0"?><Test><Wrong>OK</Wrong></Test>', $resources);
if (is_wp_error($invalidXsd) || !empty($invalidXsd['ok']) || ($invalidXsd['checks']['xsd'] ?? '') !== 'invalid') {
    fwrite(STDERR, "Invalid XSD document was incorrectly accepted\n"); exit(1);
}

$invalidXsl = $validator->validate('<?xml version="1.0"?><Test><Value>BAD</Value></Test>', $resources);
if (is_wp_error($invalidXsl) || !empty($invalidXsl['ok']) || ($invalidXsl['checks']['xsl'] ?? '') !== 'invalid') {
    fwrite(STDERR, "Invalid XSL rule document was incorrectly accepted\n"); exit(1);
}

$remote = $validator->validate('<?xml version="1.0"?><Test><Value>OK</Value></Test>', ['xsd_path'=>'https://example.test/test.xsd']);
if (is_wp_error($remote) || !empty($remote['ok'])) {
    fwrite(STDERR, "Remote validation resource was incorrectly accepted\n"); exit(1);
}

@unlink($xsl); @unlink($xsd); @rmdir($dir);
echo "fiscal-validation-smoke: ok\n";
