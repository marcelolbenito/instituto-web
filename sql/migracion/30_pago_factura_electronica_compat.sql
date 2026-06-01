-- Vincula cobros (recibos) con comprobante / factura electrónica.
-- Requiere 04_schema_facturacion.sql

SET NAMES utf8mb4;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_registrado' AND COLUMN_NAME = 'comprobante_id'
    ),
    'SELECT ''comprobante_id ya existe'' AS info',
    'ALTER TABLE pago_registrado ADD COLUMN comprobante_id BIGINT UNSIGNED NULL AFTER id'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_registrado' AND INDEX_NAME = 'idx_pago_comprobante'
    ),
    'SELECT ''idx_pago_comprobante ya existe'' AS info',
    'ALTER TABLE pago_registrado ADD KEY idx_pago_comprobante (comprobante_id)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
