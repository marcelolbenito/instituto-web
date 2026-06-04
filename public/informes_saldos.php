<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/InformesAlumnos.php';
require_once dirname(__DIR__) . '/src/RegularidadAlumno.php';

$pdo = web_init($config);

$activo = (string) ($_GET['activo'] ?? 'activos');
$barrioId = isset($_GET['barrio_id']) ? (int) $_GET['barrio_id'] : 0;
$minSaldo = isset($_GET['min_saldo']) ? (float) str_replace(',', '.', (string) $_GET['min_saldo']) : 0.0;
$export = (string) ($_GET['export'] ?? '') === 'csv';

$rows = informes_listar_alumnos($pdo, [
    'activo' => $activo,
    'barrio_id' => $barrioId,
    'min_saldo' => $minSaldo,
]);
$tot = informes_totales_saldo($rows);

if ($export) {
    $csvRows = [];
    foreach ($rows as $r) {
        $reg = regularidad_clasificar((int) ($r['activo'] ?? 0) === 1, $r['ultimo_pago'] ?? null);
        $ult = $r['ultimo_pago'] ?? '';
        $ultTxt = $ult !== '' && ($ts = strtotime((string) $ult)) !== false ? date('d/m/Y', $ts) : '';
        $csvRows[] = [
            (string) ($r['codigo_legacy'] ?? ''),
            (string) ($r['nombre_completo'] ?? ''),
            (string) ($r['barrio_nombre'] ?? ''),
            number_format((float) ($r['saldo_cc'] ?? 0), 2, '.', ''),
            $ultTxt,
            $reg['label'],
        ];
    }
    informes_csv_salida(
        'saldo_general_' . date('Y-m-d') . '.csv',
        ['Legacy', 'Nombre', 'Barrio', 'Saldo', 'Ultimo pago', 'Regularidad'],
        $csvRows
    );
}

$barrios = $pdo->query('SELECT id, nombre FROM barrios ORDER BY nombre')->fetchAll();

layout_start($config, 'Saldo general');
echo '<h1>Resumen de saldos</h1>';
echo '<p class="muted">Saldos por alumno según cuenta corriente (<code>saldo_cc</code>).</p>';

echo '<p class="nav-tabs">';
echo '<a class="is-active" href="informes_saldos.php">Saldo general</a> ';
echo '<a href="informes_morosos.php?vista=simplificado">Morosos (simplificado)</a> ';
echo '<a href="informes_morosos.php?vista=detallado">Morosos (detallado)</a>';
echo '</p>';

$qFiltros = array_filter([
    'activo' => $activo !== 'activos' ? $activo : null,
    'barrio_id' => $barrioId > 0 ? $barrioId : null,
    'min_saldo' => $minSaldo > 0 ? $minSaldo : null,
]);
$qCsv = $qFiltros + ['export' => 'csv'];

echo '<form method="get" class="form informes-filtro-form">';
echo '<fieldset class="fieldset"><legend>Filtros del informe</legend>';
echo '<div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));gap:0.75rem 1.25rem">';
echo '<label>Estado del alumno <select name="activo">';
foreach (['activos' => 'Activos (operación)', 'inactivos' => 'Inactivos', 'todos' => 'Todos'] as $k => $lbl) {
    echo '<option value="' . h($k) . '"' . ($activo === $k ? ' selected' : '') . '>' . h($lbl) . '</option>';
}
echo '</select></label>';
echo '<label>Barrio <select name="barrio_id"><option value="0">Todos los barrios</option>';
foreach ($barrios as $b) {
    $bid = (int) $b['id'];
    echo '<option value="' . $bid . '"' . ($barrioId === $bid ? ' selected' : '') . '>' . h((string) $b['nombre']) . '</option>';
}
echo '</select></label>';
echo '<label>Saldo mínimo ($) <input type="text" name="min_saldo" inputmode="decimal" placeholder="Sin mínimo" value="'
    . h($minSaldo > 0 ? number_format($minSaldo, 2, ',', '') : '') . '"></label>';
echo '</div>';
echo '<div class="form-actions"><button type="submit">Aplicar filtros</button> ';
echo '<a href="informes_saldos.php">Restablecer</a></div>';
echo '</fieldset></form>';

echo '<div class="toolbar">';
echo '<a class="btn-secondary" href="informes_saldos.php?' . h(http_build_query($qCsv)) . '">Exportar CSV</a>';
echo '</div>';

echo '<section class="dashboard-grid">';
echo '<article class="kpi"><div class="kpi-label">Clientes en listado</div><div class="kpi-value">' . (int) $tot['count'] . '</div></article>';
echo '<article class="kpi"><div class="kpi-label">Con saldo &gt; 0</div><div class="kpi-value">' . (int) $tot['morosos'] . '</div></article>';
echo '<article class="kpi"><div class="kpi-label">Suma de saldos</div><div class="kpi-value">$ '
    . h(number_format($tot['total_saldo'], 2, ',', '.')) . '</div></article>';
echo '</section>';

echo '<h2>Resultado</h2>';
echo '<table class="table js-data-table"><thead><tr>';
echo '<th>Legacy</th><th>Nombre</th><th>Barrio</th><th>Saldo</th><th>Último pago</th><th>Regularidad</th><th data-nosort="1"></th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    $reg = regularidad_clasificar((int) ($r['activo'] ?? 0) === 1, $r['ultimo_pago'] ?? null);
    $saldo = (float) ($r['saldo_cc'] ?? 0);
    $ult = $r['ultimo_pago'] ?? null;
    $ultTxt = '';
    if (!empty($ult)) {
        $ts = strtotime((string) $ult);
        $ultTxt = $ts !== false ? date('d/m/Y', $ts) : (string) $ult;
    }
    echo '<tr>';
    echo '<td>' . h((string) ($r['codigo_legacy'] ?? '')) . '</td>';
    echo '<td>' . h((string) ($r['nombre_completo'] ?? '')) . '</td>';
    echo '<td>' . h((string) ($r['barrio_nombre'] ?? '')) . '</td>';
    echo '<td class="num' . ($saldo > 0.009 ? ' text-debe' : '') . '">$ ' . h(number_format($saldo, 2, ',', '.')) . '</td>';
    echo '<td>' . h($ultTxt) . '</td>';
    echo '<td><span class="badge ' . h($reg['class']) . '">' . h($reg['label']) . '</span></td>';
    echo '<td class="nowrap"><a class="action-icon" href="cuenta_corriente.php?alumno_id=' . (int) $r['id']
        . '" title="Ver cuenta corriente">💳</a></td>';
    echo '</tr>';
}
echo '</tbody></table>';
layout_end();
