# Arquitectura fiscal

## Principio

Sistema para Farmacias separa cuatro responsabilidades:

1. **Core fiscal**: obtiene el contexto canónico de venta/comprobante y gobierna estados internos.
2. **Adaptador de país**: valida reglas territoriales y construye el formato fiscal correspondiente.
3. **Transporte**: empaqueta y ejecuta el mecanismo de entrega definido por cada sistema fiscal.
4. **Resultado fiscal**: reconcilia únicamente respuestas reales de autoridad/PSE/OSE.

## Estados independientes

`internal_status` describe nuestro flujo local (`draft`, `ready`, `prepared`, etc.).

`transport_status` describe el estado técnico del paquete (`not_prepared`, `dry_run_validated`, `blocked_unsigned`, `submitted`, `response_received`, `transport_error`).

`fiscal_status` describe únicamente un resultado o tránsito fiscal real (`not_sent`, `submitted`, `accepted`, `accepted_with_observation`, `rejected`, `transport_error`).

La columna histórica `sunat_status` se conserva temporalmente por compatibilidad con FarmaPOS, pero no es autoridad semántica del nuevo diseño.

## Preflight

`SPF_Fiscal_Manager` construye un contexto a partir de comprobante, venta, cliente, líneas, perfil del país e identidad fiscal del emisor.

El adaptador devuelve errores y advertencias. Solo sin errores se genera un XML de previsualización. Ese XML no está firmado, no se envía, no se guarda como comprobante fiscal definitivo y nunca cambia por sí mismo el estado fiscal a aceptado/rechazado.

## Contrato de transporte

`SPF_Fiscal_Transport_Interface` separa el transporte de la construcción del documento. Un transporte declara:

- código;
- entorno;
- capacidades;
- inspección de paquete;
- transmisión.

El primer transporte es `SPF_Fiscal_Transport_Dry_Run`. Puede inspeccionar el paquete, estrategia, nombre y hash, pero su método `transmit()` devuelve siempre un error deliberado. No contiene URL, credencial ni cliente HTTP/SOAP.

La primera versión de `SPF_Fiscal_Transport_Manager` construye paquetes con `signed=false`, ejecuta el dry-run y deja `fiscal_status=not_sent`. Un dry-run correcto queda técnicamente como `blocked_unsigned`: demuestra que el paquete puede prepararse pero sigue siendo imposible transmitirlo.

## Perú

El adaptador `SPF_Fiscal_Adapter_Peru` soporta en esta etapa preflight de factura y boleta, UBL 2.1 / CustomizationID 2.0, series F/B, identificación de emisor/cliente, totales, líneas y afectación tributaria base.

El perfil Perú declara además la estrategia de transporte por comprobante:

- `factura` → `single`;
- `boleta` → `daily_summary`.

Esta separación responde al flujo publicado para SEE-Contribuyente: la factura puede enviarse individualmente mientras las boletas se informan mediante resumen diario.

La documentación oficial consultada incluye:

- SUNAT, **Guías y Manuales**: reglas de validación CPE actualizadas al 26/08/2026.
- SUNAT, **Guía de elaboración de documentos electrónicos XML - UBL 2.1**.
- SUNAT, **SEE del Contribuyente**: CDR aceptada, aceptada con observación y rechazada; factura y resumen diario de boletas.
- SUNAT, **Servicios Web disponibles**: beta exclusivo para pruebas y servicios de producción separados.
- SUNAT, **Servicio Beta para realizar pruebas UBL 2.1**: el beta sirve para probar estructura XML, no verifica consistencia, no admite documentos reales ni pruebas de estrés.
- SUNAT, **Certificado Digital**: los sistemas de emisión electrónica correspondientes requieren certificado digital para la firma.

## Bloqueos actuales

La versión 0.8.0 no incluye:

- certificado o llave privada;
- firma XML;
- ZIP fiscal definitivo;
- SOAP/HTTP;
- credenciales SOL;
- URL beta o producción en PHP;
- `SendBill`, `SendSummary` o `GetStatus`;
- CDR real.

CI falla si se introduce código de red o endpoints oficiales dentro de este corte.

## Próximo corte

El siguiente bloque deberá implementar **firma digital desacoplada** y validación local del XML firmado. Solo después se podrá crear un transporte beta controlado. Producción seguirá bloqueada hasta disponer de certificado/credenciales, validación XSD/XSL/reglas y evidencia de aceptación controlada.
