-- Vincula cobros (recibos) con comprobante / factura electrónica.

SET NAMES utf8mb4;

ALTER TABLE pago_registrado
  ADD COLUMN IF NOT EXISTS comprobante_id BIGINT UNSIGNED NULL AFTER id,
  ADD KEY IF NOT EXISTS idx_pago_comprobante (comprobante_id);
