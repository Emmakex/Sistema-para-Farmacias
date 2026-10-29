# Arquitectura fiscal

## Principio

Sistema para Farmacias separa tres responsabilidades:

1. **Core fiscal**: obtiene el contexto canónico de venta/comprobante y gobierna estados internos.
2. **Adaptador de país**: valida reglas territoriales y construye el formato fiscal correspondiente.
3. **Transporte**: firma, autenticación, envío, recepción y reconciliación con la autoridad/PSE/OSE.

El transporte no forma parte del corte 0.7.0.

## Estados

`internal_status` describe únicamente nuestro flujo local (`draft`, `ready`, `prepared`, etc.).

`fiscal_status` describe únicamente un resultado o tránsito frente a una autoridad/proveedor (`not_sent`, `submitted`, `accepted`, `accepted_with_observation`, `rejected`, `transport_error`).

La columna histórica `sunat_status` se conserva temporalmente por compatibilidad con FarmaPOS, pero no es la autoridad semántica del nuevo diseño.

## Preflight

`SPF_Fiscal_Manager` construye un contexto inmutable a partir de:

- comprobante;
- venta;
- cliente;
- líneas de venta;
- perfil de país activo;
- identidad fiscal del emisor.

El adaptador devuelve errores y advertencias. Solo sin errores se genera un XML de previsualización. Ese XML:

- no está firmado;
- no se envía;
- no se guarda como XML fiscal definitivo;
- no cambia `fiscal_status` a aceptado/rechazado;
- se usa exclusivamente para validar que el dominio interno puede proyectarse al formato del país.

## Perú

El primer adaptador es `SPF_Fiscal_Adapter_Peru`.

En este corte soporta preflight de factura y boleta, UBL 2.1 / CustomizationID 2.0, series F/B, identificación del emisor, identificación del cliente, totales, líneas y afectación tributaria base.

La documentación oficial consultada para este corte incluye:

- SUNAT, **Guías y Manuales**: reglas de validación CPE actualizadas al 26/08/2026.
- SUNAT, **Guía de elaboración de documentos electrónicos XML - UBL 2.1** para factura y boleta.
- SUNAT, **SEE del Contribuyente**: CDR aceptada, aceptada con observación y rechazada.

La guía UBL es una ayuda técnica; antes de habilitar transporte real el XML deberá validarse contra los XSD/XSL y reglas de validación vigentes, y deberá incorporarse firma digital y el flujo de CDR correspondiente.

## Próximo corte

El siguiente bloque debe introducir una interfaz de transporte separada del adaptador de estructura y empezar por un entorno de pruebas, sin permitir producción hasta disponer de credenciales/certificado y evidencia de aceptación controlada.
