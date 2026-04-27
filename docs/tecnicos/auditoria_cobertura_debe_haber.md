# Auditoría de cobertura Debe/Haber

Script: `sql/migracion/11_auditoria_cobertura_debe_haber.sql`

Objetivo: identificar inconsistencias de migración histórica donde:

- hay pagos sin cuota/deuda equivalente (haber sin debe),
- hay cuotas sin pagos (debe sin haber),
- existen desbalances extremos por alumno.

## Bloques del script

1. **Resumen por año (sistema completo)**  
   Permite ver años con fuerte desbalance global.

2. **Alumnos/año con haber y sin debe**  
   Principal foco para casos de saldo negativo “raro”.

3. **Alumnos/año con debe y sin haber**  
   Detecta morosidad histórica o faltante de pagos.

4. **Top desbalance absoluto por alumno**  
   Priorización de revisión manual.

## Qué hacer con los resultados

- Si predominan casos de “haber sin debe” en años viejos:
  - aplicar política de **fecha de corte operativa** y/o
  - registrar “saldo heredado” para esos alumnos.

- Si son pocos casos puntuales:
  - revisar y ajustar manualmente por alumno.

## Recomendación de uso

Ejecutar esta auditoría antes de:

- recalcular saldos masivos,
- definir fecha de corte definitiva,
- ocultar/mostrar casos heredados en la operación diaria.
