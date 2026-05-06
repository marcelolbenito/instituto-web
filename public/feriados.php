<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';

$pdo = Db::pdo($config);

if (!db_has_column($pdo, 'feriados', 'fecha')) {
    layout_start($config, 'Feriados');
    flash_err('Falta la migración 19 (feriados por jurisdicción).');
    echo '<p class="muted">Ejecutá <code>sql/migracion/19_feriados_calendario_jurisdiccion.sql</code>.</p>';
    layout_end();
    exit;
}

$anio = isset($_GET['anio']) ? (int) $_GET['anio'] : (int) date('Y');
if ($anio < 2000 || $anio > 2100) {
    $anio = (int) date('Y');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $fecha = trim((string) ($_POST['fecha'] ?? ''));
        $ambito = trim((string) ($_POST['ambito'] ?? 'nacional'));
        $provincia = trim((string) ($_POST['provincia'] ?? ''));
        $ciudad = trim((string) ($_POST['ciudad'] ?? ''));
        $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            header('Location: feriados.php?anio=' . $anio . '&err=' . rawurlencode('Fecha inválida.'));
            exit;
        }
        if (!in_array($ambito, ['nacional', 'provincia', 'ciudad'], true)) {
            $ambito = 'nacional';
        }
        if ($descripcion === '') {
            header('Location: feriados.php?anio=' . $anio . '&err=' . rawurlencode('Descripción obligatoria.'));
            exit;
        }
        if ($ambito === 'provincia' && $provincia === '') {
            header('Location: feriados.php?anio=' . $anio . '&err=' . rawurlencode('Para ámbito provincia, indicar provincia.'));
            exit;
        }
        if ($ambito === 'ciudad' && $ciudad === '') {
            header('Location: feriados.php?anio=' . $anio . '&err=' . rawurlencode('Para ámbito ciudad, indicar ciudad.'));
            exit;
        }
        if ($ambito === 'nacional') {
            $provincia = '';
            $ciudad = '';
        } elseif ($ambito === 'provincia') {
            $ciudad = '';
        }

        try {
            $st = $pdo->prepare(
                'INSERT INTO feriados (fecha, ambito, provincia, ciudad, descripcion)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $st->execute([$fecha, $ambito, $provincia !== '' ? $provincia : null, $ciudad !== '' ? $ciudad : null, $descripcion]);
            header('Location: feriados.php?anio=' . (int) substr($fecha, 0, 4) . '&ok=1');
            exit;
        } catch (Throwable $e) {
            header('Location: feriados.php?anio=' . $anio . '&err=' . rawurlencode('No se pudo guardar (puede estar repetido).'));
            exit;
        }
    }

    if ($action === 'del') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $st = $pdo->prepare('DELETE FROM feriados WHERE id = ?');
            $st->execute([$id]);
        }
        header('Location: feriados.php?anio=' . $anio . '&ok=' . rawurlencode('Feriado eliminado.'));
        exit;
    }
}

$st = $pdo->prepare(
    'SELECT id, fecha, ambito, provincia, ciudad, descripcion
     FROM feriados
     WHERE YEAR(fecha) = ?
     ORDER BY fecha, ambito, provincia, ciudad'
);
$st->execute([$anio]);
$rows = $st->fetchAll();

layout_start($config, 'Feriados');
if (isset($_GET['ok'])) {
    flash_ok((string) ($_GET['ok'] ?: 'Guardado.'));
}
if (isset($_GET['err'])) {
    flash_err((string) $_GET['err']);
}

echo '<h1>Feriados (nacional/provincia/ciudad)</h1>';
echo '<p class="muted">Estos feriados se usan en el cálculo de días hábiles de cobranza (pronto pago y BECA).</p>';
echo '<form method="get" class="form"><label>Año <input type="number" name="anio" min="2000" max="2100" value="' . $anio . '"></label> <button type="submit">Ver</button></form>';

echo '<h2>Nuevo feriado</h2>';
echo '<form method="post" class="form form-grid">';
echo '<input type="hidden" name="action" value="add">';
echo '<label>Fecha <input type="date" name="fecha" required></label>';
echo '<label>Ámbito <select name="ambito"><option value="nacional">Nacional</option><option value="provincia">Provincia</option><option value="ciudad">Ciudad</option></select></label>';
echo '<label>Provincia <input name="provincia" maxlength="80" placeholder="Ej: Chaco"></label>';
echo '<label>Ciudad <input name="ciudad" maxlength="80" placeholder="Ej: Resistencia"></label>';
echo '<label>Descripción <input name="descripcion" maxlength="160" required placeholder="Ej: Día de la Ciudad"></label>';
echo '<div class="form-actions"><button type="submit">Agregar</button></div>';
echo '</form>';

echo '<h2>Feriados ' . $anio . '</h2>';
echo '<table class="table js-data-table"><thead><tr><th>Fecha</th><th>Ámbito</th><th>Provincia</th><th>Ciudad</th><th>Descripción</th><th data-nosort="1"></th></tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>' . h((string) $r['fecha']) . '</td>';
    echo '<td>' . h((string) $r['ambito']) . '</td>';
    echo '<td>' . h((string) ($r['provincia'] ?? '')) . '</td>';
    echo '<td>' . h((string) ($r['ciudad'] ?? '')) . '</td>';
    echo '<td>' . h((string) $r['descripcion']) . '</td>';
    echo '<td><form method="post" class="inline"><input type="hidden" name="action" value="del"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><button type="submit" class="btn-secondary">Eliminar</button></form></td>';
    echo '</tr>';
}
echo '</tbody></table>';
echo '<p><a href="index.php">Inicio</a></p>';

layout_end();
