<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/FeFacturaHtml.php';

$pdo = Db::pdo($config);

$pagoId = isset($_GET['pago_id']) ? (int) $_GET['pago_id'] : 0;
$comprobanteId = isset($_GET['comprobante_id']) ? (int) $_GET['comprobante_id'] : 0;
$autoPrint = !isset($_GET['auto']) || (string) $_GET['auto'] !== '0';

$datos = null;
if ($pagoId > 0) {
    $datos = fe_impresion_cargar($pdo, $config, $pagoId, null);
} elseif ($comprobanteId > 0) {
    $datos = fe_impresion_cargar($pdo, $config, null, $comprobanteId);
}

header('Content-Type: text/html; charset=UTF-8');
$cssPath = __DIR__ . '/assets/app.css';
$cssVer = is_file($cssPath) ? (string) filemtime($cssPath) : '1';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Factura electrónica<?= $datos ? ' ' . h(fe_formato_pv_numero((int) $datos['comprobante']['punto_venta'], (int) $datos['comprobante']['numero'])) : '' ?></title>
<link rel="stylesheet" href="assets/app.css?v=<?= h($cssVer) ?>">
<style>
body.fe-print-page { margin: 0; padding: 1rem; background: #f0f2f5; }
body.fe-print-page .main { max-width: 48rem; margin: 0 auto; }
body.fe-print-page .fe-toolbar { margin-bottom: 1rem; }
.fe-factura {
  background: #fff;
  border: 1px solid #ccc;
  padding: 1.25rem 1.5rem;
  font-size: 0.9rem;
  color: #111;
}
.fe-homo-banner {
  background: #fff3cd;
  border: 2px dashed #856404;
  color: #856404;
  text-align: center;
  font-weight: 700;
  padding: 0.5rem;
  margin-bottom: 1rem;
}
.fe-factura-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  border-bottom: 2px solid #222;
  padding-bottom: 0.75rem;
  margin-bottom: 1rem;
  gap: 1rem;
}
.fe-factura-tipo { display: flex; align-items: center; gap: 0.75rem; }
.fe-letra {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.5rem;
  height: 2.5rem;
  border: 2px solid #222;
  font-size: 1.5rem;
  font-weight: 700;
}
.fe-cod { font-size: 0.8rem; color: #555; }
.fe-factura-num { text-align: right; font-size: 0.85rem; line-height: 1.5; }
.fe-pv-full { font-size: 1rem; margin-top: 0.25rem; }
.fe-bloque { margin-bottom: 1rem; }
.fe-bloque h2 { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 0.35rem; color: #555; }
.fe-bloque p { margin: 0.15rem 0; }
.fe-detalle { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: 0.85rem; }
.fe-detalle th, .fe-detalle td { border: 1px solid #ddd; padding: 0.35rem 0.5rem; }
.fe-detalle th { background: #f5f5f5; text-align: left; }
.fe-detalle .num { text-align: right; white-space: nowrap; }
.fe-totales { text-align: right; margin: 0.5rem 0 1rem; }
.fe-total-row { display: flex; justify-content: flex-end; gap: 1.5rem; font-size: 1.05rem; }
.fe-cae-qr {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  border-top: 1px solid #ccc;
  padding-top: 1rem;
  margin-top: 1rem;
}
.fe-cae { flex: 1; }
.fe-qr { text-align: center; flex-shrink: 0; }
.fe-leyenda-afip { font-size: 0.75rem; color: #444; margin-top: 0.5rem; }
.fe-pie-legal { font-size: 0.7rem; color: #666; text-align: center; margin-top: 1rem; }
.fe-original { text-align: center; font-weight: 700; letter-spacing: 0.15em; margin: 0 0 0.5rem; }
.fe-dos-columnas { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 0.75rem; }
.fe-periodo { font-size: 0.85rem; margin: 0.5rem 0; }
.fe-leyenda-monotributo { font-size: 0.75rem; color: #444; margin: 0.75rem 0; line-height: 1.35; }
.fe-warn-banner { background: #f8d7da; border: 1px solid #f5c2c7; color: #842029; padding: 0.5rem 0.75rem; margin-bottom: 0.75rem; font-size: 0.85rem; }
@media (max-width: 640px) {
  .fe-dos-columnas { grid-template-columns: 1fr; }
}
@media print {
  body.fe-print-page { background: #fff; padding: 0; }
  body.fe-print-page .fe-toolbar { display: none !important; }
  .fe-factura { border: none; padding: 0; }
}
</style>
</head>
<body class="fe-print-page">
<main class="main">
<?php
if ($datos === null) {
    echo '<p class="err">Factura electrónica no encontrada o aún no autorizada.</p>';
    echo '<p class="no-print"><a href="factura_electronica.php">Volver a factura electrónica</a></p>';
} else {
    $pagoIdReal = $datos['pago_id'];
    $pv = fe_formato_pv_numero((int) $datos['comprobante']['punto_venta'], (int) $datos['comprobante']['numero']);

    echo '<div class="fe-toolbar no-print">';
    echo '<button type="button" class="btn-secondary" onclick="window.print()">Imprimir factura</button>';
    if ($pagoIdReal !== null && $pagoIdReal > 0) {
        echo ' <a class="btn-secondary" href="factura_electronica.php?pago_id=' . (int) $pagoIdReal . '">Volver</a>';
        echo ' <a class="btn-secondary" href="imprimir_recibo.php?pago_id=' . (int) $pagoIdReal . '" target="_blank" rel="noopener">Recibo</a>';
    } else {
        echo ' <a class="btn-secondary" href="factura_electronica.php">Volver</a>';
    }
    echo '</div>';

    fe_factura_render_html($config, $datos, false, $pdo);

    if ($autoPrint) {
        echo '<script>window.addEventListener("load",function(){window.print();});</script>';
    }
}
?>
</main>
</body>
</html>
