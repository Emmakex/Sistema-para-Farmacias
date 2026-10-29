# Migración desde FarmaPOS Perú v0.3.0

Base funcional validada: `farmapos-peru-v0.3.0`.

## Estrategia

1. Crear contrato multi-país y perfil Perú.
2. Migrar moneda/formato de importes a `SPF_Config::money()`.
3. Migrar impuesto/IGV a configuración de país.
4. Migrar documentos de cliente (DNI/RUC) al perfil.
5. Migrar métodos de pago (Yape/Plin/etc.) al perfil.
6. Aislar SUNAT en un adaptador de facturación de Perú.
7. Migrar el resto del core funcional sin cambiar comportamiento.
8. Añadir asistente de instalación y selección de país.
9. Añadir nuevos países únicamente cuando sus reglas estén verificadas.

## Regla de producto

No se expondrá un país como "soportado" hasta que su fiscalidad, documentos y facturación electrónica estén implementados y probados. La arquitectura puede aceptar múltiples países desde el inicio sin fingir soporte funcional donde todavía no existe.
