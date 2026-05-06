<?php
declare(strict_types=1);

/**
 * Recalcula y persiste saldo de cuenta corriente por alumno.
 * Saldo = Debe histórico (cuotas) - Haber histórico (pagos).
 */
function saldo_corte_desde(): ?string
{
    $raw = trim((string) getenv('SALDO_CORTE_DESDE'));
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
        return null;
    }
    return $raw;
}

function recalcular_saldo_alumnos(\PDO $pdo, ?int $alumnoId = null, ?string $fechaCorte = null): int
{
    $fechaCorte = $fechaCorte ?: saldo_corte_desde();
    $periodoCorte = null;
    if ($fechaCorte !== null) {
        $tsCorte = strtotime($fechaCorte);
        if ($tsCorte !== false) {
            $periodoCorte = date('Y-m', $tsCorte);
        }
    }
    $whereCuotas = '';
    $wherePagos = '';
    $params = [];

    if ($fechaCorte !== null) {
        $whereCuotas = "
            WHERE COALESCE(
                cm.fecha_vencimiento,
                STR_TO_DATE(CONCAT(cm.anio, '-', LPAD(cm.mes, 2, '0'), '-01'), '%Y-%m-%d')
            ) >= ?
        ";
        $wherePagos = "
            WHERE fecha_pago >= ?
              AND NOT (
                medio = 'legacy'
                AND referencia LIKE 'PAGOS:ncuenta=%:%'
                AND RIGHT(referencia, 7) REGEXP '^[0-9]{4}-[0-9]{2}$'
                AND RIGHT(referencia, 7) < ?
              )
        ";
        $params[] = $fechaCorte;
        $params[] = $fechaCorte;
        $params[] = $periodoCorte ?? substr($fechaCorte, 0, 7);
    }

    $usaComponentesPago = false;
    $stCols = $pdo->query(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'pago_registrado'
          AND COLUMN_NAME IN ('importe_capital', 'importe_interes', 'importe_beca_perdida', 'importe_descuento')"
    );
    if ($stCols !== false) {
        $cols = $stCols->fetchAll(\PDO::FETCH_COLUMN);
        $usaComponentesPago = in_array('importe_capital', $cols, true)
            && in_array('importe_interes', $cols, true)
            && in_array('importe_beca_perdida', $cols, true)
            && in_array('importe_descuento', $cols, true);
    }
    $haberExpr = $usaComponentesPago
        ? 'COALESCE(NULLIF(importe_capital, 0), COALESCE(importe, 0))
           + COALESCE(importe_interes, 0)
           + COALESCE(importe_beca_perdida, 0)
           - COALESCE(importe_descuento, 0)'
        : 'COALESCE(importe, 0)';

    $sql = '
        UPDATE alumnos a
        LEFT JOIN (
            SELECT
                cm.alumno_id,
                SUM(
                    CASE
                        WHEN COALESCE(cm.importe_original, 0) > 0
                            THEN cm.importe_original
                        ELSE COALESCE(cm.saldo, 0) + COALESCE(pa.aplicado, 0)
                    END
                ) AS debe_total
            FROM cuota_mensual cm
            LEFT JOIN (
                SELECT cuota_id, SUM(importe_aplicado) AS aplicado
                FROM pago_aplica_cuota
                GROUP BY cuota_id
            ) pa ON pa.cuota_id = cm.id
            ' . $whereCuotas . '
            GROUP BY cm.alumno_id
        ) d ON d.alumno_id = a.id
        LEFT JOIN (
            SELECT
                alumno_id,
                SUM(' . $haberExpr . ') AS haber_total
            FROM pago_registrado
            ' . $wherePagos . '
            GROUP BY alumno_id
        ) h ON h.alumno_id = a.id
        SET a.saldo_cc = ROUND(COALESCE(d.debe_total, 0) - COALESCE(h.haber_total, 0), 2)
    ';

    if ($alumnoId !== null && $alumnoId > 0) {
        $sql .= ' WHERE a.id = ?';
        $st = $pdo->prepare($sql);
        $params[] = $alumnoId;
        $st->execute($params);
        return $st->rowCount();
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}
