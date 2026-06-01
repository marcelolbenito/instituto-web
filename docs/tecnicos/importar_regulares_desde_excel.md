# Importar regulares desde Excel (reemplazo de pagos Fox)

Objetivo: dejar de usar el histórico `PAGOS` de Fox y operar con la planilla **ALUMNOS REGULARES** como fuente de alumnos activos y último mes abonado.

## Qué hace el proceso

1. **Purge Fox** (`23_purge_pagos_legacy_fox.sql`): borra ~66k cuotas y ~40k pagos con `Migrado desde PAGOS` / `medio=legacy`.
2. **Import Excel** (`tools/importar_regulares_excel.py`):
   - Carga staging `staging_excel_regulares`.
   - Empareja por **DNI** con `alumnos`.
   - Marca `activo=1`, `tipo_alumno=regular`, `curso` = carrera del Excel.
   - Opcional: desactiva alumnos que no están en la planilla.
   - Por cada alumno con artículos asignados, crea cuotas del **año operativo** (default 2026):
     - Meses **hasta "mes abonado"** (inclusive si es 2026): cuota **pagada** + pago `medio=excel`.
     - Meses **posteriores** en el año operativo: cuota **pendiente**.
   - Filas con `BECA` / `MATRICULA` en mes abonado: solo activan alumno y nota en observaciones (sin cuotas automáticas).

## Requisitos previos

- Migraciones `21` y columnas de pagos (`14`, `16`) aplicadas.
- Cada alumno del Excel debe existir en `alumnos.documento` (DNI).
- Casi todos deben tener **conceptos** en `alumno_articulo` (importe = suma de `importe_referencia`).

## Producción — checklist

### Antes

- [ ] Backup completo de la base.
- [ ] Ventana de mantenimiento acordada (la purge es irreversible sin restore).
- [ ] Archivo Excel validado con administración (misma estructura: hoja `ALUMNOS-CUOTA`, fila 3 = encabezados).
- [ ] Copiar `.xlsx` al servidor (no versionar en git si tiene datos sensibles).

### Ejecución

```bash
cd instituto-web

# 1) Backup (ejemplo Docker)
docker exec instituto-db mariadb-dump -u root -p"$MYSQL_ROOT_PASSWORD" instituto > backup_$(date +%Y%m%d).sql

# 2) Purge Fox
docker exec -i instituto-db mariadb -u instituto -pinstituto instituto < sql/migracion/23_purge_pagos_legacy_fox.sql

# 3) Staging (si no existe)
docker exec -i instituto-db mariadb -u instituto -pinstituto instituto < sql/migracion/24_staging_excel_regulares.sql

# 4) Prueba en seco
python tools/importar_regulares_excel.py --excel "ALUMNOS REGULARES (MARCELO) (1).xlsx" --dry-run

# 5) Aplicar (recomendado con flags de corte limpio)
python tools/importar_regulares_excel.py \
  --excel "ALUMNOS REGULARES (MARCELO) (1).xlsx" \
  --desactivar-no-listados \
  --limpiar-cuotas-operativo

# 6) Recalcular saldos
php tools/recalcular_saldos_cli.php
```

### Después — verificación

```sql
-- Sin restos Fox
SELECT COUNT(*) FROM cuota_mensual WHERE nota LIKE 'Migrado desde PAGOS%';
SELECT COUNT(*) FROM pago_registrado WHERE medio = 'legacy';

-- Import Excel
SELECT COUNT(*) FROM pago_registrado WHERE medio = 'excel';
SELECT estado, COUNT(*) FROM cuota_mensual WHERE anio = 2026 GROUP BY estado;

-- Errores de import
SELECT dni, apellido, nombre, error_msg FROM staging_excel_regulares WHERE error_msg IS NOT NULL;
```

En la app:

- **Alumnos**: ~247 activos (si usaste `--desactivar-no-listados`).
- **Generar cuotas**: muchas omitidas (ya creadas por import).
- **Cuenta corriente**: saldos desde `SALDO_CORTE_DESDE` sin pagos `PAGOS:ncuenta=...`.

## Variables de entorno

| Variable | Uso |
|----------|-----|
| `OPERATIVO_ANIO_DESDE` | Año de cuotas a generar (default 2026) |
| `SALDO_CORTE_DESDE` | Corte en CC / saldo_cc (ej. 2026-01-01) |
| `MYSQL_DOCKER_CONTAINER` | Nombre contenedor DB (default `instituto-db`) |

## Errores frecuentes

| Mensaje staging | Acción |
|-----------------|--------|
| DNI no encontrado | Alta o corrección de DNI en `alumnos` |
| Sin artículos / importe 0 | Asignar conceptos en **Conceptos por alumno** y reimportar fila |
| BECA / MATRICULA | Revisar manualmente; no se generan cuotas automáticas |

## Relación con Fox

- **Maestros** (alumnos, artículos, barrios) pueden seguir viniendo de migraciones Fox anteriores; este proceso solo reemplaza **pagos y cuotas históricas Fox**.
- `staging_fox_pagos` puede quedar en la base; no se usa más si no re-ejecutás `07_etl_pagos_historico.sql`.
