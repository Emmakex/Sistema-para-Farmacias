# Arquitectura fiscal

## Principio

Sistema para Farmacias separa cinco responsabilidades:

1. **Core fiscal**: obtiene el contexto canónico de venta/comprobante y gobierna estados internos.
2. **Adaptador de país**: valida reglas territoriales y construye el formato fiscal correspondiente.
3. **Firma**: aplica y verifica localmente XMLDSig usando credenciales externas al repositorio y a la base de datos.
4. **Transporte**: empaqueta y ejecuta el mecanismo de entrega definido por cada sistema fiscal.
5. **Resultado fiscal**: reconcilia únicamente respuestas reales de autoridad/PSE/OSE.

## Estados independientes

`internal_status` describe nuestro flujo local (`draft`, `ready`, `prepared`, etc.).

`signature_status` describe únicamente la firma local (`not_signed`, `verified`, `signature_error`).

`transport_status` describe el estado técnico del paquete (`not_prepared`, `dry_run_validated`, `blocked_unsigned`, `submitted`, `response_received`, `transport_error`).

`fiscal_status` describe únicamente un resultado o tránsito fiscal real (`not_sent`, `submitted`, `accepted`, `accepted_with_observation`, `rejected`, `transport_error`).

La columna histórica `sunat_status` se conserva temporalmente por compatibilidad con FarmaPOS, pero no es autoridad semántica del nuevo diseño.

## Preflight

`SPF_Fiscal_Manager` construye un contexto a partir de comprobante, venta, cliente, líneas, perfil del país e identidad fiscal del emisor.

El adaptador devuelve errores y advertencias. Solo sin errores se genera un XML de previsualización. Ese XML no se envía, no se guarda como comprobante fiscal definitivo y nunca cambia por sí mismo el estado fiscal a aceptado/rechazado.

## Firma local

`SPF_Fiscal_Signer_Interface` desacopla el motor de firma del país y del transporte. La primera implementación es `SPF_Fiscal_XMLDSig_Signer`.

La firma:

- inserta `ext:UBLExtensions` al inicio del documento;
- inserta `cac:Signature` antes de `cac:AccountingSupplierParty`;
- firma el documento completo mediante XMLDSig enveloped;
- incrusta el certificado X.509 público;
- verifica inmediatamente digest y firma con la clave pública del certificado;
- rechaza certificados todavía no válidos o vencidos;
- genera hashes SHA-256 como evidencia local;
- no persiste el XML firmado completo en la base de datos.

El proveedor `SPF_Fiscal_Credentials_Environment` carga un PKCS#12 desde `SPF_FISCAL_P12_PATH` y `SPF_FISCAL_P12_PASSWORD`, definidos en entorno seguro o `wp-config.php`. La clave privada y la contraseña nunca se guardan en opciones, transients, logs ni `response_body`.

CI genera en cada ejecución un certificado RSA efímero, firma un UBL mínimo, verifica la firma y comprueba que una alteración posterior del XML invalida el digest.

## Política criptográfica

El algoritmo forma parte del perfil territorial, no del core. La implementación soporta:

- `rsa_sha256` + `sha256` como perfil moderno;
- `rsa_sha1_legacy` + `sha1_legacy` únicamente como compatibilidad explícita y nunca como fallback automático.

El perfil inicial de Perú usa RSA-SHA256/SHA-256 para validación local. Su aceptación remota queda marcada como `pending` hasta ejecutar validación beta/controlada contra las reglas vigentes. La guía UBL 2.1 publicada por SUNAT exige XMLDSig, certificado X.509 y firma del documento completo, pero los URI SHA-1 que aparecen en la guía corresponden a un ejemplo histórico y no se tratan como mandato criptográfico actual.

## Contrato de transporte

`SPF_Fiscal_Transport_Interface` separa el transporte de la construcción y firma del documento. Un transporte declara código, entorno, capacidades, inspección de paquete y transmisión.

El primer transporte es `SPF_Fiscal_Transport_Dry_Run`. Puede inspeccionar el paquete, estrategia, nombre, hash y estado de firma, pero su método `transmit()` devuelve siempre un error deliberado. No contiene URL, credencial ni cliente HTTP/SOAP.

`SPF_Fiscal_Transport_Manager` intenta construir un paquete firmado localmente. Si no hay certificado disponible conserva el comportamiento fail-closed `blocked_unsigned`. Si la firma local está verificada, el dry-run puede quedar `dry_run_validated`, pero `fiscal_status` continúa obligatoriamente en `not_sent`.

## Perú

El adaptador `SPF_Fiscal_Adapter_Peru` soporta en esta etapa preflight de factura y boleta, UBL 2.1 / CustomizationID 2.0, series F/B, identificación de emisor/cliente, totales, líneas y afectación tributaria base.

El perfil Perú declara:

- `factura` → transporte `single`;
- `boleta` → transporte `daily_summary`;
- firmador `xmldsig`;
- credenciales `environment_p12`;
- firma local `rsa_sha256`;
- digest local `sha256`.

Esta separación responde al flujo publicado para SEE-Contribuyente: la factura puede enviarse individualmente mientras las boletas se informan mediante resumen diario.

La documentación oficial consultada incluye:

- SUNAT, **Guías y Manuales**: reglas de validación CPE actualizadas al 26/08/2026.
- SUNAT, **Archivo XSL 2.1** publicado en la sección oficial de validadores.
- SUNAT, **Guía de elaboración de documentos electrónicos XML - UBL 2.1**: XMLDSig obligatorio, certificado X.509 válido, firma del documento completo y prohibición de modificarlo después de firmar.
- SUNAT, **SEE del Contribuyente**: CDR aceptada, aceptada con observación y rechazada; factura y resumen diario de boletas.
- SUNAT, **Servicios Web disponibles** y servicio beta de pruebas.
- SUNAT, **Certificado Digital**.

## Bloqueos actuales

La versión 0.9.0 no incluye:

- certificado o llave privada dentro del repositorio;
- certificado o contraseña dentro de la base de datos;
- ZIP fiscal definitivo;
- SOAP/HTTP;
- credenciales SOL;
- URL beta o producción en PHP;
- `SendBill`, `SendSummary` o `GetStatus`;
- CDR real;
- afirmación de compatibilidad remota de RSA-SHA256 hasta probarla contra el servicio/reglas vigentes.

CI falla si se introduce código de red, endpoints oficiales o ficheros de credenciales dentro de este corte.

## Próximo corte

El siguiente bloque deberá añadir validación XSD/XSL local y una matriz de conformidad territorial. Solo después se habilitará un transporte beta controlado. Producción seguirá bloqueada hasta disponer de evidencia de aceptación controlada y reconciliación CDR.
