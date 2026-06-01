-- Tipo de alumno y ventana configurable de meses para postgrado.
-- Regla esperada:
-- - regular: genera cuota en cualquier mes.
-- - postgrado: genera solo entre postgrado_mes_desde y postgrado_mes_hasta.

SET NAMES utf8mb4;

ALTER TABLE alumnos
  ADD COLUMN IF NOT EXISTS tipo_alumno ENUM('regular', 'postgrado') NOT NULL DEFAULT 'regular'
  AFTER curso;

ALTER TABLE parametros_cobranza
  ADD COLUMN IF NOT EXISTS postgrado_mes_desde TINYINT UNSIGNED NOT NULL DEFAULT 4
    COMMENT 'Mes inicial de generación para alumnos postgrado (1-12)'
  AFTER dia_generacion_cuota;

ALTER TABLE parametros_cobranza
  ADD COLUMN IF NOT EXISTS postgrado_mes_hasta TINYINT UNSIGNED NOT NULL DEFAULT 11
    COMMENT 'Mes final de generación para alumnos postgrado (1-12)'
  AFTER postgrado_mes_desde;
