-- ETL focalizado: staging_fox_pagos -> cuota_mensual + pago_registrado + pago_aplica_cuota
-- Uso sugerido:
--   1) importar PAGOS_migra.csv en staging_fox_pagos
--   2) ejecutar este script
--   3) revisar filas con error_msg en staging_fox_pagos

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

START TRANSACTION;

-- 1) Validaciones mínimas
UPDATE staging_fox_pagos
SET error_msg = 'Cliente legacy inexistente en alumnos'
WHERE procesado = 0
  AND error_msg IS NULL
  AND NOT EXISTS (
    SELECT 1
    FROM alumnos a
    WHERE a.codigo_legacy = COALESCE(staging_fox_pagos.codigo_cliente_legacy, staging_fox_pagos.ficha, staging_fox_pagos.ncuenta)
  );

UPDATE staging_fox_pagos
SET error_msg = 'Periodo invalido (anio/mes)'
WHERE procesado = 0
  AND error_msg IS NULL
  AND (anio IS NULL OR mes NOT BETWEEN 1 AND 12);

-- 2) Cuotas mensuales (histórico)
INSERT INTO cuota_mensual (
  alumno_id, anio, mes, importe_original, saldo, fecha_vencimiento, estado, nota
)
SELECT
  agg.alumno_id,
  agg.anio,
  agg.mes,
  ROUND(GREATEST(agg.s_cuota_max, agg.debe_total, agg.haber_total), 2) AS importe_original,
  agg.saldo_max AS saldo,
  NULL AS fecha_vencimiento,
  CASE
    WHEN COALESCE(agg.saldo_max, 0) = 0 THEN 'pagada'
    WHEN COALESCE(agg.haber_total, 0) > 0 AND COALESCE(agg.saldo_max, 0) > 0 THEN 'parcial'
    ELSE 'pendiente'
  END AS estado,
  CONCAT('Migrado desde PAGOS. ncuenta=', agg.ncuenta_ref, ' (filas=', agg.filas, ')')
FROM (
  SELECT
    al.id AS alumno_id,
    p.anio,
    p.mes,
    MAX(COALESCE(NULLIF(p.s_cuota, 0), 0.00)) AS s_cuota_max,
    SUM(COALESCE(p.debe, 0.00)) AS debe_total,
    SUM(COALESCE(p.haber, 0.00)) AS haber_total,
    MAX(COALESCE(p.saldo, 0.00)) AS saldo_max,
    MAX(COALESCE(p.ncuenta, 0)) AS ncuenta_ref,
    COUNT(*) AS filas
  FROM staging_fox_pagos p
  JOIN alumnos al
    ON al.codigo_legacy = COALESCE(p.codigo_cliente_legacy, p.ficha, p.ncuenta)
  WHERE p.procesado = 0
    AND p.error_msg IS NULL
  GROUP BY al.id, p.anio, p.mes
) agg
ON DUPLICATE KEY UPDATE
  importe_original = VALUES(importe_original),
  saldo = VALUES(saldo),
  estado = VALUES(estado),
  nota = VALUES(nota);

-- 3) Pagos registrados (histórico)
INSERT INTO pago_registrado (
  alumno_id, fecha_pago, importe, medio, referencia, nota
)
SELECT
  al.id,
  COALESCE(p.fecha, CURDATE()) AS fecha_pago,
  COALESCE(NULLIF(p.haber, 0), 0.00) AS importe,
  'legacy',
  CONCAT('PAGOS:ncuenta=', COALESCE(p.ncuenta, 0), ':', p.anio, '-', LPAD(p.mes, 2, '0')),
  'Migrado desde PAGOS'
FROM staging_fox_pagos p
JOIN alumnos al
  ON al.codigo_legacy = COALESCE(p.codigo_cliente_legacy, p.ficha, p.ncuenta)
WHERE p.procesado = 0
  AND p.error_msg IS NULL
  AND (
    COALESCE(p.haber, 0) > 0
    OR UPPER(COALESCE(p.pago, 'N')) IN ('S', 'Y', '1', 'P')
  );

-- 4) Aplicación pago -> cuota (mismo alumno + año/mes)
INSERT IGNORE INTO pago_aplica_cuota (pago_id, cuota_id, importe_aplicado)
SELECT
  pr.id AS pago_id,
  cm.id AS cuota_id,
  LEAST(pr.importe, cm.importe_original) AS importe_aplicado
FROM pago_registrado pr
JOIN alumnos al ON al.id = pr.alumno_id
JOIN cuota_mensual cm ON cm.alumno_id = pr.alumno_id
JOIN staging_fox_pagos p
  ON COALESCE(p.codigo_cliente_legacy, p.ficha, p.ncuenta) = al.codigo_legacy
 AND p.anio = cm.anio
 AND p.mes = cm.mes
WHERE pr.medio = 'legacy'
  AND pr.nota = 'Migrado desde PAGOS'
  AND pr.referencia = CONCAT('PAGOS:ncuenta=', COALESCE(p.ncuenta, 0), ':', p.anio, '-', LPAD(p.mes, 2, '0'))
  AND p.procesado = 0
  AND p.error_msg IS NULL;

-- 5) Marcar staging procesado
UPDATE staging_fox_pagos
SET procesado = 1
WHERE procesado = 0
  AND error_msg IS NULL;

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

-- Post-chequeo sugerido:
-- SELECT COUNT(*) FROM cuota_mensual;
-- SELECT COUNT(*) FROM pago_registrado;
-- SELECT COUNT(*) FROM pago_aplica_cuota;
-- SELECT * FROM staging_fox_pagos WHERE error_msg IS NOT NULL LIMIT 50;
