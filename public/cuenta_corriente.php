<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/Saldos.php';
require_once dirname(__DIR__) . '/src/Cobranza.php';
require_once dirname(__DIR__) . '/src/FormasPago.php';
require_once dirname(__DIR__) . '/src/CuentaCorrienteMovimientos.php';
require_once dirname(__DIR__) . '/src/Auth.php';
require_once dirname(__DIR__) . '/src/FacturaElectronica.php';

$pdo = web_init($config);
$hasFormasPagoCc = formas_pago_schema_ok($pdo);
$alumnoId = isset($_GET['alumno_id']) ? (int) $_GET['alumno_id'] : 0;
auth_enforce_alumno_cc_scope($alumnoId);
$esPortalAlumno = auth_is_alumno();
$buscar = $esPortalAlumno ? '' : trim((string) ($_GET['q'] ?? ''));
$modoCc = strtolower(trim((string) ($_GET['modo'] ?? 'simple')));
if (!in_array($modoCc, ['simple', 'detalle'], true)) {
    $modoCc = 'simple';
}
$fechaCorte = saldo_corte_desde();

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
$fePorPago = [];

if ($alumnoId > 0) {
    $stAlumno = $pdo->prepare('SELECT id, codigo_legacy, nombre_completo, documento, activo FROM alumnos WHERE id = ?');
    $stAlumno->execute([$alumnoId]);
    $alumno = $stAlumno->fetch();

    if (!$alumno) {
        $error = 'Alumno inexistente.';
    } else {
        if ($modoCc === 'simple') {
            $ultimoPeriodoPagado = cobranza_ultimo_periodo_pagado($pdo, $alumnoId);
            $cuotasPendientes = cobranza_cuotas_pendientes_alumno($pdo, $alumnoId);
        }

        [$movimientos, $resumen] = cc_build_movimientos($pdo, $alumnoId, $modoCc);
        if (!$esPortalAlumno && fe_schema_ok($pdo)) {
            $pagoIdsCc = [];
            foreach ($movimientos as $mCc) {
                $pidCc = (int) ($mCc['pago_id'] ?? 0);
                if ($pidCc > 0 && (float) ($mCc['haber'] ?? 0) > 0.00001) {
                    $pagoIdsCc[] = $pidCc;
                }
            }
            $fePorPago = fe_estados_por_pagos($pdo, $pagoIdsCc);
        }
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

if (!$esPortalAlumno) {
    echo '<form method="get" class="search-form">';
    echo '<div class="search-title">Buscar alumno</div>';
    echo '<div class="search-input-row">';
    echo '<input name="q" value="' . h($buscar) . '" placeholder="Nombre, DNI o código legacy (ej: Perez, 32123456, 1502)" required>';
    echo '<button type="submit" class="search-submit" aria-label="Buscar alumno">Buscar</button>';
    echo '</div>';
    echo '</form>';
}

if (!$esPortalAlumno && $alumnoId <= 0 && $buscar !== '') {
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
                $feCc = $fePorPago[$pid] ?? null;
                if ($feCc !== null && ($feCc['estado'] ?? '') === 'autorizado') {
                    echo ' <a class="action-icon action-icon-fe" href="imprimir_factura_electronica.php?pago_id=' . $pid
                        . '" target="_blank" rel="noopener" title="Imprimir factura electrónica ARCA">'
                        . '<span class="action-icon-fe-label" aria-hidden="true">FE</span></a>';
                } elseif ($feCc !== null && ($feCc['estado'] ?? '') === 'sin_fe' && !$esPortalAlumno) {
                    echo ' <a class="action-icon action-icon-fe action-icon-fe-pendiente" href="factura_electronica.php?pago_id=' . $pid
                        . '" title="Emitir factura electrónica">'
                        . '<span class="action-icon-fe-label" aria-hidden="true">+FE</span></a>';
                }
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</section>';
    }
}

layout_end();
