# Evidencia remota append-only para lotes fiscales

## Objetivo

La versión 0.16.0 añade un ledger separado para conservar evidencia de cada interacción remota de un lote fiscal sin almacenar el XML o ZIP completos.

El problema que resuelve es específico y crítico: una autoridad remota puede devolver un ticket correctamente y, a continuación, la actualización de `fiscal_batches.remote_ticket` puede fallar por un problema local. Sin un registro independiente, reenviar el lote podría duplicar una operación que ya fue recibida.

## Tabla

El ledger vive en:

`fiscal_batch_remote_events`

Cada fila es append-only y contiene únicamente evidencia mínima:

- UUID del evento;
- `batch_id`;
- operación;
- transporte y entorno;
- fingerprint SHA-256 de la evidencia local;
- ticket, cuando existe;
- estado beta y `statusCode`;
- SHA-256 del XML firmado solicitado;
- SHA-256 del ZIP enviado;
- SHA-256 del ZIP de respuesta;
- SHA-256 del XML CDR;
- códigos/mensajes remotos;
- metadata no sensible;
- timestamp.

No se guardan:

- XML completos;
- ZIP completos;
- certificados privados;
- contraseñas;
- claves privadas;
- credenciales productivas.

## Fingerprint de evidencia

Cada evento remoto queda ligado a un fingerprint SHA-256 construido con:

- identidad del lote;
- país y tipo;
- identificador fiscal del lote;
- hash del snapshot;
- hash del XML sin firmar persistido;
- hash del XML firmado;
- hash del certificado;
- hash del XML validado;
- transporte;
- entorno.

Por tanto, un ticket solo es recuperable cuando sigue correspondiendo exactamente a la misma evidencia criptográfica.

## Orden obligatorio de eventos

### Envío

Antes de red:

1. integridad del snapshot;
2. firma reproducible;
3. validación local completa;
4. inspección del transporte;
5. comprobación de que no existe un `ticket_received` previo para el mismo fingerprint;
6. persistencia de `submit_intent`.

Solo entonces se permite `submit_batch()`.

Si llega un ticket:

1. se persiste `ticket_received` en el ledger;
2. después se intenta asociar el ticket a `fiscal_batches`.

Este orden es deliberado. Si el paso 2 falla, el ticket ya está preservado y puede recuperarse sin reenviar.

### Consulta

Antes de `poll_batch()` se exige `poll_intent`.

La respuesta remota se guarda primero como `poll_result` y después se refleja en el lote principal. Si la actualización del lote falla, el ledger mantiene la evidencia recibida.

## Recuperación de ticket

No existe un campo para escribir un ticket manualmente.

`Recuperar ticket` solo aparece si:

- `fiscal_batches.remote_ticket` está vacío;
- existe un evento `ticket_received`;
- el evento pertenece al mismo lote;
- el fingerprint del evento coincide con la evidencia actual;
- el transporte y entorno siguen siendo los mismos.

La recuperación:

1. toma el ticket registrado por el sistema;
2. lo asocia al lote;
3. restaura `beta_status` y hash del ZIP solicitado;
4. añade un evento `ticket_recovered` con referencia al evento fuente.

No realiza red y no vuelve a ejecutar `sendSummary`.

## Congelación de evidencia

Desde que existe cualquiera de estas condiciones:

- `remote_ticket` asociado al lote;
- `ticket_received` recuperable en el ledger;

el lote queda bloqueado para:

- nueva firma;
- nueva validación local.

El bloqueo existe tanto en UI como en handlers server-side.

## Concurrencia

Las operaciones remotas de lote continúan protegidas mediante advisory lock MySQL (`GET_LOCK` / `RELEASE_LOCK`) y cooldown.

El ledger no sustituye ese lock: añade persistencia de intención y resultado para poder reconstruir qué ocurrió incluso cuando la actualización del agregado principal falla.

## Semántica de producción

El ledger registra evidencia beta, pero no otorga autoridad productiva.

La versión 0.16.0 no escribe `fiscal_status` desde el beta y el endpoint productivo sigue ausente del código PHP.

## CI

CI verifica:

- smoke de fingerprint determinista;
- cambio de fingerprint al cambiar el certificado;
- registro `submit_intent`;
- registro `ticket_received`;
- detección de ticket recuperable;
- recuperación sin red;
- registro `ticket_recovered`;
- rechazo de fingerprints inválidos;
- orden `submit_intent` antes de `submit_batch`;
- orden `ticket_received` antes de asociar ticket al lote;
- orden `poll_intent` antes de `poll_batch`;
- ausencia de update/delete en el repositorio de eventos;
- ausencia de cuerpos XML/ZIP en el esquema del ledger;
- bloqueo server-side de firma/validación ante ticket recuperable.

## Siguiente fase

Con el ledger certificado, una prueba beta real controlada puede dejar una cadena de evidencia completa:

`submit_intent → ticket_received → poll_intent → poll_result`

Solo después de obtener evidencia beta real se evaluará la siguiente fase. Producción seguirá separada y bloqueada hasta disponer de credenciales productivas externas, idempotencia/reintentos definidos, observabilidad y reconciliación formal de CDR.