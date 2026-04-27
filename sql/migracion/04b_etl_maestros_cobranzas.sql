-- ETL base: staging_fox_* -> maestros y cobranzas.
-- Objetivo: dejar lista la base para luego correr 05/06 de facturacion.
--
-- Fuente staging esperada:
--   staging_fox_clientes
--   staging_fox_abonclie
--   staging_fox_pagos
--
-- Nota: como no hay staging explicito de ARTICULO/BARRIOS/PORCEN,
-- se infieren barrios desde clientes y articulos desde abonclie.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

START TRANSACTION;

-- 1) Normalizar textos en staging
UPDATE staging_fox_clientes
SET
  razon = NULLIF(TRIM(razon), ''),
  ndoc = NULLIF(TRIM(ndoc), ''),
  nrocuit = NULLIF(TRIM(nrocuit), ''),
  observ = NULLIF(TRIM(observ), ''),
  barrio = NULLIF(TRIM(barrio), '')
WHERE procesado = 0;

UPDATE staging_fox_abonclie
SET
  detartic = NULLIF(TRIM(detartic), '')
WHERE procesado = 0;

-- 2) Validaciones minimas
UPDATE staging_fox_clientes
SET error_msg = 'Falta codigo legacy de cliente'
WHERE procesado = 0
  AND error_msg IS NULL
  AND codigo IS NULL;

UPDATE staging_fox_clientes
SET error_msg = 'Falta nombre/razon del cliente'
WHERE procesado = 0
  AND error_msg IS NULL
  AND razon IS NULL;

UPDATE staging_fox_pagos
SET error_msg = 'Cliente legacy inexistente en staging_fox_clientes'
WHERE procesado = 0
  AND error_msg IS NULL
  AND NOT EXISTS (
    SELECT 1
    FROM staging_fox_clientes c
    WHERE c.codigo = COALESCE(staging_fox_pagos.codigo_cliente_legacy, staging_fox_pagos.ficha, staging_fox_pagos.ncuenta)
  );

-- 3) Barrios (inferidos de staging_fox_clientes)
INSERT INTO barrios (codigo_legacy, nombre)
SELECT DISTINCT
  c.codbarrio,
  COALESCE(c.barrio, CONCAT('Barrio ', COALESCE(c.codbarrio, 0)))
FROM staging_fox_clientes c
WHERE c.procesado = 0
  AND c.error_msg IS NULL
  AND (c.codbarrio IS NOT NULL OR c.barrio IS NOT NULL)
ON DUPLICATE KEY UPDATE
  nombre = VALUES(nombre);

-- 4) Alumnos (desde CLIENTES)
INSERT INTO alumnos (
  codigo_legacy, nombre_completo, documento, curso, barrio_id, activo
)
SELECT
  c.codigo,
  c.razon,
  CAST(COALESCE(NULLIF(c.ndoc, 0), NULLIF(c.nrocuit, 0)) AS CHAR(20)),
  c.observ,
  b.id,
  CASE WHEN COALESCE(c.estado, 1) = 1 THEN 1 ELSE 0 END
FROM staging_fox_clientes c
LEFT JOIN barrios b
  ON b.codigo_legacy <=> c.codbarrio
WHERE c.procesado = 0
  AND c.error_msg IS NULL
ON DUPLICATE KEY UPDATE
  nombre_completo = VALUES(nombre_completo),
  documento = VALUES(documento),
  curso = VALUES(curso),
  barrio_id = VALUES(barrio_id),
  activo = VALUES(activo),
  actualizado_en = CURRENT_TIMESTAMP;

-- 5) Articulos (inferidos de ABONCLIE)
INSERT INTO articulos (codigo_legacy, detalle, importe_referencia, activo)
SELECT DISTINCT
  a.cod_artic,
  COALESCE(a.detartic, CONCAT('Articulo legacy ', COALESCE(a.cod_artic, 0))),
  0.00,
  1
FROM staging_fox_abonclie a
WHERE a.procesado = 0
  AND a.error_msg IS NULL
  AND a.cod_artic IS NOT NULL
ON DUPLICATE KEY UPDATE
  detalle = VALUES(detalle),
  activo = 1;

-- 6) Relacion alumno-articulo
INSERT IGNORE INTO alumno_articulo (alumno_id, articulo_id)
SELECT
  al.id,
  ar.id
FROM staging_fox_abonclie a
JOIN alumnos al ON al.codigo_legacy = a.codclie
JOIN articulos ar ON ar.codigo_legacy = a.cod_artic
WHERE a.procesado = 0
  AND a.error_msg IS NULL;

UPDATE staging_fox_abonclie a
LEFT JOIN alumnos al ON al.codigo_legacy = a.codclie
LEFT JOIN articulos ar ON ar.codigo_legacy = a.cod_artic
SET a.error_msg = 'No se pudo vincular alumno/articulo'
WHERE a.procesado = 0
  AND a.error_msg IS NULL
  AND (al.id IS NULL OR ar.id IS NULL);

-- 7) Parametros de cobranza (fallback)
-- Si ya cargaste PORCEN por otro camino, esto no pisa valores manuales.
INSERT INTO parametros_cobranza (
  id, dia_generacion_cuota, dia_tope_pronto_pago, recargo_coeficiente, bonificacion_pronto_pago
)
VALUES (1, 1, 5, 0.00000, 0.00)
ON DUPLICATE KEY UPDATE
  id = id;

-- 8) Cuotas mensuales desde PAGOS
-- Regla simple inicial:
--   importe_original = s_cuota (si no, debe)
--   saldo = saldo
--   estado segun saldo/debe/haber
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
  JOIN alumnos al ON al.codigo_legacy = COALESCE(p.codigo_cliente_legacy, p.ficha, p.ncuenta)
  WHERE p.procesado = 0
    AND p.error_msg IS NULL
    AND p.anio IS NOT NULL
    AND p.mes BETWEEN 1 AND 12
  GROUP BY al.id, p.anio, p.mes
) agg
ON DUPLICATE KEY UPDATE
  importe_original = VALUES(importe_original),
  saldo = VALUES(saldo),
  estado = VALUES(estado),
  nota = VALUES(nota);

UPDATE staging_fox_pagos p
SET p.error_msg = 'Periodo invalido (anio/mes)'
WHERE p.procesado = 0
  AND p.error_msg IS NULL
  AND (p.anio IS NULL OR p.mes NOT BETWEEN 1 AND 12);

-- 9) Pagos registrados desde PAGOS (solo filas con haber > 0 o marca de pago)
INSERT INTO pago_registrado (
  alumno_id, fecha_pago, importe, medio, referencia, nota
)
SELECT
  al.id,
  COALESCE(p.fecha, CURDATE()) AS fecha_pago,
  COALESCE(NULLIF(p.haber, 0), 0.00) AS importe,
  'legacy',
  CONCAT('PAGOS:ncuenta=', COALESCE(p.ncuenta, 0)),
  'Migrado desde PAGOS'
FROM staging_fox_pagos p
JOIN alumnos al ON al.codigo_legacy = COALESCE(p.codigo_cliente_legacy, p.ficha, p.ncuenta)
WHERE p.procesado = 0
  AND p.error_msg IS NULL
  AND (
    COALESCE(p.haber, 0) > 0
    OR UPPER(COALESCE(p.pago, 'N')) IN ('S', 'Y', '1', 'P')
  );

-- 10) Aplicacion de pagos a cuota (estrategia simple por mismo alumno/anio/mes)
INSERT IGNORE INTO pago_aplica_cuota (pago_id, cuota_id, importe_aplicado)
SELECT
  pr.id AS pago_id,
  cm.id AS cuota_id,
  LEAST(pr.importe, cm.importe_original) AS importe_aplicado
FROM pago_registrado pr
JOIN cuota_mensual cm
  ON cm.alumno_id = pr.alumno_id
JOIN staging_fox_pagos p
  ON COALESCE(p.codigo_cliente_legacy, p.ficha, p.ncuenta) = (
      SELECT a.codigo_legacy FROM alumnos a WHERE a.id = pr.alumno_id
     )
 AND p.anio = cm.anio
 AND p.mes = cm.mes
WHERE pr.medio = 'legacy'
  AND pr.nota = 'Migrado desde PAGOS'
  AND p.procesado = 0
  AND p.error_msg IS NULL;

-- 11) Marcar staging como procesado
UPDATE staging_fox_clientes SET procesado = 1 WHERE procesado = 0 AND error_msg IS NULL;
UPDATE staging_fox_abonclie SET procesado = 1 WHERE procesado = 0 AND error_msg IS NULL;
UPDATE staging_fox_pagos SET procesado = 1 WHERE procesado = 0 AND error_msg IS NULL;

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

-- Consultas utiles post ETL:
-- SELECT COUNT(*) AS alumnos FROM alumnos;
-- SELECT COUNT(*) AS articulos FROM articulos;
-- SELECT COUNT(*) AS alumno_articulo FROM alumno_articulo;
-- SELECT COUNT(*) AS cuotas FROM cuota_mensual;
-- SELECT COUNT(*) AS pagos FROM pago_registrado;
-- SELECT * FROM staging_fox_clientes WHERE error_msg IS NOT NULL;
-- SELECT * FROM staging_fox_abonclie WHERE error_msg IS NOT NULL;
-- SELECT * FROM staging_fox_pagos WHERE error_msg IS NOT NULL;
