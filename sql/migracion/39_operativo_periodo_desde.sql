-- Período operativo desde (YYYY-MM): oculta cuotas y movimientos anteriores en vistas operativas.
--
-- En MySQL/MariaDB de producción (gesis2) usar SIEMPRE:
--   sql/migracion/39_operativo_periodo_desde_compat.sql
-- El ADD COLUMN IF NOT EXISTS no funciona en versiones antiguas (#1064).

SET NAMES utf8mb4;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parametros_cobranza' AND COLUMN_NAME = 'operativo_periodo_desde'
    ),
    'SELECT ''operativo_periodo_desde ya existe'' AS info',
    'ALTER TABLE parametros_cobranza ADD COLUMN operativo_periodo_desde CHAR(7) NULL COMMENT ''YYYY-MM: cuotas/movimientos anteriores ocultos (vista operativa)'' AFTER bonificacion_pronto_pago'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
