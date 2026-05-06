-- Calendario de feriados por ámbito (nacional/provincia/ciudad)
-- y jurisdicción del alumno para cálculo de días hábiles.

SET NAMES utf8mb4;

ALTER TABLE alumnos
  ADD COLUMN IF NOT EXISTS provincia VARCHAR(80) NULL AFTER barrio_id;

ALTER TABLE alumnos
  ADD COLUMN IF NOT EXISTS ciudad VARCHAR(80) NULL AFTER provincia;

CREATE TABLE IF NOT EXISTS feriados (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL,
  ambito ENUM('nacional', 'provincia', 'ciudad') NOT NULL DEFAULT 'nacional',
  provincia VARCHAR(80) NULL,
  ciudad VARCHAR(80) NULL,
  provincia_norm VARCHAR(80) AS (COALESCE(provincia, '')) STORED,
  ciudad_norm VARCHAR(80) AS (COALESCE(ciudad, '')) STORED,
  descripcion VARCHAR(160) NOT NULL,
  UNIQUE KEY uq_feriado_scope (fecha, ambito, provincia_norm, ciudad_norm),
  KEY idx_feriados_fecha (fecha),
  KEY idx_feriados_scope (ambito, provincia, ciudad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
