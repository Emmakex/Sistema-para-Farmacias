# Perú · Resumen Diario de boletas

## Alcance 0.12.0

Este corte incorpora únicamente la **construcción local en preview** de `SummaryDocuments` para comprobantes cuya estrategia fiscal es `daily_summary`.

No firma el lote, no lo valida con el paquete XSD/XSL específico del resumen, no llama `sendSummary`, no consulta `getStatus`, no persiste ticket y no modifica `fiscal_status`.

## Contrato

El core usa `SPF_Fiscal_Batch_Builder_Interface` y `SPF_Fiscal_Batch_Manager`. El perfil territorial decide qué builder atiende cada tipo de lote. Perú registra:

- batch type: `daily_summary`;
- builder: `peru_summary_documents`;
- UBL: `2.0`;
- `CustomizationID`: `1.1`;
- prefijo de preview: `RC`;
- operación remota: pendiente.

`SPF_Fiscal_Batch_Manager` selecciona por fecha los comprobantes cuyo `fiscal_submission` coincide con `daily_summary`, agrega sus importes por afectación tributaria y entrega un contexto neutral al country pack.

## SummaryDocuments Perú

El builder Perú genera:

- namespace `SummaryDocuments-1`;
- `UBLVersionID=2.0`;
- `CustomizationID=1.1`;
- identificador `RC-YYYYMMDD-00001` para preview local;
- `ReferenceDate` como fecha de emisión de los documentos;
- `IssueDate` como fecha de generación;
- emisor con RUC;
- una `sac:SummaryDocumentsLine` por boleta;
- `DocumentTypeCode=03`;
- serie/correlativo;
- documento del adquirente, usando `-` / `-` cuando no fue informado;
- `ConditionCode=1` (adicionar) en este primer corte;
- `TotalAmount`;
- `BillingPayment` 01/02/03 para gravado, exonerado e inafecto;
- `TaxTotal` IGV.

No se implementan todavía percepciones, ISC, otros tributos, operaciones gratuitas, notas de crédito/débito dentro del resumen ni estados 2/3 de modificación/anulación.

## Evidencia normativa revisada

Se contrastó la guía oficial de SUNAT para Resumen Diario de boletas y notas electrónicas y la documentación vigente de SEE-Contribuyente. La guía mantiene `SummaryDocuments` con UBL 2.0 / CustomizationID 1.1 y el ejemplo oficial usa estado `1` para adicionar. La documentación de boletas indica que el Resumen Diario se remite el mismo día o, como máximo, hasta el séptimo día calendario siguiente a la emisión.

Fuentes oficiales:

- https://cpe.sunat.gob.pe/sites/default/files/inline-files/GUIA_Resumen_de_Boletas_11-01-2018%20%282%29_2_0%20%281%29_0.pdf
- https://cpe.sunat.gob.pe/tipos_de_comprobantes/boleta
- https://cpe.sunat.gob.pe/sistema_emision/see_contribuyente

## Guards

CI exige:

- smoke real del XML `SummaryDocuments`;
- UBL 2.0 / CustomizationID 1.1;
- código 03 y estado 1;
- comprador sin documento representado como `-` / `-`;
- buckets gravado/exonerado/inafecto e IGV;
- ausencia absoluta de `sendSummary`, `getStatus` o red en la capa batch.

## Siguiente corte

El siguiente bloque debe persistir el lote fiscal con secuencia real, asociar los comprobantes incluidos y permitir firma XMLDSig + validadores XSD/XSL específicos del SummaryDocuments. Solo después se habilitará `sendSummary` beta y la persistencia/consulta del ticket mediante `getStatus`.
