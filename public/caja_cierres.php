<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/Caja.php';

$pdo = Db::pdo($config);
$cierreOk = caja_cierre_schema_ok($pdo);

layout_start($config, 'Cierres de caja');
echo '<h1>Historial de cierres de caja</h1>';
echo '<p class="muted">Cada fila es un día <strong>cerrado</strong> con totales congelados. '
    . 'Para operar un día usá <a href="caja.php">Caja del día</a>.</p>';

if (!$cierreOk) {
    echo '<p class="err">Ejecutá <code>sql/migracion/28_caja_cierre.sql</code> (o <code>28_caja_cierre_compat.sql</code>).</p>';
    layout_end();
    return;
}

$abiertas = caja_fechas_abiertas_recientes($pdo, 45);
if (count($abiertas) > 0) {
    echo '<div class="help-box">';
    echo '<h3 style="margin-top:0">Días con movimientos sin cerrar (recientes)</h3><ul>';
    foreach (array_slice($abiertas, 0, 15) as $f) {
        $ts = strtotime($f);
        $txt = $ts !== false ? date('d/m/Y', $ts) : $f;
        echo '<li><a href="caja.php?fecha=' . h($f) . '">' . h($txt) . '</a></li>';
    }
    if (count($abiertas) > 15) {
        echo '<li class="muted">… y ' . (count($abiertas) - 15) . ' más</li>';
    }
    echo '</ul></div>';
}

$cierres = caja_listar_cierres($pdo, 120);
if (count($cierres) === 0) {
    echo '<p class="muted">Aún no hay cierres registrados.</p>';
} else {
    echo '<table class="table js-data-table">';
    $colArqueo = caja_arqueo_schema_ok($pdo);
    echo '<thead><tr><th>Fecha</th><th>Cerrado el</th><th>Ingresos</th><th>Egresos</th><th>Saldo</th>';
    if ($colArqueo) {
        echo '<th class="num">Arqueo Δ</th>';
    }
    echo '<th>Mov.</th><th data-nosort="1"></th></tr></thead><tbody>';
    foreach ($cierres as $c) {
        $f = (string) $c['fecha'];
        $tsF = strtotime($f);
        $txtF = $tsF !== false ? date('d/m/Y', $tsF) : $f;
        $tsC = strtotime((string) ($c['cerrado_en'] ?? ''));
        $txtC = $tsC !== false ? date('d/m/Y H:i', $tsC) : '';
        echo '<tr>';
        echo '<td><a href="caja.php?fecha=' . h($f) . '">' . h($txtF) . '</a></td>';
        echo '<td>' . h($txtC) . '</td>';
        echo '<td class="num">$ ' . number_format((float) $c['ingresos'], 2, ',', '.') . '</td>';
        echo '<td class="num">$ ' . number_format((float) $c['egresos'], 2, ',', '.') . '</td>';
        echo '<td class="num"><strong>$ ' . number_format((float) $c['saldo'], 2, ',', '.') . '</strong></td>';
        if ($colArqueo) {
            $ad = $c['arqueo_diferencia'] ?? null;
            if ($ad === null || $ad === '') {
                echo '<td class="num muted">—</td>';
            } else {
                $adf = (float) $ad;
                $cls = abs($adf) > 0.02 ? 'err' : 'ok';
                echo '<td class="num ' . h($cls) . '">$ ' . number_format($adf, 2, ',', '.') . '</td>';
            }
        }
        echo '<td>' . (int) ($c['cantidad_movimientos'] ?? 0) . '</td>';
        echo '<td class="nowrap">';
        echo '<a class="action-icon" href="imprimir_caja_cierre.php?fecha=' . h($f) . '" target="_blank" title="Imprimir cierre">🖨️</a>';
        echo '</td></tr>';
    }
    echo '</tbody></table>';
}

echo '<p class="muted"><a href="caja.php">Caja del día</a></p>';
layout_end();
