<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/Saldos.php';
require_once dirname(__DIR__) . '/src/Cobranza.php';
require_once dirname(__DIR__) . '/src/FormasPago.php';

$pdo = Db::pdo($config);
$hasFormasPagoCc = formas_pago_schema_ok($pdo);
$alumnoId = isset($_GET['alumno_id']) ? (int) $_GET['alumno_id'] : 0;
$buscar = trim((string) ($_GET['q'] ?? ''));
$modoCc = strtolower(trim((string) ($_GET['modo'] ?? 'simple')));
if (!in_array($modoCc, ['simple', 'detalle'], true)) {
    $modoCc = 'simple';
}
$fechaCorte = saldo_corte_desde();
$usaComponentesPago = db_has_column($pdo, 'pago_registrado', 'importe_capital')
    && db_has_column($pdo, 'pago_registrado', 'importe_interes')
    && db_has_column($pdo, 'pago_registrado', 'importe_beca_perdida')
    && db_has_column($pdo, 'pago_registrado', 'importe_descuento');
$periodoCorte = null;
if ($fechaCorte !== null) {
    $tsCorte = strtotime($fechaCorte);
    if ($tsCorte !== false) {
        $periodoCorte = date('Y-m', $tsCorte);
    }
}

/**
 * Formatea importes en ARS y oculta ceros para lectura simple.
 */
function money_or_blank(float $value): string
{
    if (abs($value) < 0.00001) {
        return '';
    }
    return '$ ' . number_format($value, 2, ',', '.');
}

/**
 * Detecta período YYYY-MM desde referencia legacy.
 */
function extract_period_from_reference(string $ref): ?string
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
 * Orden cronológico ascendente (para saldo acumulado) y comparador inverso para la tabla.
 *
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

$coincidencias = [];
if ($alumnoId <= 0 && $buscar !== '') {
    $like = '%' . $buscar . '%';
    $stBuscar = $pdo->prepare(
        'SELECT id, codigo_legacy, nombre_completo, documento
         FROM alumnos
         WHERE nombre_completo LIKE ?
            OR COALESCE(documento, \'\') LIKE ?
            OR CAST(COALESCE(codigo_legacy, 0) AS CHAR(20)) LIKE ?
         ORDER BY nombre_completo
         LIMIT 80'
    );
    $stBuscar->execute([$like, $like, $like]);
    $coincidencias = $stBuscar->fetchAll();
}

$alumno = null;
$movimientos = [];
$resumen = [
    'deuda' => 0.0,
    'pagado' => 0.0,
    'saldo' => 0.0,
];
$ultimoPeriodoPagado = null;
$cuotasPendientes = [];
$error = null;

if ($alumnoId > 0) {
    $stAlumno = $pdo->prepare('SELECT id, codigo_legacy, nombre_completo, documento, activo FROM alumnos WHERE id = ?');
    $stAlumno->execute([$alumnoId]);
    $alumno = $stAlumno->fetch();

    if (!$alumno) {
        $error = 'Alumno inexistente.';
    } else {
        $vistaOperativa = $modoCc === 'simple';
        $anioOperativo = cobranza_anio_operativo_desde();
        if ($vistaOperativa) {
            $ultimoPeriodoPagado = cobranza_ultimo_periodo_pagado($pdo, $alumnoId);
            $cuotasPendientes = cobranza_cuotas_pendientes_alumno($pdo, $alumnoId);
        }

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
        foreach ($stCuotas->fetchAll() as $c) {
            $debeCuota = (float) $c['debe'];
            if (abs($debeCuota) < 0.00001) {
                continue;
            }
            $fechaMov = (string) $c['fecha_mov'];
            if ($fechaCorte !== null && $fechaMov !== '' && $fechaMov < $fechaCorte) {
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
                   AND COALESCE(debe, 0) > 0.005';
            if ($vistaOperativa) {
                $desdeAdj = $fechaCorte ?? (sprintf('%d-01-01', $anioOperativo));
                $sqlAdj .= ' AND (pago_id IS NULL OR fecha_mov >= ? OR referencia LIKE \'RECIBO_INC:%\')';
            } else {
                $sqlAdj .= ' AND (pago_id IS NULL OR referencia LIKE \'RECIBO_INC:%\')';
            }
            $stAdj = $pdo->prepare($sqlAdj);
            $paramsAdj = [$alumnoId];
            if ($vistaOperativa) {
                $paramsAdj[] = $desdeAdj ?? sprintf('%d-01-01', $anioOperativo);
            }
            $stAdj->execute($paramsAdj);
            foreach ($stAdj->fetchAll() as $aj) {
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
        $sqlPagos = "SELECT {$colsPago}
               FROM pago_registrado
               WHERE alumno_id = ?
                 AND fecha_pago IS NOT NULL";
        $stPagos = $pdo->prepare($sqlPagos);
        $stPagos->execute([$alumnoId]);
        $pagosRaw = $stPagos->fetchAll();
        $marcasFoxPorMovimiento = [];
        $pagosConImportePorMovimiento = [];
        foreach ($pagosRaw as $p) {
            $capitalPago = (float) ($p['importe_capital'] ?? 0);
            $interesPago = (float) ($p['importe_interes'] ?? 0);
            $becaPago = (float) ($p['importe_beca_perdida'] ?? 0);
            $descuentoPago = (float) ($p['importe_descuento'] ?? 0);
            if (abs($capitalPago) < 0.00001 && abs($interesPago) < 0.00001 && abs($becaPago) < 0.00001 && abs($descuentoPago) < 0.00001) {
                $capitalPago = (float) $p['importe']; // compatibilidad con esquema viejo
            }
            $recMedio = (float) ($p['importe_recargo_medio'] ?? 0);
            $descMedio = (float) ($p['importe_descuento_medio'] ?? 0);
            $haberPago = (float) ($p['importe'] ?? 0);
            if (abs($haberPago) < 0.00001) {
                $haberPago = $capitalPago + $interesPago + $becaPago - $descuentoPago + $recMedio - $descMedio;
            }
            $fechaMov = (string) $p['fecha_pago'];
            $ref = trim((string) ($p['referencia'] ?? ''));
            $periodo = extract_period_from_reference($ref);
            if ($periodo === null && !empty($p['fecha_pago'])) {
                $tsTmp = strtotime((string) $p['fecha_pago']);
                if ($tsTmp !== false) {
                    $periodo = date('Y-m', $tsTmp);
                }
            }
            $periodoKey = $periodo ?? '';
            $movKey = $fechaMov . '|' . $periodoKey;
            if ($fechaCorte !== null && $fechaMov !== '' && $fechaMov < $fechaCorte) {
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
            if (abs($haberPago) >= 0.00001) {
                $pagosConImportePorMovimiento[$movKey] = true;
            }
        }

        foreach ($pagosRaw as $p) {
            $capitalPago = (float) ($p['importe_capital'] ?? 0);
            $interesPago = (float) ($p['importe_interes'] ?? 0);
            $becaPago = (float) ($p['importe_beca_perdida'] ?? 0);
            $descuentoPago = (float) ($p['importe_descuento'] ?? 0);
            if (abs($capitalPago) < 0.00001 && abs($interesPago) < 0.00001 && abs($becaPago) < 0.00001 && abs($descuentoPago) < 0.00001) {
                $capitalPago = (float) $p['importe']; // compatibilidad con esquema viejo
            }
            $recMedio = (float) ($p['importe_recargo_medio'] ?? 0);
            $descMedio = (float) ($p['importe_descuento_medio'] ?? 0);
            $haberPago = (float) ($p['importe'] ?? 0);
            if (abs($haberPago) < 0.00001) {
                $haberPago = $capitalPago + $interesPago + $becaPago - $descuentoPago + $recMedio - $descMedio;
            }
            if (abs($haberPago) < 0.00001) {
                continue;
            }
            $fechaMov = (string) $p['fecha_pago'];
            if ($fechaCorte !== null && $fechaMov !== '' && $fechaMov < $fechaCorte) {
                continue;
            }
            $ref = trim((string) ($p['referencia'] ?? ''));
            $periodo = extract_period_from_reference($ref);
            if ($periodo === null && !empty($p['fecha_pago'])) {
                $tsTmp = strtotime((string) $p['fecha_pago']);
                if ($tsTmp !== false) {
                    $periodo = date('Y-m', $tsTmp);
                }
            }
            $periodoKey = $periodo ?? '';
            $movKey = $fechaMov . '|' . $periodoKey;
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

        [$movimientos, $resumen] = cc_ordenar_y_saldo_movimientos($movimientos, true);
        if ((int) ($alumno['activo'] ?? 0) === 1) {
            recalcular_saldo_alumnos($pdo, $alumnoId);
        }
    }
}

layout_start($config, 'Cuenta corriente');
echo '<h1>Cuenta corriente por alumno</h1>';
echo '<p class="muted">Vista <strong>' . ($modoCc === 'simple' ? 'operativa' : 'detalle histórico') . '</strong>: '
    . ($modoCc === 'simple'
        ? 'una sola tabla con <strong>cargos</strong> (cuotas y obligaciones) y <strong>pagos</strong> (recibos, transferencias, Excel), desde el año operativo.'
        : 'histórico completo de cuotas, obligaciones y pagos.')
    . '</p>';

if ($error !== null) {
    flash_err($error);
}

echo '<form method="get" class="search-form">';
echo '<div class="search-title">Buscar alumno</div>';
echo '<div class="search-input-row">';
echo '<input name="q" value="' . h($buscar) . '" placeholder="Nombre, DNI o código legacy (ej: Perez, 32123456, 1502)" required>';
echo '<button type="submit" class="search-submit" aria-label="Buscar alumno">Buscar</button>';
echo '</div>';
echo '</form>';

if ($alumnoId <= 0 && $buscar !== '') {
    if (count($coincidencias) === 0) {
        echo '<p class="muted">Sin resultados para la búsqueda.</p>';
    } else {
        echo '<p class="muted">Resultados encontrados: ' . count($coincidencias) . '.</p>';
        echo '<table class="table js-data-table">';
        echo '<thead><tr><th>Id</th><th>Legacy</th><th>Alumno</th><th>DNI</th><th data-nosort="1">Acción</th></tr></thead><tbody>';
        foreach ($coincidencias as $c) {
            echo '<tr>';
            echo '<td>' . (int) $c['id'] . '</td>';
            echo '<td>' . h((string) ($c['codigo_legacy'] ?? '')) . '</td>';
            echo '<td>' . h((string) $c['nombre_completo']) . '</td>';
            echo '<td>' . h((string) ($c['documento'] ?? '')) . '</td>';
            echo '<td><a class="action-icon" href="cuenta_corriente.php?alumno_id=' . (int) $c['id'] . '" title="Ver cuenta corriente">💳</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}

if ($alumno) {
    $urlBaseAlumno = 'cuenta_corriente.php?alumno_id=' . (int) $alumnoId;
    $toggleModo = $modoCc === 'simple'
        ? $urlBaseAlumno . '&modo=detalle'
        : $urlBaseAlumno . '&modo=simple';
    $toggleLabel = $modoCc === 'simple' ? 'Ver detalle histórico' : 'Volver a vista operativa';

    $saldoNetoMsg = '';
    if ($resumen['saldo'] > 0.00001) {
        $saldoNetoMsg = 'Pendiente a cobrar';
    } elseif ($resumen['saldo'] < -0.00001) {
        $saldoNetoMsg = 'Saldo a favor / cobro adelantado o deuda no migrada';
    } else {
        $saldoNetoMsg = $modoCc === 'simple' ? 'Al día — no debe nada' : 'Cuenta equilibrada';
    }

    echo '<section class="dashboard-grid">';
    if ($modoCc === 'simple') {
        echo '<article class="kpi"><div class="kpi-label">Cargos (debe)</div><div class="kpi-value">$ '
            . number_format($resumen['deuda'], 2, ',', '.') . '</div></article>';
        echo '<article class="kpi"><div class="kpi-label">Pagos (haber)</div><div class="kpi-value">$ '
            . number_format($resumen['pagado'], 2, ',', '.') . '</div></article>';
        echo '<article class="kpi"><div class="kpi-label">Saldo</div><div class="kpi-value">$ '
            . number_format($resumen['saldo'], 2, ',', '.') . '</div><div class="kpi-label">' . h($saldoNetoMsg) . '</div></article>';
        echo '<article class="kpi"><div class="kpi-label">Cuotas impagas</div><div class="kpi-value">'
            . count($cuotasPendientes) . '</div><div class="kpi-label">Últ. abonado: '
            . h($ultimoPeriodoPagado ?? '—') . '</div></article>';
    } else {
        echo '<article class="kpi"><div class="kpi-label">Debe histórico</div><div class="kpi-value">$ ' . number_format($resumen['deuda'], 2, ',', '.') . '</div></article>';
        echo '<article class="kpi"><div class="kpi-label">Haber histórico</div><div class="kpi-value">$ ' . number_format($resumen['pagado'], 2, ',', '.') . '</div></article>';
        echo '<article class="kpi"><div class="kpi-label">Saldo neto</div><div class="kpi-value">$ ' . number_format($resumen['saldo'], 2, ',', '.') . '</div><div class="kpi-label">' . h($saldoNetoMsg) . '</div></article>';
    }
    echo '</section>';

    if ($modoCc === 'simple' && count($movimientos) === 0) {
        echo '<p class="muted">Sin movimientos del año operativo (' . (int) cobranza_anio_operativo_desde() . ') para este alumno.</p>';
    }

    echo '<p class="current-student"><strong>Alumno:</strong> ' . h($alumno['nombre_completo']);
    if (!empty($alumno['documento'])) {
        echo ' <span class="muted">(DNI ' . h((string) $alumno['documento']) . ')</span>';
    }
    if ($fechaCorte !== null) {
        $tsCorte = strtotime($fechaCorte);
        $txtCorte = $tsCorte !== false ? date('d/m/Y', $tsCorte) : $fechaCorte;
        echo ' <span class="muted">· Operativo desde ' . h($txtCorte) . '</span>';
    }
    echo '<span class="student-actions">';
    if ((int) ($alumno['activo'] ?? 0) === 1) {
        echo '<a class="action-icon" href="alumnos.php?id=' . (int) $alumno['id'] . '" title="Ver ficha del alumno">👤</a>';
        echo '<a class="action-icon" href="registrar_cobro.php?alumno_id=' . (int) $alumno['id'] . '&fecha_pago=' . h(date('Y-m-d')) . '" title="Registrar cobro">💵</a>';
    } else {
        echo '<span class="muted" title="Alumno inactivo: la ficha no se edita desde la app">👤 (solo consulta)</span>';
    }
    echo '</span> · <a href="' . h($toggleModo) . '">' . h($toggleLabel) . '</a></p>';

    if (count($movimientos) > 0) {
        $fechaEmision = date('d/m/Y H:i');
        $vistaTxt = $modoCc === 'simple'
            ? 'Operativa · año ' . (int) cobranza_anio_operativo_desde()
            : 'Detalle histórico';
        $appNombre = (string) ($config['app']['name'] ?? 'Instituto');
        $tituloPrint = 'Cuenta corriente — ' . (string) $alumno['nombre_completo'];

        echo '<section id="cc-reporte" data-print-report="1" data-print-title="' . h($tituloPrint) . '">';
        echo '<div class="cc-only-print" hidden>';
        echo '<header class="cc-reporte-encabezado">';
        echo '<p class="cc-print-instituto">' . h($appNombre) . '</p>';
        echo '<h2 class="cc-print-titulo">Cuenta corriente</h2>';
        echo '<dl class="cc-print-meta-grid">';
        echo '<div><dt>Alumno</dt><dd>' . h((string) $alumno['nombre_completo']) . '</dd></div>';
        $docAl = trim((string) ($alumno['documento'] ?? ''));
        if ($docAl !== '') {
            echo '<div><dt>DNI</dt><dd>' . h($docAl) . '</dd></div>';
        }
        $codLeg = trim((string) ($alumno['codigo_legacy'] ?? ''));
        if ($codLeg !== '') {
            echo '<div><dt>Código</dt><dd>' . h($codLeg) . '</dd></div>';
        }
        echo '<div><dt>Fecha de emisión</dt><dd>' . h($fechaEmision) . '</dd></div>';
        echo '<div><dt>Vista</dt><dd>' . h($vistaTxt) . '</dd></div>';
        if ($fechaCorte !== null) {
            $tsCorteHdr = strtotime($fechaCorte);
            $txtCorteHdr = $tsCorteHdr !== false ? date('d/m/Y', $tsCorteHdr) : $fechaCorte;
            echo '<div><dt>Operativo desde</dt><dd>' . h($txtCorteHdr) . '</dd></div>';
        }
        echo '</dl>';
        echo '<table class="cc-print-resumen"><tbody>';
        echo '<tr><th>Saldo</th><td class="num"><strong>$ '
            . number_format($resumen['saldo'], 2, ',', '.') . '</strong></td></tr>';
        echo '</tbody></table>';
        echo '<p class="cc-print-nota">Listado de movimientos según filtros de la pantalla.</p>';
        echo '</header>';
        echo '</div>';

        echo '<h2>Movimientos</h2>';
        echo '<table class="table js-data-table" data-print-report-id="cc-reporte">';
        echo '<thead><tr><th>Fecha</th><th>Período</th><th>Concepto</th><th>Debe</th><th>Haber</th><th>Saldo</th><th data-nosort="1"></th></tr></thead><tbody>';
        foreach ($movimientos as $m) {
            $fecha = '';
            if (!empty($m['fecha_mov'])) {
                $ts = strtotime((string) $m['fecha_mov']);
                $fecha = $ts !== false ? date('d/m/Y', $ts) : (string) $m['fecha_mov'];
            }
            $pid = isset($m['pago_id']) ? (int) $m['pago_id'] : 0;
            echo '<tr>';
            echo '<td>' . h($fecha) . '</td>';
            echo '<td>' . h((string) ($m['periodo'] ?? '')) . '</td>';
            echo '<td>' . h((string) $m['concepto']) . '</td>';
            echo '<td>' . h(money_or_blank((float) $m['debe'])) . '</td>';
            echo '<td>' . h(money_or_blank((float) $m['haber'])) . '</td>';
            echo '<td>' . h(money_or_blank((float) ($m['saldo_final'] ?? 0))) . '</td>';
            echo '<td class="nowrap">';
            if ($pid > 0 && (float) ($m['haber'] ?? 0) > 0.00001) {
                echo '<a class="action-icon" href="imprimir_recibo.php?alumno_id=' . (int) $alumnoId
                    . '&pago_id=' . $pid . '" target="_blank" rel="noopener" title="Imprimir recibo">🖨️</a>';
                echo ' <a class="action-icon" href="registrar_cobro.php?alumno_id=' . (int) $alumnoId
                    . '&pago_id=' . $pid . '#recibo" title="Ver detalle del cobro">🧾</a>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</section>';
    }
}

layout_end();
