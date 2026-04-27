-- Auditoría de cobertura Debe/Haber por alumno y año
-- Objetivo: detectar casos con pagos sin cuota (haber sin debe)
-- y cuotas sin pago (debe sin haber), para validar calidad de migración histórica.

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- A) Resumen general por año (total sistema)
-- ------------------------------------------------------------
SELECT
  x.anio,
  ROUND(SUM(x.debe), 2) AS debe_total,
  ROUND(SUM(x.haber), 2) AS haber_total,
  ROUND(SUM(x.debe - x.haber), 2) AS saldo_total
FROM (
  SELECT
    cm.anio,
    SUM(
      CASE
        WHEN COALESCE(cm.importe_original, 0) > 0 THEN cm.importe_original
        ELSE COALESCE(cm.saldo, 0) + COALESCE(pa.aplicado, 0)
      END
    ) AS debe,
    0.00 AS haber
  FROM cuota_mensual cm
  LEFT JOIN (
    SELECT cuota_id, SUM(importe_aplicado) AS aplicado
    FROM pago_aplica_cuota
    GROUP BY cuota_id
  ) pa ON pa.cuota_id = cm.id
  GROUP BY cm.anio

  UNION ALL

  SELECT
    YEAR(pr.fecha_pago) AS anio,
    0.00 AS debe,
    SUM(pr.importe) AS haber
  FROM pago_registrado pr
  WHERE pr.fecha_pago IS NOT NULL
  GROUP BY YEAR(pr.fecha_pago)
) x
GROUP BY x.anio
ORDER BY x.anio;

-- ------------------------------------------------------------
-- B) Alumnos/año con HABER y sin DEBE (caso crítico típico)
-- ------------------------------------------------------------
SELECT
  a.id AS alumno_id,
  a.codigo_legacy,
  a.nombre_completo,
  y.anio,
  ROUND(y.debe, 2) AS debe_anio,
  ROUND(y.haber, 2) AS haber_anio,
  ROUND(y.debe - y.haber, 2) AS saldo_anio
FROM alumnos a
JOIN (
  SELECT
    t.alumno_id,
    t.anio,
    SUM(t.debe) AS debe,
    SUM(t.haber) AS haber
  FROM (
    SELECT
      cm.alumno_id,
      cm.anio,
      SUM(
        CASE
          WHEN COALESCE(cm.importe_original, 0) > 0 THEN cm.importe_original
          ELSE COALESCE(cm.saldo, 0) + COALESCE(pa.aplicado, 0)
        END
      ) AS debe,
      0.00 AS haber
    FROM cuota_mensual cm
    LEFT JOIN (
      SELECT cuota_id, SUM(importe_aplicado) AS aplicado
      FROM pago_aplica_cuota
      GROUP BY cuota_id
    ) pa ON pa.cuota_id = cm.id
    GROUP BY cm.alumno_id, cm.anio

    UNION ALL

    SELECT
      pr.alumno_id,
      YEAR(pr.fecha_pago) AS anio,
      0.00 AS debe,
      SUM(pr.importe) AS haber
    FROM pago_registrado pr
    WHERE pr.fecha_pago IS NOT NULL
    GROUP BY pr.alumno_id, YEAR(pr.fecha_pago)
  ) t
  GROUP BY t.alumno_id, t.anio
) y ON y.alumno_id = a.id
WHERE y.haber > 0
  AND y.debe = 0
ORDER BY y.haber DESC, a.nombre_completo, y.anio;

-- ------------------------------------------------------------
-- C) Alumnos/año con DEBE y sin HABER (riesgo de morosidad histórica)
-- ------------------------------------------------------------
SELECT
  a.id AS alumno_id,
  a.codigo_legacy,
  a.nombre_completo,
  y.anio,
  ROUND(y.debe, 2) AS debe_anio,
  ROUND(y.haber, 2) AS haber_anio,
  ROUND(y.debe - y.haber, 2) AS saldo_anio
FROM alumnos a
JOIN (
  SELECT
    t.alumno_id,
    t.anio,
    SUM(t.debe) AS debe,
    SUM(t.haber) AS haber
  FROM (
    SELECT
      cm.alumno_id,
      cm.anio,
      SUM(
        CASE
          WHEN COALESCE(cm.importe_original, 0) > 0 THEN cm.importe_original
          ELSE COALESCE(cm.saldo, 0) + COALESCE(pa.aplicado, 0)
        END
      ) AS debe,
      0.00 AS haber
    FROM cuota_mensual cm
    LEFT JOIN (
      SELECT cuota_id, SUM(importe_aplicado) AS aplicado
      FROM pago_aplica_cuota
      GROUP BY cuota_id
    ) pa ON pa.cuota_id = cm.id
    GROUP BY cm.alumno_id, cm.anio

    UNION ALL

    SELECT
      pr.alumno_id,
      YEAR(pr.fecha_pago) AS anio,
      0.00 AS debe,
      SUM(pr.importe) AS haber
    FROM pago_registrado pr
    WHERE pr.fecha_pago IS NOT NULL
    GROUP BY pr.alumno_id, YEAR(pr.fecha_pago)
  ) t
  GROUP BY t.alumno_id, t.anio
) y ON y.alumno_id = a.id
WHERE y.debe > 0
  AND y.haber = 0
ORDER BY y.debe DESC, a.nombre_completo, y.anio;

-- ------------------------------------------------------------
-- D) Top alumnos con mayor desbalance histórico absoluto
-- ------------------------------------------------------------
SELECT
  a.id AS alumno_id,
  a.codigo_legacy,
  a.nombre_completo,
  ROUND(COALESCE(d.debe_total, 0), 2) AS debe_total,
  ROUND(COALESCE(h.haber_total, 0), 2) AS haber_total,
  ROUND(COALESCE(d.debe_total, 0) - COALESCE(h.haber_total, 0), 2) AS saldo_total
FROM alumnos a
LEFT JOIN (
  SELECT
    cm.alumno_id,
    SUM(
      CASE
        WHEN COALESCE(cm.importe_original, 0) > 0 THEN cm.importe_original
        ELSE COALESCE(cm.saldo, 0) + COALESCE(pa.aplicado, 0)
      END
    ) AS debe_total
  FROM cuota_mensual cm
  LEFT JOIN (
    SELECT cuota_id, SUM(importe_aplicado) AS aplicado
    FROM pago_aplica_cuota
    GROUP BY cuota_id
  ) pa ON pa.cuota_id = cm.id
  GROUP BY cm.alumno_id
) d ON d.alumno_id = a.id
LEFT JOIN (
  SELECT alumno_id, SUM(importe) AS haber_total
  FROM pago_registrado
  GROUP BY alumno_id
) h ON h.alumno_id = a.id
ORDER BY ABS(COALESCE(d.debe_total, 0) - COALESCE(h.haber_total, 0)) DESC
LIMIT 200;
