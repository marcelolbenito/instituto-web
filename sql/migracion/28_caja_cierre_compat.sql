-- Variante compat: crear caja_cierre si no existe (MySQL sin IF NOT EXISTS en todas las versiones).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS caja_cierre (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL COMMENT 'Día operativo (fecha del recibo / movimientos)',
  cerrado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ingresos DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  egresos DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  saldo DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  cantidad_movimientos INT UNSIGNED NOT NULL DEFAULT 0,
  observaciones VARCHAR(500) NULL,
  UNIQUE KEY uq_caja_cierre_fecha (fecha),
  KEY idx_caja_cierre_cerrado (cerrado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
