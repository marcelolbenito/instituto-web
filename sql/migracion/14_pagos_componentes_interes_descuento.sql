-- Extiende pago_registrado para separar componentes de cobro:
-- capital + interes - descuento = neto aplicado en cuenta corriente.

SET NAMES utf8mb4;

START TRANSACTION;

ALTER TABLE pago_registrado
  ADD COLUMN IF NOT EXISTS importe_capital DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER importe,
  ADD COLUMN IF NOT EXISTS importe_interes DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER importe_capital,
  ADD COLUMN IF NOT EXISTS importe_descuento DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER importe_interes;

-- Compatibilidad hacia atrás:
-- si venimos de esquema viejo, todo el importe histórico se considera capital.
UPDATE pago_registrado
SET
  importe_capital = CASE
    WHEN COALESCE(importe_capital, 0) = 0
      AND COALESCE(importe_interes, 0) = 0
      AND COALESCE(importe_descuento, 0) = 0
    THEN COALESCE(importe, 0)
    ELSE importe_capital
  END
WHERE 1 = 1;

COMMIT;

-- Regla esperada para nuevos cobros:
-- importe = importe_capital + importe_interes - importe_descuento
