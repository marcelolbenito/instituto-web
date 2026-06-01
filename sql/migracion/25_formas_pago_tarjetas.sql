-- Formas de pago, tarjetas y planes de recargo por cuotas (ex Fox FORPAGO / TARJETA / TARRECA).
-- Recargo por medio es distinto del recargo por mora de cuotas (parametros_cobranza).
--
-- Si phpMyAdmin/MySQL antiguo falla en ADD COLUMN IF NOT EXISTS (#1064), usar en su lugar:
--   sql/migracion/25_formas_pago_tarjetas_compat.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

START TRANSACTION;

CREATE TABLE IF NOT EXISTS formas_pago (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(24) NOT NULL COMMENT 'Slug estable (medio en pago_registrado)',
  nombre VARCHAR(80) NOT NULL,
  tipo ENUM('efectivo','tarjeta','transferencia','cheque','cuenta_corriente','debito','otro') NOT NULL,
  recargo_pct DECIMAL(8,2) NOT NULL DEFAULT 0.00 COMMENT 'Recargo fijo % sobre subtotal si no usa planes de tarjeta',
  permite_descuento_pct TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Descuento manual al cobrar (ex Fox efectivo)',
  usa_planes_tarjeta TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Recargo según tarjeta + cantidad de cuotas',
  requiere_referencia TINYINT(1) NOT NULL DEFAULT 0,
  pide_datos_tarjeta TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Lote, autorización, últimos dígitos',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  codigo_legacy TINYINT UNSIGNED NULL COMMENT 'Fox FORPAGO: 1 efectivo, 2 tarjeta, 3 cta cte',
  UNIQUE KEY uq_formas_pago_codigo (codigo),
  KEY idx_formas_pago_activo (activo, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tarjetas (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo_legacy SMALLINT UNSIGNED NULL,
  nombre VARCHAR(80) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_tarjetas_codigo_legacy (codigo_legacy),
  KEY idx_tarjetas_activo (activo, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tarjeta_recargo_cuota (
  tarjeta_id SMALLINT UNSIGNED NOT NULL,
  cuotas TINYINT UNSIGNED NOT NULL COMMENT 'Cantidad de cuotas (Fox NCUOTA)',
  recargo_pct DECIMAL(8,2) NOT NULL COMMENT 'Fox PORRECA',
  PRIMARY KEY (tarjeta_id, cuotas),
  CONSTRAINT fk_trc_tarjeta FOREIGN KEY (tarjeta_id) REFERENCES tarjetas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE pago_registrado
  ADD COLUMN IF NOT EXISTS forma_pago_id SMALLINT UNSIGNED NULL AFTER medio,
  ADD COLUMN IF NOT EXISTS tarjeta_id SMALLINT UNSIGNED NULL AFTER forma_pago_id,
  ADD COLUMN IF NOT EXISTS tarjeta_cuotas TINYINT UNSIGNED NULL AFTER tarjeta_id,
  ADD COLUMN IF NOT EXISTS recargo_medio_pct DECIMAL(8,2) NULL AFTER tarjeta_cuotas,
  ADD COLUMN IF NOT EXISTS importe_recargo_medio DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER importe_interes,
  ADD COLUMN IF NOT EXISTS importe_descuento_medio DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER importe_recargo_medio,
  ADD COLUMN IF NOT EXISTS nro_lote VARCHAR(40) NULL AFTER referencia,
  ADD COLUMN IF NOT EXISTS cod_autorizacion VARCHAR(40) NULL AFTER nro_lote,
  ADD COLUMN IF NOT EXISTS ultimos_digitos VARCHAR(4) NULL AFTER cod_autorizacion,
  ADD COLUMN IF NOT EXISTS referencia_medio VARCHAR(100) NULL AFTER ultimos_digitos;

-- FKs opcionales (si ya existían filas, forma_pago_id queda NULL)
-- ALTER TABLE pago_registrado ADD CONSTRAINT fk_pago_forma FOREIGN KEY (forma_pago_id) REFERENCES formas_pago (id);
-- ALTER TABLE pago_registrado ADD CONSTRAINT fk_pago_tarjeta FOREIGN KEY (tarjeta_id) REFERENCES tarjetas (id);

INSERT INTO formas_pago (codigo, nombre, tipo, recargo_pct, permite_descuento_pct, usa_planes_tarjeta, requiere_referencia, pide_datos_tarjeta, activo, orden, codigo_legacy)
VALUES
  ('efectivo', 'Efectivo', 'efectivo', 0.00, 1, 0, 0, 0, 1, 10, 1),
  ('tarjeta', 'Tarjeta de crédito', 'tarjeta', 0.00, 0, 1, 0, 1, 1, 20, 2),
  ('debito', 'Tarjeta de débito', 'debito', 0.00, 0, 0, 0, 1, 1, 25, NULL),
  ('transferencia', 'Transferencia', 'transferencia', 0.00, 0, 0, 1, 0, 1, 30, NULL),
  ('cheque', 'Cheque', 'cheque', 0.00, 0, 0, 1, 0, 1, 40, NULL),
  ('cuenta_corriente', 'Cuenta corriente', 'cuenta_corriente', 0.00, 0, 0, 0, 0, 1, 50, 3),
  ('otro', 'Otro', 'otro', 0.00, 0, 0, 0, 0, 1, 99, NULL)
ON DUPLICATE KEY UPDATE
  nombre = VALUES(nombre),
  tipo = VALUES(tipo),
  permite_descuento_pct = VALUES(permite_descuento_pct),
  usa_planes_tarjeta = VALUES(usa_planes_tarjeta),
  pide_datos_tarjeta = VALUES(pide_datos_tarjeta),
  codigo_legacy = VALUES(codigo_legacy),
  orden = VALUES(orden);

INSERT INTO tarjetas (codigo_legacy, nombre, activo)
VALUES
  (1, 'TARJETA', 1),
  (2, 'NARANJA', 1)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

INSERT INTO tarjeta_recargo_cuota (tarjeta_id, cuotas, recargo_pct)
SELECT t.id, v.cuotas, v.recargo_pct
FROM tarjetas t
JOIN (
  SELECT 1 AS cod_legacy, 2 AS cuotas, 10.36 AS recargo_pct UNION ALL
  SELECT 1, 3, 12.20 UNION ALL
  SELECT 1, 4, 14.48 UNION ALL
  SELECT 1, 5, 16.46 UNION ALL
  SELECT 1, 6, 18.46
) v ON v.cod_legacy = t.codigo_legacy
ON DUPLICATE KEY UPDATE recargo_pct = VALUES(recargo_pct);

COMMIT;

SET FOREIGN_KEY_CHECKS = 1;
