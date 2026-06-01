-- Logo del instituto para impresiones — compatible MySQL/MariaDB antiguos.

SET NAMES utf8mb4;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parametros_factura_electronica' AND COLUMN_NAME = 'logo_path'
    ),
    'SELECT ''logo_path ya existe'' AS info',
    'ALTER TABLE parametros_factura_electronica ADD COLUMN logo_path VARCHAR(200) NULL COMMENT ''Logo impresiones'' AFTER observaciones'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
