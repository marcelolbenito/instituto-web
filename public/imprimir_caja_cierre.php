<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Caja.php';
require_once dirname(__DIR__) . '/src/InstitutoLogo.php';

$pdo = web_init($config);
$fecha = trim((string) ($_GET['fecha'] ?? ''));
$cierre = caja_cierre_schema_ok($pdo) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) === 1
    ? caja_obtener_cierre($pdo, $fecha)
    : null;
$appNombre = (string) ($config['app']['name'] ?? 'Instituto');
$autoPrint = !isset($_GET['auto']) || (string) $_GET['auto'] !== '0';

$tsF = $cierre ? strtotime((string) $cierre['fecha']) : false;
$fechaTxt = $tsF !== false ? date('d/m/Y', $tsF) : $fecha;
$tsC = $cierre ? strtotime((string) $cierre['cerrado_en']) : false;
$cerradoTxt = $tsC !== false ? date('d/m/Y H:i', $tsC) : '';

$cssPath = __DIR__ . '/assets/app.css';
$cssVer = is_file($cssPath) ? (string) filemtime($cssPath) : '1';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cierre de caja <?= h($fechaTxt) ?></title>
<link rel="stylesheet" href="assets/app.css?v=<?= h($cssVer) ?>">
<style>
body.cc-print-body { margin: 0; padding: 12mm 10mm; background: #fff; color: #111; }
.cc-print-toolbar { margin-bottom: 1rem; }
@media print { .cc-print-toolbar { display: none !important; } }
</style>
</head>
<body class="cc-print-body">
<?php if ($cierre === null): ?>
<p class="err">Cierre no encontrado para esa fecha.</p>
<p><a href="caja.php">Volver a caja</a></p>
<?php else: ?>
<div class="cc-print-toolbar no-print">
<button type="button" class="btn-secondary" onclick="window.print()">Imprimir</button>
<a class="btn-secondary" href="caja.php?fecha=<?= h($fecha) ?>">Caja del día</a>
<a class="btn-secondary" href="caja_cierres.php">Historial de cierres</a>
</div>
<header class="cc-reporte-encabezado cc-print-encabezado">
<?php instituto_logo_render_html($pdo, 'instituto-logo-print instituto-logo-caja'); ?>
<p class="cc-print-instituto"><?= h($appNombre) ?></p>
<h2 class="cc-print-titulo">Cierre de caja</h2>
<dl class="cc-print-meta-grid">
<div><dt>Fecha operativa</dt><dd><?= h($fechaTxt) ?></dd></div>
<div><dt>Cerrado el</dt><dd><?= h($cerradoTxt) ?></dd></div>
</dl>
<table class="cc-print-resumen"><tbody>
<tr><th>Saldo del día (sistema)</th><td class="num"><strong>$ <?= number_format((float) $cierre['saldo'], 2, ',', '.') ?></strong></td></tr>
<tr><th>Ingresos</th><td class="num">$ <?= number_format((float) $cierre['ingresos'], 2, ',', '.') ?></td></tr>
<tr><th>Egresos</th><td class="num">$ <?= number_format((float) $cierre['egresos'], 2, ',', '.') ?></td></tr>
</tbody></table>
<?php
$arqueo = caja_decodificar_arqueo($cierre);
if ($arqueo !== null && !empty($arqueo['medios'])):
    $hayFilas = false;
    foreach ($arqueo['medios'] as $lin) {
        if (($lin['contado'] ?? null) !== null) {
            $hayFilas = true;
            break;
        }
    }
    if ($hayFilas):
?>
<h3 style="margin:1rem 0 0.5rem;font-size:1rem">Arqueo (sistema vs. contado)</h3>
<table class="table cc-print-arqueo">
<thead><tr><th>Medio</th><th class="num">Sistema</th><th class="num">Contado</th><th class="num">Dif.</th></tr></thead>
<tbody>
<?php foreach ($arqueo['medios'] as $slug => $lin):
    $cont = $lin['contado'] ?? null;
    if ($cont === null) {
        continue;
    }
    $dif = (float) ($lin['diferencia'] ?? 0);
?>
<tr>
<td><?= h((string) ($lin['label'] ?? caja_label_medio((string) $slug))) ?></td>
<td class="num">$ <?= number_format((float) ($lin['esperado'] ?? 0), 2, ',', '.') ?></td>
<td class="num">$ <?= number_format((float) $cont, 2, ',', '.') ?></td>
<td class="num"><?= $dif >= 0 ? '' : '−' ?>$ <?= number_format(abs($dif), 2, ',', '.') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php if (isset($arqueo['diferencia_total']) && $arqueo['diferencia_total'] !== null): ?>
<p class="cc-print-nota"><strong>Diferencia total arqueo:</strong> $ <?= number_format((float) $arqueo['diferencia_total'], 2, ',', '.') ?></p>
<?php endif; ?>
<?php
    endif;
endif;
if (!empty($cierre['observaciones'])): ?>
<p class="cc-print-nota"><strong>Observaciones:</strong> <?= h((string) $cierre['observaciones']) ?></p>
<?php endif; ?>
<p class="cc-print-nota muted"><?= (int) $cierre['cantidad_movimientos'] ?> movimiento(s) al cierre.</p>
</header>
<?php if ($autoPrint): ?>
<script>window.addEventListener('load',function(){window.print();});</script>
<?php endif; ?>
<?php endif; ?>
</body>
</html>
