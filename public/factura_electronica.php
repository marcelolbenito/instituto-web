<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/FacturaElectronica.php';
require_once dirname(__DIR__) . '/src/ParametrosFe.php';
require_once dirname(__DIR__) . '/src/ReciboHtml.php';

$pdo = web_init($config);
$feOk = fe_schema_ok($pdo);
$gesisCfg = fe_gesis_config($config, $pdo);
$gesisListo = (new GesisArcaClient($gesisCfg))->isConfigured();

$pagoId = isset($_GET['pago_id']) ? (int) $_GET['pago_id'] : 0;
$alumnoId = isset($_GET['alumno_id']) ? (int) $_GET['alumno_id'] : 0;
$q = trim((string) ($_GET['q'] ?? ''));

// Mismo criterio que en cobros: un solo campo; si es solo dígitos y existe el recibo, abrirlo.
if ($pagoId <= 0 && $q !== '' && preg_match('/^\d+$/', $q)) {
    $stRec = $pdo->prepare('SELECT id FROM pago_registrado WHERE id = ? LIMIT 1');
    $stRec->execute([(int) $q]);
    if ($stRec->fetchColumn()) {
        $pagoId = (int) $q;
    }
}
$buscar = $q !== '' ? $q : ($pagoId > 0 ? (string) $pagoId : '');

$msgOk = isset($_GET['ok']) ? (string) $_GET['ok'] : '';
$msgErr = isset($_GET['err']) ? (string) $_GET['err'] : '';

if ($feOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $pagoEmit = (int) ($_POST['pago_id'] ?? 0);
    $ptoPost = isset($_POST['punto_venta']) && $_POST['punto_venta'] !== ''
        ? (int) $_POST['punto_venta'] : null;
    $prodPost = isset($_POST['production']) ? true : null;

    if ($action === 'emitir' && $pagoEmit > 0) {
        $res = fe_emitir_desde_pago($pdo, $config, $pagoEmit, $ptoPost, $prodPost);
        $params = 'pago_id=' . $pagoEmit;
        if ($res['ok']) {
            $txt = 'FE autorizada. CAE ' . ($res['cae'] ?? '')
                . ' — comprobante ' . ($res['punto_venta'] ?? '') . '-' . ($res['numero'] ?? '');
            header('Location: factura_electronica.php?' . $params . '&ok=' . rawurlencode($txt));
        } else {
            header('Location: factura_electronica.php?' . $params . '&err=' . rawurlencode($res['msg']));
        }
        exit;
    }
}

$listPagoId = null;
$listAlumnoId = $alumnoId > 0 ? $alumnoId : null;
$listQ = $q;
if ($pagoId > 0 && ($q === '' || $q === (string) $pagoId)) {
    $listPagoId = $pagoId;
    $listQ = '';
} elseif ($pagoId > 0) {
    $listPagoId = $pagoId;
}
$pagos = $feOk ? fe_buscar_pagos($pdo, $listPagoId, $listAlumnoId, $listQ) : [];
$preview = null;
$previewVoucher = null;
$pagoMigradoExcel = false;
if ($feOk && $pagoId > 0) {
    $datosRecibo = recibo_cargar_por_pago($pdo, $pagoId);
    if ($datosRecibo !== null && fe_pago_es_migrado_excel($pdo, $datosRecibo['pago'])) {
        $pagoMigradoExcel = true;
    } elseif ($datosRecibo !== null) {
        $preview = $datosRecibo;
        try {
            $previewVoucher = fe_armar_voucher_desde_recibo($gesisCfg, $preview);
        } catch (Throwable $e) {
            $previewVoucher = ['_error' => $e->getMessage()];
        }
    }
}

layout_start($config, 'Factura electrónica desde recibo');
echo '<h1>Factura electrónica desde recibo</h1>';
echo '<p class="muted">Emití la factura ARCA por el importe de un recibo de cobro real.</p>';

if (!$feOk) {
    echo '<p class="err">Ejecute las migraciones <code>04_schema_facturacion.sql</code> y '
        . '<code>30_pago_factura_electronica_compat.sql</code>.</p>';
    layout_end();
    exit;
}

if (!$gesisListo) {
    echo '<p class="warn">Falta configurar la facturación electrónica en '
        . '<a href="parametros_factura_electronica.php">Utilitarios → Factura electrónica (parámetros)</a>.</p>';
}

if ($msgOk !== '') {
    flash_ok($msgOk);
}
if ($msgErr !== '') {
    flash_err($msgErr);
}

echo '<form method="get" class="search-form">';
echo '<div class="search-title">Buscar cobro para facturar</div>';
echo '<div class="search-input-row">';
echo '<input name="q" value="' . h($buscar) . '" placeholder="Nombre, DNI, código legacy o nº de recibo (ej: Pérez, 32123456, 1523)">';
echo '<button type="submit" class="search-submit" aria-label="Buscar cobro">Buscar</button>';
echo '</div>';
echo '</form>';

if (count($pagos) > 0) {
    echo '<p class="muted">Cobros encontrados: ' . count($pagos) . '. '
        . 'Elegí <strong>Ver / emitir</strong> o abrí el recibo por número en el mismo buscador.</p>';
    echo '<table class="table js-data-table"><thead><tr>';
    echo '<th>Recibo</th><th>Fecha</th><th>Alumno</th><th class="num">Importe</th><th>Factura FE</th><th></th>';
    echo '</tr></thead><tbody>';
    foreach ($pagos as $row) {
        $pid = (int) $row['pago_id'];
        $feEst = 'sin_fe';
        if (!empty($row['fe_estado']) && $row['fe_estado'] === 'autorizado') {
            $feEst = 'autorizado';
        } elseif (!empty($row['comprobante_id'])) {
            $feEst = (string) ($row['fe_estado'] ?? 'pendiente');
        }
        if ($feEst === 'autorizado') {
            $badge = '<span class="badge badge-ok">FE emitida</span>';
        } elseif ($feEst === 'pendiente') {
            $badge = '<span class="badge badge-warn">En proceso</span>';
        } elseif ($feEst === 'rechazado' || $feEst === 'error') {
            $badge = '<span class="badge badge-bad">Error FE</span>';
        } else {
            $badge = '<span class="badge badge-muted">Sin FE</span>';
        }
        if ($feEst === 'autorizado' && !empty($row['cae'])) {
            $badge .= '<br><small class="muted">CAE ' . h((string) $row['cae']);
            if (!empty($row['comp_numero'])) {
                $badge .= ' · ' . h((string) ($row['punto_venta'] ?? '')) . '-' . h((string) $row['comp_numero']);
            }
            $badge .= '</small>';
        }

        echo '<tr>';
        echo '<td><strong>Nº ' . $pid . '</strong></td>';
        echo '<td>' . h((string) $row['fecha_pago']) . '</td>';
        echo '<td>' . h((string) $row['nombre_completo']);
        if (!empty($row['documento'])) {
            echo '<br><small class="muted">DNI ' . h((string) $row['documento']) . '</small>';
        }
        echo '</td>';
        echo '<td class="num">$ ' . number_format((float) $row['importe'], 2, ',', '.') . '</td>';
        echo '<td>' . $badge . '</td>';
        echo '<td>';
        echo '<a class="btn-secondary" href="factura_electronica.php?pago_id=' . $pid . '">Ver / emitir</a>';
        echo ' <a class="btn-secondary" href="imprimir_recibo.php?pago_id=' . $pid . '&alumno_id=' . (int) $row['alumno_id'] . '" target="_blank" rel="noopener">Recibo</a>';
        if ($feEst === 'autorizado') {
            echo ' <a class="btn-secondary" href="imprimir_factura_electronica.php?pago_id=' . $pid
                . '" target="_blank" rel="noopener">Factura</a>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';
} elseif ($pagoMigradoExcel) {
    echo '<p class="warn">El recibo <strong>Nº ' . (int) $pagoId . '</strong> no admite factura ARCA desde aquí. '
        . 'Use un cobro registrado en <a href="registrar_cobro.php">Registrar cobro</a>.</p>';
} elseif ($buscar !== '') {
    echo '<p class="muted">Sin resultados para la búsqueda.</p>';
}

if ($preview !== null) {
    $estFe = fe_estado_pago($pdo, $pagoId);
    echo '<section class="card" style="margin-top:1.5rem"><h2>Recibo Nº ' . (int) $pagoId . '</h2>';

    if ($estFe['estado'] === 'autorizado') {
        echo '<p class="ok">Ya tiene factura electrónica autorizada.';
        if ($estFe['cae']) {
            echo ' CAE <strong>' . h($estFe['cae']) . '</strong>';
        }
        if ($estFe['punto_venta'] && $estFe['numero']) {
            echo ' — comprobante <strong>' . (int) $estFe['punto_venta'] . '-' . (int) $estFe['numero'] . '</strong>';
        }
        echo '.</p>';
        echo '<p class="form-actions"><a class="btn-primary" href="imprimir_factura_electronica.php?pago_id=' . (int) $pagoId
            . '" target="_blank" rel="noopener">Imprimir factura ARCA (PDF)</a></p>';
    } else {
        if (is_array($previewVoucher) && isset($previewVoucher['_error'])) {
            echo '<p class="err">' . h((string) $previewVoucher['_error']) . '</p>';
        }

        if ($gesisListo) {
            $importeFe = (float) ($preview['pago']['importe'] ?? 0);
            echo '<form method="post" class="form-actions" style="margin:1rem 0" onsubmit="return confirm(\'¿Emitir factura electrónica por $ '
                . number_format($importeFe, 2, ',', '.')
                . '?\');">';
            echo '<input type="hidden" name="action" value="emitir">';
            echo '<input type="hidden" name="pago_id" value="' . (int) $pagoId . '">';
            echo '<button type="submit" class="btn-primary">Emitir factura electrónica</button>';
            echo '</form>';
        }
    }

    echo '<div style="margin-top:1rem">';
    recibo_render_html($pdo, $preview, (int) ($preview['pago']['alumno_id'] ?? 0), false, false, true, false);
    echo '</div></section>';
}

layout_end();
