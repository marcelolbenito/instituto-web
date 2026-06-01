# Factura electrónica desde recibo (fase 1)

Pantalla: `public/factura_electronica.php`  
Lógica: `src/FacturaElectronica.php`, `src/GesisArcaClient.php`  
API: `DEV_INTEGRATION.md` (Gesis → AFIP).

## Qué hace

1. Busca cobros en `pago_registrado` por nº de recibo, id de alumno o texto (nombre/DNI).
   - **No lista** recibos con `medio = excel` (importación Excel; no son cobros reales en caja).
2. Muestra si el recibo **ya tiene FE** (`comprobante_id` + `comprobante_electronico.estado = autorizado`).
3. Vista previa del body AFIP y emisión con `POST /api/v1/arca/crear-proximo-comprobante`.
4. Guarda `comprobante`, `comprobante_detalle`, `comprobante_electronico` y vincula `pago_registrado.comprobante_id`.
5. **Impresión** con formato ARCA/AFIP y código QR: `public/imprimir_factura_electronica.php?pago_id=…` (lógica en `src/FeFacturaHtml.php`). El QR se arma según RG 4291 (JSON → Base64 → `https://www.afip.gob.ar/fe/qr/?p=…`).

## Migración

Ejecutar en prod: `sql/migracion/30_pago_factura_electronica_compat.sql` (después de `04_schema_facturacion`).

## Punto de venta: Fox vs web

| Origen | Campo | Uso |
|--------|--------|-----|
| Fox SYSABON | `NCOMPRO.FACSUCU2` | Punto de venta AFIP para factura electrónica (Factura C / `CIVA>2`) |
| Fox SYSABON | `NCOMPRO.FACTURA2` | Último número electrónico usado en ese PV |
| Fox SYSABON | `NUMSUCU` (default 1 en `MENU.PRG`) | Sucursal operativa en **cobros/recibos** (`PAGOS.SUCURSAL`), no necesariamente el PV AFIP |
| Web | `parametros_factura_electronica.punto_venta` | `PtoVta` enviado a Gesis al emitir |

Para ver el valor histórico en Fox: abrir `NCOMPRO.dbf` y revisar `FACSUCU2` y `FACTURA2`.

## Parámetros del emisor (recomendado)

Pantalla: **`public/parametros_factura_electronica.php`** (Utilitarios → Factura electrónica (parámetros)).

Migraciones: `31_…`, `32_parametros_fe_datos_emisor_compat.sql`, `33_parametros_logo_instituto_compat.sql` (logo en impresiones).

| Campo en pantalla | Uso |
|-------------------|-----|
| URL Gesis | Servicio (`https://servicios.gesis2.com`) |
| Email / contraseña | Login integración |
| Razón social, domicilio, localidad, CP | Impresión ARCA (emisor) |
| CUIT, condición IVA emisor | Impresión + QR |
| Inicio de actividades, Ingresos Brutos | Impresión (obligatorio en papel) |
| Punto de venta | `PtoVta` en AFIP |
| Tipo comprobante | `CbteTipo` (ej. 11 = Factura C) |
| Concepto | Servicios = 2 |
| Producción | Homologación si está desmarcado |

Opcional: sección `gesis` en `config/config.php` si no se usa la pantalla (la BD tiene prioridad).

## Mapeo recibo → AFIP (MVP)

- **Importe:** `pago_registrado.importe` (total cobrado).
- **Receptor:** `alumnos.condicion_iva`, `cuit`, `documento` → `DocTipo` / `DocNro` / `CondicionIVAReceptorId`.
- **Factura C (11):** `ImpNeto = ImpTotal`, `ImpIVA = 0` (instituto monotributo típico).
- **Concepto 2 (servicios):** fechas del mes del `fecha_pago`.
- Detalle interno en BD replica líneas del recibo (`fe_lineas_desde_recibo`).

## Próximos pasos

- Filtrar por `hace_factura` en listado.
- Factura B/A según condición del instituto y del alumno.
- NC / anulaciones y reintento si falló guardado post-CAE.
- PDF nativo (hoy: imprimir desde el navegador).
