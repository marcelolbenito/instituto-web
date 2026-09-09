-- OPCIONAL: deshacer un backfill agresivo previo que puso la lista actual
-- (ej. 101000) como importe_abono_referencia en cuotas becadas históricas.
-- Restaura el valor de cuenta corriente (importe_original).
--
-- NO correr si ya generaste cuotas NUEVAS con freeze correcto y querés
-- conservar pérdida de beca sobre esa base congelada.

SET NAMES utf8mb4;

UPDATE cuota_mensual cm
SET cm.importe_abono_referencia = cm.importe_original
WHERE cm.importe_abono_referencia IS NOT NULL
  AND cm.importe_abono_referencia > cm.importe_original + 0.005
  AND EXISTS (
      SELECT 1 FROM articulos ar_b
      WHERE UPPER(ar_b.detalle) LIKE '%BECA%'
        AND ABS(ar_b.importe_referencia - cm.importe_original) <= 0.02
  )
  AND ABS(
      cm.importe_abono_referencia - (
          SELECT COALESCE(MAX(ar.importe_referencia), 0)
          FROM articulos ar
          WHERE ar.activo = 1
            AND ar.es_abono = 1
            AND ar.importe_referencia > 0
            AND UPPER(ar.detalle) NOT LIKE '%BECA%'
            AND UPPER(ar.detalle) NOT LIKE '%DESCUENTO%'
      )
  ) <= 0.02;
