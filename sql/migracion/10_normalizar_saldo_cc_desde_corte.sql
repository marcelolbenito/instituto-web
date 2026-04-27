-- Normalización operativa de saldo_cc por fecha de corte.
-- Objetivo: evitar saldos distorsionados por histórico legacy incompleto.
--
-- NO borra movimientos históricos.
-- Solo recalcula alumnos.saldo_cc usando movimientos DESDE @fecha_corte.
--
-- Uso recomendado:
-- 1) Ajustar @fecha_corte (ej: '2023-01-01')
-- 2) Ejecutar SELECT de control
-- 3) Ejecutar UPDATE final

SET @fecha_corte = '2023-01-01';

-- ------------------------------------------------------------
-- 1) Control previo: saldo histórico completo vs saldo desde corte
-- ------------------------------------------------------------
SELECT
  a.id,
  a.codigo_legacy,
  a.nombre_completo,
  a.saldo_cc AS saldo_actual_guardado,
  ROUND(COALESCE(d_full.debe_full, 0) - COALESCE(h_full.haber_full, 0), 2) AS saldo_historico_completo,
  ROUND(COALESCE(d_cut.debe_cut, 0) - COALESCE(h_cut.haber_cut, 0), 2) AS saldo_desde_corte
FROM alumnos a
LEFT JOIN (
  SELECT
    cm.alumno_id,
    SUM(
      CASE
        WHEN COALESCE(cm.importe_original, 0) > 0 THEN cm.importe_original
        ELSE COALESCE(cm.saldo, 0) + COALESCE(pa.aplicado, 0)
      END
    ) AS debe_full
  FROM cuota_mensual cm
  LEFT JOIN (
    SELECT cuota_id, SUM(importe_aplicado) AS aplicado
    FROM pago_aplica_cuota
    GROUP BY cuota_id
  ) pa ON pa.cuota_id = cm.id
  GROUP BY cm.alumno_id
) d_full ON d_full.alumno_id = a.id
LEFT JOIN (
  SELECT alumno_id, SUM(importe) AS haber_full
  FROM pago_registrado
  GROUP BY alumno_id
) h_full ON h_full.alumno_id = a.id
LEFT JOIN (
  SELECT
    cm.alumno_id,
    SUM(
      CASE
        WHEN COALESCE(cm.importe_original, 0) > 0 THEN cm.importe_original
        ELSE COALESCE(cm.saldo, 0) + COALESCE(pa.aplicado, 0)
      END
    ) AS debe_cut
  FROM cuota_mensual cm
  LEFT JOIN (
    SELECT cuota_id, SUM(importe_aplicado) AS aplicado
    FROM pago_aplica_cuota
    GROUP BY cuota_id
  ) pa ON pa.cuota_id = cm.id
  WHERE COALESCE(
    cm.fecha_vencimiento,
    STR_TO_DATE(CONCAT(cm.anio, '-', LPAD(cm.mes, 2, '0'), '-01'), '%Y-%m-%d')
  ) >= @fecha_corte
  GROUP BY cm.alumno_id
) d_cut ON d_cut.alumno_id = a.id
LEFT JOIN (
  SELECT alumno_id, SUM(importe) AS haber_cut
  FROM pago_registrado
  WHERE fecha_pago >= @fecha_corte
  GROUP BY alumno_id
) h_cut ON h_cut.alumno_id = a.id
ORDER BY ABS(saldo_desde_corte) DESC, a.nombre_completo
LIMIT 200;

-- ------------------------------------------------------------
-- 2) Recalcular saldo_cc usando SOLO movimientos desde corte
-- ------------------------------------------------------------
UPDATE alumnos a
LEFT JOIN (
  SELECT
    cm.alumno_id,
    SUM(
      CASE
        WHEN COALESCE(cm.importe_original, 0) > 0 THEN cm.importe_original
        ELSE COALESCE(cm.saldo, 0) + COALESCE(pa.aplicado, 0)
      END
    ) AS debe_cut
  FROM cuota_mensual cm
  LEFT JOIN (
    SELECT cuota_id, SUM(importe_aplicado) AS aplicado
    FROM pago_aplica_cuota
    GROUP BY cuota_id
  ) pa ON pa.cuota_id = cm.id
  WHERE COALESCE(
    cm.fecha_vencimiento,
    STR_TO_DATE(CONCAT(cm.anio, '-', LPAD(cm.mes, 2, '0'), '-01'), '%Y-%m-%d')
  ) >= @fecha_corte
  GROUP BY cm.alumno_id
) d ON d.alumno_id = a.id
LEFT JOIN (
  SELECT alumno_id, SUM(importe) AS haber_cut
  FROM pago_registrado
  WHERE fecha_pago >= @fecha_corte
  GROUP BY alumno_id
) h ON h.alumno_id = a.id
SET a.saldo_cc = ROUND(COALESCE(d.debe_cut, 0) - COALESCE(h.haber_cut, 0), 2);

-- ------------------------------------------------------------
-- 3) Verificación final rápida
-- ------------------------------------------------------------
SELECT
  COUNT(*) AS alumnos_total,
  SUM(CASE WHEN saldo_cc > 0 THEN 1 ELSE 0 END) AS saldo_positivo,
  SUM(CASE WHEN saldo_cc < 0 THEN 1 ELSE 0 END) AS saldo_negativo,
  SUM(CASE WHEN saldo_cc = 0 THEN 1 ELSE 0 END) AS saldo_cero
FROM alumnos;
