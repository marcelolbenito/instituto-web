-- Arqueo al cierre: esperado por medio (sistema) vs contado (realidad).
-- Requiere 28_caja_cierre.

SET NAMES utf8mb4;

ALTER TABLE caja_cierre
  ADD COLUMN IF NOT EXISTS arqueo_json TEXT NULL COMMENT 'Snapshot: ingresos/egresos/esperado/contado por medio',
  ADD COLUMN IF NOT EXISTS arqueo_diferencia DECIMAL(14,2) NULL COMMENT 'Suma(contado - esperado) donde se informó contado';
