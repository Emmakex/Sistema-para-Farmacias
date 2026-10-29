# Transporte beta del Resumen Diario · Perú

## Alcance

La versión 0.15.0 añade el primer transporte remoto asíncrono para lotes fiscales persistidos de tipo `daily_summary`.

El flujo es deliberadamente beta y separado de producción:

1. lote persistido desde snapshots inmutables;
2. firma XMLDSig local verificada;
3. validación XSD/XSL local completa (`valid_local`);
4. revalidación del XML firmado exacto inmediatamente antes del envío;
5. empaquetado ZIP;
6. envío beta asíncrono mediante `sendSummary`;
7. persistencia del ticket devuelto;
8. consulta posterior mediante `getStatus`;
9. interpretación del estado beta y, cuando existe, del ZIP de respuesta/CDR.

Ningún resultado beta modifica `fiscal_status`.

## Contrato asíncrono genérico

El core no conoce nombres de operaciones de una autoridad concreta.

`SPF_Fiscal_Async_Batch_Transport_Interface` expone únicamente:

- `batch_enabled()`;
- `inspect_batch()`;
- `submit_batch()`;
- `poll_batch()`.

El country pack decide qué transporte implementa ese contrato mediante `batch_profiles.<tipo>.beta_transport`.

Para Perú, `daily_summary` declara `peru_beta`.

## Aislamiento Perú

Las operaciones, endpoint y credenciales de prueba permanecen exclusivamente en:

`includes/countries/peru/class-spf-fiscal-transport-peru-beta.php`

El transporte beta utiliza únicamente el endpoint de pruebas publicado para `billService`.

El código PHP no contiene el endpoint productivo.

## Doble opt-in independiente

Activar el beta de facturas no activa el beta del Resumen Diario.

El lote requiere ambas constantes externas:

```php
define('SPF_FISCAL_BETA_SUMMARY_ENABLED', true);
define('SPF_FISCAL_BETA_SUMMARY_CONFIRMATION', 'SUNAT_SUMMARY_TEST_ONLY');
```

Sin ambas condiciones, `submit_batch()` y `poll_batch()` fallan antes de cualquier acceso de red.

Las credenciales beta siguen encapsuladas en el country pack y nunca se persisten en WordPress.

## Preconditions de envío

Un lote solo puede enviarse si:

- pertenece al país Perú;
- su tipo es `daily_summary`;
- tiene identificador `RC-YYYYMMDD-NNNNN`;
- la fecha no es futura;
- la fecha está dentro de la ventana beta de siete días calendario;
- la firma está verificada;
- la validación local es `valid_local`;
- `signature_xml_sha256` coincide con `validation_xml_sha256`;
- el certificado actual reproduce la evidencia criptográfica persistida;
- el lote todavía no tiene ticket remoto.

Además, `SPF_Fiscal_Batch_Beta_Manager` vuelve a ejecutar `SPF_Fiscal_Batch_Lifecycle::validate_batch()` inmediatamente antes de transmitir. De este modo no basta una validación histórica: el XML firmado exacto debe seguir superando los recursos XSD/XSL configurados en ese momento.

## Envío

El archivo se empaqueta como:

- XML: `RUC-RC-YYYYMMDD-NNNNN.XML`
- ZIP: `RUC-RC-YYYYMMDD-NNNNN.ZIP`

`sendSummary` devuelve un ticket. El sistema persiste:

- `remote_ticket`;
- `beta_status=beta_batch_ticketed`;
- SHA-256 del ZIP solicitado;
- transporte y entorno;
- timestamps de evidencia;
- `production=false`.

Si se recibe ticket pero falla su persistencia, la operación devuelve un error explícito con el ticket y advierte que no debe reenviarse el lote.

## Consulta

`getStatus(ticket)` se interpreta con los estados documentados para el servicio asíncrono:

- `98`: sigue en proceso → `beta_batch_processing`;
- `0`: procesamiento terminado; se interpreta el ZIP de respuesta/CDR;
- `99`: procesamiento terminado con errores; se conserva e interpreta la evidencia disponible.

Para respuestas finalizadas se guardan, cuando existen:

- SHA-256 del ZIP de respuesta;
- SHA-256 del XML CDR;
- código remoto;
- mensaje remoto;
- observaciones.

Los estados beta terminales son:

- `beta_batch_accepted`;
- `beta_batch_accepted_with_observation`;
- `beta_batch_rejected`.

## Inmutabilidad tras ticket

Desde el momento en que `remote_ticket` deja de estar vacío:

- no se puede volver a firmar el lote;
- no se puede volver a validar manualmente el lote;
- la UI deshabilita ambas acciones;
- los handlers server-side también las rechazan.

Esto evita modificar la evidencia local mientras existe un procesamiento remoto asociado.

## Concurrencia y protección contra carga

Las operaciones beta de lotes están serializadas mediante un advisory lock MySQL global de instalación (`GET_LOCK` / `RELEASE_LOCK`).

Además:

- envío: cooldown de 15 segundos;
- consulta: cooldown de 5 segundos.

El objetivo es evitar duplicados accidentales y uso masivo del entorno de pruebas.

## Estados separados de producción

La respuesta beta solo puede actualizar:

- `beta_status`;
- `remote_ticket`;
- `response_body.fiscal_beta_batch`;
- `updated_at`.

No escribe `fiscal_status`.

Producción continúa bloqueada.

## CI

El corte añade y refuerza gates para demostrar:

- beta de Resumen Diario deshabilitado por defecto;
- fail-closed antes de red;
- igualdad entre hash firmado y validado;
- ventana de siete días y rechazo de fecha futura;
- parsing de ticket de `sendSummary`;
- parsing de respuesta `getStatusResponse/status/statusCode`;
- `sendSummary` y `getStatus` confinados al transporte Perú beta;
- ausencia física del endpoint productivo en PHP;
- revalidación inmediatamente previa al envío;
- advisory lock + cooldown;
- ausencia de escrituras a `fiscal_status` desde el manager beta;
- bloqueo de firma/validación local tras existir ticket;
- credenciales y recursos XSD/XSL fuera del repositorio.

## Fuera de alcance

0.15.0 no habilita:

- endpoint productivo;
- credenciales SOL productivas;
- promoción de estados beta a estados fiscales productivos;
- reintento automático de tickets;
- cron de polling;
- envío masivo o pruebas de carga.

Antes de abrir producción deberá existir una fase separada con credenciales productivas externas, endpoint productivo gobernado, idempotencia remota, estrategia de reintentos, observabilidad y evidencia de aceptación real.