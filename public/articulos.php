<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';

$pdo = Db::pdo($config);

try {
    $pdo->query('SELECT rubro_id FROM articulos LIMIT 1');
} catch (Throwable $e) {
    layout_start($config, 'Artículos');
    flash_err('Ejecute sql/init/04_schema_modo_operativo.sql (rubros y columnas de artículo).');
    echo '<p class="muted">' . h($e->getMessage()) . '</p>';
    layout_end();
    exit;
}

$rubros = $pdo->query('SELECT id, nombre FROM rubros ORDER BY nombre')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $codigoLegacy = isset($_POST['codigo_legacy']) && $_POST['codigo_legacy'] !== ''
            ? (int) $_POST['codigo_legacy'] : null;
        $detalle = trim((string) ($_POST['detalle'] ?? ''));
        if ($detalle === '') {
            header('Location: articulos.php?err=' . rawurlencode('El detalle es obligatorio.'));
            exit;
        }
        $rubroId = isset($_POST['rubro_id']) && $_POST['rubro_id'] !== '' ? (int) $_POST['rubro_id'] : null;
        $esAbono = isset($_POST['es_abono']) ? 1 : 0;
        $medida = (string) ($_POST['medida_venta'] ?? 'unidad');
        if (!in_array($medida, ['unidad', 'fraccion'], true)) {
            $medida = 'unidad';
        }
        $p1 = (float) str_replace(',', '.', (string) ($_POST['importe_referencia'] ?? '0'));
        $p2 = (float) str_replace(',', '.', (string) ($_POST['precio_lista_2'] ?? '0'));
        $p3 = (float) str_replace(',', '.', (string) ($_POST['precio_lista_3'] ?? '0'));
        $p4 = (float) str_replace(',', '.', (string) ($_POST['precio_lista_4'] ?? '0'));
        $activo = isset($_POST['activo']) ? 1 : 0;

        if ($id > 0) {
            $sql = 'UPDATE articulos SET codigo_legacy = ?, rubro_id = ?, detalle = ?, es_abono = ?, medida_venta = ?,
              importe_referencia = ?, precio_lista_2 = ?, precio_lista_3 = ?, precio_lista_4 = ?, activo = ?
              WHERE id = ?';
            $st = $pdo->prepare($sql);
            $st->execute([$codigoLegacy, $rubroId, $detalle, $esAbono, $medida, $p1, $p2, $p3, $p4, $activo, $id]);
        } else {
            $sql = 'INSERT INTO articulos (codigo_legacy, rubro_id, detalle, es_abono, medida_venta, importe_referencia,
              precio_lista_2, precio_lista_3, precio_lista_4, activo)
              VALUES (?,?,?,?,?,?,?,?,?,?)';
            $st = $pdo->prepare($sql);
            $st->execute([$codigoLegacy, $rubroId, $detalle, $esAbono, $medida, $p1, $p2, $p3, $p4, $activo]);
        }
        header('Location: articulos.php?ok=1');
        exit;
    }
}

$edit = null;
if (isset($_GET['id'])) {
    $st = $pdo->prepare('SELECT * FROM articulos WHERE id = ?');
    $st->execute([(int) $_GET['id']]);
    $edit = $st->fetch();
}

$rows = $pdo->query(
    'SELECT a.*, r.nombre AS rubro_nombre FROM articulos a
     LEFT JOIN rubros r ON r.id = a.rubro_id
     ORDER BY a.detalle'
)->fetchAll();

layout_start($config, 'Artículos');
if (isset($_GET['ok'])) {
    flash_ok('Guardado correctamente.');
}
if (isset($_GET['err'])) {
    flash_err((string) $_GET['err']);
}

echo '<h1>Artículos / conceptos</h1>';
echo '<p class="muted">Ficha alineada al modo operativo (Archivos → Artículos). Lista 1 = importe referencia.</p>';

$row = $edit ?: [];

echo '<div class="toolbar"><button type="button" class="btn-secondary" data-open-modal="articulo-modal">Nuevo artículo</button></div>';
echo '<dialog id="articulo-modal" class="app-modal"><div class="app-modal-content">';
echo '<div class="app-modal-head"><h3>' . ($edit ? 'Editar artículo' : 'Nuevo artículo') . '</h3>';
echo '<button type="button" class="app-modal-close" data-close-modal="articulo-modal">Cerrar</button></div>';
echo '<form method="post" class="form form-grid">';
echo '<input type="hidden" name="action" value="save">';
if ($edit) {
    echo '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">';
}
echo '<label>Código legacy <input name="codigo_legacy" type="number" value="' . h((string) ($row['codigo_legacy'] ?? '')) . '"></label>';
echo '<label>Rubro <select name="rubro_id"><option value="">—</option>';
foreach ($rubros as $b) {
    $sel = isset($row['rubro_id']) && (int) $row['rubro_id'] === (int) $b['id'] ? ' selected' : '';
    echo '<option value="' . (int) $b['id'] . '"' . $sel . '>' . h($b['nombre']) . '</option>';
}
echo '</select></label>';
echo '<label>Detalle * <input name="detalle" required maxlength="200" value="' . h($row['detalle'] ?? '') . '"></label>';
echo '<label>Tipo <select name="medida_venta">';
foreach (['unidad' => 'Unidad', 'fraccion' => 'Fracción'] as $k => $lab) {
    $sel = ($row['medida_venta'] ?? 'unidad') === $k ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($lab) . '</option>';
}
echo '</select></label>';
$ea = !isset($row['es_abono']) || (int) $row['es_abono'] === 1;
echo '<label class="check"><input type="checkbox" name="es_abono" value="1"' . ($ea ? ' checked' : '') . '> Es abono / cuota</label>';
echo '<label>Precio lista 1 (referencia) <input name="importe_referencia" type="number" step="0.01" value="' . h((string) ($row['importe_referencia'] ?? '0')) . '"></label>';
echo '<label>Precio lista 2 <input name="precio_lista_2" type="number" step="0.01" value="' . h((string) ($row['precio_lista_2'] ?? '0')) . '"></label>';
echo '<label>Precio lista 3 <input name="precio_lista_3" type="number" step="0.01" value="' . h((string) ($row['precio_lista_3'] ?? '0')) . '"></label>';
echo '<label>Precio lista 4 <input name="precio_lista_4" type="number" step="0.01" value="' . h((string) ($row['precio_lista_4'] ?? '0')) . '"></label>';
$ac = !isset($row['activo']) || (int) $row['activo'] === 1;
echo '<label class="check"><input type="checkbox" name="activo" value="1"' . ($ac ? ' checked' : '') . '> Activo</label>';
echo '<div class="form-actions"><button type="submit">Guardar</button></div>';
echo '</form>';
echo '</div></dialog>';
if ($edit) {
    echo '<span data-auto-open="articulo-modal"></span>';
}

echo '<h2>Listado</h2><table class="table js-data-table"><thead><tr><th>Id</th><th>Legacy</th><th>Detalle</th><th>Rubro</th><th>Lista 1</th><th>Abono</th><th data-nosort="1"></th></tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr><td>' . (int) $r['id'] . '</td><td>' . h((string) ($r['codigo_legacy'] ?? '')) . '</td><td>' . h($r['detalle']) . '</td>';
    echo '<td>' . h($r['rubro_nombre'] ?? '') . '</td><td>' . h((string) $r['importe_referencia']) . '</td>';
    echo '<td>' . ((int) ($r['es_abono'] ?? 1) ? 'Sí' : 'No') . '</td>';
    echo '<td><span class="action-icons"><a class="action-icon" href="articulos.php?id=' . (int) $r['id'] . '" title="Editar artículo">✏️</a></span></td></tr>';
}
echo '</tbody></table>';

layout_end();
