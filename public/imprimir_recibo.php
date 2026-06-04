<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/ReciboHtml.php';

$pdo = web_init($config);
$hasFormasPago = formas_pago_schema_ok($pdo);

$pagoId = isset($_GET['pago_id']) ? (int) $_GET['pago_id'] : 0;
$alumnoId = isset($_GET['alumno_id']) ? (int) $_GET['alumno_id'] : 0;
$autoPrint = !isset($_GET['auto']) || (string) $_GET['auto'] !== '0';

$datos = recibo_cargar_por_pago($pdo, $pagoId, $alumnoId);
$alumnoIdReal = $datos ? (int) ($datos['pago']['alumno_id'] ?? 0) : 0;

header('Content-Type: text/html; charset=UTF-8');
$cssPath = __DIR__ . '/assets/app.css';
$cssVer = is_file($cssPath) ? (string) filemtime($cssPath) : '1';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recibo<?= $datos ? ' #' . (int) $datos['pago']['id'] : '' ?></title>
<link rel="stylesheet" href="assets/app.css?v=<?= h($cssVer) ?>">
<style>
body.recibo-print-page { margin: 0; padding: 1rem; background: #f0f2f5; }
body.recibo-print-page .main { max-width: 42rem; margin: 0 auto; }
body.recibo-print-page .recibo-toolbar { margin-bottom: 1rem; }
@media print {
  body.recibo-print-page { background: #fff; padding: 0; }
  body.recibo-print-page .recibo-toolbar { display: none !important; }
}
</style>
</head>
<body class="recibo-print-page">
<main class="main">
<?php
if ($datos === null) {
    echo '<p class="err">Recibo no encontrado.</p>';
    echo '<p class="no-print"><a href="javascript:history.back()">Volver</a></p>';
} else {
    echo '<div class="recibo-toolbar no-print">';
    echo '<button type="button" class="btn-secondary" onclick="window.print()">Imprimir</button>';
    echo ' <a class="btn-secondary" href="cuenta_corriente.php?alumno_id=' . $alumnoIdReal . '">Cuenta corriente</a>';
    echo ' <a class="btn-secondary" href="registrar_cobro.php?alumno_id=' . $alumnoIdReal
        . '&pago_id=' . $pagoId . '#recibo">Ver en cobros</a>';
    echo '</div>';
    recibo_render_html($pdo, $datos, $alumnoIdReal, $hasFormasPago, false, false);
    if ($autoPrint) {
        echo '<script>window.addEventListener("load",function(){window.print();});</script>';
    }
}
?>
</main>
</body>
</html>
