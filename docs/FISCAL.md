# Arquitectura fiscal

## Estado actual

La arquitectura fiscal vigente corresponde a **Sistema para Farmacias 0.23.0**.

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
11. **Readiness beta**: diagnóstico local fail-closed de precondiciones antes de una aceptación real de pruebas.
12. **Aceptación beta de factura**: certificación post-prueba del flujo síncrono.
13. **Aceptación beta de lote**: certificación post-prueba del flujo asíncrono y sus ciclos de polling.
14. **Resultado fiscal productivo**: reservado para una fase posterior.

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
→ [poll_intent → getStatus → poll_result]...
→ archivo CDR
→ evidence_archived
→ proyección del lote
```

El ledger `SPF_Fiscal_Batch_Remote_Event_Repository` es append-only y permite recuperar tickets ya recibidos sin red.

## Factura beta síncrona

Las facturas de prueba usan estrategia `single`, código fiscal `01` y `sendBill`.

Flujo:

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

El ledger `SPF_Fiscal_Document_Remote_Event_Repository` queda ligado por fingerprint a documento/venta, país/tipo fiscal, serie/correlativo, XML firmado, certificado, hash de validación, transporte y entorno.

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

`SPF_Fiscal_Document_Recovery_UI` expone en la pantalla **Fiscal** únicamente candidatos en los que existe `evidence_archived`, el fingerprint archivado coincide con la firma/certificado/validación actuales y el estado beta local está desalineado.

La detección usa una consulta agrupada y `evidence_sha256_from_row()`; no reconstruye firma/validación para cada fila. Al pulsar **Recuperar desde ledger**, la autoridad server-side vuelve a ejecutar la comprobación completa antes de modificar la proyección.

## Readiness beta 0.21.0+

`SPF_Fiscal_Beta_Readiness` añade a la pantalla **Fiscal** un diagnóstico local, sin transmisión, con dos alcances independientes:

- **Factura beta**;
- **Resumen Diario beta**.

Cada alcance se declara `LISTO` únicamente cuando pasan todas sus precondiciones. El diagnóstico comprueba runtime, identidad mínima del emisor, firma, proveedor de credenciales, certificado externo, validación XSD/XSL, Evidence Store, ledgers, transportes beta, builder del Resumen Diario, opt-ins independientes y producción bloqueada.

Las reglas territoriales se declaran en el pack activo de país. El core de readiness se limita a evaluar ese contrato.

El readiness **no envía**, **no cambia estados**, **no habilita producción** y **no sustituye la validación exacta previa a cada transmisión**.

## Aceptación beta de factura 0.22.0+

`SPF_Fiscal_Beta_Invoice_Acceptance` verifica una prueba beta de factura ya realizada sin efectuar una nueva transmisión.

La certificación exige simultáneamente:

1. resultado `beta_accepted` o `beta_accepted_with_observation`;
2. firma XMLDSig `verified` con hashes válidos;
3. validación `valid_local` del mismo XML firmado;
4. fingerprint actual idéntico al ledger;
5. `submit_intent`;
6. `response_received`;
7. `evidence_archived`;
8. orden `submit_intent < response_received < evidence_archived`;
9. hash del request idéntico al XML firmado;
10. hashes ZIP/XML CDR coherentes entre respuesta y archivo;
11. `cdr.zip`, `cdr.xml` y `manifest.json` archivados como `verified`;
12. proyección beta alineada con el resultado archivado;
13. `fiscal_status = not_sent`.

Un rechazo remoto, manifest inválido, fingerprint distinto, ruptura del orden o promoción del estado productivo bloquean la certificación.

## Aceptación beta de lote 0.23.0

`SPF_Fiscal_Beta_Batch_Acceptance` extiende el mismo principio al flujo asíncrono de lotes.

La pantalla **Fiscal** toma el lote con actividad beta más reciente y muestra `CERTIFICADA` o `BLOQUEADA` sin ejecutar red ni modificar datos.

La certificación exige simultáneamente:

1. resultado `beta_batch_accepted` o `beta_batch_accepted_with_observation`;
2. firma local `verified` con hashes de XML/certificado válidos;
3. validación `valid_local` aplicada al XML firmado exacto;
4. fingerprint actual idéntico al fingerprint del ledger;
5. `submit_intent` presente;
6. `ticket_received` presente;
7. `poll_intent` correspondiente a la consulta final presente;
8. `poll_result` final presente;
9. `evidence_archived` presente;
10. orden estricto `submit_intent < ticket_received < poll_intent final < poll_result final < evidence_archived`;
11. ticket idéntico en lote, `ticket_received`, `poll_intent`, `poll_result`, `evidence_archived` y proyección mutable;
12. hash de request ligado al XML firmado;
13. SHA-256 del ZIP de envío presente en `ticket_received`;
14. hashes ZIP/XML CDR coherentes entre `poll_result` final y archivo;
15. `cdr.zip`, `cdr.xml` y `manifest.json` archivados con estado `verified`, locator y SHA-256 válidos;
16. proyección `fiscal_beta_batch` alineada con estado, ticket y hashes del ledger;
17. `fiscal_status = not_sent`.

### Polling múltiple

Un ticket puede devolver uno o varios estados intermedios `beta_batch_processing`. El certificador no asume una única consulta: busca el **último `poll_result` final** anterior a `evidence_archived` y exige un `poll_intent` del mismo ticket inmediatamente dentro del tramo posterior a `ticket_received` y anterior a ese resultado final.

Por tanto, este flujo es certificable:

```text
submit_intent
→ ticket_received
→ poll_intent
→ poll_result (processing)
→ poll_intent
→ poll_result (accepted)
→ evidence_archived
```

Un lote rechazado, ticket inconsistente, poll final sin intención previa, manifest inválido, proyección mutable desalineada, hashes distintos o cualquier promoción a producción dejan la aceptación `BLOQUEADA`.

El certificador batch es **solo lectura**, country-neutral, no consume bytes XML/ZIP y no muestra locators privados ni material criptográfico.

## Situaciones deliberadamente bloqueadas

- respuesta remota sin `evidence_archived`: requiere reconciliación y no certifica aceptación;
- evidencia con fingerprint distinto al objeto actual: no certifica;
- falta de Evidence Store: no existe aceptación verificable final;
- readiness incompleto: no se considera preparado el alcance;
- respuesta beta rechazada: demuestra comunicación, no aceptación;
- ticket incoherente entre ledger y proyección: no certifica lote;
- `poll_result` final sin `poll_intent` previo del mismo ticket: no certifica lote;
- cadena de ledger incompleta o desordenada: no certifica;
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
- aislamiento del transporte territorial;
- ausencia de endpoint productivo;
- ausencia de escrituras beta a `fiscal_status`;
- ledgers append-only;
- orden de eventos antes/después de cada operación remota;
- archivo CDR privado y verificado;
- recuperación de ticket y de proyección sin red;
- bloqueo de reenvíos duplicados;
- ausencia de XML/ZIP crudos en base de datos;
- UI de recuperación sin N+1 criptográfico;
- readiness por alcance, local y sin exposición de secretos;
- aceptación de factura solo lectura, country-neutral y fail-closed;
- aceptación batch asíncrona con múltiples polls, ticket consistente y evidencia final verificada;
- coherencia automática entre versión de cabecera y `SPF_VERSION` en guards heredados.

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

## Estado de aceptación real

Las capas de readiness y certificación automatizan la comprobación, pero **no constituyen por sí mismas una aceptación beta real**.

Para obtener evidencia real deberán ejecutarse en una instalación WordPress de pruebas configurada con emisor, certificado externo, XSD/XSL oficiales externos, Evidence Store privado y opt-ins beta.

La secuencia operativa será:

```text
Readiness Factura = LISTA
→ ejecutar factura beta
→ Aceptación Factura = CERTIFICADA
→ Readiness Resumen Diario = LISTO
→ ejecutar lote beta y consultar ticket hasta final
→ Aceptación Lote = CERTIFICADA
```

Solo cuando ambas cadenas reales queden certificadas podremos considerar cerrada la aceptación beta técnica de esta arquitectura.

## Próximo paso

Tras certificar 0.23.0 en repositorio, el siguiente paso no es habilitar producción: es desplegar esta versión en la instalación de pruebas y ejecutar la aceptación beta controlada de **factura** y **Resumen Diario**. Si alguno de los paneles queda `BLOQUEADA`, el fallo deberá resolverse sobre la evidencia concreta antes de avanzar.

Producción continuará bloqueada hasta disponer de ambas aceptaciones reales y definir por separado credenciales productivas externas, idempotencia/reintentos formales, observabilidad, backup/retención y reconciliación operativa.
