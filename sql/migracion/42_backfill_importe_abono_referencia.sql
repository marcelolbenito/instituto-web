-- Backfill conservador: alinear ref faltante con el valor de CC (importe_original).
-- No usa el precio de lista actual (evita liquidar cuotas viejas de 96.000 como 101.000).
--
-- Pérdida de beca a cuota base: solo cuotas generadas con freeze real
-- (importe_abono_referencia > importe_original al crearlas en generar_cuotas).

SET NAMES utf8mb4;

UPDATE cuota_mensual
SET importe_abono_referencia = importe_original
WHERE importe_abono_referencia IS NULL;
