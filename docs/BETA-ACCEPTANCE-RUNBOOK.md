# Runbook · Aceptación beta fiscal controlada

## Objetivo

Este procedimiento sirve para ejecutar y documentar la primera aceptación beta real de **Factura** y **Resumen Diario** desde una instalación WordPress de pruebas de Sistema para Farmacias.

El objetivo no es demostrar conformidad de producción ni homologación. El servicio BETA de SUNAT está publicado como servicio exclusivo de pruebas y SUNAT indica que se utiliza para probar estructuras XML; no debe utilizarse para pruebas de carga ni para emitir comprobantes reales.

La aceptación interna del proyecto solo se considera cerrada cuando:

```text
Readiness Factura = LISTA
→ envío beta puntual
→ Aceptación beta real · Factura = CERTIFICADA
→ Readiness Resumen Diario = LISTO
→ envío beta puntual + polling hasta estado final
→ Aceptación beta real · Lote fiscal = CERTIFICADA
```

Producción debe permanecer bloqueada durante todo el procedimiento.

---

## 1. Referencias oficiales verificadas

Documentación oficial consultada el 29 de octubre de 2026:

- Servicios Web disponibles: https://orientacion.sunat.gob.pe/guias-manuales-y-servicios-web
- Pautas servicio BETA: https://orientacion.sunat.gob.pe/12-pautas-servicio-beta
- Guías y manuales UBL 2.1: https://orientacion.sunat.gob.pe/11-guias-y-manuales
- Reglas de validación: https://orientacion.sunat.gob.pe/04-reglas-de-validacion
- Manual del programador SEE - Sistemas del Contribuyente: https://cpe.sunat.gob.pe/sites/default/files/inline-files/manual_programador%20%281%29.pdf

Datos operativos publicados por SUNAT para BETA:

```text
WSDL: https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService?wsdl
Usuario: [RUC]MODDATOS
Password: MODDATOS
```

El transporte `peru_beta` del plugin usa ese WSDL y esas credenciales de pruebas.

SUNAT indica además que para usar este servicio beta no es necesario que el certificado digital esté registrado en SUNAT. Sistema para Farmacias mantiene, aun así, su propia firma XMLDSig y verificación local como precondición deliberada para aproximar el flujo técnico al que deberá exigirse antes de producción.

---

## 2. Regla de seguridad

Esta prueba debe cumplir todas estas condiciones:

- instalación WordPress de pruebas;
- datos de prueba;
- una única factura de prueba por ciclo;
- un único Resumen Diario de prueba por ciclo;
- nada de pruebas de carga o estrés;
- Evidence Store fuera del webroot;
- certificado y contraseña fuera del repositorio y de la base de datos;
- XSD/XSL oficiales fuera del plugin;
- `fiscal_status` debe permanecer `not_sent`;
- ningún endpoint productivo debe configurarse ni utilizarse.

Si alguna condición no se cumple, detener la prueba.

---

## 3. Configuración externa requerida

Estas constantes deben configurarse fuera del plugin, por ejemplo en el entorno seguro de la instalación WordPress de pruebas.

### Certificado

```php
define('SPF_FISCAL_P12_PATH', '/ruta/privada/certificado.p12');
define('SPF_FISCAL_P12_PASSWORD', '***');
```

No guardar el `.p12`, la clave ni la contraseña dentro del repositorio.

### Validación de comprobantes individuales

```php
define('SPF_FISCAL_XSD_PATH', '/ruta/privada/validadores/factura.xsd');
define('SPF_FISCAL_XSL_PATH', '/ruta/privada/validadores/factura.xsl');
```

Usar recursos oficiales compatibles con el XML que genera el plugin.

### Validación de Resumen Diario

```php
define('SPF_FISCAL_PERU_SUMMARY_XSD_PATH', '/ruta/privada/validadores/resumen-diario.xsd');
define('SPF_FISCAL_PERU_SUMMARY_XSL_PATH', '/ruta/privada/validadores/resumen-diario.xsl');
```

### Evidence Store

```php
define('SPF_FISCAL_EVIDENCE_DIR', '/ruta/privada/fuera-del-webroot/fiscal-evidence');
```

La ruta debe existir, ser escribible por PHP y permanecer fuera de `ABSPATH` y del webroot.

### Opt-in de factura beta

```php
define('SPF_FISCAL_BETA_ENABLED', true);
define('SPF_FISCAL_BETA_CONFIRMATION', 'SUNAT_TEST_ONLY');
```

### Opt-in de Resumen Diario beta

```php
define('SPF_FISCAL_BETA_SUMMARY_ENABLED', true);
define('SPF_FISCAL_BETA_SUMMARY_CONFIRMATION', 'SUNAT_SUMMARY_TEST_ONLY');
```

Estos flags habilitan únicamente el transporte beta definido por el pack Perú. No habilitan producción.

---

## 4. Precondición: identidad del emisor

En **Sistema para Farmacias → Configuración** completar la identidad fiscal del emisor de pruebas:

- identificación fiscal;
- razón social;
- dirección fiscal.

Para el pack Perú, el readiness aplica la política territorial configurada por el propio pack.

No continuar mientras el panel **Preparación para aceptación beta fiscal** no muestre el alcance correspondiente como `LISTO`.

---

# PARTE A · Factura beta

## 5. Preparar una factura de prueba

Crear una venta de prueba cuyo comprobante sea una factura y utilizar datos ficticios coherentes con el entorno de pruebas.

En **Fiscal**, el documento debe superar en este orden:

```text
preflight
→ firma XMLDSig
→ verificación de firma
→ validación XSD/XSL
```

Antes del envío deben cumplirse como mínimo:

```text
signature_status = verified
validation_status = valid_local
fiscal_status = not_sent
Readiness Factura = LISTA
```

Si el readiness muestra `BLOQUEADA`, abrir sus comprobaciones y resolver únicamente la precondición marcada. No forzar el envío.

## 6. Ejecutar el envío beta de factura

Usar una única vez la acción beta disponible para la factura validada.

El flujo esperado en el ledger es:

```text
submit_intent
→ respuesta de sendBill
→ response_received
→ archivo físico CDR
→ evidence_archived
→ proyección beta local
```

El duplicate guard debe impedir que la misma evidencia criptográfica se transmita nuevamente después de existir respuesta remota.

## 7. Criterio de éxito de factura

Después del envío, volver a **Fiscal**.

El panel:

```text
Aceptación beta real · Factura
```

debe mostrar:

```text
CERTIFICADA
```

La certificación exige, entre otras cosas:

- resultado `beta_accepted` o `beta_accepted_with_observation`;
- fingerprint actual igual al del ledger;
- `submit_intent → response_received → evidence_archived` en orden;
- hash del request igual al XML firmado;
- hashes CDR coherentes;
- ZIP/XML/manifest archivados y verificados;
- proyección beta alineada;
- `fiscal_status = not_sent`.

Si el panel muestra `BLOQUEADA`, no repetir el envío automáticamente. Registrar las comprobaciones que fallan y diagnosticar primero la evidencia existente.

---

# PARTE B · Resumen Diario beta

## 8. Preparar boletas para el lote

Crear una o varias boletas de prueba aptas para Resumen Diario.

En **Fiscal → Lote fiscal diario**:

1. seleccionar la fecha de emisión;
2. ejecutar **Generar preview**;
3. comprobar número de documentos y advertencias;
4. ejecutar **Crear lote persistente**.

Persistir el lote congela un snapshot local. A partir de ese momento la firma, validación y envío deben operar sobre el snapshot, no sobre las ventas mutables.

## 9. Preparar el lote

El lote debe superar:

```text
integridad del snapshot
→ firma XMLDSig
→ verificación de firma
→ validación XSD/XSL del Resumen Diario
```

Antes de transmitir deben cumplirse:

```text
signature_status = verified
validation_status = valid_local
fiscal_status = not_sent
Readiness Resumen Diario = LISTO
```

## 10. Enviar el lote beta

Ejecutar una única transmisión del lote preparado.

El método asíncrono esperado es:

```text
submit_intent
→ sendSummary
→ ticket_received
```

SUNAT documenta que `sendSummary` devuelve un ticket que posteriormente se consulta mediante `getStatus`.

## 11. Consultar el ticket

Usar la acción de consulta beta del lote.

Los estados documentados para `getStatus` son:

```text
0  = procesó correctamente
98 = en proceso
99 = proceso con errores
```

Si devuelve `98`, no crear otro lote ni reenviar el mismo snapshot. Conservar el ticket y volver a consultar mediante la acción de polling existente respetando el cooldown del sistema.

Un flujo válido puede ser:

```text
poll_intent
→ poll_result (98 / processing)
→ poll_intent
→ poll_result (0 / final)
```

Cuando el resultado sea final, el sistema debe archivar la CDR antes de proyectar el estado del lote:

```text
poll_result final
→ archivo físico CDR
→ evidence_archived
→ proyección beta del lote
```

## 12. Criterio de éxito del lote

Después del resultado final, el panel:

```text
Aceptación beta real · Lote fiscal
```

debe mostrar:

```text
CERTIFICADA
```

La certificación exige:

- resultado `beta_batch_accepted` o `beta_batch_accepted_with_observation`;
- fingerprint actual igual al ledger;
- último intento de submit efectivo ligado al ticket;
- ticket consistente en lote, ledger y proyección;
- último `poll_result` final ligado a su propio `poll_intent`;
- orden `submit → ticket → poll_intent final → poll_result final → evidence_archived`;
- hashes request/ZIP/CDR coherentes;
- ZIP/XML/manifest archivados y verificados;
- proyección mutable alineada con el ledger;
- `fiscal_status = not_sent`.

Un estado remoto rechazado demuestra comunicación con el servicio, pero **no** certifica aceptación.

---

## 13. Qué registrar en cada prueba

No copiar XML, ZIP, certificado, contraseña ni rutas privadas.

Registrar únicamente:

```text
Fecha/hora de prueba
Versión Sistema para Farmacias
Tipo: Factura / Resumen Diario
Referencia: serie-correlativo o identificador del lote
Readiness: LISTO/BLOQUEADO
Resultado beta proyectado
Código remoto
Mensaje remoto saneado
Ticket, solo para lote
SHA-256 de evidencia
Fecha/hora de evidence_archived
Acceptance: CERTIFICADA/BLOQUEADA
Checks bloqueados, si existen
fiscal_status
```

Estos datos son suficientes para diagnosticar el flujo sin exponer material fiscal sensible.

---

## 14. Matriz de decisión

### Readiness BLOQUEADA

No transmitir. Resolver la precondición local.

### Error antes de recibir respuesta remota

Conservar el ledger. Revisar transporte/configuración antes de decidir un nuevo intento.

### `response_received` sin `evidence_archived`

No reenviar automáticamente. Tratarlo como reconciliación técnica de evidencia.

### Ticket recibido y polling `98`

No reenviar. Consultar el mismo ticket posteriormente.

### Resultado remoto rechazado

No certificar. Revisar código/mensaje y XML/validadores; crear una nueva evidencia solo después de corregir la causa.

### Resultado remoto aceptado pero Acceptance BLOQUEADA

No considerar éxito. Resolver la discrepancia entre ledger, hashes, archivo o proyección.

### Acceptance CERTIFICADA

Guardar el registro saneado de la prueba. Confirmar nuevamente que `fiscal_status = not_sent`.

---

## 15. Cierre de aceptación beta técnica

La etapa beta se considera técnicamente cerrada solo cuando existen dos pruebas reales independientes:

```text
Factura beta          = CERTIFICADA
Resumen Diario beta   = CERTIFICADA
Producción            = BLOQUEADA
```

Solo después de ese punto puede abrirse un proyecto separado de preparación de producción: credenciales productivas externas, política formal de reintentos e idempotencia, observabilidad, backup/retención, reconciliación y revisión normativa final.

La configuración productiva no forma parte de este runbook.
