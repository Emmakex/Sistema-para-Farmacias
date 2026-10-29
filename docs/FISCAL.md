# Arquitectura fiscal

## Estado actual

La arquitectura fiscal vigente corresponde a **Sistema para Farmacias 0.20.0**.

El sistema mantiene separadas las responsabilidades locales, beta y productivas para impedir que una prueba remota pueda convertirse accidentalmente en autoridad fiscal real.

## Capas

1. **Core fiscal**: contexto canónico de venta/comprobante y estados internos.
2. **Adaptador de país**: reglas territoriales y construcción XML.
3. **Firma**: XMLDSig con credenciales externas al repositorio y a la base de datos.
4. **Validación local**: XSD/XSL con recursos externos al plugin.
5. **Lotes fiscales**: snapshots inmutables para documentos agrupados.
6. **Transporte local**: inspección/dry-run sin red.
7. **Transporte beta**: pruebas remotas explícitas y aisladas.
8. **Ledgers remotos append-only**: intención, respuesta, tickets, consultas y hashes.
9. **Evidence Store privado**: conservación verificable de ZIP/XML CDR fuera del webroot.
10. **Recuperación operativa**: reconstrucción de proyecciones locales desde evidencia ya archivada, sin reenvío.
11. **Resultado fiscal productivo**: reservado para una fase posterior.

## Estados independientes

- `internal_status`: flujo local;
- `signature_status`: firma local;
- `validation_status`: validación local;
- `transport_status`: dry-run/transporte local;
- `beta_status`: evidencia y resultado del entorno de pruebas;
- `fiscal_status`: reservado para tránsito/resultado productivo real.

**Una respuesta beta nunca modifica `fiscal_status`.**

La columna histórica `sunat_status` se mantiene temporalmente por compatibilidad, pero no gobierna la arquitectura nueva.

## Firma y validación local

`SPF_Fiscal_XMLDSig_Signer` firma y verifica localmente XMLDSig. Las credenciales se cargan mediante `SPF_Fiscal_Credentials_Environment` desde:

```php
SPF_FISCAL_P12_PATH
SPF_FISCAL_P12_PASSWORD
```

No se persisten claves privadas ni contraseñas.

`SPF_Fiscal_XSD_XSL_Validator` ejecuta validación local sin red. Los recursos oficiales permanecen fuera del repositorio.

Comprobantes individuales:

```php
SPF_FISCAL_XSD_PATH
SPF_FISCAL_XSL_PATH
```

Resumen Diario Perú:

```php
SPF_FISCAL_PERU_SUMMARY_XSD_PATH
SPF_FISCAL_PERU_SUMMARY_XSL_PATH
```

`valid_local` requiere que el XML firmado exacto supere los recursos configurados.

## Lotes fiscales y Resumen Diario Perú

Las boletas usan estrategia `daily_summary`.

Cada lote persistido conserva snapshot de cabecera y miembros, hashes por línea y globales, identificador estable y XML reconstruible. Firma y validación operan sobre el snapshot, no sobre ventas/clientes mutables.

Flujo local:

```text
snapshot → integridad → firma → validación
```

Flujo beta:

```text
revalidación
→ submit_intent
→ sendSummary
→ ticket_received
→ poll_intent
→ getStatus
→ poll_result
→ archivo CDR
→ evidence_archived
→ proyección del lote
```

El ledger `SPF_Fiscal_Batch_Remote_Event_Repository` es append-only y permite recuperar tickets ya recibidos sin red.

## Factura beta síncrona

Las facturas de prueba usan estrategia `single`, código fiscal `01` y `sendBill`.

El flujo desde 0.19.0 es:

```text
firma + validación exactas
→ duplicate guard
→ submit_intent
→ sendBill
→ response_received
→ archivo CDR
→ evidence_archived
→ proyección beta local
```

El ledger `SPF_Fiscal_Document_Remote_Event_Repository` queda ligado por fingerprint a:

- documento/venta;
- país y tipo fiscal;
- serie/correlativo;
- XML firmado;
- certificado;
- hash de validación;
- transporte y entorno.

Si existe una respuesta remota para la misma evidencia, un nuevo `sendBill` queda bloqueado.

## Evidence Store privado

El provider actual es `external_filesystem` y se configura fuera del plugin:

```php
define('SPF_FISCAL_EVIDENCE_DIR', '/ruta/privada/fuera/del/webroot/fiscal-evidence');
```

Reglas:

- ruta local absoluta;
- fuera de `ABSPATH` / webroot;
- sin URLs ni traversal;
- escritura atómica;
- verificación SHA-256 tras escribir;
- no sobrescribir locators con contenido distinto.

Los bytes CDR ZIP/XML no entran en `response_body`, options, transients ni ledgers. La base de datos conserva únicamente hashes, locators relativos, tamaños y metadata normalizada.

## Recuperación operativa 0.20.0

Si la CDR ya quedó archivada pero falla la proyección mutable del comprobante, `recover_beta_invoice()` reconstruye `beta_status`/`response_body` desde el ledger y añade `projection_recovered` sin realizar red.

`SPF_Fiscal_Document_Recovery_UI` expone en la pantalla **Fiscal** únicamente candidatos en los que:

1. existe `evidence_archived`;
2. el fingerprint archivado coincide con la firma/certificado/validación actuales;
3. el estado beta local está desalineado.

La detección usa una consulta agrupada y `evidence_sha256_from_row()`; no reconstruye firma/validación para cada fila de la pantalla. Al pulsar **Recuperar desde ledger**, la autoridad server-side vuelve a ejecutar la comprobación completa antes de modificar la proyección.

## Situaciones deliberadamente bloqueadas

- `response_received` sin `evidence_archived`: no se reenvía automáticamente y requiere reconciliación técnica;
- evidencia archivada con fingerprint distinto al documento actual: no es recuperable;
- falta de Evidence Store: un resultado beta final no se presenta como final archivado;
- endpoint productivo: ausente;
- credenciales productivas: ausentes;
- retry/polling automático remoto: ausente.

## Concurrencia

Los lotes beta usan advisory lock MySQL (`GET_LOCK` / `RELEASE_LOCK`) y cooldowns separados de envío/consulta.

La factura beta mantiene lock/cooldown local y duplicate guard criptográfico antes de la transmisión.

## CI

CI cubre, entre otros:

- sintaxis PHP;
- XMLDSig y rechazo de manipulación;
- XSD/XSL;
- `SummaryDocuments`;
- snapshots e idempotencia;
- firma/validación de lotes;
- beta individual y batch fail-closed;
- ticket/getStatus;
- aislamiento SOAP territorial;
- ausencia de endpoint productivo;
- ausencia de escrituras beta a `fiscal_status`;
- ledgers append-only;
- orden de eventos antes/después de cada operación remota;
- archivo CDR privado y verificado;
- recuperación de ticket y de proyección sin red;
- bloqueo de reenvíos duplicados;
- ausencia de XML/ZIP crudos en base de datos;
- UI de recuperación sin N+1 criptográfico.

## Opt-in beta Perú

Factura:

```php
define('SPF_FISCAL_BETA_ENABLED', true);
define('SPF_FISCAL_BETA_CONFIRMATION', 'SUNAT_TEST_ONLY');
```

Resumen Diario:

```php
define('SPF_FISCAL_BETA_SUMMARY_ENABLED', true);
define('SPF_FISCAL_BETA_SUMMARY_CONFIRMATION', 'SUNAT_SUMMARY_TEST_ONLY');
```

Estos flags habilitan exclusivamente el entorno de pruebas.

## Próximo corte

Con 0.20.0 certificado, el siguiente paso será preparar una **aceptación beta real controlada** en una instalación de prueba con:

- emisor de pruebas;
- certificado configurado externamente;
- XSD/XSL oficiales externos;
- Evidence Store privado disponible;
- factura de prueba y Resumen Diario de prueba;
- captura completa de ambos ledgers y manifests;
- reconciliación de estado local frente a la respuesta remota.

Producción continuará bloqueada hasta que esa aceptación esté documentada y, después, se definan credenciales productivas externas, idempotencia/reintentos formales, observabilidad, backup/retención y reconciliación operativa.
