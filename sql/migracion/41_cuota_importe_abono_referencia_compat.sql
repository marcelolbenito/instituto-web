-- Compatible MySQL/MariaDB antiguos (sin ADD COLUMN IF NOT EXISTS).

SET NAMES utf8mb4;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'cuota_mensual'
        AND COLUMN_NAME = 'importe_abono_referencia'
    ),
    'SELECT ''importe_abono_referencia ya existe'' AS info',
    'ALTER TABLE cuota_mensual ADD COLUMN importe_abono_referencia DECIMAL(12,2) NULL COMMENT ''Abono/cuota base congelado al generar (para pérdida de beca)'' AFTER importe_original'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
