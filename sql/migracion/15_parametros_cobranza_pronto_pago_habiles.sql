-- Parámetros de cobranza: tope en días hábiles (pronto pago / mora) e interés fijo opcional.
-- El descuento fijo por pronto pago sigue en bonificacion_pronto_pago (ARS).

SET NAMES utf8mb4;

ALTER TABLE parametros_cobranza
  ADD COLUMN IF NOT EXISTS dias_habiles_tope_pronto_pago TINYINT UNSIGNED NOT NULL DEFAULT 5
    COMMENT 'Días hábiles desde el día 1 del mes del período de la cuota: si paga dentro, aplica descuento fijo'
    AFTER dia_tope_pronto_pago;

ALTER TABLE parametros_cobranza
  ADD COLUMN IF NOT EXISTS importe_interes_mora_fijo DECIMAL(12,2) NOT NULL DEFAULT 0.00
    COMMENT 'Interés/mora fijo (ARS) si el pago es después del tope en días hábiles (complementa recargo_coeficiente si se usa)'
    AFTER recargo_coeficiente;
