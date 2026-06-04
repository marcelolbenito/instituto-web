<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/PagoAnulacion.php';
require_once dirname(__DIR__) . '/src/ReciboHtml.php';
require_once dirname(__DIR__) . '/src/FacturaElectronica.php';
require_once dirname(__DIR__) . '/src/FormasPago.php';

$pdo = web_init($config);
$schemaOk = pago_anulacion_schema_ok($pdo);
$hasFormasPago = formas_pago_schema_ok($pdo);

$user = auth_user();
$rol = (string) ($user['rol'] ?? '');
if ($rol === 'consulta' || $rol === 'alumno') {
    auth_forbidden('La anulación de recibos está reservada a operadores de caja.');
}

$pagoId = isset($_GET['pago_id']) ? (int) $_GET['pago_id'] : 0;
$q = trim((string) ($_GET['q'] ?? ''));
if ($pagoId <= 0 && $q !== '' && preg_match('/^\d+$/', $q)) {
    $pagoId = (int) $q;
}

$msgOk = isset($_GET['ok']) ? (string) $_GET['ok'] : '';
$msgErr = isset($_GET['err']) ? (string) $_GET['err'] : '';

if ($schemaOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $pagoPost = (int) ($_POST['pago_id'] ?? 0);
    $motivo = trim((string) ($_POST['motivo'] ?? ''));
    if ($action === 'anular' && $pagoPost > 0) {
        $uid = $user !== null ? (int) ($user['id'] ?? 0) : null;
        $res = pago_anular_recibo($pdo, $pagoPost, $uid > 0 ? $uid : null, $motivo);
        if ($res['ok']) {
            $aid = (int) ($res['alumno_id'] ?? 0);
            header('Location: anular_recibo.php?pago_id=' . $pagoPost . '&ok=' . rawurlencode($res['msg'])
                . ($aid > 0 ? '&alumno_id=' . $aid : ''));
        } else {
            header('Location: anular_recibo.php?pago_id=' . $pagoPost . '&err=' . rawurlencode($res['msg']));
        }
        exit;
    }
}

$datos = null;
$validacion = ['ok' => false, 'msg' => ''];
$feEst = null;
if ($pagoId > 0) {
    $datos = recibo_cargar_por_pago($pdo, $pagoId);
    if ($datos !== null) {
        $validacion = pago_anulacion_validar($pdo, $pagoId);
        if (pago_esta_anulado($datos['pago'])) {
            $validacion = ['ok' => false, 'msg' => 'Este recibo ya está anulado.'];
        }
        $feEst = fe_estado_pago($pdo, $pagoId);
    }
}

layout_start($config, 'Anular recibo');
echo '<h1>Anular recibo</h1>';
echo '<p class="muted">Revoca un cobro web: reabre cuotas, desvincula obligaciones en CC y elimina contramovimientos del recibo. '
    . '<strong>Caja:</strong> si el día del cobro sigue abierto, se registra un egreso el mismo día (netea el ingreso). '
    . 'Si ese día ya está cerrado —caso típico de transferencia verificada días después— '
    . '<strong>no se toca la caja</strong>: el ingreso deja de contar en totales operativos y el cierre impreso queda como histórico.</p>';
echo '<p><a href="informes_recibos.php">Resumen de recibos</a> · <a href="registrar_cobro.php">Registrar cobro</a></p>';

if (!$schemaOk) {
    echo '<p class="err">Ejecutá <code>sql/migracion/37_pago_anulacion_compat.sql</code> en la base de datos.</p>';
    layout_end();
    return;
}

if ($msgOk !== '') {
    echo '<p class="ok">' . h($msgOk) . '</p>';
}
if ($msgErr !== '') {
    echo '<p class="err">' . h($msgErr) . '</p>';
}

echo '<form method="get" class="form-inline" style="margin-bottom:1.5rem">';
echo '<label>Nº recibo o búsqueda <input type="text" name="q" value="' . h($q !== '' ? $q : ($pagoId > 0 ? (string) $pagoId : '')) . '" placeholder="Ej. 41195"></label> ';
echo '<button type="submit">Buscar</button>';
echo '</form>';

if ($pagoId > 0 && $datos === null) {
    echo '<p class="err">Recibo Nº ' . $pagoId . ' no encontrado.</p>';
    layout_end();
    return;
}

if ($datos !== null) {
    $pago = $datos['pago'];
    $alumnoId = (int) ($pago['alumno_id'] ?? 0);
    $anulado = pago_esta_anulado($pago);

    echo '<div class="help-box">';
    echo '<p><strong>Recibo Nº ' . (int) $pago['id'] . '</strong>';
    if ($datos['alumno']) {
        echo ' — ' . h((string) $datos['alumno']['nombre_completo']);
    }
    echo '</p>';
    if ($anulado) {
        $tsAn = strtotime((string) ($pago['anulado_en'] ?? ''));
        echo '<p class="err"><strong>ANULADO</strong>';
        if ($tsAn !== false) {
            echo ' el ' . h(date('d/m/Y H:i', $tsAn));
        }
        $mot = trim((string) ($pago['motivo_anulacion'] ?? ''));
        if ($mot !== '') {
            echo ' — ' . h($mot);
        }
        echo '</p>';
    }
    if ($feEst !== null && $feEst['estado'] === 'autorizado') {
        echo '<p>Factura electrónica: <strong>' . h((string) ($feEst['punto_venta'] ?? ''))
            . '-' . h((string) ($feEst['numero'] ?? '')) . '</strong> (CAE ' . h((string) ($feEst['cae'] ?? '')) . ')</p>';
    }
    echo '</div>';

    recibo_render_html($pdo, $datos, $alumnoId, $hasFormasPago, false, true, false);

    echo '<div class="no-print" style="margin-top:1.5rem">';
    echo '<a class="btn-secondary" href="imprimir_recibo.php?pago_id=' . $pagoId . '&alumno_id=' . $alumnoId . '">Imprimir recibo</a> ';
    echo '<a class="btn-secondary" href="cuenta_corriente.php?alumno_id=' . $alumnoId . '">Cuenta corriente</a>';
    echo '</div>';

    if (!$anulado) {
        if ($validacion['ok']) {
            echo '<section class="card" style="margin-top:1.5rem;border-color:#c0392b">';
            echo '<h2 style="margin-top:0;color:#922b21">Confirmar anulación</h2>';
            echo '<p>Esta acción <strong>no se puede deshacer</strong> desde el sistema. Verificá alumno e importe antes de continuar.</p>';
            echo '<form method="post" onsubmit="return confirm(\'¿Anular el recibo Nº '
                . (int) $pagoId . '? Se revertirán cuotas y movimientos.\');">';
            echo '<input type="hidden" name="action" value="anular">';
            echo '<input type="hidden" name="pago_id" value="' . $pagoId . '">';
            echo '<p><label>Motivo (obligatorio)<br><textarea name="motivo" rows="3" cols="60" required maxlength="255" placeholder="Ej. error de carga, duplicado"></textarea></label></p>';
            echo '<button type="submit" class="btn-danger">Anular recibo</button>';
            echo '</form></section>';
        } else {
            echo '<p class="err" style="margin-top:1rem">' . h($validacion['msg']) . '</p>';
        }
    }
}

layout_end();
