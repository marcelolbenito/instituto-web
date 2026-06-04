<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/InformesAlumnos.php';
require_once dirname(__DIR__) . '/src/RegularidadAlumno.php';
require_once dirname(__DIR__) . '/src/CuentaCorrienteMovimientos.php';
require_once dirname(__DIR__) . '/src/Cobranza.php';

$pdo = web_init($config);

$vista = (string) ($_GET['vista'] ?? 'simplificado');
if (!in_array($vista, ['simplificado', 'detallado'], true)) {
    $vista = 'simplificado';
}
$activo = (string) ($_GET['activo'] ?? 'activos');
$barrioId = isset($_GET['barrio_id']) ? (int) $_GET['barrio_id'] : 0;
$export = (string) ($_GET['export'] ?? '') === 'csv';
$detalleId = isset($_GET['alumno_id']) ? (int) $_GET['alumno_id'] : 0;

$regFiltro = [];
$rawReg = $_GET['reg'] ?? [];
if (is_array($rawReg)) {
    $regFiltro = array_values(array_intersect(
        ['regular', 'riesgo', 'irregular', 'sin_pagos'],
        array_map('strval', $rawReg)
    ));
}

$rows = informes_listar_alumnos($pdo, [
    'activo' => $activo,
    'barrio_id' => $barrioId,
    'solo_morosos' => true,
    'regularidad' => $regFiltro,
]);
$tot = informes_totales_saldo($rows);

if ($export) {
    $csvRows = [];
    foreach ($rows as $r) {
        $reg = regularidad_clasificar(true, $r['ultimo_pago'] ?? null);
        $csvRows[] = [
            (string) ($r['codigo_legacy'] ?? ''),
            (string) ($r['nombre_completo'] ?? ''),
            number_format((float) ($r['saldo_cc'] ?? 0), 2, '.', ''),
            $reg['label'],
            $reg['dias'] !== null ? (string) $reg['dias'] : '',
        ];
    }
    informes_csv_salida(
        'morosos_' . $vista . '_' . date('Y-m-d') . '.csv',
        ['Legacy', 'Nombre', 'Saldo', 'Regularidad', 'Dias sin pago'],
        $csvRows
    );
}

$barrios = $pdo->query('SELECT id, nombre FROM barrios ORDER BY nombre')->fetchAll();

layout_start($config, 'Morosos');
echo '<h1>Listado de morosos</h1>';
$paramMorosos = cobranza_cargar_parametros($pdo);
$diasTopeMorosos = max(1, (int) ($paramMorosos['dias_habiles_tope_pronto_pago'] ?? 5));
echo '<p class="muted">Alumnos con al menos una <strong>cuota mensual impaga</strong> cuyo plazo de pronto pago venció '
    . '(<strong>' . $diasTopeMorosos . '° día hábil</strong> desde la fecha de generación de la cuota, según '
    . '<a href="parametros_cobranza.php">Parámetros cobranza</a>; feriados nacionales). '
    . 'Vista ';
echo $vista === 'detallado' ? '<strong>detallada</strong> (ítems abiertos de CC).' : '<strong>simplificada</strong>.';
echo '</p>';

echo '<p class="nav-tabs">';
$qBase = ['activo' => $activo, 'barrio_id' => $barrioId > 0 ? $barrioId : null];
if ($regFiltro !== []) {
    $qBase['reg'] = $regFiltro;
}
echo '<a class="' . ($vista === 'simplificado' ? 'is-active' : '') . '" href="informes_morosos.php?' . h(http_build_query($qBase + ['vista' => 'simplificado'])) . '">Simplificado</a> ';
echo '<a class="' . ($vista === 'detallado' ? 'is-active' : '') . '" href="informes_morosos.php?' . h(http_build_query($qBase + ['vista' => 'detallado'])) . '">Detallado</a> ';
echo '<a href="informes_saldos.php">Saldo general</a>';
echo '</p>';

echo '<form method="get" class="form informes-filtro-form">';
echo '<input type="hidden" name="vista" value="' . h($vista) . '">';
echo '<fieldset class="fieldset"><legend>Filtros del informe</legend>';
echo '<div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));gap:0.75rem 1.25rem">';
echo '<label>Estado del alumno <select name="activo">';
foreach (['activos' => 'Activos (operación)', 'todos' => 'Todos (con deuda)'] as $k => $lbl) {
    echo '<option value="' . h($k) . '"' . ($activo === $k ? ' selected' : '') . '>' . h($lbl) . '</option>';
}
echo '</select></label>';
echo '<label>Barrio <select name="barrio_id"><option value="0">Todos los barrios</option>';
foreach ($barrios as $b) {
    $bid = (int) $b['id'];
    echo '<option value="' . $bid . '"' . ($barrioId === $bid ? ' selected' : '') . '>' . h((string) $b['nombre']) . '</option>';
}
echo '</select></label>';
echo '</div>';
echo '<p class="muted small" style="margin:0.5rem 0 0">Regularidad (opcional; sin marcar = todas):</p>';
echo '<div class="reg-checks-grid">';
foreach (['regular' => 'Regular', 'riesgo' => 'Riesgo', 'irregular' => 'Irregular', 'sin_pagos' => 'Sin pagos'] as $rk => $rl) {
    $chk = in_array($rk, $regFiltro, true) ? ' checked' : '';
    echo '<label class="check"><input type="checkbox" name="reg[]" value="' . h($rk) . '"' . $chk . '> ' . h($rl) . '</label>';
}
echo '</div>';
echo '<div class="form-actions"><button type="submit">Aplicar filtros</button> ';
echo '<a href="informes_morosos.php?vista=' . h($vista) . '">Restablecer</a></div>';
echo '</fieldset></form>';

echo '<div class="toolbar">';
echo '<a class="btn-secondary" href="informes_morosos.php?' . h(http_build_query(array_filter($qBase + [
    'vista' => $vista,
    'export' => 'csv',
]))) . '">Exportar CSV</a>';
echo '</div>';

echo '<section class="dashboard-grid">';
echo '<article class="kpi"><div class="kpi-label">Alumnos con cuota vencida</div><div class="kpi-value">' . (int) $tot['count'] . '</div></article>';
echo '<article class="kpi"><div class="kpi-label">Suma saldos CC (listado)</div><div class="kpi-value">$ '
    . h(number_format($tot['total_saldo'], 2, ',', '.')) . '</div></article>';
echo '</section>';

echo '<h2>Resultado</h2>';

if ($vista === 'simplificado') {
    echo '<table class="table js-data-table"><thead><tr>';
    echo '<th>Legacy</th><th>Nombre</th><th>Barrio</th><th>Saldo</th><th>Días</th><th>Regularidad</th><th></th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $reg = regularidad_clasificar((int) ($r['activo'] ?? 0) === 1, $r['ultimo_pago'] ?? null);
        $saldo = (float) ($r['saldo_cc'] ?? 0);
        echo '<tr>';
        echo '<td>' . h((string) ($r['codigo_legacy'] ?? '')) . '</td>';
        echo '<td>' . h((string) ($r['nombre_completo'] ?? '')) . '</td>';
        echo '<td>' . h((string) ($r['barrio_nombre'] ?? '')) . '</td>';
        echo '<td class="num text-debe">$ ' . h(number_format($saldo, 2, ',', '.')) . '</td>';
        echo '<td>' . ($reg['dias'] !== null ? (int) $reg['dias'] : '—') . '</td>';
        echo '<td><span class="badge ' . h($reg['class']) . '">' . h($reg['label']) . '</span></td>';
        echo '<td><a href="informes_morosos.php?vista=detallado&amp;alumno_id=' . (int) $r['id'] . '">Detalle</a> · ';
        echo '<a href="cuenta_corriente.php?alumno_id=' . (int) $r['id'] . '">CC</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
} else {
    $mostrar = $rows;
    if ($detalleId > 0) {
        $mostrar = array_values(array_filter($rows, static fn (array $r): bool => (int) ($r['id'] ?? 0) === $detalleId));
        if ($mostrar === []) {
            $idsVenc = cobranza_alumno_ids_con_cuotas_vencidas($pdo);
            if (in_array($detalleId, $idsVenc, true)) {
                $st = $pdo->prepare(
                    'SELECT a.id, a.codigo_legacy, a.nombre_completo, a.saldo_cc, a.activo, b.nombre AS barrio_nombre, up.ultimo_pago
                     FROM alumnos a
                     LEFT JOIN barrios b ON b.id = a.barrio_id
                     LEFT JOIN (SELECT alumno_id, MAX(fecha_pago) AS ultimo_pago FROM pago_registrado GROUP BY alumno_id) up ON up.alumno_id = a.id
                     WHERE a.id = ?'
                );
                $st->execute([$detalleId]);
                $one = $st->fetch(PDO::FETCH_ASSOC);
                $mostrar = $one ? [$one] : [];
            }
        }
        if ($mostrar !== []) {
            echo '<p><a href="informes_morosos.php?vista=detallado">← Ver todos los morosos</a></p>';
        }
    }

    foreach ($mostrar as $r) {
        $aid = (int) ($r['id'] ?? 0);
        $saldo = (float) ($r['saldo_cc'] ?? 0);
        $reg = regularidad_clasificar((int) ($r['activo'] ?? 0) === 1, $r['ultimo_pago'] ?? null);
        echo '<section class="informe-detalle-block">';
        echo '<h2>' . h((string) ($r['nombre_completo'] ?? '')) . ' <span class="muted">#' . $aid . '</span></h2>';
        echo '<p>Saldo CC: <strong class="text-debe">$ ' . h(number_format($saldo, 2, ',', '.')) . '</strong> · ';
        echo '<span class="badge ' . h($reg['class']) . '">' . h($reg['label']) . '</span> · ';
        echo '<a href="cuenta_corriente.php?alumno_id=' . $aid . '">Cuenta corriente</a></p>';

        [$movs, $resumenCc] = cc_build_movimientos($pdo, $aid, 'simple');
        $abiertos = array_values(array_filter(
            $movs,
            static fn (array $m): bool => (float) ($m['debe'] ?? 0) > 0.009
        ));
        if ($abiertos === []) {
            echo '<p class="muted">Sin ítems pendientes visibles en vista simple (revise cuenta corriente).</p>';
        } else {
            echo '<table class="table table-compact"><thead><tr><th>Fecha</th><th>Concepto</th><th>Debe</th><th>Haber</th><th>Saldo</th></tr></thead><tbody>';
            foreach ($abiertos as $m) {
                echo '<tr>';
                $fm = $m['fecha_mov'] ?? '';
                $ts = $fm !== '' ? strtotime((string) $fm) : false;
                echo '<td>' . h($ts !== false ? date('d/m/Y', $ts) : (string) $fm) . '</td>';
                echo '<td>' . h((string) ($m['concepto'] ?? '')) . '</td>';
                echo '<td class="num">$ ' . h(number_format((float) ($m['debe'] ?? 0), 2, ',', '.')) . '</td>';
                echo '<td class="num">$ ' . h(number_format((float) ($m['haber'] ?? 0), 2, ',', '.')) . '</td>';
                echo '<td class="num text-debe">$ ' . h(number_format((float) ($m['saldo_final'] ?? 0), 2, ',', '.')) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</section>';
    }
    if ($mostrar === []) {
        echo '<p class="muted">No hay morosos con los filtros elegidos.</p>';
    }
}
layout_end();
