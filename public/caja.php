<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/Caja.php';

$pdo = web_init($config);
$cajaOk = caja_schema_ok($pdo);
$tienePagoId = caja_tiene_pago_id($pdo);

$fecha = trim((string) ($_GET['fecha'] ?? date('Y-m-d')));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) !== 1) {
    $fecha = date('Y-m-d');
}

$msgOk = isset($_GET['ok']) ? (string) $_GET['ok'] : '';
$msgErr = isset($_GET['err']) ? (string) $_GET['err'] : '';

if ($cajaOk && isset($_GET['sync'])) {
    $n = caja_sincronizar_cobros_fecha($pdo, $fecha);
    header('Location: caja.php?fecha=' . rawurlencode($fecha) . '&ok=' . rawurlencode(
        $n > 0 ? "Se incorporaron {$n} cobro(s) a la lista de caja." : 'No había cobros del día sin movimiento en caja.'
    ));
    exit;
}

if ($cajaOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'manual'));
    $fechaPost = trim((string) ($_POST['fecha'] ?? $fecha));

    if ($action === 'cerrar') {
        $obsCierre = trim((string) ($_POST['observaciones_cierre'] ?? ''));
        $contado = caja_arqueo_schema_ok($pdo) ? caja_arqueo_contado_desde_post($_POST) : [];
        $res = caja_cerrar_dia($pdo, $fechaPost, $obsCierre, $contado);
        if ($res['ok']) {
            $msgCierre = 'Caja cerrada correctamente.';
            $dif = $res['arqueo_diferencia'] ?? null;
            if ($dif !== null && abs($dif) > 0.02) {
                $msgCierre .= ' Diferencia de arqueo: $ ' . number_format($dif, 2, ',', '.') . '.';
            } elseif ($dif !== null) {
                $msgCierre .= ' Arqueo: coincide con lo registrado.';
            }
            header('Location: caja.php?fecha=' . rawurlencode($fechaPost) . '&ok=' . rawurlencode($msgCierre));
            exit;
        }
        header('Location: caja.php?fecha=' . rawurlencode($fechaPost) . '&err=' . rawurlencode((string) ($res['msg'] ?? 'No se pudo cerrar.')));
        exit;
    }

    $tipo = strtolower(trim((string) ($_POST['tipo'] ?? '')));
    $importe = max(0.0, (float) str_replace(',', '.', (string) ($_POST['importe'] ?? '0')));
    $medio = trim((string) ($_POST['medio'] ?? 'efectivo'));
    $obs = trim((string) ($_POST['observaciones'] ?? ''));
    $alumnoId = isset($_POST['alumno_id']) && $_POST['alumno_id'] !== '' ? (int) $_POST['alumno_id'] : null;

    $res = caja_registrar_manual($pdo, $fechaPost, $tipo, $importe, $medio, $obs, $alumnoId);
    if ($res['ok']) {
        header('Location: caja.php?fecha=' . rawurlencode($fechaPost) . '&ok=' . rawurlencode('Movimiento registrado.'));
        exit;
    }
    header('Location: caja.php?fecha=' . rawurlencode($fechaPost) . '&err=' . rawurlencode((string) ($res['msg'] ?? 'Error.')));
    exit;
}

$synced = 0;
if ($cajaOk) {
    $synced = caja_sincronizar_cobros_fecha($pdo, $fecha);
    if ($synced > 0 && $msgOk === '') {
        $msgOk = 'Se agregaron ' . $synced . ' cobro(s) que faltaban en caja para esta fecha.';
    }
}

$resumen = $cajaOk ? caja_resumen_dia($pdo, $fecha) : ['ingresos' => 0.0, 'egresos' => 0.0, 'saldo' => 0.0, 'cantidad' => 0];
$movimientos = $cajaOk ? caja_listar_dia($pdo, $fecha) : [];
$cierreDia = $cajaOk && caja_cierre_schema_ok($pdo) ? caja_obtener_cierre($pdo, $fecha) : null;
$estaCerrada = $cierreDia !== null;
$resumenPorMedio = $cajaOk && !$estaCerrada ? caja_resumen_por_medio($pdo, $fecha) : [];
$arqueoGuardado = $cierreDia !== null ? caja_decodificar_arqueo($cierreDia) : null;
$tieneArqueo = caja_arqueo_schema_ok($pdo);

$tsF = strtotime($fecha);
$fechaTxt = $tsF !== false ? date('d/m/Y', $tsF) : $fecha;
$fechaAnt = date('Y-m-d', strtotime($fecha . ' -1 day'));
$fechaSig = date('Y-m-d', strtotime($fecha . ' +1 day'));

layout_start($config, 'Caja del día');
echo '<h1>Caja del día · ' . h($fechaTxt) . '</h1>';
echo '<p class="muted">Todo en esta pantalla: movimientos del día, cobros, cierre e impresión. '
    . 'Los cobros suman según la <strong>fecha del recibo</strong>.</p>';

if (!$cajaOk) {
    echo '<p class="err">Falta la tabla <code>caja_movimiento</code>. Ejecutá <code>sql/migracion/04_schema_facturacion.sql</code>.</p>';
    layout_end();
    return;
}
if (!$tienePagoId) {
    echo '<p class="help-box">Opcional: ejecutá <code>sql/migracion/27_caja_pago_id.sql</code> (o <code>27_caja_pago_id_compat.sql</code> en MySQL antiguo) '
        . 'para vincular cada cobro con un solo movimiento de caja.</p>';
}
if ($msgOk !== '') {
    echo '<p class="ok flash">' . h($msgOk) . '</p>';
}
if ($msgErr !== '') {
    echo '<p class="err flash">' . h($msgErr) . '</p>';
}

echo '<form method="get" class="search-form" style="max-width:28rem">';
echo '<div class="search-title">Fecha</div>';
echo '<div class="search-input-row">';
echo '<input type="date" name="fecha" value="' . h($fecha) . '" required>';
echo '<button type="submit" class="search-submit">Ver</button>';
echo '</div>';
echo '<p class="muted" style="margin:0.35rem 0 0">';
echo '<a href="caja.php?fecha=' . h($fechaAnt) . '">← Día anterior</a> · ';
echo '<a href="caja.php?fecha=' . h(date('Y-m-d')) . '">Hoy</a> · ';
echo '<a href="caja.php?fecha=' . h($fechaSig) . '">Día siguiente →</a> · ';
echo '<a href="caja.php?fecha=' . h($fecha) . '&sync=1" title="Solo si un cobro ya registrado no aparece abajo">Incorporar cobros faltantes</a>';
echo '</p></form>';

echo '<section class="dashboard-grid">';
echo '<article class="kpi"><div class="kpi-label">Ingresos ' . h($fechaTxt) . '</div><div class="kpi-value">$ '
    . number_format($resumen['ingresos'], 2, ',', '.') . '</div></article>';
echo '<article class="kpi"><div class="kpi-label">Egresos</div><div class="kpi-value">$ '
    . number_format($resumen['egresos'], 2, ',', '.') . '</div></article>';
echo '<article class="kpi"><div class="kpi-label">Saldo del día</div><div class="kpi-value">$ '
    . number_format($resumen['saldo'], 2, ',', '.') . '</div>';
echo '<div class="kpi-label">' . (int) $resumen['cantidad'] . ' movimiento(s)</div></article>';
$estadoCajaClass = $estaCerrada ? 'caja-estado--cerrada' : 'caja-estado--abierta';
$estadoCajaTxt = $estaCerrada ? 'Cerrada' : 'Abierta';
echo '<article class="kpi kpi-estado"><div class="kpi-label">Estado</div>';
echo '<div class="caja-estado ' . h($estadoCajaClass) . '">' . h($estadoCajaTxt) . '</div></article>';
echo '</section>';

echo '<section class="card caja-op-card">';
echo '<div class="toolbar caja-op-head">';
echo '<div>';
echo '<h2>Operación del día</h2>';
if ($estaCerrada && $cierreDia !== null) {
    $tsCierre = strtotime((string) $cierreDia['cerrado_en']);
    $txtCierre = $tsCierre !== false ? date('d/m/Y H:i', $tsCierre) : '';
    echo '<p class="muted caja-op-lead">Cerrada el ' . h($txtCierre) . ' · saldo al cierre $ '
        . number_format((float) $cierreDia['saldo'], 2, ',', '.') . '</p>';
} else {
    echo '<p class="muted caja-op-lead">Los cobros que registrás impactan esta caja al instante (fecha recibo <strong>'
        . h($fechaTxt) . '</strong>). Al finalizar, arqueo y cierre al pie de la página.</p>';
}
echo '</div>';
echo '<span class="caja-badge ' . ($estaCerrada ? 'caja-badge--cerrada' : 'caja-badge--abierta') . '">'
    . ($estaCerrada ? 'Cerrada' : 'Abierta') . '</span>';
echo '</div>';

if (!caja_cierre_schema_ok($pdo)) {
    echo '<p class="err">Para cerrar la caja ejecutá <code>sql/migracion/28_caja_cierre_compat.sql</code> en la base.</p>';
}

if ($estaCerrada && $cierreDia !== null) {
    if (!empty($cierreDia['observaciones'])) {
        echo '<p class="muted">' . h((string) $cierreDia['observaciones']) . '</p>';
    }
    echo '<div class="quick-actions caja-quick-actions">';
    echo '<a class="qa-item" href="imprimir_caja_cierre.php?fecha=' . h($fecha) . '" target="_blank" rel="noopener">';
    echo '<span class="qa-icon">🖨️</span><span class="qa-label">Imprimir cierre</span></a>';
    echo '<a class="qa-item" href="caja_cierres.php"><span class="qa-icon">📒</span><span class="qa-label">Historial</span></a>';
    echo '</div>';
    echo '<p class="muted caja-op-extra"><a href="caja.php?fecha=' . h($fecha) . '&sync=1">Incorporar cobros faltantes</a>'
        . ' <span class="caja-op-extra-hint">(solo si un recibo no figura en movimientos)</span></p>';
} else {
    echo '<div class="quick-actions caja-quick-actions">';
    echo '<a class="qa-item qa-item--primary" href="registrar_cobro.php?desde_caja_fecha=' . h($fecha) . '">';
    echo '<span class="qa-icon">💵</span><span class="qa-label">Registrar cobro</span></a>';
    echo '<a class="qa-item" href="caja_cierres.php"><span class="qa-icon">📒</span><span class="qa-label">Historial de cierres</span></a>';
    echo '</div>';
    echo '<p class="muted caja-op-extra">¿Cobraste y no aparece abajo? '
        . '<a href="caja.php?fecha=' . h($fecha) . '&sync=1">Incorporar cobros faltantes de este día</a>'
        . ' <span class="caja-op-extra-hint">(no reemplaza registrar un cobro nuevo)</span></p>';
}

echo '</section>';

echo '<h2>Movimientos del ' . h($fechaTxt) . '</h2>';
if (count($movimientos) === 0) {
    echo '<p class="muted">Sin movimientos en esta fecha.</p>';
} else {
    echo '<table class="table js-data-table">';
    echo '<thead><tr><th>Hora</th><th>Tipo</th><th>Medio</th><th>Concepto</th><th>Alumno</th><th>Importe</th><th data-nosort="1"></th></tr></thead><tbody>';
    foreach ($movimientos as $m) {
        $hora = '';
        $ts = strtotime((string) ($m['fecha_hora'] ?? ''));
        if ($ts !== false) {
            $hora = date('H:i', $ts);
        }
        $tipo = (string) ($m['tipo'] ?? '');
        $esIng = $tipo === 'ingreso';
        $imp = (float) ($m['importe'] ?? 0);
        $pid = isset($m['pago_id']) ? (int) $m['pago_id'] : 0;
        $aid = (int) ($m['alumno_id_link'] ?? 0);
        $alNom = trim((string) ($m['alumno_nombre'] ?? ''));
        $recAnulado = trim((string) ($m['anulado_en'] ?? '')) !== ''
            && ($m['tipo'] ?? '') === 'ingreso'
            && (int) ($m['pago_id'] ?? 0) > 0;
        echo '<tr' . ($recAnulado ? ' class="muted"' : '') . '>';
        echo '<td>' . h($hora) . '</td>';
        echo '<td>' . h($esIng ? 'Ingreso' : 'Egreso') . '</td>';
        echo '<td>' . h((string) ($m['medio'] ?? '')) . '</td>';
        echo '<td>' . h(caja_observacion_mostrar((string) ($m['observaciones'] ?? '')));
        if ($recAnulado) {
            echo ' <span class="err" title="Recibo anulado: no suma en totales">(anulado)</span>';
        }
        echo '</td>';
        echo '<td>' . h($alNom) . '</td>';
        echo '<td class="num">' . ($esIng ? '' : '−') . '$ ' . number_format($imp, 2, ',', '.') . '</td>';
        echo '<td class="nowrap">';
        if ($pid > 0 && $aid > 0) {
            echo '<a class="action-icon" href="imprimir_recibo.php?alumno_id=' . $aid . '&pago_id=' . $pid
                . '" target="_blank" title="Recibo">🧾</a>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';
}

if (!$estaCerrada) {
    echo '<h2>Cargar movimiento manual</h2>';
    echo '<form method="post" class="form form-grid" style="max-width:36rem">';
    echo '<input type="hidden" name="action" value="manual">';
    echo '<input type="hidden" name="fecha" value="' . h($fecha) . '">';
echo '<label>Tipo <select name="tipo" required>';
echo '<option value="ingreso">Ingreso</option>';
echo '<option value="egreso">Egreso</option>';
echo '</select></label>';
echo '<label>Importe <input name="importe" type="text" inputmode="decimal" required placeholder="0,00"></label>';
echo '<label>Medio <select name="medio">';
foreach (['efectivo', 'transferencia', 'tarjeta', 'cheque', 'otro'] as $med) {
    echo '<option value="' . h($med) . '">' . h(ucfirst($med)) . '</option>';
}
echo '</select></label>';
echo '<label>Concepto / observación <input name="observaciones" required maxlength="255" placeholder="Ej.: retiro a banco, gasto oficina"></label>';
echo '<label>Alumno (opcional) <input name="alumno_id" type="number" min="1" placeholder="Id interno"></label>';
echo '<div class="form-actions"><button type="submit">Registrar movimiento</button></div>';
    echo '</form>';
} else {
    echo '<p class="muted">Movimientos manuales deshabilitados: el día está cerrado.</p>';
}

echo '<section class="card caja-cierre-card" id="caja-cierre-arqueo">';
echo '<h2>Cierre y arqueo · ' . h($fechaTxt) . '</h2>';

if ($estaCerrada && $cierreDia !== null) {
    echo '<p class="muted">Este día ya está cerrado. Totales congelados al cierre.</p>';
    if ($arqueoGuardado !== null && !empty($arqueoGuardado['medios'])) {
        $hayArqueoFilas = false;
        foreach ($arqueoGuardado['medios'] as $lin) {
            if (($lin['contado'] ?? null) !== null) {
                $hayArqueoFilas = true;
                break;
            }
        }
        if ($hayArqueoFilas) {
            echo '<h3 class="caja-arqueo-titulo">Arqueo al cierre</h3>';
            echo '<table class="table caja-arqueo-table"><thead><tr>';
            echo '<th>Medio</th><th class="num">Según sistema</th><th class="num">Contado</th><th class="num">Diferencia</th>';
            echo '</tr></thead><tbody>';
            foreach ($arqueoGuardado['medios'] as $slug => $lin) {
                $cont = $lin['contado'] ?? null;
                if ($cont === null) {
                    continue;
                }
                $dif = $lin['diferencia'] ?? null;
                $difClass = $dif !== null && abs((float) $dif) > 0.02 ? 'caja-arqueo-diff--warn' : 'caja-arqueo-diff--ok';
                echo '<tr><td>' . h((string) ($lin['label'] ?? caja_label_medio((string) $slug))) . '</td>';
                echo '<td class="num">$ ' . number_format((float) ($lin['esperado'] ?? 0), 2, ',', '.') . '</td>';
                echo '<td class="num">$ ' . number_format((float) $cont, 2, ',', '.') . '</td>';
                echo '<td class="num ' . h($difClass) . '">$ ' . number_format((float) ($dif ?? 0), 2, ',', '.') . '</td></tr>';
            }
            echo '</tbody></table>';
            if (isset($arqueoGuardado['diferencia_total']) && $arqueoGuardado['diferencia_total'] !== null) {
                $dt = (float) $arqueoGuardado['diferencia_total'];
                $cls = abs($dt) > 0.02 ? 'err' : 'ok';
                echo '<p class="' . h($cls) . '"><strong>Diferencia total del arqueo:</strong> $ '
                    . number_format($dt, 2, ',', '.') . '</p>';
            }
        }
    }
    echo '<p class="form-actions" style="margin-top:0.75rem">';
    echo '<a class="btn-secondary" href="imprimir_caja_cierre.php?fecha=' . h($fecha) . '" target="_blank" rel="noopener">🖨️ Imprimir cierre</a>';
    echo '</p>';
    $difSaldo = abs((float) $cierreDia['saldo'] - $resumen['saldo']) > 0.02;
    $difCant = (int) $cierreDia['cantidad_movimientos'] !== (int) $resumen['cantidad'];
    if ($difSaldo || $difCant) {
        echo '<p class="err">Hay cobros o movimientos posteriores al cierre. Totales actuales: $ '
            . number_format($resumen['saldo'], 2, ',', '.') . '.</p>';
    }
} elseif (caja_cierre_schema_ok($pdo)) {
    echo '<p class="muted caja-arqueo-help">Revisá la tabla de movimientos. Luego compará lo registrado con lo que tenés en caja, banco y vouchers.</p>';
    echo '<form method="post" class="form caja-cerrar-block" id="form-cerrar-caja">';
    echo '<input type="hidden" name="action" value="cerrar">';
    echo '<input type="hidden" name="fecha" value="' . h($fecha) . '">';
    if ($tieneArqueo) {
        echo '<h3 class="caja-arqueo-titulo">Arqueo: sistema vs. realidad</h3>';
        echo '<table class="table caja-arqueo-table"><thead><tr>';
        echo '<th>Medio</th><th class="num">Según sistema</th><th class="num">Lo contado</th><th class="num">Diferencia</th>';
        echo '</tr></thead><tbody>';
        foreach (caja_medios_catalogo() as $slug => $label) {
            $pm = $resumenPorMedio[$slug] ?? ['ingresos' => 0.0, 'egresos' => 0.0, 'neto' => 0.0];
            $neto = (float) $pm['neto'];
            echo '<tr data-medio="' . h($slug) . '" data-esperado="' . h((string) $neto) . '">';
            echo '<td><strong>' . h($label) . '</strong>';
            if ($pm['ingresos'] > 0 || $pm['egresos'] > 0) {
                echo '<br><span class="muted" style="font-size:0.82rem">+ $ '
                    . number_format((float) $pm['ingresos'], 2, ',', '.')
                    . ' · − $ ' . number_format((float) $pm['egresos'], 2, ',', '.') . '</span>';
            }
            echo '</td>';
            echo '<td class="num caja-arqueo-esperado">$ ' . number_format($neto, 2, ',', '.') . '</td>';
            echo '<td class="num"><input type="text" inputmode="decimal" name="contado_' . h($slug)
                . '" class="caja-arqueo-input" placeholder="0,00" autocomplete="off"></td>';
            echo '<td class="num caja-arqueo-diff-cell"><span class="caja-arqueo-diff">—</span></td>';
            echo '</tr>';
        }
        echo '</tbody><tfoot><tr><th>Total</th>';
        echo '<td class="num" id="caja-arqueo-total-esperado">$ '
            . number_format($resumen['saldo'], 2, ',', '.') . '</td>';
        echo '<td class="num" id="caja-arqueo-total-contado">—</td>';
        echo '<td class="num" id="caja-arqueo-total-diff">—</td></tr></tfoot></table>';
    } else {
        echo '<p class="help-box">Para arqueo al cierre ejecutá <code>sql/migracion/29_caja_arqueo_compat.sql</code>.</p>';
    }
    echo '<div class="caja-cerrar-grid">';
    echo '<label>Observaciones del cierre (opcional) ';
    echo '<input type="text" name="observaciones_cierre" maxlength="500" placeholder="Ej.: faltante $ 50 en efectivo, revisar mañana">';
    echo '</label>';
    echo '<div class="form-actions caja-cerrar-actions">';
    $confirmCierre = '¿Cerrar la caja del ' . $fechaTxt
        . '? Se guardan los totales del sistema';
    if ($tieneArqueo) {
        $confirmCierre .= ' y el arqueo que cargaste';
    }
    $confirmCierre .= '. No podrás cargar movimientos manuales en esta fecha.';
    echo '<button type="submit" class="caja-btn-cerrar" onclick="return confirm(' . json_encode($confirmCierre, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) . ');">';
    echo '<span class="caja-btn-cerrar-icon" aria-hidden="true">🔒</span>';
    echo '<span>Cerrar caja del día</span></button>';
    echo '</div></div></form>';
    if ($tieneArqueo) {
        echo '<script>(function(){function parseN(v){if(!v||!String(v).trim())return null;var s=String(v).trim().replace(/\\./g,"").replace(",",".");var n=parseFloat(s);return isNaN(n)?null:n;}function fmt(n){return n===null?"—":"$ "+n.toLocaleString("es-AR",{minimumFractionDigits:2,maximumFractionDigits:2});}function recalc(){var tE=0,tC=0,tD=0,hasC=false;document.querySelectorAll("#form-cerrar-caja tbody tr[data-esperado]").forEach(function(tr){var esp=parseFloat(tr.getAttribute("data-esperado"))||0;var inp=tr.querySelector(".caja-arqueo-input");var cont=parseN(inp&&inp.value);var cell=tr.querySelector(".caja-arqueo-diff");tE+=esp;if(cont!==null){hasC=true;tC+=cont;var d=cont-esp;tD+=d;if(cell){cell.textContent=fmt(d);cell.className="caja-arqueo-diff "+(Math.abs(d)>0.02?"caja-arqueo-diff--warn":"caja-arqueo-diff--ok");}}else if(cell){cell.textContent="—";cell.className="caja-arqueo-diff";}});var te=document.getElementById("caja-arqueo-total-esperado");if(te)te.textContent=fmt(tE);var tc=document.getElementById("caja-arqueo-total-contado");if(tc)tc.textContent=hasC?fmt(tC):"—";var td=document.getElementById("caja-arqueo-total-diff");if(td){td.textContent=hasC?fmt(tD):"—";td.className=hasC&&Math.abs(tD)>0.02?"num caja-arqueo-diff--warn":"num";}}document.querySelectorAll(".caja-arqueo-input").forEach(function(inp){inp.addEventListener("input",recalc);inp.addEventListener("change",recalc);});})();</script>';
    }
} else {
    echo '<p class="err">Para cerrar la caja ejecutá <code>sql/migracion/28_caja_cierre_compat.sql</code> en la base.</p>';
}

echo '</section>';

echo '<p class="muted"><a href="index.php">Inicio</a> · <a href="cuenta_corriente.php">Cuenta corriente</a></p>';

layout_end();
