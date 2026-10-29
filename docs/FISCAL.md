# Arquitectura fiscal

## Estado actual

La arquitectura fiscal vigente corresponde a **Sistema para Farmacias 0.16.0**.

El sistema separa responsabilidades locales, beta y productivas para evitar que una prueba remota pueda convertirse accidentalmente en autoridad fiscal real.

## Capas

1. **Core fiscal**: construye el contexto canónico de venta/comprobante y gobierna estados internos.
2. **Adaptador de país**: aplica reglas territoriales y construye el XML correspondiente.
3. **Firma**: aplica y verifica XMLDSig usando credenciales externas al repositorio y a la base de datos.
4. **Validación local**: ejecuta XSD/XSL desde recursos externos al plugin.
5. **Lotes fiscales**: congela snapshots inmutables para documentos que se transmiten agrupados.
6. **Transporte local**: inspección y dry-run sin red.
7. **Transporte beta**: pruebas remotas explícitas, aisladas y sin autoridad productiva.
8. **Ledger remoto**: evidencia append-only de intenciones, tickets, consultas y hashes de respuestas.
9. **Resultado fiscal productivo**: reservado para una fase futura y separada.

## Estados independientes

`internal_status` describe el flujo local.

`signature_status` describe únicamente la firma local.

`validation_status` describe únicamente la validación local.

`transport_status` describe el transporte local/dry-run.

`beta_status` describe exclusivamente evidencia del entorno de pruebas.

`fiscal_status` queda reservado para resultado o tránsito fiscal real de producción.

**Una respuesta beta nunca modifica `fiscal_status`.**

La columna histórica `sunat_status` se conserva temporalmente por compatibilidad con FarmaPOS, pero no es autoridad del nuevo diseño.

## Firma local

`SPF_Fiscal_XMLDSig_Signer` es el motor genérico de firma.

La firma:

- inserta los contenedores UBL necesarios;
- firma mediante XMLDSig enveloped;
- incrusta el certificado X.509 público;
- verifica inmediatamente digest y firma;
- rechaza certificados fuera de vigencia;
- genera hashes SHA-256 de evidencia;
- nunca persiste claves privadas ni contraseñas.

Las credenciales se cargan mediante `SPF_Fiscal_Credentials_Environment` desde:

- `SPF_FISCAL_P12_PATH`;
- `SPF_FISCAL_P12_PASSWORD`.

## Validación local

`SPF_Fiscal_XSD_XSL_Validator` ejecuta validación local sin red.

Para comprobantes individuales:

- `SPF_FISCAL_XSD_PATH`;
- `SPF_FISCAL_XSL_PATH`.

Para Resumen Diario Perú:

- `SPF_FISCAL_PERU_SUMMARY_XSD_PATH`;
- `SPF_FISCAL_PERU_SUMMARY_XSL_PATH`.

`valid_local` requiere XSD y XSL configurados y válidos.

Los recursos oficiales permanecen fuera del repositorio.

## Lotes fiscales

Los comprobantes con estrategia `daily_summary` se agrupan en lotes persistidos.

Cada lote conserva:

- cabecera congelada;
- miembros congelados;
- SHA-256 por línea;
- SHA-256 global del snapshot;
- identificador y secuencia estables;
- hash del XML reconstruido.

La firma y validación del lote reconstruyen exclusivamente desde snapshots persistidos, no desde ventas o clientes actuales.

## Perú · Resumen Diario

El perfil Perú declara:

- `boleta` → estrategia `daily_summary`;
- builder `peru_summary_documents`;
- identificador `RC-YYYYMMDD-NNNNN`;
- firma XMLDSig local;
- validación XSD/XSL específica del lote;
- transporte beta asíncrono `peru_beta`;
- producción deshabilitada.

El flujo local es:

`snapshot → integridad → firma → validación`

El flujo beta es:

`revalidación → submit intent → sendSummary → ticket → poll intent → getStatus → evidencia de respuesta`

## Beta Perú · factura individual

Las facturas de prueba usan:

- estrategia `single`;
- código fiscal `01`;
- `sendBill`;
- CDR síncrona;
- estado separado en `beta_status`.

El beta individual requiere:

```php
define('SPF_FISCAL_BETA_ENABLED', true);
define('SPF_FISCAL_BETA_CONFIRMATION', 'SUNAT_TEST_ONLY');
```

## Beta Perú · Resumen Diario

El beta de lotes tiene opt-in independiente:

```php
define('SPF_FISCAL_BETA_SUMMARY_ENABLED', true);
define('SPF_FISCAL_BETA_SUMMARY_CONFIRMATION', 'SUNAT_SUMMARY_TEST_ONLY');
```

Antes de `sendSummary` se exige:

1. snapshot íntegro;
2. firma reproducible y verificada;
3. XSD/XSL local completo;
4. igualdad entre hash firmado y hash validado;
5. misma evidencia de certificado;
6. fecha dentro de la ventana admitida por el perfil beta;
7. advisory lock MySQL;
8. cooldown;
9. evento append-only `submit_intent`.

`sendSummary` devuelve un ticket. `getStatus` consulta después ese ticket y distingue procesamiento en curso y resultados finalizados.

El transporte beta de Perú es el único lugar donde existen las operaciones SOAP y el endpoint beta.

## Ledger remoto append-only

`SPF_Fiscal_Batch_Remote_Event_Repository` conserva una cadena de evidencia separada del agregado `fiscal_batches`.

Eventos principales:

- `submit_intent`;
- `submit_error`;
- `ticket_received`;
- `poll_intent`;
- `poll_error`;
- `poll_result`;
- `ticket_recovered`.

El ledger guarda hashes, ticket, códigos, mensajes y timestamps; no guarda XML/ZIP completos.

Cada evento queda unido a un fingerprint SHA-256 que incluye identidad del lote, snapshot, XML, firma, certificado, validación, transporte y entorno.

### Recuperación de ticket

Si la autoridad devuelve un ticket y después falla la actualización de `fiscal_batches`, el ticket ya debe existir en `ticket_received`.

La acción `Recuperar ticket`:

- no permite escribir tickets manualmente;
- solo acepta un evento previo del propio sistema;
- exige fingerprint coincidente;
- no realiza red;
- asocia el ticket al lote;
- añade `ticket_recovered`.

Mientras exista un ticket asociado **o recuperable**, la firma y validación local del lote quedan congeladas tanto en UI como server-side.

## Concurrencia y carga

Las operaciones beta de lote usan advisory lock MySQL (`GET_LOCK` / `RELEASE_LOCK`).

Además existen cooldowns separados para envío y consulta.

El servicio beta debe utilizarse solo para pruebas controladas. No se utiliza para estrés, carga masiva ni comprobantes reales.

## Límites de producción

El código actual no habilita:

- endpoint productivo;
- credenciales SOL productivas;
- promoción de estados beta a `fiscal_status`;
- reintentos automáticos de envíos asíncronos;
- polling automático mediante cron;
- almacenamiento de XML/ZIP remotos como evidencia definitiva;
- afirmación de compatibilidad productiva hasta completar pruebas y reconciliación formal.

## CI

CI verifica, entre otros:

- sintaxis PHP;
- firma XMLDSig y rechazo de manipulación;
- validación XSD/XSL;
- construcción `SummaryDocuments`;
- persistencia e idempotencia del snapshot;
- firma y validación del lote;
- beta individual fail-closed;
- beta de Resumen Diario fail-closed;
- parsing de ticket y `getStatus`;
- aislamiento de operaciones SOAP en el country pack Perú;
- ausencia del endpoint productivo en PHP;
- advisory lock y cooldown;
- ausencia de escrituras beta a `fiscal_status`;
- ledger remoto append-only;
- orden de evidencia antes de cada llamada remota;
- recuperación de ticket sin red;
- ausencia de cuerpos XML/ZIP en el ledger;
- credenciales, certificados y paquetes XSD/XSL fuera del repositorio.

## Referencias funcionales

La implementación se contrasta con documentación pública de SUNAT para:

- `sendBill`;
- `sendSummary`;
- `getStatus`;
- credenciales y endpoint del servicio beta;
- restricciones de uso del beta;
- guías y recursos UBL/XSD/XSL.

## Próximo corte

Tras certificar 0.16.0, el siguiente paso es ejecutar **una prueba beta real controlada** desde una instalación preparada y conservar la cadena completa:

`submit_intent → ticket_received → poll_intent → poll_result`

Esa evidencia servirá para reconciliar el comportamiento real del servicio vigente. Producción seguirá bloqueada hasta una fase posterior con credenciales productivas externas, estrategia formal de idempotencia/reintentos, observabilidad y reconciliación CDR.