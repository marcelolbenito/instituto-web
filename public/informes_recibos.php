<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/InformesRecibos.php';
require_once dirname(__DIR__) . '/src/InformesAlumnos.php';

$pdo = web_init($config);

$fechaDesde = trim((string) ($_GET['fecha_desde'] ?? date('Y-m-01')));
$fechaHasta = trim((string) ($_GET['fecha_hasta'] ?? date('Y-m-d')));
$medio = trim((string) ($_GET['medio'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));
$incluirAnulados = (string) ($_GET['incluir_anulados'] ?? '') === '1';
$export = (string) ($_GET['export'] ?? '') === 'csv';

$rows = informes_listar_recibos($pdo, [
    'fecha_desde' => $fechaDesde,
    'fecha_hasta' => $fechaHasta,
    'medio' => $medio,
    'q' => $q,
    'incluir_anulados' => $incluirAnulados,
]);
$tot = informes_totales_recibos($rows);
$medios = informes_recibos_medios($pdo);

$user = auth_user();
$rol = (string) ($user['rol'] ?? '');
$puedeAnular = $rol !== 'consulta' && $rol !== 'alumno';

if ($export) {
    $csvRows = [];
    foreach ($rows as $r) {
        $ts = strtotime((string) ($r['fecha_pago'] ?? ''));
        $csvRows[] = [
            (string) ($r['id'] ?? ''),
            $ts !== false ? date('d/m/Y', $ts) : '',
            (string) ($r['codigo_legacy'] ?? ''),
            (string) ($r['nombre_completo'] ?? ''),
            number_format((float) ($r['importe'] ?? 0), 2, '.', ''),
            (string) ($r['medio_etiqueta'] ?? ''),
            !empty($r['anulado']) ? 'Anulado' : 'Vigente',
            (string) ($r['fe_label'] ?? ''),
        ];
    }
    informes_csv_salida(
        'recibos_' . $fechaDesde . '_' . $fechaHasta . '.csv',
        ['Recibo', 'Fecha', 'Legacy', 'Alumno', 'Importe', 'Medio', 'Estado', 'FE'],
        $csvRows
    );
}

layout_start($config, 'Resumen de recibos');
echo '<h1>Resumen de recibos</h1>';
echo '<p class="muted">Cobros web registrados en el período (excluye legacy y Excel). '
    . 'Total vigente: <strong>$ ' . number_format($tot['total'], 2, ',', '.') . '</strong>'
    . ' · ' . $tot['count'] . ' fila(s)';
if ($tot['anulados'] > 0) {
    echo ' · ' . $tot['anulados'] . ' anulado(s)';
}
echo '.</p>';
echo '<p><a href="registrar_cobro.php">Registrar cobro</a>';
if ($puedeAnular) {
    echo ' · <a href="anular_recibo.php">Anular recibo</a>';
}
echo ' · <a href="caja.php">Caja del día</a></p>';

echo '<form method="get" class="form-grid">';
echo '<label>Desde <input type="date" name="fecha_desde" value="' . h($fechaDesde) . '"></label>';
echo '<label>Hasta <input type="date" name="fecha_hasta" value="' . h($fechaHasta) . '"></label>';
echo '<label>Medio <select name="medio"><option value="">Todos</option>';
foreach ($medios as $m) {
    $sel = $medio === $m ? ' selected' : '';
    echo '<option value="' . h($m) . '"' . $sel . '>' . h($m) . '</option>';
}
echo '</select></label>';
echo '<label>Buscar <input type="search" name="q" value="' . h($q) . '" placeholder="Nº recibo, nombre, DNI"></label>';
echo '<label class="checkbox-inline"><input type="checkbox" name="incluir_anulados" value="1"'
    . ($incluirAnulados ? ' checked' : '') . '> Incluir anulados</label>';
echo '<button type="submit">Filtrar</button>';
$qExport = http_build_query([
    'fecha_desde' => $fechaDesde,
    'fecha_hasta' => $fechaHasta,
    'medio' => $medio !== '' ? $medio : null,
    'q' => $q !== '' ? $q : null,
    'incluir_anulados' => $incluirAnulados ? '1' : null,
    'export' => 'csv',
]);
echo ' <a class="btn-secondary" href="informes_recibos.php?' . h($qExport) . '">Exportar CSV</a>';
echo '</form>';

if (count($rows) === 0) {
    echo '<p class="muted">Sin recibos para los filtros indicados.</p>';
    layout_end();
    return;
}

echo '<div class="table-wrap"><table class="table"><thead><tr>';
echo '<th>Nº</th><th>Fecha</th><th>Alumno</th><th class="num">Importe</th><th>Medio</th><th>FE</th><th>Estado</th><th></th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    $pid = (int) ($r['id'] ?? 0);
    $aid = (int) ($r['alumno_id'] ?? 0);
    $ts = strtotime((string) ($r['fecha_pago'] ?? ''));
    $fechaTxt = $ts !== false ? date('d/m/Y', $ts) : '';
    $anulado = !empty($r['anulado']);
    echo '<tr' . ($anulado ? ' class="muted"' : '') . '>';
    echo '<td><a href="registrar_cobro.php?alumno_id=' . $aid . '&pago_id=' . $pid . '#recibo">' . $pid . '</a></td>';
    echo '<td>' . h($fechaTxt) . '</td>';
    echo '<td>' . h((string) ($r['nombre_completo'] ?? ''));
    if (!empty($r['documento'])) {
        echo ' <span class="muted">· DNI ' . h((string) $r['documento']) . '</span>';
    }
    echo '</td>';
    echo '<td class="num">$ ' . number_format((float) ($r['importe'] ?? 0), 2, ',', '.') . '</td>';
    echo '<td>' . h((string) ($r['medio_etiqueta'] ?? '')) . '</td>';
    echo '<td>' . h((string) ($r['fe_label'] ?? '')) . '</td>';
    echo '<td>' . ($anulado ? '<span class="err">Anulado</span>' : 'Vigente') . '</td>';
    echo '<td class="actions">';
    echo '<a href="imprimir_recibo.php?pago_id=' . $pid . '&alumno_id=' . $aid . '" title="Imprimir">🖨</a> ';
    if ($puedeAnular && !$anulado) {
        echo '<a href="anular_recibo.php?pago_id=' . $pid . '" title="Anular">✕</a>';
    }
    echo '</td>';
    echo '</tr>';
}
echo '</tbody></table></div>';

layout_end();
