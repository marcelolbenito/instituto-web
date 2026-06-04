-- Anulación de recibos (soft delete): revierte cuotas, CC y caja.

SET NAMES utf8mb4;

ALTER TABLE pago_registrado
  ADD COLUMN anulado_en TIMESTAMP NULL DEFAULT NULL AFTER creado_en,
  ADD COLUMN anulado_por SMALLINT UNSIGNED NULL AFTER anulado_en,
  ADD COLUMN motivo_anulacion VARCHAR(255) NULL AFTER anulado_por;

ALTER TABLE pago_registrado
  ADD KEY idx_pago_anulado (anulado_en);
