-- Variante compat (sin IF NOT EXISTS en ADD COLUMN). Requiere tabla caja_cierre.

SET NAMES utf8mb4;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_cierre' AND COLUMN_NAME = 'arqueo_json'
    ),
    'SELECT ''arqueo_json ya existe'' AS info',
    'ALTER TABLE caja_cierre ADD COLUMN arqueo_json TEXT NULL COMMENT ''Snapshot arqueo por medio'''
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_cierre' AND COLUMN_NAME = 'arqueo_diferencia'
    ),
    'SELECT ''arqueo_diferencia ya existe'' AS info',
    'ALTER TABLE caja_cierre ADD COLUMN arqueo_diferencia DECIMAL(14,2) NULL COMMENT ''Suma contado - esperado'''
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
