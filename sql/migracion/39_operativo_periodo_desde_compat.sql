-- Período operativo desde (YYYY-MM) — compatible MySQL/MariaDB antiguos (idempotente).

SET NAMES utf8mb4;

SET @sql = (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parametros_cobranza' AND COLUMN_NAME = 'operativo_periodo_desde'
    ),
    'SELECT ''operativo_periodo_desde ya existe'' AS info',
    'ALTER TABLE parametros_cobranza ADD COLUMN operativo_periodo_desde CHAR(7) NULL COMMENT ''YYYY-MM: cuotas/movimientos anteriores ocultos'' AFTER bonificacion_pronto_pago'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
