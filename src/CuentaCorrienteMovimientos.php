<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/Saldos.php';
require_once __DIR__ . '/Cobranza.php';
require_once __DIR__ . '/FormasPago.php';
require_once __DIR__ . '/PagoAnulacion.php';

function cc_extract_period_from_reference(string $ref): ?string
{
    if (preg_match('/(\d{4}-\d{2})/', $ref, $m) === 1) {
        return $m[1];
    }

    return null;
}

/**
 * @param array<string,mixed> $pago
 */
function cc_etiqueta_concepto_pago(PDO $pdo, array $pago, bool $hasFormasPago): string
{
    $id = (int) ($pago['id'] ?? 0);
    $txt = 'Recibo #' . $id;
    if ($hasFormasPago && formas_pago_schema_ok($pdo)) {
        $txt .= ' · ' . formas_pago_etiqueta_cobro($pdo, $pago);
    } else {
        $med = trim((string) ($pago['medio'] ?? ''));
        if ($med !== '') {
            $txt .= ' · ' . $med;
        }
    }
    $refMed = trim((string) ($pago['referencia_medio'] ?? ''));
    if ($refMed !== '') {
        $txt .= ' · ref. ' . $refMed;
    }

    return $txt;
}

/**
 * @param array<string,mixed> $a
 * @param array<string,mixed> $b
 */
function cc_cmp_movimiento_cronologico(array $a, array $b): int
{
    $fa = strtotime((string) ($a['fecha_mov'] ?? ''));
    $fb = strtotime((string) ($b['fecha_mov'] ?? ''));
    if ($fa !== $fb) {
        return $fa <=> $fb;
    }
    $pa = (string) ($a['periodo'] ?? '');
    $pb = (string) ($b['periodo'] ?? '');
    if ($pa !== $pb) {
        return strcmp($pa, $pb);
    }
    $prio = static function (array $m): int {
        $c = (string) ($m['concepto'] ?? '');
        if (str_starts_with($c, 'Recibo #')) {
            return 1;
        }
        if (str_contains($c, 'Cuota mensual') || $c === 'ABONO/CUOTA' || str_contains($c, 'Cuota pendiente')) {
            return 0;
        }

        return 2;
    };
    $oa = $prio($a);
    $ob = $prio($b);
    if ($oa !== $ob) {
        return $oa <=> $ob;
    }

    return strcmp((string) ($a['concepto'] ?? ''), (string) ($b['concepto'] ?? ''));
}

/**
 * @param array<string,mixed> $movimientos
 * @return array{0: array<int, array<string,mixed>>, 1: array{deuda: float, pagado: float, saldo: float}}
 */
function cc_ordenar_y_saldo_movimientos(array $movimientos, bool $masRecientePrimero = true): array
{
    usort($movimientos, 'cc_cmp_movimiento_cronologico');

    $resumen = ['deuda' => 0.0, 'pagado' => 0.0, 'saldo' => 0.0];
    $saldoAcumulado = 0.0;
    foreach ($movimientos as $idx => $m) {
        $saldoAcumulado += ((float) $m['debe'] - (float) $m['haber']);
        $movimientos[$idx]['saldo_final'] = $saldoAcumulado;
        $resumen['deuda'] += (float) $m['debe'];
        $resumen['pagado'] += (float) $m['haber'];
    }
    $resumen['saldo'] = $saldoAcumulado;

    if ($masRecientePrimero) {
        usort($movimientos, static function (array $a, array $b): int {
            return -cc_cmp_movimiento_cronologico($a, $b);
        });
    }

    return [$movimientos, $resumen];
}

function cc_fecha_pasa_corte(?string $fechaCorte, string $fechaMov): bool
{
    return $fechaCorte !== null && $fechaMov !== '' && $fechaMov < $fechaCorte;
}

/**
 * @return array{capital: float, interes: float, beca: float, descuento: float, recMedio: float, descMedio: float}
 */
function cc_importes_pago_row(array $p): array
{
    $capitalPago = (float) ($p['importe_capital'] ?? 0);
    $interesPago = (float) ($p['importe_interes'] ?? 0);
    $becaPago = (float) ($p['importe_beca_perdida'] ?? 0);
    $descuentoPago = (float) ($p['importe_descuento'] ?? 0);
    if (abs($capitalPago) < 0.00001 && abs($interesPago) < 0.00001 && abs($becaPago) < 0.00001 && abs($descuentoPago) < 0.00001) {
        $capitalPago = (float) ($p['importe'] ?? 0);
    }

    return [
        'capital' => $capitalPago,
        'interes' => $interesPago,
        'beca' => $becaPago,
        'descuento' => $descuentoPago,
        'recMedio' => (float) ($p['importe_recargo_medio'] ?? 0),
        'descMedio' => (float) ($p['importe_descuento_medio'] ?? 0),
    ];
}

function cc_haber_desde_pago_row(array $p): float
{
    $haberPago = (float) ($p['importe'] ?? 0);
    if (abs($haberPago) < 0.00001) {
        $imp = cc_importes_pago_row($p);
        $haberPago = $imp['capital'] + $imp['interes'] + $imp['beca'] - $imp['descuento']
            + $imp['recMedio'] - $imp['descMedio'];
    }

    return $haberPago;
}

/**
 * Arma movimientos y resumen (misma lógica que cuenta_corriente.php).
 *
 * @return array{0: array<int, array<string,mixed>>, 1: array{deuda: float, pagado: float, saldo: float}}
 */
function cc_build_movimientos(PDO $pdo, int $alumnoId, string $modoCc = 'simple'): array
{
    if (!in_array($modoCc, ['simple', 'detalle'], true)) {
        $modoCc = 'simple';
    }

    $fechaCorte = saldo_corte_desde();
    $vistaOperativa = $modoCc === 'simple';
    $anioOperativo = cobranza_anio_operativo_desde();
    $hasFormasPagoCc = formas_pago_schema_ok($pdo);
    $usaComponentesPago = db_has_column($pdo, 'pago_registrado', 'importe_capital')
        && db_has_column($pdo, 'pago_registrado', 'importe_interes')
        && db_has_column($pdo, 'pago_registrado', 'importe_beca_perdida')
        && db_has_column($pdo, 'pago_registrado', 'importe_descuento');

    $movimientos = [];

    $sqlCuotas = 'SELECT
            cm.id,
            cm.anio,
            cm.mes,
            STR_TO_DATE(CONCAT(cm.anio, "-", LPAD(cm.mes, 2, "0"), "-01"), "%Y-%m-%d") AS fecha_mov,
            CASE
                WHEN COALESCE(cm.importe_original, 0) > 0
                    THEN cm.importe_original
                ELSE COALESCE(cm.saldo, 0) + COALESCE(pa.aplicado, 0)
            END AS debe,
            cm.estado
         FROM cuota_mensual cm
         LEFT JOIN (
            SELECT cuota_id, SUM(importe_aplicado) AS aplicado
            FROM pago_aplica_cuota
            GROUP BY cuota_id
         ) pa ON pa.cuota_id = cm.id
         WHERE cm.alumno_id = ?';
    if ($vistaOperativa) {
        $sqlCuotas .= ' AND cm.anio >= ' . (int) $anioOperativo;
    }
    $stCuotas = $pdo->prepare($sqlCuotas);
    $stCuotas->execute([$alumnoId]);
    foreach ($stCuotas->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $debeCuota = (float) $c['debe'];
        if (abs($debeCuota) < 0.00001) {
            continue;
        }
        $fechaMov = (string) $c['fecha_mov'];
        if (cc_fecha_pasa_corte($fechaCorte, $fechaMov)) {
            continue;
        }
        $per = (int) $c['anio'] . '-' . str_pad((string) ((int) $c['mes']), 2, '0', STR_PAD_LEFT);
        $conceptoCuota = $vistaOperativa ? 'Cuota mensual ' . $per : 'ABONO/CUOTA';
        $movimientos[] = [
            'fecha_mov' => $fechaMov,
            'periodo' => $per,
            'concepto' => $conceptoCuota,
            'debe' => $debeCuota,
            'haber' => 0.0,
            'pago_id' => null,
        ];
    }

    if (db_has_column($pdo, 'cc_ajuste_debe', 'debe')) {
        $sqlAdj = 'SELECT id, fecha_mov, concepto, debe, pago_id, referencia
             FROM cc_ajuste_debe
             WHERE alumno_id = ?
               AND ABS(COALESCE(debe, 0)) > 0.005';
        if ($vistaOperativa) {
            $desdeAdj = $fechaCorte ?? sprintf('%d-01-01', $anioOperativo);
            $sqlAdj .= ' AND (pago_id IS NULL OR fecha_mov >= ? OR referencia LIKE \'RECIBO_INC:%\' OR referencia LIKE \'RECIBO_DEC:%\')';
        } else {
            $sqlAdj .= ' AND (pago_id IS NULL OR referencia LIKE \'RECIBO_INC:%\' OR referencia LIKE \'RECIBO_DEC:%\')';
        }
        $stAdj = $pdo->prepare($sqlAdj);
        $paramsAdj = [$alumnoId];
        if ($vistaOperativa) {
            $paramsAdj[] = $desdeAdj ?? sprintf('%d-01-01', $anioOperativo);
        }
        $stAdj->execute($paramsAdj);
        foreach ($stAdj->fetchAll(PDO::FETCH_ASSOC) as $aj) {
            $fechaAj = (string) ($aj['fecha_mov'] ?? '');
            if (cc_fecha_pasa_corte($fechaCorte, $fechaAj)) {
                continue;
            }
            $tsAj = strtotime($fechaAj);
            $perAj = $tsAj !== false ? date('Y-m', $tsAj) : '';
            $debeAj = (float) ($aj['debe'] ?? 0);
            if (abs($debeAj) < 0.00001) {
                continue;
            }
            $presAjDet = cobranza_debe_pendiente_presentacion($aj);
            $esPend = empty($aj['pago_id']);
            $sufijo = $esPend ? '' : ' (cobrado)';
            if ($debeAj < -0.005) {
                $movimientos[] = [
                    'fecha_mov' => $fechaAj,
                    'periodo' => $perAj,
                    'concepto' => $presAjDet['etiqueta_tipo'] . ': ' . $presAjDet['concepto'] . $sufijo,
                    'debe' => 0.0,
                    'haber' => abs($debeAj),
                    'pago_id' => $esPend ? null : (int) $aj['pago_id'],
                ];
            } else {
                $movimientos[] = [
                    'fecha_mov' => $fechaAj,
                    'periodo' => $perAj,
                    'concepto' => $presAjDet['etiqueta_tipo'] . ': ' . $presAjDet['concepto'] . $sufijo,
                    'debe' => $debeAj,
                    'haber' => 0.0,
                    'pago_id' => $esPend ? null : (int) $aj['pago_id'],
                ];
            }
        }
    }

    $colsPago = 'id, fecha_pago, importe, medio, referencia, nota';
    if (db_has_column($pdo, 'pago_registrado', 'referencia_medio')) {
        $colsPago .= ', referencia_medio';
    } else {
        $colsPago .= ', NULL AS referencia_medio';
    }
    if (db_has_column($pdo, 'pago_registrado', 'forma_pago_id')) {
        $colsPago .= ', forma_pago_id';
    } else {
        $colsPago .= ', NULL AS forma_pago_id';
    }
    if ($usaComponentesPago) {
        $colsPago .= ', importe_capital, importe_interes, importe_beca_perdida, importe_descuento';
    } else {
        $colsPago .= ', 0 AS importe_capital, 0 AS importe_interes, 0 AS importe_beca_perdida, 0 AS importe_descuento';
    }
    if (db_has_column($pdo, 'pago_registrado', 'importe_recargo_medio')) {
        $colsPago .= ', importe_recargo_medio, importe_descuento_medio';
    } else {
        $colsPago .= ', 0 AS importe_recargo_medio, 0 AS importe_descuento_medio';
    }
    if (db_has_column($pdo, 'pago_registrado', 'anulado_en')) {
        $colsPago .= ', anulado_en, motivo_anulacion';
    }
    $stPagos = $pdo->prepare(
        "SELECT {$colsPago} FROM pago_registrado WHERE alumno_id = ? AND fecha_pago IS NOT NULL"
    );
    $stPagos->execute([$alumnoId]);
    $pagosRaw = $stPagos->fetchAll(PDO::FETCH_ASSOC);

    $marcasFoxPorMovimiento = [];
    foreach ($pagosRaw as $p) {
        if (pago_anulacion_schema_ok($pdo) && pago_esta_anulado($p)) {
            continue;
        }
        $haberPago = cc_haber_desde_pago_row($p);
        $fechaMov = (string) $p['fecha_pago'];
        $ref = trim((string) ($p['referencia'] ?? ''));
        $periodo = cc_extract_period_from_reference($ref);
        if ($periodo === null && !empty($p['fecha_pago'])) {
            $tsTmp = strtotime((string) $p['fecha_pago']);
            if ($tsTmp !== false) {
                $periodo = date('Y-m', $tsTmp);
            }
        }
        $movKey = $fechaMov . '|' . ($periodo ?? '');
        if (cc_fecha_pasa_corte($fechaCorte, $fechaMov)) {
            continue;
        }
        $medioPago = strtolower(trim((string) ($p['medio'] ?? '')));
        $notaPago = trim((string) ($p['nota'] ?? ''));
        $marcaPagoFox = $medioPago === 'legacy'
            && str_starts_with($ref, 'PAGOS:ncuenta=')
            && strcasecmp($notaPago, 'Migrado desde PAGOS') === 0
            && abs($haberPago) < 0.00001;
        if ($marcaPagoFox) {
            $marcasFoxPorMovimiento[$movKey] = true;
        }
    }

    foreach ($pagosRaw as $p) {
        if (pago_anulacion_schema_ok($pdo) && pago_esta_anulado($p)) {
            continue;
        }
        $haberPago = cc_haber_desde_pago_row($p);
        if (abs($haberPago) < 0.00001) {
            continue;
        }
        $fechaMov = (string) $p['fecha_pago'];
        if (cc_fecha_pasa_corte($fechaCorte, $fechaMov)) {
            continue;
        }
        $ref = trim((string) ($p['referencia'] ?? ''));
        $periodo = cc_extract_period_from_reference($ref);
        if ($periodo === null && !empty($p['fecha_pago'])) {
            $tsTmp = strtotime((string) $p['fecha_pago']);
            if ($tsTmp !== false) {
                $periodo = date('Y-m', $tsTmp);
            }
        }
        $movKey = $fechaMov . '|' . ($periodo ?? '');
        $medioPago = strtolower(trim((string) ($p['medio'] ?? '')));
        if ($medioPago === 'legacy' || $medioPago === 'excel') {
            if (!empty($marcasFoxPorMovimiento[$movKey])) {
                $conceptoPago = 'Pago (marca Fox legacy)';
            } else {
                $conceptoPago = $medioPago === 'excel' ? 'Pago importado Excel' : 'Pago legacy';
            }
        } else {
            $conceptoPago = cc_etiqueta_concepto_pago($pdo, $p, $hasFormasPagoCc);
        }
        $movimientos[] = [
            'fecha_mov' => $fechaMov,
            'periodo' => $periodo ?? '',
            'concepto' => $conceptoPago,
            'debe' => 0.0,
            'haber' => $haberPago,
            'pago_id' => (int) ($p['id'] ?? 0) ?: null,
        ];
    }

    return cc_ordenar_y_saldo_movimientos($movimientos, true);
}
