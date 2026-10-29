# Diagnóstico de preparación beta y export de evidencia

## Objetivo

La versión 0.17.0 añade una fase explícita entre "código beta disponible" y "ejecutar una transmisión beta real".

El objetivo es responder, sin hacer red, a estas preguntas:

- ¿el lote sigue íntegro?
- ¿la firma puede reproducirse con el certificado actual?
- ¿el XML firmado sigue coincidiendo con la evidencia validada?
- ¿los XSD/XSL específicos siguen disponibles?
- ¿el XML firmado vuelve a superar XSD y XSL ahora mismo?
- ¿el runtime PHP tiene DOM, OpenSSL, SOAP, XSL y ZIP?
- ¿el transporte beta acepta el paquete localmente?
- ¿el doble opt-in está habilitado?
- ¿existe ya un ticket asociado o recuperable que prohíba reenviar?

## Diagnóstico sin red

`SPF_Fiscal_Batch_Beta_Diagnostics::inspect()` no llama a `submit_batch()` ni `poll_batch()`.

El diagnóstico:

1. carga el lote persistido;
2. localiza el transporte asíncrono declarado por el perfil territorial;
3. verifica capacidades PHP necesarias;
4. reconstruye el lote desde snapshots persistidos;
5. comprueba los hashes de firma y certificado;
6. comprueba que `validation_xml_sha256` coincide con `signature_xml_sha256`;
7. verifica que los recursos XSD/XSL externos son legibles;
8. reproduce el XML firmado en memoria;
9. vuelve a ejecutar XSD y XSL en memoria;
10. construye el paquete de envío sin efectos laterales;
11. ejecuta únicamente `inspect_batch()`;
12. comprueba el opt-in beta;
13. devuelve siempre `network_performed=false`.

`ready_for_submit=true` solo puede alcanzarse si todas las comprobaciones obligatorias pasan y no existe ticket remoto ni ticket recuperable.

## Estados remotos

El diagnóstico distingue tres intenciones:

- `ready_for_submit`: el lote puede pasar a una prueba beta controlada;
- `ready_for_poll`: ya existe ticket y el lote puede consultarse;
- `ready_for_recovery`: existe un `ticket_received` compatible en el ledger y debe recuperarse antes de cualquier otra operación.

Un lote con ticket asociado o recuperable nunca se considera listo para un nuevo envío.

## Panel Fiscal

En la pantalla **Fiscal** aparece un panel independiente llamado:

**Preparación beta controlada · sin red**

Para los últimos lotes muestra:

- identificador y fecha;
- estado beta;
- ticket asociado, si existe;
- los últimos eventos del ledger;
- acción **Diagnóstico beta**;
- acción **Exportar evidencia JSON**.

El resultado del diagnóstico lista cada comprobación como éxito/fallo y muestra explícitamente:

`network_performed=false`

## Export de evidencia JSON

El export genera un documento JSON descargable con esquema:

`spf-fiscal-beta-evidence-v1`

Incluye únicamente:

- versión del plugin;
- timestamp de generación;
- identidad del lote;
- hashes de snapshot/XML/firma/certificado/validación;
- estados local, beta y fiscal;
- ticket;
- resultado del diagnóstico;
- timeline sanitizado del ledger remoto;
- SHA-256 del propio payload exportado.

No incluye:

- XML completos;
- ZIP completos;
- `snapshot_body`;
- `response_body`;
- `metadata_body` crudo;
- claves privadas;
- certificados PEM;
- contraseña PKCS#12;
- rutas privadas del servidor.

## Flujo recomendado para una prueba beta real

1. Crear/persistir el Resumen Diario con datos de prueba.
2. Firmar el lote.
3. Validar XSD/XSL.
4. Ejecutar **Diagnóstico beta**.
5. No transmitir si cualquier comprobación obligatoria falla.
6. Confirmar que el resultado indica `network_performed=false` y `ready_for_submit=true`.
7. Ejecutar una única acción **Enviar lote beta**.
8. Confirmar que se obtiene ticket y que el ledger contiene `submit_intent → ticket_received`.
9. Consultar el ticket de forma controlada hasta obtener resultado terminal.
10. Verificar `poll_intent → poll_result`.
11. Exportar **Evidencia JSON**.
12. Conservar el `export_sha256` junto a la evidencia de la prueba.

## Seguridad

El diagnóstico y el export no habilitan producción.

La ruta productiva sigue fuera del código PHP y `fiscal_status` continúa sin ser modificado por resultados beta.

El servicio beta debe utilizarse únicamente para pruebas controladas; no para documentos reales, carga masiva o estrés.

## CI

El workflow `Fiscal Beta Readiness` demuestra:

- sintaxis de la nueva capa;
- diagnóstico positivo completo con recursos locales simulados;
- `network_performed=false`;
- cero llamadas a `submit_batch()` / `poll_batch()`;
- presencia de revalidación local actual;
- export con esquema y SHA-256;
- exclusión de metadata/payloads sensibles;
- controles disponibles en la pantalla Fiscal;
- endpoint productivo ausente;
- versión 0.17.0 coherente.

## Límite del corte

0.17.0 no ejecuta automáticamente una prueba remota. La transmisión real debe hacerse desde una instalación WordPress preparada con certificado, XSD/XSL, extensiones PHP y acceso de red, mediante la acción beta explícita ya gobernada.