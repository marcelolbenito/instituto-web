<?php
declare(strict_types=1);

/**
 * Carga manual de debe en cuenta corriente (sin cobro).
 * Usa cc_ajuste_debe con pago_id NULL hasta que se cobre en registrar_cobro o se anule.
 */
$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/Saldos.php';

$pdo = web_init($config);
$hasTabla = db_has_column($pdo, 'cc_ajuste_debe', 'debe');

$alumnoId = isset($_GET['alumno_id']) ? (int) $_GET['alumno_id'] : 0;
$buscar = trim((string) ($_GET['q'] ?? ''));
$fechaDefault = date('Y-m-d');

if ($hasTabla && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'add');
    $alumnoId = (int) ($_POST['alumno_id'] ?? 0);
    $anioRedirect = (int) date('Y');

    if ($action === 'del') {
        $adjId = (int) ($_POST['ajuste_id'] ?? 0);
        if ($adjId > 0) {
            $st = $pdo->prepare(
                "SELECT id, alumno_id FROM cc_ajuste_debe
                 WHERE id = ? AND pago_id IS NULL AND referencia LIKE 'AJUSTE:manual:%'"
            );
            $st->execute([$adjId]);
            $row = $st->fetch();
            if ($row) {
                $alumnoId = (int) $row['alumno_id'];
                $pdo->prepare('DELETE FROM cc_ajuste_debe WHERE id = ?')->execute([$adjId]);
                recalcular_saldo_alumnos($pdo, $alumnoId);
            }
        }
        header('Location: ajuste_debe.php?alumno_id=' . $alumnoId . '&ok=' . rawurlencode('Ajuste eliminado.'));
        exit;
    }

    $fechaMov = trim((string) ($_POST['fecha_mov'] ?? ''));
    $concepto = trim((string) ($_POST['concepto'] ?? ''));
    $referencia = trim((string) ($_POST['referencia'] ?? ''));
    $articuloId = isset($_POST['articulo_id']) && $_POST['articulo_id'] !== '' ? (int) $_POST['articulo_id'] : 0;
    $cantidad = max(0.0, (float) str_replace(',', '.', (string) ($_POST['cantidad'] ?? '1')));
    $importeManual = max(0.0, (float) str_replace(',', '.', (string) ($_POST['importe'] ?? '0')));

    if ($alumnoId <= 0) {
        header('Location: ajuste_debe.php?err=' . rawurlencode('Seleccioná un alumno.'));
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaMov)) {
        header('Location: ajuste_debe.php?alumno_id=' . $alumnoId . '&err=' . rawurlencode('Fecha inválida.'));
        exit;
    }

    $stAl = $pdo->prepare('SELECT id, activo FROM alumnos WHERE id = ?');
    $stAl->execute([$alumnoId]);
    $al = $stAl->fetch();
    if (!$al) {
        header('Location: ajuste_debe.php?err=' . rawurlencode('Alumno inexistente.'));
        exit;
    }

    if ($articuloId > 0) {
        $stArt = $pdo->prepare('SELECT detalle, importe_referencia FROM articulos WHERE id = ? AND activo = 1');
        $stArt->execute([$articuloId]);
        $art = $stArt->fetch();
        if (!$art) {
            header('Location: ajuste_debe.php?alumno_id=' . $alumnoId . '&err=' . rawurlencode('Artículo no válido.'));
            exit;
        }
        if ($concepto === '') {
            $concepto = (string) $art['detalle'];
        }
        if ($importeManual <= 0.00001) {
            $qty = $cantidad > 0.00001 ? $cantidad : 1.0;
            $importeManual = round((float) $art['importe_referencia'] * $qty, 2);
        }
    }

    if ($concepto === '') {
        header('Location: ajuste_debe.php?alumno_id=' . $alumnoId . '&err=' . rawurlencode('El concepto es obligatorio.'));
        exit;
    }
    if ($importeManual <= 0.00001) {
        header('Location: ajuste_debe.php?alumno_id=' . $alumnoId . '&err=' . rawurlencode('El importe debe ser mayor a cero.'));
        exit;
    }
    if (strlen($concepto) > 200) {
        $concepto = substr($concepto, 0, 200);
    }
    if ($referencia === '') {
        $referencia = 'AJUSTE:manual:' . date('YmdHis');
    } elseif (strlen($referencia) > 80) {
        $referencia = substr($referencia, 0, 80);
    }

    try {
        $ins = $pdo->prepare(
            'INSERT INTO cc_ajuste_debe (alumno_id, fecha_mov, concepto, debe, referencia, pago_id)
             VALUES (?, ?, ?, ?, ?, NULL)'
        );
        $ins->execute([$alumnoId, $fechaMov, $concepto, round($importeManual, 2), $referencia]);
        recalcular_saldo_alumnos($pdo, $alumnoId);
        header(
            'Location: ajuste_debe.php?alumno_id=' . $alumnoId
            . '&ok=' . rawurlencode('Debe registrado: $ ' . number_format($importeManual, 2, ',', '.'))
        );
        exit;
    } catch (Throwable $e) {
        header('Location: ajuste_debe.php?alumno_id=' . $alumnoId . '&err=' . rawurlencode($e->getMessage()));
        exit;
    }
}

$coincidencias = [];
if ($alumnoId <= 0 && $buscar !== '') {
    $like = '%' . $buscar . '%';
    $stBuscar = $pdo->prepare(
        'SELECT id, codigo_legacy, nombre_completo, documento
         FROM alumnos
         WHERE nombre_completo LIKE ?
            OR COALESCE(documento, \'\') LIKE ?
            OR CAST(COALESCE(codigo_legacy, 0) AS CHAR(20)) LIKE ?
         ORDER BY nombre_completo
         LIMIT 80'
    );
    $stBuscar->execute([$like, $like, $like]);
    $coincidencias = $stBuscar->fetchAll();
}

$alumno = null;
$ajustesPendientes = [];
$articulos = [];

if ($hasTabla) {
    $articulos = $pdo->query(
        "SELECT id, detalle, importe_referencia
         FROM articulos
         WHERE activo = 1
         ORDER BY detalle"
    )->fetchAll();
}

if ($alumnoId > 0 && $hasTabla) {
    $stAl = $pdo->prepare('SELECT id, nombre_completo, documento, activo, saldo_cc FROM alumnos WHERE id = ?');
    $stAl->execute([$alumnoId]);
    $alumno = $stAl->fetch();
    if ($alumno) {
        require_once dirname(__DIR__) . '/src/Cobranza.php';
        $ajustesPendientes = cobranza_ajustes_debe_pendientes($pdo, $alumnoId);
    }
}

layout_start($config, 'Ajuste de debe');
echo '<h1>Cargar debe manual</h1>';
echo '<p class="muted">Registra un <strong>debe</strong> en cuenta corriente sin cobrar todavía (matrícula, libro, otro concepto). '
    . 'Para cobrarlo: <a href="registrar_cobro.php">Recibos / Cobros</a> → elegir alumno → marcar en <strong>Debes manuales pendientes</strong> → calcular y registrar.</p>';

if (!$hasTabla) {
    flash_err('Falta la migración 22 (tabla cc_ajuste_debe).');
    echo '<p class="muted">Ejecutá <code>sql/migracion/22_cobro_items_y_contramovimiento_cc.sql</code>.</p>';
    layout_end();
    exit;
}

if (isset($_GET['ok'])) {
    flash_ok((string) $_GET['ok']);
}
if (isset($_GET['err'])) {
    flash_err((string) $_GET['err']);
}

echo '<form method="get" class="search-form">';
echo '<div class="search-title">Buscar alumno</div>';
echo '<div class="search-input-row">';
echo '<input name="q" value="' . h($buscar) . '" placeholder="Nombre, DNI o código legacy" required>';
echo '<button type="submit" class="search-submit">Buscar</button>';
echo '</div></form>';

if ($alumnoId <= 0 && $buscar !== '') {
    if (count($coincidencias) === 0) {
        echo '<p class="muted">Sin resultados.</p>';
    } else {
        echo '<table class="table js-data-table"><thead><tr><th>Alumno</th><th>DNI</th><th data-nosort="1"></th></tr></thead><tbody>';
        foreach ($coincidencias as $c) {
            echo '<tr><td>' . h((string) $c['nombre_completo']) . '</td><td>' . h((string) ($c['documento'] ?? '')) . '</td>';
            echo '<td><a href="ajuste_debe.php?alumno_id=' . (int) $c['id'] . '">Cargar debe</a></td></tr>';
        }
        echo '</tbody></table>';
    }
}

if ($alumno) {
    echo '<p class="current-student"><strong>' . h((string) $alumno['nombre_completo']) . '</strong>';
    if (!empty($alumno['documento'])) {
        echo ' · DNI ' . h((string) $alumno['documento']);
    }
    echo ' · Saldo ref. <strong>$ ' . number_format((float) ($alumno['saldo_cc'] ?? 0), 2, ',', '.') . '</strong>';
    echo ' <a href="cuenta_corriente.php?alumno_id=' . (int) $alumnoId . '">Ver cuenta corriente</a>';
    echo ' <a href="registrar_cobro.php?alumno_id=' . (int) $alumnoId . '">Cobrar</a></p>';

    echo '<section class="card"><h2 style="margin-top:0">Nuevo debe</h2>';
    echo '<form method="post" class="form form-grid">';
    echo '<input type="hidden" name="action" value="add">';
    echo '<input type="hidden" name="alumno_id" value="' . (int) $alumnoId . '">';
    echo '<label>Fecha del movimiento <input type="date" name="fecha_mov" required value="' . h($fechaDefault) . '"></label>';
    echo '<label>Artículo (opcional, completa concepto e importe) <select name="articulo_id">';
    echo '<option value="">— texto libre —</option>';
    foreach ($articulos as $art) {
        echo '<option value="' . (int) $art['id'] . '">' . h((string) $art['detalle'])
            . ' ($ ' . number_format((float) ($art['importe_referencia'] ?? 0), 2, ',', '.') . ')</option>';
    }
    echo '</select></label>';
    echo '<label>Cantidad <input type="number" name="cantidad" step="0.01" min="0" value="1"></label>';
    echo '<label>Concepto <input name="concepto" maxlength="200" placeholder="Ej. Matrícula 2026, Fotocopias…"></label>';
    echo '<label>Importe debe <input type="number" name="importe" step="0.01" min="0" placeholder="Si elige artículo, se calcula solo"></label>';
    echo '<label>Referencia (opcional) <input name="referencia" maxlength="80" placeholder="Se genera automática si queda vacío"></label>';
    echo '<div class="form-actions"><button type="submit">Registrar debe</button></div>';
    echo '</form></section>';

    echo '<section class="card"><h2 style="margin-top:0">Debes manuales pendientes de cobro</h2>';
    if (count($ajustesPendientes) === 0) {
        echo '<p class="muted">No hay ajustes manuales sin cobrar para este alumno.</p>';
    } else {
        echo '<table class="table js-data-table"><thead><tr><th>Fecha</th><th>Concepto</th><th>Debe</th><th>Referencia</th><th data-nosort="1"></th></tr></thead><tbody>';
        foreach ($ajustesPendientes as $adj) {
            $f = (string) ($adj['fecha_mov'] ?? '');
            $ts = strtotime($f);
            $fTxt = $ts !== false ? date('d/m/Y', $ts) : $f;
            echo '<tr>';
            echo '<td>' . h($fTxt) . '</td>';
            echo '<td>' . h((string) ($adj['concepto'] ?? '')) . '</td>';
            echo '<td>$ ' . number_format((float) ($adj['debe'] ?? 0), 2, ',', '.') . '</td>';
            echo '<td><code>' . h((string) ($adj['referencia'] ?? '')) . '</code></td>';
            echo '<td>';
            if (str_starts_with((string) ($adj['referencia'] ?? ''), 'AJUSTE:manual:')) {
                echo '<form method="post" style="display:inline" onsubmit="return confirm(\'¿Eliminar este debe?\');">';
                echo '<input type="hidden" name="action" value="del">';
                echo '<input type="hidden" name="alumno_id" value="' . (int) $alumnoId . '">';
                echo '<input type="hidden" name="ajuste_id" value="' . (int) $adj['id'] . '">';
                echo '<button type="submit" class="btn-link">Eliminar</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</section>';
}

layout_end();
