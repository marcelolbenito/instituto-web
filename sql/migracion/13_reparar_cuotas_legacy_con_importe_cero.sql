-- Repara cuotas legacy (PAGOS) que quedaron en 0 por filas múltiples del mismo período.
-- Caso típico: una fila con marca P/importe 0 y otra con haber > 0 pisa el importe de cuota.

SET NAMES utf8mb4;

START TRANSACTION;

UPDATE cuota_mensual cm
JOIN (
  SELECT
    al.id AS alumno_id,
    p.anio,
    p.mes,
    ROUND(
      GREATEST(
        MAX(COALESCE(NULLIF(p.s_cuota, 0), 0.00)),
        SUM(COALESCE(p.debe, 0.00)),
        SUM(COALESCE(p.haber, 0.00))
      ),
      2
    ) AS importe_reconstruido,
    MAX(COALESCE(p.saldo, 0.00)) AS saldo_reconstruido
  FROM staging_fox_pagos p
  JOIN alumnos al
    ON al.codigo_legacy = COALESCE(p.codigo_cliente_legacy, p.ficha, p.ncuenta)
  WHERE p.error_msg IS NULL
    AND p.anio IS NOT NULL
    AND p.mes BETWEEN 1 AND 12
  GROUP BY al.id, p.anio, p.mes
) agg
  ON agg.alumno_id = cm.alumno_id
 AND agg.anio = cm.anio
 AND agg.mes = cm.mes
SET
  cm.importe_original = agg.importe_reconstruido,
  cm.saldo = agg.saldo_reconstruido,
  cm.estado = CASE
    WHEN COALESCE(agg.saldo_reconstruido, 0) = 0 THEN 'pagada'
    WHEN COALESCE(agg.importe_reconstruido, 0) > COALESCE(agg.saldo_reconstruido, 0) THEN 'parcial'
    ELSE 'pendiente'
  END,
  cm.nota = CONCAT(COALESCE(cm.nota, ''), ' | ajuste 13: reconstruido por agregación staging')
WHERE cm.importe_original = 0
  AND cm.nota LIKE 'Migrado desde PAGOS%';

COMMIT;

-- Chequeos sugeridos:
-- SELECT COUNT(*) FROM cuota_mensual WHERE importe_original = 0 AND nota LIKE 'Migrado desde PAGOS%';
-- SELECT * FROM cuota_mensual WHERE alumno_id = (SELECT id FROM alumnos WHERE nombre_completo='ABALLAY LUCAS') ORDER BY anio, mes;
