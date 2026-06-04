<?php
declare(strict_types=1);

require_once __DIR__ . '/FormasPago.php';
require_once __DIR__ . '/Cobranza.php';
require_once __DIR__ . '/InstitutoLogo.php';
require_once __DIR__ . '/PagoAnulacion.php';

/**
 * Carga datos de un recibo (pago_registrado) para vista o impresión.
 *
 * @return array{
 *   pago: array<string,mixed>,
 *   alumno: array<string,mixed>|null,
 *   lineas: list<array<string,mixed>>,
 *   ajustes: list<array<string,mixed>>,
 *   items: list<array<string,mixed>>,
 *   pendientes: list<array{concepto:string,periodo:string,importe:float}>
 * }|null
 */
function recibo_cargar_por_pago(PDO $pdo, int $pagoId, int $alumnoIdEsperado = 0): ?array
{
    if ($pagoId <= 0) {
        return null;
    }

    $stP = $pdo->prepare('SELECT * FROM pago_registrado WHERE id = ?');
    $stP->execute([$pagoId]);
    $pago = $stP->fetch(PDO::FETCH_ASSOC);
    if (!$pago) {
        return null;
    }

    $alumnoId = (int) ($pago['alumno_id'] ?? 0);
    if ($alumnoIdEsperado > 0 && $alumnoId !== $alumnoIdEsperado) {
        return null;
    }

    $stAl = $pdo->prepare(
        'SELECT id, nombre_completo, documento, codigo_legacy FROM alumnos WHERE id = ?'
    );
    $stAl->execute([$alumnoId]);
    $alumno = $stAl->fetch(PDO::FETCH_ASSOC) ?: null;

    $stL = $pdo->prepare(
        'SELECT pac.*, cm.anio, cm.mes
         FROM pago_aplica_cuota pac
         JOIN cuota_mensual cm ON cm.id = pac.cuota_id
         WHERE pac.pago_id = ?
         ORDER BY cm.anio, cm.mes'
    );
    $stL->execute([$pagoId]);
    $lineas = $stL->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    if (db_has_column($pdo, 'pago_item_articulo', 'importe_total')) {
        $stI = $pdo->prepare(
            'SELECT descripcion, cantidad, importe_unitario, importe_total
             FROM pago_item_articulo WHERE pago_id = ? ORDER BY id'
        );
        $stI->execute([$pagoId]);
        $items = $stI->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $ajustes = [];
    if (db_has_column($pdo, 'cc_ajuste_debe', 'debe')) {
        $stAdj = $pdo->prepare(
            'SELECT concepto, debe, fecha_mov, referencia FROM cc_ajuste_debe WHERE pago_id = ? ORDER BY id'
        );
        $stAdj->execute([$pagoId]);
        $ajustes = $stAdj->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $pendientes = [];
    foreach (cobranza_cuotas_pendientes_alumno($pdo, $alumnoId) as $cPend) {
        $saldoP = cobranza_saldo_impago_cuota($cPend);
        if ($saldoP > 0.005) {
            $pendientes[] = [
                'concepto' => 'Cuota mensual',
                'periodo' => sprintf('%04d-%02d', (int) $cPend['anio'], (int) $cPend['mes']),
                'importe' => $saldoP,
            ];
        }
    }
    foreach (cobranza_ajustes_debe_pendientes($pdo, $alumnoId) as $adjPend) {
        $presP = cobranza_debe_pendiente_presentacion($adjPend);
        $pendientes[] = [
            'concepto' => $presP['concepto'],
            'periodo' => '',
            'importe' => (float) ($adjPend['debe'] ?? 0),
        ];
    }

    return [
        'pago' => $pago,
        'alumno' => $alumno,
        'lineas' => $lineas,
        'ajustes' => $ajustes,
        'items' => $items,
        'pendientes' => $pendientes,
    ];
}

/**
 * @param array{
 *   pago: array<string,mixed>,
 *   alumno: array<string,mixed>|null,
 *   lineas: list<array<string,mixed>>,
 *   ajustes: list<array<string,mixed>>,
 *   items: list<array<string,mixed>>,
 *   pendientes?: list<array{concepto:string,periodo:string,importe:float}>
 * } $datos
 */
function recibo_render_html(
    PDO $pdo,
    array $datos,
    int $alumnoId,
    bool $hasFormasPago,
    bool $mostrarPendientesEnPantalla = true,
    bool $mostrarAcciones = true,
    bool $enPaginaCobro = false
): void {
    $pago = $datos['pago'];
    $alumno = $datos['alumno'];
    $lineasRecibo = $datos['lineas'];
    $ajustesRecibo = $datos['ajustes'];
    $itemsRecibo = $datos['items'];
    $pendientesPostRecibo = $datos['pendientes'] ?? [];

    $etiqMedio = $hasFormasPago ? formas_pago_etiqueta_cobro($pdo, $pago) : (string) ($pago['medio'] ?? '');
    $fechaRec = (string) $pago['fecha_pago'];
    $tsRec = strtotime($fechaRec);
    $fechaRecTxt = $tsRec !== false ? date('d/m/Y', $tsRec) : $fechaRec;

    echo '<section id="recibo" class="card cobro-card recibo-simple recibo-impresion">';
    echo '<header class="recibo-encabezado-impresion">';
    instituto_logo_render_html($pdo, 'instituto-logo-print instituto-logo-recibo');
    echo '<p class="recibo-titulo-principal">RECIBO PROVISORIO</p>';
    echo '<p class="recibo-numero">Nº ' . (int) $pago['id'] . '</p>';
    echo '</header>';
    if (pago_anulacion_schema_ok($pdo) && pago_esta_anulado($pago)) {
        echo '<p class="err recibo-anulado-banner" style="text-align:center;font-weight:bold;border:2px solid #c0392b;padding:0.5rem">'
            . 'RECIBO ANULADO</p>';
        $motAn = trim((string) ($pago['motivo_anulacion'] ?? ''));
        if ($motAn !== '') {
            echo '<p class="muted" style="text-align:center">Motivo: ' . h($motAn) . '</p>';
        }
    }
    if ($alumno) {
        echo '<p class="recibo-alumno"><strong>' . h((string) $alumno['nombre_completo']) . '</strong>';
        $doc = trim((string) ($alumno['documento'] ?? ''));
        if ($doc !== '') {
            echo ' · DNI ' . h($doc);
        }
        if (!empty($alumno['codigo_legacy'])) {
            echo ' · Cód. ' . h((string) $alumno['codigo_legacy']);
        }
        echo '</p>';
    }
    echo '<p class="recibo-meta">Fecha de pago: <strong>' . h($fechaRecTxt) . '</strong><br>';
    echo 'Forma de pago: <strong>' . h($etiqMedio) . '</strong>';
    $notaParcial = trim((string) ($pago['nota'] ?? ''));
    if (strcasecmp($notaParcial, 'Abono parcial') === 0) {
        echo '<br><span class="badge badge-warn">Abono parcial — consulte CC por saldo pendiente</span>';
    }
    if (!empty($pago['referencia_medio'])) {
        echo '<br>Referencia: <strong>' . h((string) $pago['referencia_medio']) . '</strong>';
    }
    echo '</p>';

    echo '<h3 class="recibo-subtitle">Detalle cobrado</h3>';
    echo '<table class="table recibo-tabla-simple"><thead><tr><th>Concepto</th><th class="num">Importe</th></tr></thead><tbody>';
    $filasDetalle = cobranza_pago_lineas_detalle_recibo($pago, $lineasRecibo, $ajustesRecibo, $itemsRecibo);
    foreach ($filasDetalle as $fila) {
        $imp = (float) $fila['importe'];
        $prefijo = $imp < -0.00001 ? '−$ ' : '$ ';
        $monto = number_format(abs($imp), 2, ',', '.');
        echo '<tr><td>' . h((string) $fila['concepto']) . '</td><td class="num">' . $prefijo
            . $monto . '</td></tr>';
    }
    echo '<tr class="recibo-total-row"><td><strong>Total cobrado</strong></td><td class="num"><strong>$ '
        . number_format((float) $pago['importe'], 2, ',', '.') . '</strong></td></tr>';
    echo '</tbody></table>';

    if ($mostrarPendientesEnPantalla) {
        if (count($pendientesPostRecibo) > 0) {
            echo '<div class="help-box recibo-pendiente-box recibo-no-imprimir">';
            echo '<h3>Sigue pendiente de cobro</h3>';
            echo '<p class="muted" style="margin:0 0 0.5rem">Este recibo <strong>no incluyó</strong> todo lo que el alumno adeuda. '
                . 'Para cobrar lo restante, volvé a <a href="registrar_cobro.php?alumno_id=' . $alumnoId
                . '">Registrar cobro</a> y marcá también estas líneas.</p>';
            echo '<ul class="recibo-pendiente-list">';
            foreach ($pendientesPostRecibo as $pp) {
                $txt = $pp['concepto'];
                if ($pp['periodo'] !== '') {
                    $txt = $pp['concepto'] . ' · ' . $pp['periodo'];
                }
                echo '<li><strong>' . h($txt) . '</strong> — $ '
                    . number_format((float) $pp['importe'], 2, ',', '.')
                    . ' (saldo base, sin recargos del día)</li>';
            }
            echo '</ul></div>';
        } else {
            echo '<p class="ok recibo-no-imprimir" style="margin-top:1rem">Con este cobro no quedan cuotas ni obligaciones pendientes en el sistema.</p>';
        }
    }

    if ($mostrarAcciones) {
        echo '<p class="recibo-actions no-print">';
        $lblImprimir = $enPaginaCobro ? 'Imprimir recibo' : 'Imprimir';
        echo '<button type="button" class="btn-secondary" onclick="window.print()">' . h($lblImprimir) . '</button>';
        echo ' <a class="btn-secondary" href="imprimir_recibo.php?alumno_id=' . $alumnoId
            . '&pago_id=' . (int) $pago['id'] . '" target="_blank" rel="noopener">Impresión directa</a>';
        echo ' <a class="btn-secondary" href="cuenta_corriente.php?alumno_id=' . $alumnoId . '">Cuenta corriente</a>';
        if (!$enPaginaCobro) {
            echo ' <a class="btn-secondary" href="registrar_cobro.php?alumno_id=' . $alumnoId
                . '&pago_id=' . (int) $pago['id'] . '#recibo">Ver en cobros</a>';
        }
        echo '</p>';
    }
    echo '</section>';
}
