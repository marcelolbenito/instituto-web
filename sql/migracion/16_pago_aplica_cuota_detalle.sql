-- Detalle por cuota en cada cobro: capital, recargo (% diario × días mora), descuento pronto pago.

SET NAMES utf8mb4;

ALTER TABLE pago_aplica_cuota
  ADD COLUMN IF NOT EXISTS importe_capital DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER importe_aplicado;

ALTER TABLE pago_aplica_cuota
  ADD COLUMN IF NOT EXISTS dias_mora INT UNSIGNED NOT NULL DEFAULT 0 AFTER importe_capital;

ALTER TABLE pago_aplica_cuota
  ADD COLUMN IF NOT EXISTS importe_recargo DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER dias_mora;

ALTER TABLE pago_aplica_cuota
  ADD COLUMN IF NOT EXISTS importe_descuento DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER importe_recargo;

-- Compatibilidad: lo aplicado a capital era todo el importe_aplicado histórico.
UPDATE pago_aplica_cuota
SET importe_capital = importe_aplicado
WHERE importe_capital = 0.00
  AND importe_aplicado > 0;
