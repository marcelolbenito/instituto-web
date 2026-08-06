<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Auth.php';
require_once dirname(__DIR__) . '/src/InstitutoLogo.php';
require_once dirname(__DIR__) . '/src/InformesEstadoCuenta.php';

$pdo = web_init($config);
$alumnoId = isset($_GET['alumno_id']) ? (int) $_GET['alumno_id'] : 0;
auth_enforce_alumno_cc_scope($alumnoId);
$fechaConsulta = trim((string) ($_GET['fecha'] ?? date('Y-m-d')));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaConsulta) !== 1) {
    $fechaConsulta = date('Y-m-d');
}
$autoPrint = !isset($_GET['auto']) || (string) $_GET['auto'] !== '0';

$reporte = $alumnoId > 0 ? informes_estado_cuenta_armar($pdo, $alumnoId, $fechaConsulta) : null;
$appNombre = (string) ($config['app']['name'] ?? 'Instituto');
$cssPath = __DIR__ . '/assets/app.css';
$cssVer = is_file($cssPath) ? (string) filemtime($cssPath) : '1';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Estado de cuenta</title>
<link rel="stylesheet" href="assets/app.css?v=<?= h($cssVer) ?>">
<style>
body.ec-print-body { margin: 0; padding: 12mm 10mm; background: #fff; color: #111; }
.ec-print-toolbar { margin-bottom: 1rem; }
.estado-cuenta-datos {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
  gap: 0.35rem 1.25rem;
  margin: 0 0 1rem;
}
.estado-cuenta-datos dt { font-size: 0.78rem; color: #666; margin: 0; }
.estado-cuenta-datos dd { margin: 0; font-weight: 600; }
.estado-cuenta-total { font-size: 1.05rem; margin-top: 0.85rem; }
@media print { .ec-print-toolbar { display: none !important; } }
</style>
</head>
<body class="ec-print-body">
<?php if ($reporte === null): ?>
<p class="err">No se pudo generar el estado de cuenta.</p>
<p><a href="informes_estado_cuenta.php">Volver</a></p>
<?php else: ?>
<div class="ec-print-toolbar no-print">
<button type="button" class="btn-secondary" onclick="window.print()">Imprimir / Guardar PDF</button>
<a class="btn-secondary" href="informes_estado_cuenta.php?alumno_id=<?= (int) $alumnoId ?>&amp;fecha=<?= h($fechaConsulta) ?>">Volver al informe</a>
</div>
<header class="cc-print-encabezado">
<?php instituto_logo_render_html($pdo, 'instituto-logo-print instituto-logo-caja'); ?>
<p class="cc-print-instituto"><?= h($appNombre) ?></p>
<h2 class="cc-print-titulo">Estado de cuenta por alumno</h2>
</header>
<?php
informes_estado_cuenta_render_bloque($reporte, true);
if ($autoPrint):
?>
<script>window.addEventListener('load',function(){window.print();});</script>
<?php endif; ?>
<?php endif; ?>
</body>
</html>
