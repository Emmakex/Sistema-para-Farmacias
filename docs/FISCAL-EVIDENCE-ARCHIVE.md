# Archivo privado de evidencia fiscal

Versión objetivo: **0.18.0**.

## Objetivo

Conservar de forma verificable las respuestas fiscales finales recibidas por transportes remotos sin guardar ZIP/XML crudos en la base de datos de WordPress.

El store ya cubre dos flujos beta de Perú:

- Resumen Diario asíncrono (`sendSummary` + ticket + `getStatus`);
- factura síncrona (`sendBill` + CDR en la misma respuesta).

La arquitectura del store sigue siendo genérica y reutilizable por futuras localizaciones.

## Configuración

Definir fuera del plugin, preferentemente en `wp-config.php` o en una variable de entorno:

```php
define('SPF_FISCAL_EVIDENCE_DIR', '/ruta/privada/fuera/del/webroot/fiscal-evidence');
```

Reglas:

- la ruta debe ser local y absoluta;
- no puede ser una URL;
- debe estar fuera de `ABSPATH` / webroot;
- el proceso PHP debe poder crear/escribir los subdirectorios;
- no debe versionarse ni sincronizarse al repositorio público.

El provider actual es `external_filesystem`. Otros stores podrán registrarse mediante `SPF_Fiscal_Evidence_Store_Registry` sin cambiar el core fiscal.

## Estructura

### Resumen Diario

```text
<root>/
  pe/
    YYYY/
      MM/
        RC-YYYYMMDD-NNNNN/
          <ticket>/
            cdr.zip
            R-....xml
            manifest.json
```

### Factura síncrona

```text
<root>/
  pe/
    YYYY/
      MM/
        documents/
          F001-00000001/
            <sha256-cdr-zip>/
              cdr.zip
              R-....xml
              manifest.json
```

La identidad de factura incluye el SHA-256 del ZIP CDR. Por tanto, repetir exactamente la misma respuesta es idempotente y una respuesta CDR distinta para el mismo comprobante no puede sobrescribir la evidencia anterior.

## Qué conserva la base de datos

La base de datos conserva únicamente:

- locator relativo;
- SHA-256;
- tamaño;
- store utilizado;
- estado de verificación;
- fecha de archivado;
- resultado remoto normalizado: código, mensaje, notas y, cuando existe, ticket.

Los bytes de `cdr.zip` y del XML CDR **no** entran en `response_body`, options, transients ni en el ledger append-only.

## Integridad

Antes de archivar:

1. el transporte calcula SHA-256 del ZIP CDR recibido;
2. extrae el XML CDR y calcula su SHA-256;
3. el Evidence Manager vuelve a calcular ambos hashes sobre los bytes exactos;
4. el store escribe de forma atómica;
5. el store vuelve a leer/verificar los hashes archivados;
6. se genera un `manifest.json` determinista con locators y hashes;
7. el manifest también se verifica tras escribirlo.

Repetir el mismo archivado es idempotente. Un locator existente con contenido distinto se considera colisión y se bloquea.

## Resumen Diario y ledger append-only

La cadena remota queda:

```text
poll_intent
→ poll_result
→ evidence_archived
→ estado beta final
```

Si la autoridad devuelve un resultado final pero el archivo privado falla:

```text
poll_intent
→ poll_result
→ evidence_error
→ beta_batch_evidence_error
```

El ledger conserva solo locators, hashes y metadatos saneados. Nunca recibe los bytes ZIP/XML.

## Factura síncrona

`sendBill` devuelve la CDR dentro de la misma operación. La proyección local queda:

```text
firma + validación local
→ sendBill beta
→ CDR recibida
→ archive_document_cdr
→ estado beta final
```

Si el archivo falla después de recibir una respuesta remota:

```text
CDR recibida
→ archive_document_cdr ERROR
→ beta_evidence_error
```

Aunque la respuesta remota indique aceptación o rechazo, el documento no queda presentado como resultado beta final mientras la CDR no esté conservada y verificada.

## Estados beta de evidencia

Para lotes:

```text
beta_batch_evidence_error
```

Para facturas individuales:

```text
beta_evidence_error
```

En ambos casos se conservan hashes y resultado remoto saneado para diagnóstico, pero los bytes fiscales siguen fuera de la base de datos.

## Seguridad

- El store no realiza red.
- No conoce SUNAT ni ningún proveedor concreto.
- No acepta traversal `../`.
- No acepta rutas dentro del webroot.
- No sobrescribe un archivo existente si su hash es distinto.
- Usa escritura temporal + `rename()` y verificación SHA-256.
- Los bytes CDR solo existen transitoriamente en el transporte territorial y en el Evidence Manager.
- La capa beta sigue sin escribir `fiscal_status` de producción.
- El ledger remoto continúa siendo append-only y no almacena cuerpos XML/ZIP.

## Alcance deliberadamente fuera de 0.18.0

- endpoint productivo;
- credenciales SOL productivas;
- cron/polling automático;
- reintentos automáticos;
- backup/replicación del evidence store;
- política de retención o cifrado en reposo gestionado por el plugin;
- ledger append-only específico para el flujo síncrono individual.

La siguiente fase productiva deberá definir backup, retención, observabilidad e idempotencia remota completa antes de habilitar cualquier endpoint real.
