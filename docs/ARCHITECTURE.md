# Arquitectura multi-país

## Principio

`Sistema para Farmacias` separa el **core funcional** de las **reglas locales por país**.

El core no debe conocer directamente `S/`, `PEN`, `IGV`, `DNI`, `RUC`, `Yape`, `Plin`, `SUNAT` ni ninguna otra regla específica de Perú. Esos valores se consumen a través de `SPF_Config`, cuya fuente es `SPF_Country_Registry`.

## Capas

```text
Sistema para Farmacias
├── Core
│   ├── productos
│   ├── lotes / FEFO
│   ├── inventario / Kardex
│   ├── POS
│   ├── clientes
│   ├── ventas
│   ├── roles
│   └── dashboard
└── Countries
    ├── Peru (primero)
    ├── España (futuro)
    ├── Colombia (futuro)
    ├── México (futuro)
    └── ...
```

## Contrato de país

Cada perfil puede declarar:

- nombre y locale;
- moneda y formato;
- impuesto principal;
- documentos personales y empresariales;
- métodos de pago;
- documentos de venta;
- autoridad/adaptador de facturación electrónica;
- defaults locales como series.

Los country packs futuros pueden registrarse sin editar el core mediante `SPF_Country_Registry::register()` o el filtro `spf_countries`.

## Compatibilidad con FarmaPOS Perú

La migración desde el prototipo validado será incremental. Los identificadores internos `farmapos_*` se conservarán temporalmente donde protejan datos y compatibilidad. La marca visible y la nueva API interna usan `Sistema para Farmacias` / `SPF_*`.
