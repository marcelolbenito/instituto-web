-- Vencimiento de postítulo por período — compatible MySQL/MariaDB antiguos (idempotente).
-- CREATE TABLE IF NOT EXISTS es estándar y seguro en versiones antiguas.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS postitulo_vencimiento (
  anio SMALLINT UNSIGNED NOT NULL,
  mes TINYINT UNSIGNED NOT NULL,
  fecha_vencimiento DATE NOT NULL,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (anio, mes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
