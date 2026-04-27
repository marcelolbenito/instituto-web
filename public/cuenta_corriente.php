<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/Saldos.php';

$pdo = Db::pdo($config);
$alumnoId = isset($_GET['alumno_id']) ? (int) $_GET['alumno_id'] : 0;
$buscar = trim((string) ($_GET['q'] ?? ''));
$fechaCorte = saldo_corte_desde();
$usaComponentesPago = db_has_column($pdo, 'pago_registrado', 'importe_capital')
    && db_has_column($pdo, 'pago_registrado', 'importe_interes')
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
$error = null;

if ($alumnoId > 0) {
    $stAlumno = $pdo->prepare('SELECT id, codigo_legacy, nombre_completo, documento, activo FROM alumnos WHERE id = ?');
    $stAlumno->execute([$alumnoId]);
    $alumno = $stAlumno->fetch();

    if (!$alumno) {
        $error = 'Alumno inexistente.';
    } else {
        $stCuotas = $pdo->prepare(
            'SELECT
                cm.id,
                cm.anio,
                cm.mes,
                COALESCE(
                  cm.fecha_vencimiento,
                  STR_TO_DATE(CONCAT(cm.anio, "-", LPAD(cm.mes, 2, "0"), "-01"), "%Y-%m-%d")
                ) AS fecha_mov,
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
             WHERE cm.alumno_id = ?'
        );
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
            $movimientos[] = [
                'fecha_mov' => $fechaMov,
                'periodo' => (int) $c['anio'] . '-' . str_pad((string) ((int) $c['mes']), 2, '0', STR_PAD_LEFT),
                'concepto' => 'ABONO/CUOTA',
                'debe' => $debeCuota,
                'haber' => 0.0,
            ];
        }

        $sqlPagos = $usaComponentesPago
            ? 'SELECT fecha_pago, importe, importe_capital, importe_interes, importe_descuento, medio, referencia, nota
               FROM pago_registrado
               WHERE alumno_id = ?
                 AND fecha_pago IS NOT NULL'
            : 'SELECT fecha_pago, importe, 0 AS importe_capital, 0 AS importe_interes, 0 AS importe_descuento, medio, referencia, nota
               FROM pago_registrado
               WHERE alumno_id = ?
                 AND fecha_pago IS NOT NULL';
        $stPagos = $pdo->prepare($sqlPagos);
        $stPagos->execute([$alumnoId]);
        $pagosRaw = $stPagos->fetchAll();
        $marcasFoxPorMovimiento = [];
        $pagosConImportePorMovimiento = [];
        foreach ($pagosRaw as $p) {
            $capitalPago = (float) ($p['importe_capital'] ?? 0);
            $interesPago = (float) ($p['importe_interes'] ?? 0);
            $descuentoPago = (float) ($p['importe_descuento'] ?? 0);
            if (abs($capitalPago) < 0.00001 && abs($interesPago) < 0.00001 && abs($descuentoPago) < 0.00001) {
                $capitalPago = (float) $p['importe']; // compatibilidad con esquema viejo
            }
            $haberPago = $capitalPago + $interesPago - $descuentoPago;
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
            if ($periodoCorte !== null && $periodoKey !== '' && $periodoKey < $periodoCorte) {
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
            $descuentoPago = (float) ($p['importe_descuento'] ?? 0);
            if (abs($capitalPago) < 0.00001 && abs($interesPago) < 0.00001 && abs($descuentoPago) < 0.00001) {
                $capitalPago = (float) $p['importe']; // compatibilidad con esquema viejo
            }
            $haberPago = $capitalPago + $interesPago - $descuentoPago;
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
            if ($periodoCorte !== null && $periodoKey !== '' && $periodoKey < $periodoCorte) {
                continue;
            }
            $conceptoPago = 'Pago';
            if (!empty($marcasFoxPorMovimiento[$movKey])) {
                $conceptoPago = 'Pago (incluye marca Fox: P)';
            }
            if (abs($interesPago) > 0.00001 || abs($descuentoPago) > 0.00001) {
                $conceptoPago .= ' [cap $ ' . number_format($capitalPago, 2, ',', '.')
                    . ' + int $ ' . number_format($interesPago, 2, ',', '.')
                    . ' - desc $ ' . number_format($descuentoPago, 2, ',', '.') . ']';
            }
            $movimientos[] = [
                'fecha_mov' => $fechaMov,
                'periodo' => $periodo ?? '',
                'concepto' => $conceptoPago,
                'debe' => 0.0,
                'haber' => $haberPago,
            ];
        }

        // Modo operativo limpio: no arrastrar saldo histórico previo al corte.

        $ordenMovimiento = static function (array $a, array $b): int {
            $pa = (string) ($a['periodo'] ?? '');
            $pb = (string) ($b['periodo'] ?? '');
            if ($pa !== $pb) {
                return strcmp($pa, $pb);
            }
            $fa = strtotime($a['fecha_mov']);
            $fb = strtotime($b['fecha_mov']);
            if ($fa !== $fb) {
                return $fa <=> $fb;
            }
            $prio = static function (string $concepto): int {
                if ($concepto === 'ABONO/CUOTA') {
                    return 0;
                }
                if (str_starts_with($concepto, 'Pago')) {
                    return 1;
                }
                return 2;
            };
            $ca = (string) ($a['concepto'] ?? '');
            $cb = (string) ($b['concepto'] ?? '');
            $oa = $prio($ca);
            $ob = $prio($cb);
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }
            return strcmp($ca, $cb);
        };
        usort($movimientos, $ordenMovimiento);

        $saldoAcumulado = 0.0;
        foreach ($movimientos as $idx => $m) {
            $saldoAcumulado += ((float) $m['debe'] - (float) $m['haber']);
            $movimientos[$idx]['saldo_final'] = $saldoAcumulado;
            $resumen['deuda'] += (float) $m['debe'];
            $resumen['pagado'] += (float) $m['haber'];
        }
        $resumen['saldo'] = $saldoAcumulado;
        if ((int) ($alumno['activo'] ?? 0) === 1) {
            recalcular_saldo_alumnos($pdo, $alumnoId);
        }

        // Se mantiene el orden cronológico para que saldo_final sea legible.
    }
}

layout_start($config, 'Cuenta corriente');
echo '<h1>Cuenta corriente por alumno</h1>';

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
    $saldoNetoMsg = '';
    if ($resumen['saldo'] > 0.00001) {
        $saldoNetoMsg = 'Pendiente a cobrar';
    } elseif ($resumen['saldo'] < -0.00001) {
        $saldoNetoMsg = 'Saldo a favor / cobro adelantado o deuda no migrada';
    } else {
        $saldoNetoMsg = 'Cuenta equilibrada';
    }

    echo '<section class="dashboard-grid">';
    echo '<article class="kpi"><div class="kpi-label">Debe histórico</div><div class="kpi-value">$ ' . number_format($resumen['deuda'], 2, ',', '.') . '</div></article>';
    echo '<article class="kpi"><div class="kpi-label">Haber histórico</div><div class="kpi-value">$ ' . number_format($resumen['pagado'], 2, ',', '.') . '</div></article>';
    echo '<article class="kpi"><div class="kpi-label">Saldo neto</div><div class="kpi-value">$ ' . number_format($resumen['saldo'], 2, ',', '.') . '</div><div class="kpi-label">' . h($saldoNetoMsg) . '</div></article>';
    echo '</section>';

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
    echo '</span></p>';

    echo '<table class="table js-data-table">';
    echo '<thead><tr><th>Fecha</th><th>Período</th><th>Concepto</th><th>Debe</th><th>Haber</th><th>Saldo final</th></tr></thead><tbody>';
    foreach ($movimientos as $m) {
        $fecha = '';
        if (!empty($m['fecha_mov'])) {
            $ts = strtotime((string) $m['fecha_mov']);
            $fecha = $ts !== false ? date('d/m/Y', $ts) : (string) $m['fecha_mov'];
        }
        echo '<tr>';
        echo '<td>' . h($fecha) . '</td>';
        echo '<td>' . h((string) ($m['periodo'] ?? '')) . '</td>';
        echo '<td>' . h((string) $m['concepto']) . '</td>';
        echo '<td>' . h(money_or_blank((float) $m['debe'])) . '</td>';
        echo '<td>' . h(money_or_blank((float) $m['haber'])) . '</td>';
        echo '<td>' . h(money_or_blank((float) ($m['saldo_final'] ?? 0))) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

layout_end();
