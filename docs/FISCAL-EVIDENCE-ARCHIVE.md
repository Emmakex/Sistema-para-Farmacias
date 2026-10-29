# Archivo privado de evidencia fiscal

Versión objetivo: **0.17.0**.

## Objetivo

Conservar de forma verificable las respuestas fiscales finales recibidas por transportes remotos sin guardar ZIP/XML crudos en la base de datos de WordPress.

El primer consumidor es el Resumen Diario de Perú en entorno beta. La arquitectura del store es genérica y reutilizable por futuras localizaciones.

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

Para un lote beta final:

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

La base de datos conserva únicamente:

- locator relativo;
- SHA-256;
- tamaño;
- store utilizado;
- estado de verificación;
- fecha de archivado;
- datos remotos ya normalizados (código, mensaje, notas y ticket).

Los bytes de `cdr.zip` y del XML CDR **no** entran en `response_body`, options, transients ni en el ledger append-only.

## Integridad

Antes de archivar:

1. el transporte calcula SHA-256 del ZIP CDR recibido;
2. extrae el XML CDR y calcula su SHA-256;
3. el manager vuelve a calcular ambos hashes sobre los bytes exactos;
4. el store escribe de forma atómica;
5. el store vuelve a leer/verificar los hashes archivados;
6. se genera un `manifest.json` determinista con locators y hashes;
7. el manifest también se verifica tras escribirlo.

Repetir el mismo archivado es idempotente. Un locator existente con contenido distinto se considera colisión y se bloquea.

## Integración con el ledger append-only

La cadena remota queda:

```text
poll_intent
→ poll_result
→ evidence_archived
```

Si la autoridad devuelve un resultado final pero el archivo privado falla:

```text
poll_intent
→ poll_result
→ evidence_error
```

El ledger conserva solo locators, hashes y metadatos saneados. Nunca recibe los bytes ZIP/XML.

## Estados beta

Los estados finales normales siguen siendo:

- `beta_batch_accepted`;
- `beta_batch_accepted_with_observation`;
- `beta_batch_rejected`.

Estos estados solo se reflejan como resultado final en el lote después de conservar la CDR.

Si la autoridad ya devolvió un resultado final pero el archivo privado falla, se usa:

```text
beta_batch_evidence_error
```

En ese caso se conservan el ticket, hashes, `poll_result` append-only y resultado remoto normalizado, pero el lote no se presenta como resultado beta final archivado. Debe corregirse el storage y recuperarse la evidencia antes de continuar.

## Seguridad

- El store no realiza red.
- No conoce SUNAT ni ningún proveedor concreto.
- No acepta traversal `../`.
- No acepta rutas dentro del webroot.
- No sobrescribe un archivo existente si su hash es distinto.
- Usa escritura temporal + `rename()` y verificación SHA-256.
- La capa beta sigue sin escribir `fiscal_status` de producción.
- El ledger remoto continúa siendo append-only y no almacena cuerpos XML/ZIP.

## Alcance deliberadamente fuera de 0.17.0

- endpoint productivo;
- credenciales SOL productivas;
- CDR de facturas individuales dentro del evidence store;
- cron/polling automático;
- reintentos automáticos;
- backup/replicación del evidence store;
- política de retención o cifrado en reposo gestionado por el plugin.

La siguiente fase productiva deberá definir backup, retención, observabilidad e idempotencia remota antes de habilitar cualquier endpoint real.
