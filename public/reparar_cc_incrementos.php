<?php
declare(strict_types=1);

/**
 * Reparación en producción: contramovimientos CC faltantes (RECIBO_INC / RECIBO_DEC) y recálculo de saldos.
 * Solo administrador. Equivalente a: php tools/reparar_cc_incrementos_cobro.php [alumno_id]
 */
$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/Cobranza.php';
require_once dirname(__DIR__) . '/src/Saldos.php';
require_once dirname(__DIR__) . '/src/Auth.php';

$pdo = web_init($config);
auth_require_admin();

$hasCcAjuste = db_has_column($pdo, 'cc_ajuste_debe', 'debe');
$hasPacDetalle = db_has_column($pdo, 'pago_aplica_cuota', 'importe_recargo');
$reporte = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirm = (string) ($_POST['confirmar'] ?? '') === '1';
    $alumnoIdRaw = trim((string) ($_POST['alumno_id'] ?? ''));
    $alumnoId = $alumnoIdRaw === '' ? null : (int) $alumnoIdRaw;

    if (!$confirm) {
        $reporte = ['ok' => false, 'msg' => 'Marcá la casilla de confirmación para ejecutar.'];
    } elseif (!$hasCcAjuste || !$hasPacDetalle) {
        $reporte = ['ok' => false, 'msg' => 'Faltan tablas/columnas (migraciones 16 y 22).'];
    } elseif ($alumnoId !== null && $alumnoId <= 0) {
        $reporte = ['ok' => false, 'msg' => 'ID de alumno inválido (dejá vacío para todos).'];
    } elseif ($alumnoId !== null) {
        $stAl = $pdo->prepare('SELECT id, nombre_completo FROM alumnos WHERE id = ?');
        $stAl->execute([$alumnoId]);
        $alRow = $stAl->fetch(PDO::FETCH_ASSOC);
        if (!$alRow) {
            $reporte = ['ok' => false, 'msg' => 'No existe un alumno con ese ID.'];
        } else {
            $res = cobranza_backfill_incrementos_cc_desde_pagos($pdo, $alumnoId);
            $nSaldo = recalcular_saldo_alumnos($pdo, $alumnoId);
            $reporte = [
                'ok' => true,
                'creados' => (int) ($res['creados'] ?? 0),
                'omitidos' => (int) ($res['omitidos'] ?? 0),
                'saldos' => $nSaldo,
                'alumno_id' => $alumnoId,
                'alumno_nombre' => (string) ($alRow['nombre_completo'] ?? ''),
            ];
        }
    } else {
        $res = cobranza_backfill_incrementos_cc_desde_pagos($pdo, null);
        $nSaldo = recalcular_saldo_alumnos($pdo, null);
        $reporte = [
            'ok' => true,
            'creados' => (int) ($res['creados'] ?? 0),
            'omitidos' => (int) ($res['omitidos'] ?? 0),
            'saldos' => $nSaldo,
            'alumno_id' => null,
        ];
    }
}

layout_start($config, 'Reparar cuenta corriente');
echo '<h1>Reparar contramovimientos en cuenta corriente</h1>';
echo '<p class="muted">Crea en <code>cc_ajuste_debe</code> los movimientos faltantes vinculados a cobros ya registrados:</p>';
echo '<ul class="muted">';
echo '<li><strong>RECIBO_INC</strong> — mora, beca fuera de término, recargo por forma de pago (debe positivo)</li>';
echo '<li><strong>RECIBO_DEC</strong> — descuento pronto pago y descuento por medio (debe negativo → haber en CC)</li>';
echo '</ul>';
echo '<p class="muted">Sin estos contramovimientos, el haber del pago no cuadra con la cuota y el saldo queda desbalanceado. '
    . 'Los cobros <strong>nuevos</strong> ya los generan al confirmar el recibo; esta herramienta es para <strong>reparar históricos</strong>.</p>';

if (!$hasCcAjuste || !$hasPacDetalle) {
    echo '<p class="err">Requiere migraciones <code>16_pago_aplica_cuota_detalle.sql</code> y <code>22_cobro_items_y_contramovimiento_cc.sql</code>.</p>';
}

if ($reporte !== null) {
    if (!empty($reporte['ok'])) {
        $titulo = (int) ($reporte['creados'] ?? 0) > 0
            ? 'Reparación completada: se crearon ' . (int) $reporte['creados'] . ' movimiento(s).'
            : 'Reparación completada: no había movimientos faltantes (o ya existían).';
        flash_ok($titulo);
        echo '<section class="card">';
        echo '<h2 style="margin-top:0">Resultado</h2>';
        if (!empty($reporte['alumno_id'])) {
            echo '<p class="muted">Alumno: <strong>' . h((string) ($reporte['alumno_nombre'] ?? ''))
                . '</strong> (ID ' . (int) $reporte['alumno_id'] . ')</p>';
        } else {
            echo '<p class="muted">Ámbito: <strong>todos los alumnos</strong></p>';
        }
        echo '<ul>';
        echo '<li>Movimientos creados: <strong>' . (int) ($reporte['creados'] ?? 0) . '</strong></li>';
        echo '<li>Ya existían (omitidos): <strong>' . (int) ($reporte['omitidos'] ?? 0) . '</strong></li>';
        echo '<li>Saldos recalculados (filas): <strong>' . (int) ($reporte['saldos'] ?? 0) . '</strong></li>';
        echo '</ul>';
        if (!empty($reporte['alumno_id'])) {
            echo '<p><a href="cuenta_corriente.php?alumno_id=' . (int) $reporte['alumno_id']
                . '">Ver cuenta corriente del alumno</a></p>';
        }
        echo '</section>';
    } else {
        flash_err($reporte['msg'] ?? 'Error');
    }
}

echo '<form method="post" class="form form-grid" style="max-width:36rem">';
echo '<label>Alumno (opcional) <input type="number" name="alumno_id" min="1" step="1" placeholder="Vacío = todos"></label>';
echo '<p class="muted" style="margin:-0.25rem 0 0.5rem">ID interno del alumno (<code>alumnos.id</code>), no el código legacy. '
    . 'Probá primero con un alumno antes de ejecutar para todos.</p>';
echo '<label class="check"><input type="checkbox" name="confirmar" value="1" required> '
    . 'Confirmo: quiero crear contramovimientos faltantes y recalcular saldos</label>';
echo '<div class="form-actions"><button type="submit">Ejecutar reparación</button></div>';
echo '</form>';

echo '<p><a href="index.php">Inicio</a></p>';

layout_end();
