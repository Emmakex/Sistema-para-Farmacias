# Arquitectura fiscal

## Principio

Sistema para Farmacias separa seis responsabilidades:

1. **Core fiscal**: obtiene el contexto canónico de venta/comprobante y gobierna estados internos.
2. **Adaptador de país**: valida reglas territoriales y construye el formato fiscal correspondiente.
3. **Firma**: aplica y verifica localmente XMLDSig usando credenciales externas al repositorio y a la base de datos.
4. **Validación local**: ejecuta XSD/XSL desde recursos oficiales locales externos al plugin.
5. **Transporte**: empaqueta y ejecuta el mecanismo de entrega definido por cada sistema fiscal.
6. **Resultado fiscal**: reconcilia únicamente respuestas reales de autoridad/PSE/OSE.

## Estados independientes

`internal_status` describe nuestro flujo local (`draft`, `ready`, `prepared`, etc.).

`signature_status` describe únicamente la firma local (`not_signed`, `verified`, `signature_error`).

`validation_status` describe únicamente la validación local (`not_validated`, `valid_local`, `partial`, `resources_missing`, `validation_error`).

`transport_status` describe el estado técnico del paquete (`not_prepared`, `dry_run_validated`, `blocked_unsigned`, `submitted`, `response_received`, `transport_error`).

`fiscal_status` describe únicamente un resultado o tránsito fiscal real (`not_sent`, `submitted`, `accepted`, `accepted_with_observation`, `rejected`, `transport_error`).

La columna histórica `sunat_status` se conserva temporalmente por compatibilidad con FarmaPOS, pero no es autoridad semántica del nuevo diseño.

## Preflight

`SPF_Fiscal_Manager` construye un contexto a partir de comprobante, venta, cliente, líneas, perfil del país e identidad fiscal del emisor.

El adaptador devuelve errores y advertencias. Solo sin errores se genera un XML de previsualización. La construcción pura (`build_preview`) no modifica estado; la acción explícita de preflight (`prepare_preview`) sí registra evidencia e invalida firma/validación/transportes anteriores.

## Firma local

`SPF_Fiscal_Signer_Interface` desacopla el motor de firma del país y del transporte. La primera implementación es `SPF_Fiscal_XMLDSig_Signer`.

La firma:

- inserta `ext:UBLExtensions` al inicio del documento;
- inserta `cac:Signature` antes de `cac:AccountingSupplierParty`;
- firma el documento completo mediante XMLDSig enveloped;
- incrusta el certificado X.509 público;
- verifica inmediatamente digest y firma con la clave pública del certificado;
- rechaza certificados todavía no válidos o vencidos;
- genera hashes SHA-256 como evidencia local;
- no persiste el XML firmado completo en la base de datos.

El proveedor `SPF_Fiscal_Credentials_Environment` carga un PKCS#12 desde `SPF_FISCAL_P12_PATH` y `SPF_FISCAL_P12_PASSWORD`, definidos en entorno seguro o `wp-config.php`. La clave privada y la contraseña nunca se guardan en opciones, transients, logs ni `response_body`.

`build_signed_payload()` es side-effect free: validar o ejecutar dry-run puede reconstruir la firma sin alterar estados persistidos. `sign_document()` es la operación explícita que registra evidencia de firma y reinicia cualquier validación/transportes posteriores.

## Validación XSD/XSL local

`SPF_Fiscal_Validator_Interface` desacopla la validación normativa del país y del transporte. La primera implementación es `SPF_Fiscal_XSD_XSL_Validator`.

Los recursos no se incluyen en el plugin. Se configuran mediante:

- `SPF_FISCAL_XSD_PATH`;
- `SPF_FISCAL_XSL_PATH`.

Las rutas pueden definirse como constantes o variables de entorno y pueden adaptarse mediante filtros WordPress. Solo se aceptan archivos locales legibles; rutas HTTP/HTTPS u otros esquemas remotos son rechazados.

La validación XML usa `LIBXML_NONET`. La transformación XSLT bloquea escritura de archivos/directorios y lectura/escritura de red mediante las preferencias de seguridad disponibles en PHP. Esto permite usar un paquete oficial extraído localmente, incluyendo sus `xsl:include` relativos, sin permitir que las reglas contacten servicios externos.

El estado `valid_local` solo se alcanza cuando tanto XSD como XSL están configurados y ambos pasan. Si solo una de las dos capas está disponible y pasa, el resultado es `partial`. Ninguno de esos estados implica aceptación por la autoridad fiscal.

CI crea XSD/XSL efímeros y comprueba cuatro casos: documento válido, rechazo XSD, rechazo XSL y rechazo de recursos remotos. Otro guard impide versionar archivos `.xsd`, `.xsl` o `.xslt` en el repositorio.

## Política criptográfica

El algoritmo forma parte del perfil territorial, no del core. La implementación soporta:

- `rsa_sha256` + `sha256` como perfil moderno;
- `rsa_sha1_legacy` + `sha1_legacy` únicamente como compatibilidad explícita y nunca como fallback automático.

El perfil inicial de Perú usa RSA-SHA256/SHA-256 para validación local. Su aceptación remota queda marcada como `pending` hasta ejecutar validación beta/controlada contra las reglas vigentes. La guía UBL 2.1 publicada por SUNAT exige XMLDSig, certificado X.509 y firma del documento completo, pero los URI SHA-1 que aparecen en la guía corresponden a un ejemplo histórico y no se tratan como mandato criptográfico actual.

## Contrato de transporte

`SPF_Fiscal_Transport_Interface` separa el transporte de la construcción, firma y validación del documento. El primer transporte es `SPF_Fiscal_Transport_Dry_Run`.

El dry-run puede inspeccionar el paquete, estrategia, nombre, hash y estado de firma, pero su método `transmit()` devuelve siempre un error deliberado. No contiene URL, credencial ni cliente HTTP/SOAP. `fiscal_status` continúa obligatoriamente en `not_sent`.

La generación de paquete también es side-effect free: reconstruye preview/firma en memoria y conserva cualquier `validation_status` ya persistido.

## Perú

El adaptador `SPF_Fiscal_Adapter_Peru` soporta en esta etapa preflight de factura y boleta, UBL 2.1 / CustomizationID 2.0, series F/B, identificación de emisor/cliente, totales, líneas y afectación tributaria base.

El perfil Perú declara:

- `factura` → transporte `single`;
- `boleta` → transporte `daily_summary`;
- firmador `xmldsig`;
- credenciales `environment_p12`;
- firma local `rsa_sha256`;
- digest local `sha256`;
- validador `xsd_xsl_local`;
- recursos normativos `external_official_pack`;
- parámetro XSL de nombre de archivo `nombreArchivoEnviado`.

La documentación oficial consultada incluye:

- SUNAT, **Guías y Manuales**: reglas de validación CPE actualizadas al 26/08/2026.
- SUNAT, **Archivo XSL 2.1** publicado en la sección oficial de validadores.
- SUNAT, **Archivo XSD 2.1** publicado en la sección oficial de validadores.
- SUNAT, **Guía de elaboración de documentos electrónicos XML - UBL 2.1**.
- SUNAT, **SEE del Contribuyente**, **Servicios Web disponibles** y servicio beta de pruebas.
- SUNAT, **Certificado Digital**.

Los XSD/XSL oficiales evolucionan fuera del ciclo de releases del plugin; por eso se mantienen como recursos externos verificables y reemplazables sin publicar una nueva versión del producto.

## Bloqueos actuales

La versión 0.10.0 no incluye:

- XSD/XSL oficiales dentro del repositorio;
- certificado o llave privada dentro del repositorio;
- certificado o contraseña dentro de la base de datos;
- ZIP fiscal definitivo;
- SOAP/HTTP;
- credenciales SOL;
- URL beta o producción en PHP;
- `SendBill`, `SendSummary` o `GetStatus`;
- CDR real;
- validación remota;
- afirmación de compatibilidad remota de RSA-SHA256 hasta probarla contra el servicio/reglas vigentes.

CI falla si se introduce código de red, endpoints oficiales, credenciales o paquetes XSD/XSL dentro de este corte.

## Próximo corte

El siguiente bloque será una **matriz de conformidad beta** y un transporte beta controlado, todavía fail-closed para producción. Antes de cualquier transmisión se deberá exigir: firma verificada, validación local completa, credenciales de pruebas externas y evidencia explícita de que el documento pertenece al entorno de pruebas. Producción seguirá bloqueada hasta obtener y reconciliar una respuesta fiscal controlada.
