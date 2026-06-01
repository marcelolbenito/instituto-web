-- Normalizar fecha_vencimiento de cuotas: día de generación del período (no último día del mes).
-- La mora/pronto pago se calcula por anio/mes + parametros; este campo es referencia del cargo.

UPDATE cuota_mensual cm
CROSS JOIN (
    SELECT LEAST(28, GREATEST(1, COALESCE(dia_generacion_cuota, 1))) AS dia_gen
    FROM parametros_cobranza
    WHERE id = 1
    LIMIT 1
) p
SET cm.fecha_vencimiento = STR_TO_DATE(
    CONCAT(
        cm.anio,
        '-',
        LPAD(cm.mes, 2, '0'),
        '-',
        LPAD(
            LEAST(
                p.dia_gen,
                DAY(LAST_DAY(STR_TO_DATE(CONCAT(cm.anio, '-', LPAD(cm.mes, 2, '0'), '-01'), '%Y-%m-%d')))
            ),
            2,
            '0'
        )
    ),
    '%Y-%m-%d'
);
