# Ledger remoto append-only para comprobantes síncronos

Versión objetivo: **0.20.0**.

## Problema que resuelve

Una factura beta usa un transporte síncrono: se envía el XML firmado y la autoridad devuelve la CDR dentro de la misma operación.

La tabla principal del comprobante es una **proyección mutable**. Si la autoridad respondió y el archivo CDR quedó preservado, una escritura local fallida nunca debe provocar un segundo envío del mismo XML.

## Ledger

La tabla independiente es:

```text
farmapos_fiscal_document_remote_events
```

Los eventos son append-only. El repositorio no contiene `UPDATE` ni `DELETE`.

Cada evento puede conservar:

- `document_id`;
- operación;
- transporte y entorno;
- fingerprint SHA-256;
- SHA-256 del XML/ZIP enviados;
- SHA-256 del ZIP/XML CDR;
- código y mensaje remoto normalizados;
- metadata saneada;
- timestamp.

Nunca se guardan XML/ZIP crudos en el ledger.

## Binding criptográfico

`evidence_sha256` se construye con:

```text
document_id
sale_id
country_code
document_type
serie
correlativo
signature_xml_sha256
signature_certificate_sha256
validation_xml_sha256
transport
environment
```

Cambiar XML firmado, certificado, validación, documento, transporte o entorno genera otra identidad remota.

Desde 0.20.0 el mismo cálculo puede ejecutarse también sobre una fila ya cargada mediante `evidence_sha256_from_row()`. Esto permite detectar candidatos operativos sin reconstruir firma/validación para cada fila del panel administrativo.

## Orden obligatorio

### Envío correcto

```text
validación exacta
→ duplicate guard
→ submit_intent
→ transmisión beta
→ response_received
→ archivo privado CDR
→ evidence_archived
→ proyección mutable del comprobante
```

### Error de transporte

```text
submit_intent
→ error remoto/transporte
→ submit_error
→ proyección beta_transport_error
```

### Error de archivo

```text
response_received
→ evidence_error
→ beta_evidence_error
```

Una respuesta ya registrada para la misma evidencia **bloquea el reenvío**.

## Recuperación de proyección

`recover_beta_invoice()`:

1. reconstruye y valida otra vez el XML actual;
2. calcula la misma `evidence_sha256`;
3. exige un `evidence_archived` coincidente;
4. reconstruye solo metadatos saneados;
5. actualiza `beta_status` / `response_body` sin red;
6. añade `projection_recovered` al ledger.

La recuperación no transmite el comprobante de nuevo.

## UI operativa 0.20.0

`SPF_Fiscal_Document_Recovery_UI` muestra en la pantalla **Fiscal** únicamente desalineaciones recuperables:

- existe `evidence_archived`;
- el fingerprint archivado coincide con la firma, certificado y validación actualmente persistidos;
- el `beta_status` local no coincide con el resultado archivado.

La detección usa `recovery_candidates()` y una consulta agrupada; no ejecuta `build_package()`, firma ni validación completa por cada fila.

El panel muestra:

- comprobante;
- estado beta archivado;
- estado beta local;
- prefijo del fingerprint;
- código remoto;
- fecha de archivo;
- acción **Recuperar desde ledger**.

La acción está protegida por nonce y permisos. Al pulsarla, la comprobación server-side completa vuelve a ejecutar `recoverable_beta_invoice()` antes de modificar nada.

Por tanto, el panel es una **ayuda operativa**, no una nueva autoridad de integridad.

## Situación no recuperable automáticamente

Si existe `response_received` pero no existe `evidence_archived`, el sistema bloquea un nuevo envío y no muestra recuperación segura.

La autoridad respondió pero la cadena de conservación quedó incompleta. Se requiere reconciliación técnica; el sistema no puede asumir que reenviar sea seguro.

## Seguridad

- ledger append-only;
- sin cuerpos CDR/XML en base de datos;
- Evidence Store privado como autoridad de bytes;
- UI sin acceso directo a `$wpdb`;
- detección UI sin red ni reconstrucción criptográfica N+1;
- recuperación final con validación server-side;
- beta no escribe `fiscal_status`;
- endpoint productivo continúa ausente.

## Fuera de alcance

- producción;
- retry automático de operaciones remotas inciertas;
- explorador completo de todos los eventos del documento;
- reconciliación automática de un `response_received` sin CDR archivada;
- replicación/backup del ledger o Evidence Store.
