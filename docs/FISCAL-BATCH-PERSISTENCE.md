# Persistencia de lotes fiscales

## Objetivo 0.13.0

El preview de un lote fiscal no es suficiente para firmar o transmitir después: las ventas, clientes o datos del emisor podrían cambiar entre ambos momentos. La versión 0.13.0 introduce un snapshot local inmutable y reconstruible antes de cualquier firma o transporte de lotes.

## Tablas

### `farmapos_fiscal_batches`

Guarda la identidad y estado del lote:

- país;
- tipo de lote;
- fecha de referencia;
- fecha de generación;
- secuencia;
- identificador;
- builder;
- estados interno/firma/validación/transporte/beta/fiscal;
- cantidad de líneas;
- SHA-256 de identidad del snapshot;
- JSON del contexto fiscal congelado (`snapshot_body`);
- SHA-256 del XML local construido;
- ticket remoto reservado para cortes posteriores;
- metadata/evidencia.

El nombre físico conserva el prefijo histórico `farmapos_` como compatibilidad de almacenamiento del producto original.

### `farmapos_fiscal_batch_items`

Guarda los miembros congelados del lote:

- lote;
- comprobante;
- venta;
- número de línea;
- condition code;
- SHA-256 de la línea;
- JSON completo de la línea fiscal congelada.

No se usan foreign keys SQL para conservar compatibilidad con el modelo WordPress y las instalaciones existentes.

## Snapshot reconstruible

El snapshot de cabecera conserva el contexto necesario para reconstrucción posterior, incluido país, moneda, fechas, secuencia e identidad fiscal del emisor.

Cada miembro conserva su propia representación fiscal: tipo/código, serie, correlativo, documento del cliente, importes, tributos por bucket y condition code.

El XML no se toma como única autoridad persistida. En el siguiente corte podrá reconstruirse desde estos snapshots y compararse contra `xml_sha256` antes de firmar.

## Idempotencia

La creación usa un advisory lock MySQL por instalación + país + tipo de lote + fecha y una transacción explícita.

Para documentos con `condition_code=1`:

1. los comprobantes ya incluidos en un lote persistente se excluyen de nuevas selecciones;
2. si no existen comprobantes nuevos, se reutiliza el último lote de esa fecha;
3. si aparecen nuevos comprobantes después, se crea la siguiente secuencia únicamente con esos nuevos miembros;
4. el identificador queda estable y único por país;
5. una colisión o fallo de asociación provoca rollback completo.

Ejemplo conceptual:

- 10:00 → B001-1, B001-2 → `RC-20261029-00001`;
- 13:00 → vuelve a pulsarse sin nuevas boletas → se reutiliza `...00001`;
- 18:00 → aparece B001-3 → `RC-20261029-00002` solo con B001-3.

## Hash de identidad vs snapshot completo

El snapshot completo conserva `sequence` e `issue_date` porque son parte de la evidencia persistida.

El hash de idempotencia excluye esos dos campos: un mero reintento no debe convertirse en un lote distinto por haber ocurrido más tarde o por haber calculado otra secuencia provisional.

Sí cambian el hash:

- identidad/configuración fiscal del emisor;
- moneda/país/tipo de lote/fecha de referencia;
- miembros;
- cliente fiscal de cada línea;
- importes;
- tributos;
- condition code.

Las líneas y buckets tributarios se canonicalizan por orden antes de calcular el SHA-256.

## Concurrencia

`SPF_Fiscal_Batch_Repository` usa:

- `GET_LOCK` / `RELEASE_LOCK`;
- `START TRANSACTION`;
- `COMMIT` / `ROLLBACK`;
- constraints únicas para identificador, secuencia, snapshot y documento dentro de un lote.

Esto evita que dos clics simultáneos generen dos secuencias equivalentes.

## Interfaz

La pantalla Fiscal distingue:

- **Generar preview**: calcula el siguiente identificador y XML con los comprobantes todavía no agrupados, sin persistir;
- **Crear lote persistente**: congela los comprobantes y crea la secuencia real local;
- **Lotes fiscales persistidos**: muestra historial y estados separados.

Ninguna de estas acciones firma, valida remotamente o transmite.

## Bloqueos del corte

0.13.0 sigue sin:

- firma XMLDSig del lote persistido;
- XSD/XSL específicos de `SummaryDocuments` aplicados al snapshot persistido;
- `sendSummary`;
- `getStatus`;
- ticket remoto real;
- CDR del resumen;
- estados de modificación/anulación;
- producción.

## Siguiente corte

Reconstruir el `SummaryDocuments` exclusivamente desde `snapshot_body`, verificar que su hash coincide con el snapshot persistido, firmarlo con XMLDSig y aplicar los validadores locales específicos del resumen. Solo después se podrá abrir un transporte beta `sendSummary` + `getStatus` con ticket persistente.
