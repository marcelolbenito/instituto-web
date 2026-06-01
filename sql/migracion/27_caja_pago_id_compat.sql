-- Variante compat (sin IF NOT EXISTS en ALTER). Ver 25_formas_pago_tarjetas_compat.sql.

SET NAMES utf8mb4;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_movimiento' AND COLUMN_NAME = 'pago_id'
    ),
    'SELECT ''pago_id ya existe'' AS info',
    'ALTER TABLE caja_movimiento ADD COLUMN pago_id BIGINT UNSIGNED NULL AFTER comprobante_id'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_movimiento' AND INDEX_NAME = 'idx_caja_pago'
    ),
    'SELECT ''idx_caja_pago ya existe'' AS info',
    'ALTER TABLE caja_movimiento ADD KEY idx_caja_pago (pago_id)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_movimiento' AND INDEX_NAME = 'uq_caja_pago_id'
    ),
    'SELECT ''uq_caja_pago_id ya existe'' AS info',
    'CREATE UNIQUE INDEX uq_caja_pago_id ON caja_movimiento (pago_id)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
