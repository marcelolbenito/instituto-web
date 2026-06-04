<?php
declare(strict_types=1);

/**
 * P-02: conceptos por alumno (ex ABONCLIE).
 * Flujo: desde Alumnos → enlace "Conceptos" → se elige un alumno y se marcan artículos.
 */
$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';

$pdo = web_init($config);
$alumnoId = isset($_GET['alumno_id']) ? (int) $_GET['alumno_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $alumnoId = (int) ($_POST['alumno_id'] ?? 0);
    $seleccion = $_POST['articulo_id'] ?? [];
    if (!is_array($seleccion)) {
        $seleccion = [];
    }
    $ids = array_values(array_unique(array_map('intval', $seleccion)));
    $ids = array_filter($ids, fn (int $i) => $i > 0);

    if ($alumnoId <= 0) {
        header('Location: conceptos_alumno.php?err=' . rawurlencode('Alumno no válido.'));
        exit;
    }

    $st = $pdo->prepare('SELECT id, activo FROM alumnos WHERE id = ?');
    $st->execute([$alumnoId]);
    $al = $st->fetch();
    if (!$al) {
        header('Location: conceptos_alumno.php?err=' . rawurlencode('Alumno inexistente.'));
        exit;
    }
    if ((int) ($al['activo'] ?? 0) !== 1) {
        header('Location: conceptos_alumno.php?alumno_id=' . $alumnoId . '&err=' . rawurlencode('Alumno inactivo: no se pueden modificar conceptos.'));
        exit;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM alumno_articulo WHERE alumno_id = ?')->execute([$alumnoId]);
        $ins = $pdo->prepare('INSERT INTO alumno_articulo (alumno_id, articulo_id) VALUES (?, ?)');
        foreach ($ids as $artId) {
            $chk = $pdo->prepare('SELECT id FROM articulos WHERE id = ? AND activo = 1');
            $chk->execute([$artId]);
            if ($chk->fetch()) {
                $ins->execute([$alumnoId, $artId]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        header('Location: conceptos_alumno.php?alumno_id=' . $alumnoId . '&err=' . rawurlencode($e->getMessage()));
        exit;
    }
    header('Location: conceptos_alumno.php?alumno_id=' . $alumnoId . '&ok=1');
    exit;
}

layout_start($config, 'Conceptos por alumno');
if (isset($_GET['ok'])) {
    flash_ok('Conceptos guardados.');
}
if (isset($_GET['err'])) {
    flash_err((string) $_GET['err']);
}

echo '<h1>Conceptos por alumno</h1>';
echo '<p class="muted">Indica qué artículos/conceptos de cobranza aplican a cada alumno (equivalente a ABONCLIE en el sistema anterior).</p>';

if ($alumnoId <= 0) {
    echo '<p>Elegí un alumno desde el <a href="alumnos.php">listado de alumnos</a> y usá el enlace <strong>Conceptos</strong>.</p>';
    layout_end();
    exit;
}

$st = $pdo->prepare('SELECT id, nombre_completo, codigo_legacy, activo FROM alumnos WHERE id = ?');
$st->execute([$alumnoId]);
$alumno = $st->fetch();
if (!$alumno) {
    flash_err('No existe ese alumno.');
    echo '<p><a href="alumnos.php">Volver al listado</a></p>';
    layout_end();
    exit;
}

$articulos = $pdo->query(
    'SELECT id, codigo_legacy, detalle, importe_referencia FROM articulos WHERE activo = 1 ORDER BY detalle'
)->fetchAll();

$st = $pdo->prepare('SELECT articulo_id FROM alumno_articulo WHERE alumno_id = ?');
$st->execute([$alumnoId]);
$asignados = [];
while ($row = $st->fetch()) {
    $asignados[(int) $row['articulo_id']] = true;
}

$puedeEditar = (int) ($alumno['activo'] ?? 0) === 1;
echo '<p><strong>Alumno:</strong> ' . h($alumno['nombre_completo'])
    . ' <span class="muted">(id ' . (int) $alumno['id']
    . ($alumno['codigo_legacy'] !== null && $alumno['codigo_legacy'] !== '' ? ', legacy ' . h((string) $alumno['codigo_legacy']) : '')
    . ')</span> · ';
if ($puedeEditar) {
    echo '<a href="alumnos.php?id=' . (int) $alumno['id'] . '">Ficha del alumno</a> · ';
} else {
    echo '<span class="muted">Ficha no editable (inactivo)</span> · ';
}
echo '<a href="alumnos.php">Listado</a></p>';
if (!$puedeEditar) {
    echo '<p class="err">Este alumno está <strong>inactivo</strong>: solo consulta. No se pueden asignar conceptos.</p>';
}

if (count($articulos) === 0) {
    echo '<p class="err">No hay artículos activos. Cargá artículos en <a href="articulos.php">Artículos</a>.</p>';
    layout_end();
    exit;
}

if ($puedeEditar) {
    echo '<div class="toolbar"><button type="button" class="btn-secondary" data-open-modal="conceptos-modal">Asignar / editar conceptos</button></div>';
    echo '<dialog id="conceptos-modal" class="app-modal"><div class="app-modal-content">';
    echo '<div class="app-modal-head"><h3>Conceptos del alumno</h3>';
    echo '<button type="button" class="app-modal-close" data-close-modal="conceptos-modal">Cerrar</button></div>';
    echo '<form method="post" class="form">';
    echo '<input type="hidden" name="alumno_id" value="' . $alumnoId . '">';
    echo '<fieldset class="fieldset"><legend>Artículos asignados</legend>';
    echo '<p class="muted">Podés filtrar por texto, ordenar columnas y marcar/desmarcar conceptos desde la tabla.</p>';
    echo '<table class="table js-data-table"><thead><tr>';
    echo '<th data-nosort="1">Asignado</th><th>Id</th><th>Legacy</th><th>Detalle</th><th>Lista 1</th>';
    echo '</tr></thead><tbody>';
    foreach ($articulos as $a) {
        $id = (int) $a['id'];
        $checked = isset($asignados[$id]) ? ' checked' : '';
        $precio = h((string) $a['importe_referencia']);
        $legacy = h((string) ($a['codigo_legacy'] ?? ''));
        echo '<tr>';
        echo '<td><input type="checkbox" name="articulo_id[]" value="' . $id . '"' . $checked . '></td>';
        echo '<td>' . $id . '</td>';
        echo '<td>' . $legacy . '</td>';
        echo '<td>' . h($a['detalle']) . '</td>';
        echo '<td>' . $precio . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></fieldset>';
    echo '<div class="form-actions"><button type="submit">Guardar conceptos</button></div>';
    echo '</form>';
    echo '</div></dialog>';
} else {
    echo '<h2>Conceptos actuales (solo lectura)</h2>';
    echo '<table class="table js-data-table"><thead><tr>';
    echo '<th>Asignado</th><th>Id</th><th>Legacy</th><th>Detalle</th><th>Lista 1</th>';
    echo '</tr></thead><tbody>';
    foreach ($articulos as $a) {
        $id = (int) $a['id'];
        $checked = isset($asignados[$id]) ? ' checked' : '';
        $precio = h((string) $a['importe_referencia']);
        $legacy = h((string) ($a['codigo_legacy'] ?? ''));
        echo '<tr>';
        echo '<td><input type="checkbox" disabled' . $checked . '></td>';
        echo '<td>' . $id . '</td>';
        echo '<td>' . $legacy . '</td>';
        echo '<td>' . h($a['detalle']) . '</td>';
        echo '<td>' . $precio . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

layout_end();
