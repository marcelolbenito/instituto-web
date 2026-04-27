# Normalización de saldos por fecha de corte

Cuando la migración histórica mezcla cuotas incompletas y pagos completos, pueden aparecer saldos extremos o poco confiables para operación diaria.

Este procedimiento recalcula `alumnos.saldo_cc` usando solo movimientos desde una fecha de corte (por ejemplo, `2023-01-01`), sin borrar histórico.

## Script

- `sql/migracion/10_normalizar_saldo_cc_desde_corte.sql`

## Uso sugerido

1. Elegir fecha de corte operativa y setear `@fecha_corte`.
2. Ejecutar el bloque de control del script (comparación histórico completo vs desde corte).
3. Ejecutar el `UPDATE` de normalización.
4. Verificar resultados con el resumen final.

## Importante

- Este ajuste impacta el campo visible `alumnos.saldo_cc`.
- No modifica tablas históricas (`cuota_mensual`, `pago_registrado`).
- Si luego ejecutás un recálculo global de saldos con otra lógica, este ajuste puede ser sobrescrito.
