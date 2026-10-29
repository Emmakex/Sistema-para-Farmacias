# Compatibilidad con FarmaPOS Perú

Sistema para Farmacias nace a partir del prototipo validado FarmaPOS Perú, pero el producto nuevo no depende de Perú en su núcleo.

## Compatibilidad conservada deliberadamente

Durante la fase 0.x se mantienen:

- las tablas `wp_farmapos_products`, `wp_farmapos_lots` y `wp_farmapos_stock_movements`;
- las capacidades WordPress `farmapos_*`;
- los roles históricos `farmapos_*`;
- aliases AJAX de las acciones históricas de productos e inventario.

Esto permite desactivar FarmaPOS Perú e instalar Sistema para Farmacias sin copiar productos, lotes, stock ni permisos de usuario.

## Autoridad nueva

La moneda, el impuesto, las clases fiscales y la configuración territorial se obtienen exclusivamente mediante `SPF_Config` y el perfil registrado en `SPF_Country_Registry`.

Perú es el primer perfil. Otros países deberán declarar sus propias reglas antes de aparecer como seleccionables.

## Regla para nuevos módulos

No introducir literales territoriales como moneda, nombre/tasa de impuesto, documentos personales, medios de pago o autoridad fiscal dentro del core. Esos valores pertenecen al country pack.
