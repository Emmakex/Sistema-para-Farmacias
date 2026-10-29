# Firma y validación local de lotes fiscales

## Alcance

La versión 0.14.0 extiende los lotes fiscales persistidos con una cadena local y fail-closed:

1. reconstrucción exclusiva desde snapshots persistidos;
2. verificación SHA-256 de cabecera y miembros;
3. reconstrucción del XML original y comprobación de `xml_sha256`;
4. firma XMLDSig con el mismo motor criptográfico genérico de comprobantes;
5. verificación local inmediata de la firma;
6. persistencia de evidencia criptográfica del lote;
7. validación XSD/XSL con recursos externos específicos del tipo de lote;
8. persistencia del hash exacto del XML firmado validado.

No se habilita transporte remoto de Resumen Diario en este corte.

## Inmutabilidad

La firma no reconstruye el lote desde ventas, clientes o comprobantes actuales. `SPF_Fiscal_Batch_Integrity` lee directamente:

- `fiscal_batches.snapshot_body`;
- `fiscal_batch_items.snapshot_body`.

Cada línea debe coincidir con su SHA-256 y con las identidades persistidas `document_id`, `sale_id` y `condition_code`. Después se recalcula el hash global del snapshot y se vuelve a ejecutar el builder original. El identificador y el SHA-256 del XML reconstruido deben coincidir con los valores congelados al crear el lote.

Si cualquiera de estas comprobaciones falla, la firma se bloquea.

## Firma

El lote reutiliza `SPF_Fiscal_XMLDSig_Signer`; no existe una segunda implementación criptográfica.

El perfil base se hereda de `electronic_invoicing.signature_profile` y el country pack puede sobrescribir valores dentro de `batch_profiles.<tipo>.signature_profile`.

Para Perú / `daily_summary` se mantiene RSA-SHA256 + SHA-256 y se utiliza un `signature_id` específico de lote.

El certificado PKCS#12 sigue siendo externo:

- `SPF_FISCAL_P12_PATH`
- `SPF_FISCAL_P12_PASSWORD`

Nunca se persiste la clave privada ni la contraseña.

La evidencia persistida en el lote incluye:

- `signature_status`;
- `signature_method`;
- `signature_certificate_sha256`;
- `signature_xml_sha256`;
- metadata de digest/canonicalización en `response_body`.

Una nueva firma invalida la validación local y cualquier evidencia posterior.

## Validación

Los recursos de validación del Resumen Diario son independientes de los usados por factura/boleta individual.

El perfil Perú declara:

- `SPF_FISCAL_PERU_SUMMARY_XSD_PATH`
- `SPF_FISCAL_PERU_SUMMARY_XSL_PATH`

Los archivos oficiales permanecen fuera del repositorio y fuera de la base de datos.

Antes de ejecutar XSD/XSL, la validación vuelve a producir el XML firmado de forma determinista y exige que coincidan:

- el SHA-256 del XML firmado con `signature_xml_sha256` persistido;
- el SHA-256 del certificado con `signature_certificate_sha256` persistido.

Si el certificado ha cambiado o la evidencia no coincide, la validación falla y exige volver a firmar el lote. De esta forma una rotación de certificado no puede validar silenciosamente una firma distinta de la que quedó registrada.

`valid_local` requiere que XSD y XSL configurados resulten válidos. Si solo hay un recurso configurado y pasa, el estado queda `partial`.

## UI Fiscal

Cada lote persistido dispone únicamente de acciones locales:

- **Firmar lote**;
- **Validar lote XSD/XSL**.

No existe botón `sendSummary`, consulta `getStatus` ni transporte remoto para el lote en 0.14.0.

## CI

El pipeline prueba:

- sintaxis PHP;
- `SummaryDocuments` local;
- idempotencia del snapshot;
- firma XMLDSig real sobre `SummaryDocuments` con certificado efímero;
- rechazo de manipulación del XML firmado;
- validación XSD/XSL local con recursos temporales de smoke;
- existencia de columnas de evidencia criptográfica;
- binding entre validación y firma persistida;
- ausencia de red, `sendSummary` y `getStatus` en toda la capa batch;
- ausencia de certificados y paquetes XSD/XSL oficiales dentro del repositorio.

## Fuera de alcance

0.14.0 no implementa:

- `sendSummary`;
- ticket remoto;
- `getStatus`;
- CDR de Resumen Diario;
- promoción de `fiscal_status` por respuestas remotas;
- endpoint productivo;
- credenciales SOL productivas.

El siguiente corte solo podrá abrir transporte beta del Resumen Diario cuando un lote persistido haya superado integridad, firma y validación local completa.