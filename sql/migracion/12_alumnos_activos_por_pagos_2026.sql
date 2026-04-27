-- Marca alumnos activos según pagos del año 2026.
-- Regla pedida:
--   activo = 1  -> si tiene al menos 1 pago registrado en 2026
--   activo = 0  -> si no tiene pagos en 2026
--
-- Recomendación: ejecutar primero los SELECT de control y luego el UPDATE.

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1) Control previo: cuántos quedarían activos/inactivos
-- ------------------------------------------------------------
SELECT
  SUM(CASE WHEN x.tiene_pago_2026 = 1 THEN 1 ELSE 0 END) AS quedarian_activos,
  SUM(CASE WHEN x.tiene_pago_2026 = 0 THEN 1 ELSE 0 END) AS quedarian_inactivos,
  COUNT(*) AS total_alumnos
FROM (
  SELECT
    a.id,
    CASE
      WHEN EXISTS (
        SELECT 1
        FROM pago_registrado pr
        WHERE pr.alumno_id = a.id
          AND pr.fecha_pago >= '2026-01-01'
          AND pr.fecha_pago <  '2027-01-01'
      ) THEN 1
      ELSE 0
    END AS tiene_pago_2026
  FROM alumnos a
) x;

-- ------------------------------------------------------------
-- 2) Update de estado activo
-- ------------------------------------------------------------
START TRANSACTION;

UPDATE alumnos a
SET a.activo = CASE
  WHEN EXISTS (
    SELECT 1
    FROM pago_registrado pr
    WHERE pr.alumno_id = a.id
      AND pr.fecha_pago >= '2026-01-01'
      AND pr.fecha_pago <  '2027-01-01'
  ) THEN 1
  ELSE 0
END;

COMMIT;

-- ------------------------------------------------------------
-- 3) Control posterior: distribución real de activos
-- ------------------------------------------------------------
SELECT
  a.activo,
  COUNT(*) AS cantidad
FROM alumnos a
GROUP BY a.activo
ORDER BY a.activo DESC;

