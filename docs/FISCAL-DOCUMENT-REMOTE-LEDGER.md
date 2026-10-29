# Ledger remoto append-only para comprobantes síncronos

Versión objetivo: **0.19.0**.

## Problema que resuelve

Una factura beta usa un transporte síncrono: se envía el XML firmado y la autoridad devuelve la CDR dentro de la misma operación.

Sin un ledger previo a la proyección de WordPress existe un fallo peligroso:

1. la autoridad recibe y responde;
2. la CDR se archiva correctamente;
3. WordPress falla al actualizar `beta_status` / `response_body`;
4. el operador vuelve a pulsar enviar;
5. el mismo XML podría transmitirse de nuevo.

La tabla principal del comprobante es una **proyección mutable**. No puede ser la única evidencia de una operación remota.

## Ledger

Se añade una tabla independiente:

```text
farmapos_fiscal_document_remote_events
```

Los eventos son append-only. El repositorio no contiene `UPDATE` ni `DELETE`.

Cada evento queda vinculado a:

- `document_id`;
- operación;
- transporte;
- entorno;
- SHA-256 de evidencia;
- SHA-256 del XML enviado;
- SHA-256 del ZIP enviado cuando existe;
- SHA-256 del ZIP CDR;
- SHA-256 del XML CDR;
- código/mensaje remoto normalizados;
- metadata saneada;
- fecha.

Nunca se guardan XML/ZIP crudos en el ledger.

## Binding criptográfico

`evidence_sha256` se construye con la identidad del comprobante y el payload exacto:

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

Cambiar XML firmado, certificado, validación o documento genera otra identidad remota.

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

Si `evidence_archived` existe pero falla la escritura de la tabla de comprobantes, la respuesta remota no se pierde.

`recover_beta_invoice()`:

1. reconstruye y valida otra vez el XML actual;
2. calcula la misma `evidence_sha256`;
3. busca el último `evidence_archived` para esa identidad;
4. reconstruye solo los metadatos saneados;
5. actualiza `beta_status` / `response_body` sin tocar red;
6. añade `projection_recovered` al ledger.

La recuperación no transmite el comprobante de nuevo.

## Situación no recuperable automáticamente

Si existe `response_received` pero no existe `evidence_archived`, el sistema bloquea un nuevo envío.

Esto significa que la autoridad respondió pero la cadena de conservación quedó incompleta. Se requiere revisión técnica; el sistema no puede asumir que reenviar sea seguro.

## Seguridad

- ledger append-only;
- sin cuerpos CDR/XML;
- sin red dentro del repositorio de eventos;
- sin literales de proveedor/autoridad en el core;
- beta no escribe `fiscal_status`;
- endpoint productivo continúa ausente;
- el Evidence Store privado sigue siendo la autoridad para los bytes fiscales.

## Fuera de alcance

- producción;
- retry automático de operaciones remotas inciertas;
- UI específica para explorar todos los eventos del documento;
- reconciliación automática de un `response_received` sin CDR archivada;
- replicación/backup del ledger o evidence store.
