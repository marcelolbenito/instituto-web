-- Regla BECA: si paga luego del 5to dia habil del mes, pierde BECA en ese periodo.
-- Se registra la diferencia en cuota y en detalle de aplicacion para trazabilidad.

SET NAMES utf8mb4;

ALTER TABLE cuota_mensual
  ADD COLUMN IF NOT EXISTS importe_diferencia_beca DECIMAL(12,2) NOT NULL DEFAULT 0.00
  AFTER importe_original;

ALTER TABLE pago_registrado
  ADD COLUMN IF NOT EXISTS importe_beca_perdida DECIMAL(12,2) NOT NULL DEFAULT 0.00
  AFTER importe_interes;

ALTER TABLE pago_aplica_cuota
  ADD COLUMN IF NOT EXISTS importe_beca_perdida DECIMAL(12,2) NOT NULL DEFAULT 0.00
  AFTER importe_descuento;
