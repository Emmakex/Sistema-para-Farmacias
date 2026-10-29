# Arquitectura fiscal

## Estado actual

La arquitectura fiscal vigente corresponde a **Sistema para Farmacias 0.17.0**.

El diseño separa de forma estricta la preparación local, la evidencia beta y cualquier futura autoridad productiva. Una prueba remota nunca puede convertirse por sí sola en estado fiscal de producción.

## Capas

1. **Core fiscal**: contexto canónico de venta/comprobante y estados internos.
2. **Adaptador de país**: reglas territoriales y XML fiscal.
3. **Firma**: XMLDSig con credenciales externas al repositorio y a la base de datos.
4. **Validación local**: XSD/XSL desde recursos externos.
5. **Lotes fiscales**: snapshots inmutables para documentos agrupados.
6. **Transporte local**: inspección y dry-run sin red.
7. **Transporte beta**: pruebas remotas explícitas y aisladas.
8. **Ledger remoto**: eventos append-only de intenciones, tickets, consultas y hashes.
9. **Readiness beta**: diagnóstico side-effect free y export de evidencia antes de una prueba real.
10. **Producción**: fase futura, separada y todavía bloqueada.

## Estados independientes

- `internal_status`: flujo local.
- `signature_status`: firma local.
- `validation_status`: validación local.
- `transport_status`: transporte local/dry-run.
- `beta_status`: evidencia del entorno de pruebas.
- `fiscal_status`: reservado para tránsito o resultado fiscal real de producción.

**El beta no escribe `fiscal_status`.**

## Firma y validación

`SPF_Fiscal_XMLDSig_Signer` firma y verifica localmente. Las credenciales se leen desde:

- `SPF_FISCAL_P12_PATH`;
- `SPF_FISCAL_P12_PASSWORD`.

No se persisten claves privadas ni contraseñas.

`SPF_Fiscal_XSD_XSL_Validator` valida sin red. Los recursos externos son:

### Comprobantes individuales

- `SPF_FISCAL_XSD_PATH`;
- `SPF_FISCAL_XSL_PATH`.

### Resumen Diario Perú

- `SPF_FISCAL_PERU_SUMMARY_XSD_PATH`;
- `SPF_FISCAL_PERU_SUMMARY_XSL_PATH`.

`valid_local` requiere que XSD y XSL pasen para el XML firmado exacto.

## Lotes fiscales

Los documentos con estrategia `daily_summary` se congelan en lotes persistidos con:

- cabecera snapshot;
- miembros snapshot;
- SHA-256 por miembro;
- SHA-256 global;
- identificador y secuencia estables;
- hash del XML reconstruido.

Firma y validación reconstruyen exclusivamente desde esos snapshots, nunca desde ventas o clientes actuales.

## Perú · Resumen Diario

El perfil Perú declara:

- `boleta` → `daily_summary`;
- builder `peru_summary_documents`;
- identificador `RC-YYYYMMDD-NNNNN`;
- firma XMLDSig local;
- XSD/XSL específicos;
- transporte beta asíncrono `peru_beta`;
- producción deshabilitada.

Flujo local:

`snapshot → integridad → firma → validación`

Flujo beta:

`revalidación → submit_intent → sendSummary → ticket_received → poll_intent → getStatus → poll_result`

Las operaciones SOAP y el endpoint beta viven únicamente en el country pack Perú.

## Opt-in beta

### Factura individual

```php
define('SPF_FISCAL_BETA_ENABLED', true);
define('SPF_FISCAL_BETA_CONFIRMATION', 'SUNAT_TEST_ONLY');
```

### Resumen Diario

```php
define('SPF_FISCAL_BETA_SUMMARY_ENABLED', true);
define('SPF_FISCAL_BETA_SUMMARY_CONFIRMATION', 'SUNAT_SUMMARY_TEST_ONLY');
```

Ambos flujos están bloqueados por defecto.

## Ledger remoto append-only

`SPF_Fiscal_Batch_Remote_Event_Repository` registra evidencia separada de `fiscal_batches`.

Eventos principales:

- `submit_intent`;
- `submit_error`;
- `ticket_received`;
- `poll_intent`;
- `poll_error`;
- `poll_result`;
- `ticket_recovered`.

El ledger guarda UUID, fingerprint criptográfico, ticket, códigos, hashes y timestamps. No guarda XML o ZIP completos.

El fingerprint incorpora identidad del lote, snapshot, XML, firma, certificado, validación, transporte y entorno.

### Recuperación

Si existe `ticket_received` con el mismo fingerprint pero `remote_ticket` quedó vacío, el sistema permite **Recuperar ticket** sin red y prohíbe reenviar el lote.

Mientras exista ticket asociado o recuperable, firma y validación quedan congeladas también server-side.

## Readiness beta 0.17.0

`SPF_Fiscal_Batch_Beta_Diagnostics` añade una fase obligatoriamente local antes de una prueba real.

El diagnóstico comprueba:

- runtime PHP: DOM, OpenSSL, SOAP, XSL y ZIP;
- integridad del snapshot;
- evidencia de firma;
- igualdad firma/validación;
- XSD/XSL legibles;
- revalidación actual del XML firmado en memoria;
- inspección local del transporte;
- doble opt-in;
- ausencia de ticket asociado o recuperable para un primer envío.

No llama a `submit_batch()` ni a `poll_batch()` y devuelve explícitamente:

`network_performed=false`

Estados de preparación:

- `ready_for_submit`;
- `ready_for_poll`;
- `ready_for_recovery`.

## Panel de preparación

La pantalla Fiscal incorpora un panel independiente:

**Preparación beta controlada · sin red**

Permite:

- ejecutar **Diagnóstico beta**;
- ver las comprobaciones una a una;
- consultar un timeline corto del ledger;
- ejecutar **Exportar evidencia JSON**.

## Export de evidencia

El esquema exportado es:

`spf-fiscal-beta-evidence-v1`

Incluye:

- identidad del lote;
- hashes de snapshot/XML/firma/certificado/validación;
- estados;
- ticket;
- diagnóstico;
- timeline sanitizado;
- `export_sha256`.

No incluye:

- `snapshot_body`;
- `response_body`;
- `metadata_body` crudo;
- XML o ZIP completos;
- claves privadas;
- certificados PEM;
- contraseña PKCS#12;
- rutas privadas del servidor.

## Concurrencia

Las operaciones beta de lote utilizan advisory lock MySQL (`GET_LOCK` / `RELEASE_LOCK`) y cooldowns separados para envío y consulta.

El ledger añade trazabilidad; no sustituye el lock.

## Producción continúa bloqueada

El código actual no habilita:

- endpoint productivo;
- credenciales SOL productivas;
- promoción de beta a `fiscal_status`;
- polling automático;
- reintentos automáticos;
- pruebas masivas o de carga;
- afirmación de compatibilidad productiva sin evidencia real y reconciliación formal.

## CI

La matriz actual verifica:

- sintaxis PHP;
- firma XMLDSig y manipulación;
- XSD/XSL;
- `SummaryDocuments`;
- snapshots e idempotencia;
- firma/validación del lote;
- beta individual fail-closed;
- beta de Resumen Diario fail-closed;
- ticket/getStatus parsing;
- aislamiento SOAP en Perú;
- ausencia del endpoint productivo;
- advisory lock/cooldown;
- ausencia de escrituras beta a `fiscal_status`;
- ledger append-only y orden de eventos;
- recuperación de ticket sin red;
- readiness positivo sin transporte remoto;
- export sanitizado con SHA-256.

## Próximo paso

Tras certificar 0.17.0, la instalación queda preparada para **una única prueba beta real controlada**.

Secuencia operativa:

1. lote de prueba persistido;
2. firma;
3. validación;
4. diagnóstico beta con `ready_for_submit=true` y `network_performed=false`;
5. un único envío beta;
6. ticket;
7. consultas controladas hasta estado terminal;
8. export JSON de evidencia;
9. conservación y revisión del `export_sha256`.

La prueba real debe ejecutarse desde una instalación WordPress preparada con certificado, XSD/XSL, extensiones PHP y acceso de red. Producción seguirá bloqueada después de esa prueba.