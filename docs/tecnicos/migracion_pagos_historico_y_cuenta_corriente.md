# Migracion de PAGOS historico y cuenta corriente

Objetivo: importar historico de `PAGOS` desde Fox y dejar una base consistente para consulta de cuenta corriente por alumno.

## Flujo corto (orden recomendado)

1. Exportar `PAGOS.DBF` a `PAGOS_migra.csv` desde Fox.
2. Importar el CSV en `staging_fox_pagos` (tabla staging).
3. Ejecutar `sql/migracion/07_etl_pagos_historico.sql`.
4. Ejecutar `sql/migracion/08_view_cuenta_corriente.sql`.
5. Verificar resultados y errores.

## Campos esperados en staging_fox_pagos

- `codigo_cliente_legacy` (o `ficha`/`ncuenta` como fallback)
- `anio`, `mes`
- `s_cuota`, `saldo`
- `debe`, `haber`
- `fecha`
- `pago`

## Verificaciones post-ETL

```sql
SELECT COUNT(*) AS cuotas FROM cuota_mensual;
SELECT COUNT(*) AS pagos FROM pago_registrado;
SELECT COUNT(*) AS aplicaciones FROM pago_aplica_cuota;
SELECT COUNT(*) AS errores_staging FROM staging_fox_pagos WHERE error_msg IS NOT NULL;
```

```sql
-- Cuenta corriente de un alumno
SELECT *
FROM vw_cuenta_corriente_alumno
WHERE alumno_id = 1
ORDER BY anio, mes;
```

```sql
-- Saldos por alumno (top de deudores)
SELECT
  alumno_id,
  codigo_alumno_legacy,
  nombre_completo,
  SUM(saldo_periodo) AS saldo_total
FROM vw_cuenta_corriente_alumno
GROUP BY alumno_id, codigo_alumno_legacy, nombre_completo
ORDER BY saldo_total DESC
LIMIT 50;
```

## Nota operativa

Para una primera migracion conviene mantener esta regla simple:

- una cuota por alumno+anio+mes (`cuota_mensual`);
- pagos migrados como `medio = 'legacy'`;
- aplicacion inicial de pago a cuota del mismo periodo.

Despues se puede ajustar la logica de aplicacion si aparece algun caso legacy especial.
