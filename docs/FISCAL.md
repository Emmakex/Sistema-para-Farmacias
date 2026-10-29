# Arquitectura fiscal

## Principio

Sistema para Farmacias separa siete responsabilidades:

1. **Core fiscal**: obtiene el contexto canónico de venta/comprobante y gobierna estados internos.
2. **Adaptador de país**: valida reglas territoriales y construye el formato fiscal correspondiente.
3. **Firma**: aplica y verifica localmente XMLDSig usando credenciales externas al repositorio y a la base de datos.
4. **Validación local**: ejecuta XSD/XSL desde recursos oficiales locales externos al plugin.
5. **Transporte local**: permite inspección/dry-run sin red.
6. **Transporte beta**: prueba remota explícita y aislada, sin autoridad de producción.
7. **Resultado fiscal**: queda reservado exclusivamente a una respuesta real del entorno productivo o del proveedor fiscal autorizado.

## Estados independientes

`internal_status` describe nuestro flujo local (`draft`, `ready`, `prepared`, etc.).

`signature_status` describe únicamente la firma local (`not_signed`, `verified`, `signature_error`).

`validation_status` describe únicamente la validación local (`not_validated`, `valid_local`, `partial`, `resources_missing`, `validation_error`).

`transport_status` describe el transporte local (`not_prepared`, `dry_run_validated`, `blocked_unsigned`, `submitted`, `response_received`, `transport_error`).

`beta_status` describe solamente evidencia del entorno de pruebas (`not_tested`, `beta_accepted`, `beta_accepted_with_observation`, `beta_rejected`, `beta_transport_error`).

`fiscal_status` describe únicamente un resultado o tránsito fiscal real (`not_sent`, `submitted`, `accepted`, `accepted_with_observation`, `rejected`, `transport_error`).

Una respuesta del beta nunca cambia `fiscal_status`.

La columna histórica `sunat_status` se conserva temporalmente por compatibilidad con FarmaPOS, pero no es autoridad semántica del nuevo diseño.

## Preflight

`SPF_Fiscal_Manager` construye un contexto a partir de comprobante, venta, cliente, líneas, perfil del país e identidad fiscal del emisor.

El adaptador devuelve errores y advertencias. Solo sin errores se genera un XML de previsualización. La construcción pura (`build_preview`) no modifica estado; la acción explícita de preflight (`prepare_preview`) sí registra evidencia e invalida firma, validación, transportes y pruebas beta anteriores.

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

La canonicalización de `SignedInfo` sigue el perfil configurado. El digest del documento referenciado se canonicaliza sin comentarios, conforme al comportamiento del `Reference` enveloped cuando no se declara un transform C14N adicional. CI incluye un caso que elimina comentarios XML y exige que la firma siga siendo válida, mientras una alteración de datos debe romper el digest.

El proveedor `SPF_Fiscal_Credentials_Environment` carga un PKCS#12 desde `SPF_FISCAL_P12_PATH` y `SPF_FISCAL_P12_PASSWORD`, definidos en entorno seguro o `wp-config.php`. La clave privada y la contraseña nunca se guardan en opciones, transients, logs ni `response_body`.

`build_signed_payload()` es side-effect free. `sign_document()` es la operación explícita que registra evidencia de firma e invalida validación, transportes y beta posteriores.

## Validación XSD/XSL local

`SPF_Fiscal_Validator_Interface` desacopla la validación normativa del país y del transporte. La primera implementación es `SPF_Fiscal_XSD_XSL_Validator`.

Los recursos no se incluyen en el plugin. Se configuran mediante:

- `SPF_FISCAL_XSD_PATH`;
- `SPF_FISCAL_XSL_PATH`.

Solo se aceptan archivos locales legibles. La validación XML usa `LIBXML_NONET` y XSLT bloquea acceso de red/escritura mediante las preferencias de seguridad disponibles.

`valid_local` solo se alcanza cuando XSD y XSL están configurados y ambos pasan. El beta vuelve a validar en memoria el **XML firmado exacto que se va a comprimir**, por lo que no confía únicamente en un estado persistido anterior.

## Transporte local

`SPF_Fiscal_Transport_Dry_Run` continúa siendo el transporte predeterminado del perfil Perú. Su método `transmit()` falla deliberadamente y no contiene clientes HTTP/SOAP ni endpoints.

La generación de preview, firma y paquete es side-effect free durante inspección. `fiscal_status` permanece en `not_sent`.

## Beta Perú — factura

La versión 0.11.0 incorpora `SPF_Fiscal_Transport_Peru_Beta`, aislado dentro del country pack de Perú.

Alcance inicial:

- solo `factura`;
- código fiscal `01`;
- estrategia `single`;
- ZIP con un único XML firmado;
- método `sendBill`;
- respuesta síncrona CDR;
- interpretación separada como `beta_status`;
- producción siempre deshabilitada.

Las boletas **no** se envían individualmente desde este corte. El perfil sigue declarando `daily_summary` y la UI las bloquea hasta implementar `SummaryDocuments` + `sendSummary` + consulta de ticket.

### Activación explícita

El beta está deshabilitado por defecto y requiere fuera del plugin:

```php
define('SPF_FISCAL_BETA_ENABLED', true);
define('SPF_FISCAL_BETA_CONFIRMATION', 'SUNAT_TEST_ONLY');
```

La contraseña/usuario de prueba del servicio beta pertenecen exclusivamente al country pack Perú. No son credenciales productivas y no se reutilizan en ningún flujo de producción.

Antes de transmitir, el manager exige:

1. transporte beta declarado por el perfil del país;
2. documento soportado por el transporte;
3. firma XMLDSig verificada;
4. nueva validación XSD + XSL sobre el XML firmado exacto;
5. doble opt-in de beta;
6. lock de concurrencia;
7. cooldown entre pruebas.

SUNAT indica que el beta sirve únicamente para probar estructura XML, no valida consistencia de datos y no debe utilizarse para documentos reales, pruebas de estrés ni envíos masivos. Por eso el sistema añade confirmación explícita y limitación temporal.

## Perú

El perfil Perú declara:

- `factura` → `single`, código `01`;
- `boleta` → `daily_summary`, código `03`;
- firmador `xmldsig`;
- credenciales `environment_p12`;
- firma local `rsa_sha256`;
- digest local `sha256`;
- validador `xsd_xsl_local`;
- recursos normativos `external_official_pack`;
- transporte local por defecto `dry_run`;
- transporte beta `peru_beta`, limitado a `factura`;
- producción deshabilitada.

La documentación oficial consultada incluye:

- SUNAT, **Guías y Manuales**: reglas de validación CPE actualizadas al 26/08/2026.
- SUNAT, **Archivo XSL 2.1** y **Archivo XSD 2.1**.
- SUNAT, **Guía de elaboración de documentos electrónicos XML - UBL 2.1**.
- SUNAT, **Manual del Programador**: WS-Security UsernameToken, `sendBill`, `sendSummary`, `getStatus` y credenciales del beta.
- SUNAT, **Servicios Web disponibles**: endpoint beta separado del receptor de producción.
- SUNAT, **Servicio Beta para realizar pruebas UBL 2.1**: restricciones de uso y prohibición de pruebas de carga/documentos reales.

## Guards CI

CI verifica:

- sintaxis PHP;
- firma XMLDSig + manipulación + invariancia frente a comentarios del documento referenciado;
- validación XSD/XSL local;
- beta fail-closed sin red;
- core multi-país sin literales territoriales;
- clientes de red prohibidos fuera del transporte beta Perú;
- endpoint de producción prohibido en PHP;
- `dry_run` obligatorio como transporte por defecto;
- `sendSummary`/`getStatus` prohibidos en este corte de factura beta;
- credenciales y certificados fuera del repositorio/BD;
- XSD/XSL oficiales fuera del repositorio.

## Bloqueos actuales

La versión 0.11.0 **no** incluye:

- transporte productivo;
- endpoint productivo en PHP;
- credenciales SOL productivas;
- cambio de `fiscal_status` desde beta;
- Resumen Diario de boletas;
- `sendSummary` / `getStatus`;
- persistencia de CDR como archivo definitivo;
- notas de crédito/débito completas;
- afirmación de compatibilidad productiva de RSA-SHA256.

El transporte beta está implementado pero su aceptación remota debe certificarse con una prueba controlada real antes de considerarlo compatible con el servicio vigente.

## Próximo corte

El siguiente bloque será obtener **evidencia beta real controlada de una factura de prueba** y reconciliar la CDR beta sin alterar estados productivos. Después se implementará `SummaryDocuments` para boletas. El transporte de producción continuará bloqueado hasta disponer de evidencia, credenciales productivas, estrategia de reintentos/idempotencia y reconciliación CDR formal.
