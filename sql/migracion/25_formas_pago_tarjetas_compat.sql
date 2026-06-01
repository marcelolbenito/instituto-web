-- Variante de 25_formas_pago_tarjetas.sql para MySQL 5.7 / MariaDB 10.2 / phpMyAdmin
-- (no soportan: ALTER TABLE ... ADD COLUMN IF NOT EXISTS).
-- Ejecutar ESTE archivo en prod si falla el 25 original. Es idempotente.

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

-- Columnas en pago_registrado (una por una, solo si faltan)
SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_registrado' AND COLUMN_NAME = 'forma_pago_id'
    ),
    'SELECT ''forma_pago_id ya existe'' AS info',
    'ALTER TABLE pago_registrado ADD COLUMN forma_pago_id SMALLINT UNSIGNED NULL AFTER medio'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_registrado' AND COLUMN_NAME = 'tarjeta_id'
    ),
    'SELECT ''tarjeta_id ya existe'' AS info',
    'ALTER TABLE pago_registrado ADD COLUMN tarjeta_id SMALLINT UNSIGNED NULL AFTER forma_pago_id'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_registrado' AND COLUMN_NAME = 'tarjeta_cuotas'
    ),
    'SELECT ''tarjeta_cuotas ya existe'' AS info',
    'ALTER TABLE pago_registrado ADD COLUMN tarjeta_cuotas TINYINT UNSIGNED NULL AFTER tarjeta_id'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_registrado' AND COLUMN_NAME = 'recargo_medio_pct'
    ),
    'SELECT ''recargo_medio_pct ya existe'' AS info',
    'ALTER TABLE pago_registrado ADD COLUMN recargo_medio_pct DECIMAL(8,2) NULL AFTER tarjeta_cuotas'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_registrado' AND COLUMN_NAME = 'importe_recargo_medio'
    ),
    'SELECT ''importe_recargo_medio ya existe'' AS info',
    'ALTER TABLE pago_registrado ADD COLUMN importe_recargo_medio DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER importe_interes'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_registrado' AND COLUMN_NAME = 'importe_descuento_medio'
    ),
    'SELECT ''importe_descuento_medio ya existe'' AS info',
    'ALTER TABLE pago_registrado ADD COLUMN importe_descuento_medio DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER importe_recargo_medio'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_registrado' AND COLUMN_NAME = 'nro_lote'
    ),
    'SELECT ''nro_lote ya existe'' AS info',
    'ALTER TABLE pago_registrado ADD COLUMN nro_lote VARCHAR(40) NULL AFTER referencia'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_registrado' AND COLUMN_NAME = 'cod_autorizacion'
    ),
    'SELECT ''cod_autorizacion ya existe'' AS info',
    'ALTER TABLE pago_registrado ADD COLUMN cod_autorizacion VARCHAR(40) NULL AFTER nro_lote'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_registrado' AND COLUMN_NAME = 'ultimos_digitos'
    ),
    'SELECT ''ultimos_digitos ya existe'' AS info',
    'ALTER TABLE pago_registrado ADD COLUMN ultimos_digitos VARCHAR(4) NULL AFTER cod_autorizacion'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_registrado' AND COLUMN_NAME = 'referencia_medio'
    ),
    'SELECT ''referencia_medio ya existe'' AS info',
    'ALTER TABLE pago_registrado ADD COLUMN referencia_medio VARCHAR(100) NULL AFTER ultimos_digitos'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

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
