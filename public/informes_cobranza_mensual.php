<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/InformesRecibos.php';
require_once dirname(__DIR__) . '/src/InformesAlumnos.php';

$pdo = web_init($config);

$periodo = trim((string) ($_GET['periodo'] ?? date('Y-m')));
if (preg_match('/^(\d{4})-(\d{2})$/', $periodo, $m) !== 1) {
    $periodo = date('Y-m');
    $m = [];
    preg_match('/^(\d{4})-(\d{2})$/', $periodo, $m);
}
$anio = (int) $m[1];
$mes = (int) $m[2];
if ($mes < 1 || $mes > 12) {
    $periodo = date('Y-m');
    preg_match('/^(\d{4})-(\d{2})$/', $periodo, $m);
    $anio = (int) $m[1];
    $mes = (int) $m[2];
}

$fechaDesde = sprintf('%04d-%02d-01', $anio, $mes);
$ultimoDia = (int) date('t', strtotime($fechaDesde));
$fechaHasta = sprintf('%04d-%02d-%02d', $anio, $mes, $ultimoDia);

$medio = trim((string) ($_GET['medio'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));
$export = (string) ($_GET['export'] ?? '') === 'csv';
$print = (string) ($_GET['print'] ?? '') === '1';

$rows = informes_listar_recibos($pdo, [
    'fecha_desde' => $fechaDesde,
    'fecha_hasta' => $fechaHasta,
    'medio' => $medio,
    'q' => $q,
    'incluir_anulados' => false,
]);

usort($rows, static function (array $a, array $b): int {
    $na = mb_strtoupper((string) ($a['nombre_completo'] ?? ''), 'UTF-8');
    $nb = mb_strtoupper((string) ($b['nombre_completo'] ?? ''), 'UTF-8');
    $cmp = $na <=> $nb;
    if ($cmp !== 0) {
        return $cmp;
    }
    $fa = (string) ($a['fecha_pago'] ?? '');
    $fb = (string) ($b['fecha_pago'] ?? '');
    if ($fa !== $fb) {
        return $fa <=> $fb;
    }

    return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
});

$tot = informes_totales_recibos($rows);
$porMedio = informes_totales_recibos_por_medio($rows);
$medios = informes_recibos_medios($pdo);

$mesesEs = [
    1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
    5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
    9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
];
$periodoTxt = ucfirst($mesesEs[$mes] ?? (string) $mes) . ' ' . $anio;

if ($export) {
    $csvRows = [];
    foreach ($rows as $r) {
        $ts = strtotime((string) ($r['fecha_pago'] ?? ''));
        $csvRows[] = [
            (string) ($r['nombre_completo'] ?? ''),
            (string) ($r['documento'] ?? ''),
            $ts !== false ? date('d/m/Y', $ts) : '',
            number_format((float) ($r['importe'] ?? 0), 2, '.', ''),
            (string) ($r['medio_etiqueta'] ?? $r['medio'] ?? ''),
            (string) ($r['id'] ?? ''),
        ];
    }
    informes_csv_salida(
        'cobranza_mensual_' . $periodo . '.csv',
        ['Alumno', 'DNI', 'Fecha de pago', 'Monto abonado', 'Medio de pago', 'Nº recibo'],
        $csvRows
    );
}

$qBase = array_filter([
    'periodo' => $periodo,
    'medio' => $medio !== '' ? $medio : null,
    'q' => $q !== '' ? $q : null,
], static fn ($v) => $v !== null && $v !== '');

if ($print) {
    $cssPath = __DIR__ . '/assets/app.css';
    $cssVer = is_file($cssPath) ? (string) filemtime($cssPath) : '1';
    $appNombre = (string) ($config['app']['name'] ?? 'Instituto');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Cobranza mensual · ' . h($periodoTxt) . '</title>';
    echo '<link rel="stylesheet" href="assets/app.css?v=' . h($cssVer) . '">';
    echo '<style>body{margin:0;padding:12mm 10mm;background:#fff;color:#111}';
    echo '.no-print{margin-bottom:1rem}@media print{.no-print{display:none!important}}</style></head><body>';
    echo '<div class="no-print">';
    echo '<button type="button" class="btn-secondary" onclick="window.print()">Imprimir</button> ';
    echo '<a class="btn-secondary" href="informes_cobranza_mensual.php?' . h(http_build_query($qBase)) . '">Volver al informe</a>';
    echo '</div>';
    echo '<header class="cc-print-encabezado">';
    echo '<p class="cc-print-instituto">' . h($appNombre) . '</p>';
    echo '<h2 class="cc-print-titulo">Cobranza mensual · ' . h($periodoTxt) . '</h2>';
    echo '<p class="muted">Del ' . h(date('d/m/Y', strtotime($fechaDesde))) . ' al '
        . h(date('d/m/Y', strtotime($fechaHasta))) . ' · '
        . (int) $tot['count'] . ' cobro(s) · Total $ '
        . number_format((float) $tot['total'], 2, ',', '.') . '</p>';
    if (count($porMedio) > 0) {
        echo '<h3 class="cc-print-seccion">Totales por medio de pago</h3>';
        echo '<table class="table"><thead><tr><th>Medio</th><th class="num">Cant.</th><th class="num">Total</th></tr></thead><tbody>';
        foreach ($porMedio as $pm) {
            echo '<tr><td>' . h($pm['medio']) . '</td>';
            echo '<td class="num">' . (int) $pm['cantidad'] . '</td>';
            echo '<td class="num">$ ' . number_format((float) $pm['total'], 2, ',', '.') . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '<h3 class="cc-print-seccion">Detalle por alumno</h3>';
    if (count($rows) === 0) {
        echo '<p class="muted">Sin cobros en el período.</p>';
    } else {
        echo '<table class="table"><thead><tr>';
        echo '<th>Alumno</th><th>Fecha de pago</th><th class="num">Monto abonado</th><th>Medio de pago</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $ts = strtotime((string) ($r['fecha_pago'] ?? ''));
            $fechaTxt = $ts !== false ? date('d/m/Y', $ts) : '';
            echo '<tr>';
            echo '<td>' . h((string) ($r['nombre_completo'] ?? ''));
            if (!empty($r['documento'])) {
                echo ' <span class="muted">· DNI ' . h((string) $r['documento']) . '</span>';
            }
            echo '</td>';
            echo '<td>' . h($fechaTxt) . '</td>';
            echo '<td class="num">$ ' . number_format((float) ($r['importe'] ?? 0), 2, ',', '.') . '</td>';
            echo '<td>' . h((string) ($r['medio_etiqueta'] ?? $r['medio'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody><tfoot><tr><th colspan="2">Total</th>';
        echo '<td class="num"><strong>$ ' . number_format((float) $tot['total'], 2, ',', '.') . '</strong></td>';
        echo '<td></td></tr></tfoot></table>';
    }
    echo '</header>';
    echo '<script>window.addEventListener("load",function(){window.print();});</script>';
    echo '</body></html>';
    exit;
}

layout_start($config, 'Cobranza mensual');
echo '<h1>Informe · Cobranza mensual</h1>';
echo '<p class="muted">Cobros del mes discriminados por <strong>alumno</strong>, <strong>fecha de pago</strong>, '
    . '<strong>monto abonado</strong> y <strong>medio de pago</strong>. '
    . 'Período: <strong>' . h($periodoTxt) . '</strong> '
    . '(' . h(date('d/m/Y', strtotime($fechaDesde))) . ' – ' . h(date('d/m/Y', strtotime($fechaHasta))) . ').'
    . ' Total: <strong>$ ' . number_format((float) $tot['total'], 2, ',', '.') . '</strong>'
    . ' · ' . (int) $tot['count'] . ' cobro(s).</p>';

echo '<form method="get" class="form form-grid" style="max-width:48rem">';
echo '<label>Mes <input type="month" name="periodo" value="' . h($periodo) . '" required></label>';
echo '<label>Medio de pago <select name="medio"><option value="">Todos</option>';
foreach ($medios as $mOpt) {
    $sel = $medio === $mOpt ? ' selected' : '';
    $lblMedio = informes_label_medio_codigo($mOpt);
    echo '<option value="' . h($mOpt) . '"' . $sel . '>' . h($lblMedio) . '</option>';
}
echo '</select></label>';
echo '<label>Buscar alumno <input type="search" name="q" value="' . h($q) . '" placeholder="Nombre, DNI o Nº recibo"></label>';
echo '<div class="form-actions" style="grid-column:1/-1">';
echo '<button type="submit">Filtrar</button> ';
echo '<a class="btn-secondary" href="informes_cobranza_mensual.php?' . h(http_build_query($qBase + ['export' => 'csv'])) . '">Exportar CSV</a> ';
echo '<a class="btn-secondary" href="informes_cobranza_mensual.php?' . h(http_build_query($qBase + ['print' => '1']))
    . '" target="_blank" rel="noopener">🖨️ Imprimir</a>';
echo '</div></form>';

if (count($porMedio) > 0) {
    echo '<h2>Totales por medio de pago</h2>';
    echo '<table class="table" style="max-width:32rem"><thead><tr>';
    echo '<th>Medio</th><th class="num">Cantidad</th><th class="num">Total</th>';
    echo '</tr></thead><tbody>';
    foreach ($porMedio as $pm) {
        echo '<tr><td>' . h($pm['medio']) . '</td>';
        echo '<td class="num">' . (int) $pm['cantidad'] . '</td>';
        echo '<td class="num">$ ' . number_format((float) $pm['total'], 2, ',', '.') . '</td></tr>';
    }
    echo '</tbody><tfoot><tr><th>Total</th>';
    echo '<td class="num">' . (int) $tot['count'] . '</td>';
    echo '<td class="num"><strong>$ ' . number_format((float) $tot['total'], 2, ',', '.') . '</strong></td>';
    echo '</tr></tfoot></table>';
}

echo '<h2>Detalle por alumno</h2>';
if (count($rows) === 0) {
    echo '<p class="muted">Sin cobros para el mes y filtros indicados.</p>';
    layout_end();
    return;
}

echo '<div class="table-wrap"><table class="table"><thead><tr>';
echo '<th>Alumno</th><th>Fecha de pago</th><th class="num">Monto abonado</th><th>Medio de pago</th><th>Recibo</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    $pid = (int) ($r['id'] ?? 0);
    $aid = (int) ($r['alumno_id'] ?? 0);
    $ts = strtotime((string) ($r['fecha_pago'] ?? ''));
    $fechaTxt = $ts !== false ? date('d/m/Y', $ts) : '';
    echo '<tr>';
    echo '<td>' . h((string) ($r['nombre_completo'] ?? ''));
    if (!empty($r['documento'])) {
        echo ' <span class="muted">· DNI ' . h((string) $r['documento']) . '</span>';
    }
    echo '</td>';
    echo '<td>' . h($fechaTxt) . '</td>';
    echo '<td class="num">$ ' . number_format((float) ($r['importe'] ?? 0), 2, ',', '.') . '</td>';
    echo '<td>' . h((string) ($r['medio_etiqueta'] ?? $r['medio'] ?? '')) . '</td>';
    echo '<td><a href="registrar_cobro.php?alumno_id=' . $aid . '&pago_id=' . $pid . '#recibo">' . $pid . '</a></td>';
    echo '</tr>';
}
echo '</tbody><tfoot><tr><th colspan="2">Total del mes</th>';
echo '<td class="num"><strong>$ ' . number_format((float) $tot['total'], 2, ',', '.') . '</strong></td>';
echo '<td colspan="2"></td></tr></tfoot></table></div>';

echo '<p class="muted"><a href="informes_recibos.php">Resumen de recibos</a> · <a href="caja_cierres.php">Cierre de caja</a></p>';
layout_end();
