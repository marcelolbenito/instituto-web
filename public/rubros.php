<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';

$pdo = web_init($config);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $codigo = isset($_POST['codigo_legacy']) && $_POST['codigo_legacy'] !== ''
            ? (int) $_POST['codigo_legacy'] : null;
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        if ($nombre === '') {
            header('Location: rubros.php?err=' . rawurlencode('El nombre es obligatorio'));
            exit;
        }
        if ($id > 0) {
            $st = $pdo->prepare('UPDATE rubros SET codigo_legacy = ?, nombre = ? WHERE id = ?');
            $st->execute([$codigo, $nombre, $id]);
        } else {
            $st = $pdo->prepare('INSERT INTO rubros (codigo_legacy, nombre) VALUES (?, ?)');
            $st->execute([$codigo, $nombre]);
        }
        header('Location: rubros.php?ok=1');
        exit;
    }
    if ($action === 'delete' && !empty($_POST['id'])) {
        $st = $pdo->prepare('DELETE FROM rubros WHERE id = ?');
        $st->execute([(int) $_POST['id']]);
        header('Location: rubros.php?ok=1');
        exit;
    }
}

$edit = null;
if (isset($_GET['id'])) {
    $st = $pdo->prepare('SELECT * FROM rubros WHERE id = ?');
    $st->execute([(int) $_GET['id']]);
    $edit = $st->fetch();
}

try {
    $rows = $pdo->query('SELECT * FROM rubros ORDER BY nombre')->fetchAll();
} catch (Throwable $e) {
    layout_start($config, 'Rubros');
    flash_err('Tabla rubros no disponible. Aplique sql/init/04_schema_modo_operativo.sql (volumen DB nuevo o migración manual).');
    echo '<p class="muted">' . h($e->getMessage()) . '</p>';
    layout_end();
    exit;
}

layout_start($config, 'Rubros');
if (isset($_GET['ok'])) {
    flash_ok('Guardado correctamente.');
}
if (isset($_GET['err'])) {
    flash_err((string) $_GET['err']);
}

echo '<h1>Rubros</h1>';
echo '<p class="muted">Maestro de rubros (modo operativo: Archivos → Rubros).</p>';

echo '<div class="toolbar"><button type="button" class="btn-secondary" data-open-modal="rubro-modal">Nuevo rubro</button></div>';
echo '<dialog id="rubro-modal" class="app-modal"><div class="app-modal-content">';
echo '<div class="app-modal-head"><h3>' . ($edit ? 'Editar rubro' : 'Nuevo rubro') . '</h3>';
echo '<button type="button" class="app-modal-close" data-close-modal="rubro-modal">Cerrar</button></div>';
echo '<form method="post" class="form">';
echo '<input type="hidden" name="action" value="save">';
if ($edit) {
    echo '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">';
}
echo '<label>Código legacy <input name="codigo_legacy" type="number" value="' . h($edit['codigo_legacy'] ?? '') . '"></label>';
echo '<label>Nombre <input name="nombre" required maxlength="80" value="' . h($edit['nombre'] ?? '') . '"></label>';
echo '<button type="submit">Guardar</button>';
echo '</form>';
echo '</div></dialog>';
if ($edit) {
    echo '<span data-auto-open="rubro-modal"></span>';
}

echo '<h2>Listado</h2><table class="table js-data-table"><thead><tr><th>Id</th><th>Cód. legacy</th><th>Nombre</th><th data-nosort="1"></th></tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr><td>' . (int) $r['id'] . '</td><td>' . h((string) ($r['codigo_legacy'] ?? '')) . '</td><td>' . h($r['nombre']) . '</td><td class="nowrap">';
    echo '<span class="action-icons">';
    echo '<a class="action-icon" href="rubros.php?id=' . (int) $r['id'] . '" title="Editar rubro">✏️</a>';
    echo '<form method="post" class="inline" onsubmit="return confirm(\'¿Eliminar? Los artículos quedarán sin rubro.\');">';
    echo '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int) $r['id'] . '">';
    echo '<button type="submit" class="action-icon danger" title="Eliminar rubro">🗑️</button></form>';
    echo '</span></td></tr>';
}
echo '</tbody></table>';

layout_end();
