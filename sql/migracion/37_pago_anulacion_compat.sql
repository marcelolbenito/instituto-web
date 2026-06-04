-- Anulación de recibos — compatible MySQL/MariaDB antiguos.

SET NAMES utf8mb4;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_registrado' AND COLUMN_NAME = 'anulado_en'
    ),
    'SELECT ''anulado_en ya existe'' AS info',
    'ALTER TABLE pago_registrado ADD COLUMN anulado_en TIMESTAMP NULL DEFAULT NULL AFTER creado_en'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_registrado' AND COLUMN_NAME = 'anulado_por'
    ),
    'SELECT ''anulado_por ya existe'' AS info',
    'ALTER TABLE pago_registrado ADD COLUMN anulado_por SMALLINT UNSIGNED NULL AFTER anulado_en'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_registrado' AND COLUMN_NAME = 'motivo_anulacion'
    ),
    'SELECT ''motivo_anulacion ya existe'' AS info',
    'ALTER TABLE pago_registrado ADD COLUMN motivo_anulacion VARCHAR(255) NULL AFTER anulado_por'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_registrado' AND INDEX_NAME = 'idx_pago_anulado'
    ),
    'SELECT ''idx_pago_anulado ya existe'' AS info',
    'ALTER TABLE pago_registrado ADD KEY idx_pago_anulado (anulado_en)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
