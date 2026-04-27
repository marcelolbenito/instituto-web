# Mapa Fox → instituto-web (MySQL)

Resumen de **desde qué archivos Fox** sale cada cosa y **en qué tabla** cae.
Este documento funciona como hub de migración vigente.

## Origen → staging → tabla final

| Origen Fox (conceptual) | Staging | Tabla destino |
|-------------------------|---------|----------------|
| CLIENTES.DBF | `staging_fox_clientes` | `barrios` (inferido por código/nombre), `alumnos` |
| ABONCLIE.DBF | `staging_fox_abonclie` | `articulos` (por código artículo), `alumno_articulo` |
| PAGOS.DBF (líneas cuota/pagos) | `staging_fox_pagos` | `cuota_mensual`, `pago_registrado`, `pago_aplica_cuota` |

## Maestros que cargás a mano o por otro camino

| Necesidad | Tabla | Nota |
|-----------|--------|------|
| Rubros de artículo | `rubros` | En Fox suele existir `RUBRO.DBF`; el ETL actual infiere artículos desde ABONCLIE, no carga rubros completos. |
| Parámetros de cobranza | `parametros_cobranza` | PORCEN en Fox; el seed pone valores por defecto. |
| Ficha extendida cliente | columnas en `alumnos` | `04_schema_modo_operativo.sql` (condición IVA, CUIT, etc.); el ETL legacy solo llena parte de los campos. |

## Orden práctico de migración

1. Cargar **staging** (CSV/DBF exportado) en `staging_fox_*`.
2. Migrar maestros y vínculos:
   - guía: `docs/tecnicos/migracion_articulos_fox_a_instituto.md`
   - scripts base: `sql/init/03_staging_fox.sql`, `sql/migracion/04b_etl_maestros_cobranzas.sql`
3. Migrar pagos históricos y cuenta corriente:
   - guía: `docs/tecnicos/migracion_pagos_historico_y_cuenta_corriente.md`
   - scripts: `sql/migracion/07_etl_pagos_historico.sql`, `sql/migracion/08_view_cuenta_corriente.sql`
4. Completar datos en web (rubros, precios de artículos, campos nuevos de alumnos).
5. Generar cuotas nuevas en la app (`generar_cuotas.php`) para períodos no presentes en histórico.

## Duplicados y no pisar

- `cuota_mensual`: clave `(alumno_id, anio, mes)`. La pantalla **Generar cuotas** no inserta si ya existe fila para ese período.
- El ETL histórico de pagos usa `ON DUPLICATE KEY UPDATE`; conviene revisar en entorno de prueba antes de producción.

## Documentación archivada

La documentación y scripts históricos de transición se movieron a:

- `sql/migracion/archive/`
