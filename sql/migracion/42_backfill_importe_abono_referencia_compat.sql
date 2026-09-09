-- Compatible: mismo backfill conservador que 42_backfill_importe_abono_referencia.sql

SET NAMES utf8mb4;

UPDATE cuota_mensual
SET importe_abono_referencia = importe_original
WHERE importe_abono_referencia IS NULL;
