-- Vincular movimientos de caja con cobros web (pago_registrado).
-- Requiere migración 04_schema_facturacion (tabla caja_movimiento).

SET NAMES utf8mb4;

ALTER TABLE caja_movimiento
  ADD COLUMN IF NOT EXISTS pago_id BIGINT UNSIGNED NULL AFTER comprobante_id,
  ADD KEY IF NOT EXISTS idx_caja_pago (pago_id);

-- Un cobro web genera a lo sumo un movimiento de caja.
CREATE UNIQUE INDEX IF NOT EXISTS uq_caja_pago_id ON caja_movimiento (pago_id);
