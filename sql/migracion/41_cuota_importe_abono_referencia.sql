-- Congela el importe de "cuota base / abono completo" al generar la cuota.
-- Si el alumno tiene BECA, importe_original suele ser el becado y este campo
-- guarda el abono normal de ese momento para liquidar si pierde la beca.

SET NAMES utf8mb4;

ALTER TABLE cuota_mensual
  ADD COLUMN IF NOT EXISTS importe_abono_referencia DECIMAL(12,2) NULL
  COMMENT 'Abono/cuota base congelado al generar (para pérdida de beca)'
  AFTER importe_original;
