<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/FormasPago.php';

$pdo = web_init($config);

if (!formas_pago_schema_ok($pdo)) {
    layout_start($config, 'Tarjetas');
    flash_err('Falta la migración 25.');
    echo '<p class="muted">Ejecutá <code>sql/migracion/25_formas_pago_tarjetas.sql</code>.</p>';
    layout_end();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_tarjeta') {
        $id = (int) ($_POST['id'] ?? 0);
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $activo = !empty($_POST['activo']) ? 1 : 0;
        $codLegacy = trim((string) ($_POST['codigo_legacy'] ?? ''));
        $codLegacyVal = $codLegacy !== '' ? (int) $codLegacy : null;

        if ($nombre === '') {
            header('Location: tarjetas.php?err=' . rawurlencode('Nombre obligatorio.'));
            exit;
        }

        if ($id > 0) {
            $st = $pdo->prepare('UPDATE tarjetas SET nombre = ?, activo = ?, codigo_legacy = ? WHERE id = ?');
            $st->execute([$nombre, $activo, $codLegacyVal, $id]);
            $tarjetaId = $id;
        } else {
            $st = $pdo->prepare('INSERT INTO tarjetas (nombre, activo, codigo_legacy) VALUES (?, ?, ?)');
            $st->execute([$nombre, $activo, $codLegacyVal]);
            $tarjetaId = (int) $pdo->lastInsertId();
        }

        $cuotasRaw = $_POST['plan_cuotas'] ?? [];
        $pctRaw = $_POST['plan_pct'] ?? [];
        if (!is_array($cuotasRaw)) {
            $cuotasRaw = [];
        }
        if (!is_array($pctRaw)) {
            $pctRaw = [];
        }
        $pdo->prepare('DELETE FROM tarjeta_recargo_cuota WHERE tarjeta_id = ?')->execute([$tarjetaId]);
        $ins = $pdo->prepare(
            'INSERT INTO tarjeta_recargo_cuota (tarjeta_id, cuotas, recargo_pct) VALUES (?, ?, ?)'
        );
        $n = max(count($cuotasRaw), count($pctRaw));
        for ($i = 0; $i < $n; $i++) {
            $cuo = (int) ($cuotasRaw[$i] ?? 0);
            $pct = (float) str_replace(',', '.', (string) ($pctRaw[$i] ?? '0'));
            if ($cuo > 0 && $cuo <= 99) {
                $ins->execute([$tarjetaId, $cuo, round($pct, 2)]);
            }
        }

        header('Location: tarjetas.php?ok=1');
        exit;
    }

    if ($action === 'delete_tarjeta') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            header('Location: tarjetas.php?err=' . rawurlencode('Tarjeta inválida.'));
            exit;
        }
        if (db_has_column($pdo, 'pago_registrado', 'tarjeta_id')) {
            $stUso = $pdo->prepare('SELECT COUNT(*) FROM pago_registrado WHERE tarjeta_id = ?');
            $stUso->execute([$id]);
            $nUsos = (int) $stUso->fetchColumn();
            if ($nUsos > 0) {
                header(
                    'Location: tarjetas.php?err=' . rawurlencode(
                        'No se puede eliminar: hay ' . $nUsos . ' cobro(s) registrado(s) con esta tarjeta. '
                        . 'Desactívela en editar si no la usa más.'
                    )
                );
                exit;
            }
        }
        $st = $pdo->prepare('DELETE FROM tarjetas WHERE id = ?');
        $st->execute([$id]);
        if ($st->rowCount() < 1) {
            header('Location: tarjetas.php?err=' . rawurlencode('La tarjeta no existe.'));
            exit;
        }
        header('Location: tarjetas.php?ok=' . rawurlencode('Tarjeta eliminada.'));
        exit;
    }
}

$edit = null;
$planesEdit = [];
if (isset($_GET['id'])) {
    $st = $pdo->prepare('SELECT * FROM tarjetas WHERE id = ?');
    $st->execute([(int) $_GET['id']]);
    $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($edit) {
        $stP = $pdo->prepare(
            'SELECT cuotas, recargo_pct FROM tarjeta_recargo_cuota WHERE tarjeta_id = ? ORDER BY cuotas'
        );
        $stP->execute([(int) $edit['id']]);
        $planesEdit = $stP->fetchAll(PDO::FETCH_ASSOC);
    }
}

$tarjetas = $pdo->query(
    'SELECT t.*,
            (SELECT COUNT(*) FROM tarjeta_recargo_cuota trc WHERE trc.tarjeta_id = t.id) AS n_planes
     FROM tarjetas t
     ORDER BY t.nombre'
)->fetchAll(PDO::FETCH_ASSOC);

layout_start($config, 'Tarjetas');
if (isset($_GET['ok'])) {
    flash_ok((string) ($_GET['ok'] ?: 'Guardado correctamente.'));
}
if (isset($_GET['err'])) {
    flash_err((string) $_GET['err']);
}

echo '<h1>Tarjetas y recargo por cuotas</h1>';
echo '<p class="muted">Marcas de tarjeta y porcentaje según cantidad de cuotas (Fox: TARJETA + TARRECA).</p>';
echo '<p><a href="formas_pago.php">← Formas de pago</a></p>';

echo '<div class="help-box">';
echo '<h3>Relación con «Formas de pago»</h3>';
echo '<p style="margin:0">Esta pantalla <strong>solo aplica</strong> cuando en '
    . '<a href="formas_pago.php">Formas de pago</a> la forma tiene activo <em>Usa tarjetas y % por cuotas</em> '
    . '(hoy: <strong>Tarjeta de crédito</strong>).</p>';
echo '<ul>';
echo '<li>En el cobro, paso 3: forma «Tarjeta de crédito» → el cajero elige <strong>marca</strong> (fila de abajo) y <strong>cuotas</strong>.</li>';
echo '<li>El % de la tabla se aplica sobre el <strong>subtotal del recibo</strong> (cuotas + mora + ítems), antes de cerrar el pago.</li>';
echo '<li>Si una marca no tiene filas de cuotas (ej. NARANJA vacía), no se puede cobrar con esa marca hasta cargar los planes.</li>';
echo '</ul>';
echo '</div>';

echo '<div class="toolbar"><button type="button" class="btn-secondary" data-open-modal="tarjeta-modal">Nueva tarjeta</button></div>';

echo '<dialog id="tarjeta-modal" class="app-modal app-modal-wide"><div class="app-modal-content">';
echo '<div class="app-modal-head"><h3>' . ($edit ? 'Editar tarjeta' : 'Nueva tarjeta') . '</h3>';
echo '<button type="button" class="app-modal-close" data-close-modal="tarjeta-modal">Cerrar</button></div>';
echo '<form method="post" class="form" id="tarjeta-form">';
echo '<input type="hidden" name="action" value="save_tarjeta">';
if ($edit) {
    echo '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">';
}
echo '<div class="form-grid">';
echo '<label>Nombre <input name="nombre" required maxlength="80" value="' . h($edit['nombre'] ?? '') . '"></label>';
echo '<label>Cód. legacy Fox <input name="codigo_legacy" type="number" min="0" placeholder="Opcional" value="'
    . h(isset($edit['codigo_legacy']) && $edit['codigo_legacy'] !== null ? (string) $edit['codigo_legacy'] : '') . '"></label>';
echo '<label class="checkbox"><input type="checkbox" name="activo" value="1"' . (empty($edit) || !empty($edit['activo']) ? ' checked' : '') . '> Activa</label>';
echo '</div>';

echo '<h4>Planes: cuotas → % recargo</h4>';
echo '<p class="muted" style="font-size:0.9em">Datos actuales Fox para <strong>TARJETA</strong>: 2→10,36% · 3→12,20% · 4→14,48% · 5→16,46% · 6→18,46%.</p>';
echo '<table class="table" id="planes-table"><thead><tr><th>Cuotas</th><th>% recargo</th><th></th></tr></thead><tbody>';
$filas = $planesEdit;
if ($filas === []) {
    $filas = [['cuotas' => '', 'recargo_pct' => '']];
}
foreach ($filas as $pl) {
    echo '<tr class="plan-row">';
    echo '<td><input name="plan_cuotas[]" type="number" min="1" max="99" style="width:5rem" value="'
        . h($pl['cuotas'] !== '' ? (string) (int) $pl['cuotas'] : '') . '"></td>';
    echo '<td><input name="plan_pct[]" type="number" step="0.01" min="0" max="100" style="width:7rem" value="'
        . h(isset($pl['recargo_pct']) ? number_format((float) $pl['recargo_pct'], 2, '.', '') : '') . '"></td>';
    echo '<td><button type="button" class="btn-secondary btn-sm plan-del" title="Quitar fila">−</button></td>';
    echo '</tr>';
}
echo '</tbody></table>';
echo '<button type="button" class="btn-secondary btn-sm" id="plan-add">+ Agregar cuota</button>';
echo '<div class="form-actions" style="margin-top:1rem"><button type="submit">Guardar tarjeta y planes</button></div>';
echo '</form></div></dialog>';
if ($edit) {
    echo '<span data-auto-open="tarjeta-modal"></span>';
}

echo '<h2>Listado</h2>';
echo '<table class="table js-data-table"><thead><tr><th>Id</th><th>Nombre</th><th>Cód. Fox</th><th>Planes</th><th>Activa</th><th data-nosort="1"></th></tr></thead><tbody>';
foreach ($tarjetas as $t) {
    $tid = (int) $t['id'];
    $stP = $pdo->prepare(
        'SELECT cuotas, recargo_pct FROM tarjeta_recargo_cuota WHERE tarjeta_id = ? ORDER BY cuotas'
    );
    $stP->execute([$tid]);
    $det = [];
    foreach ($stP->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $det[] = (int) $p['cuotas'] . '→' . number_format((float) $p['recargo_pct'], 2, ',', '.') . '%';
    }
    echo '<tr>';
    echo '<td>' . $tid . '</td>';
    echo '<td>' . h($t['nombre']) . '</td>';
    echo '<td>' . h($t['codigo_legacy'] !== null ? (string) $t['codigo_legacy'] : '—') . '</td>';
    echo '<td class="muted" style="font-size:0.9em">' . h($det !== [] ? implode(' · ', $det) : 'Sin planes') . '</td>';
    echo '<td>' . (!empty($t['activo']) ? 'Sí' : 'No') . '</td>';
    echo '<td class="nowrap"><span class="action-icons">';
    echo '<a class="action-icon" href="tarjetas.php?id=' . $tid . '" title="Editar tarjeta">✏️</a>';
    echo '<form method="post" class="inline" onsubmit="return confirm(\'¿Eliminar esta tarjeta y todos sus planes de cuotas? No se puede deshacer.\');">';
    echo '<input type="hidden" name="action" value="delete_tarjeta">';
    echo '<input type="hidden" name="id" value="' . $tid . '">';
    echo '<button type="submit" class="action-icon danger" title="Eliminar tarjeta">🗑️</button>';
    echo '</form></span></td>';
    echo '</tr>';
}
echo '</tbody></table>';

echo '<script>
(function () {
  const tbody = document.querySelector("#planes-table tbody");
  const addBtn = document.getElementById("plan-add");
  if (!tbody || !addBtn) return;
  addBtn.addEventListener("click", function () {
    const tr = document.createElement("tr");
    tr.className = "plan-row";
    tr.innerHTML = \'<td><input name="plan_cuotas[]" type="number" min="1" max="99" style="width:5rem"></td>\'
      + \'<td><input name="plan_pct[]" type="number" step="0.01" min="0" max="100" style="width:7rem"></td>\'
      + \'<td><button type="button" class="btn-secondary btn-sm plan-del" title="Quitar fila">−</button></td>\';
    tbody.appendChild(tr);
  });
  tbody.addEventListener("click", function (e) {
    const btn = e.target.closest(".plan-del");
    if (!btn) return;
    const row = btn.closest("tr");
    if (tbody.querySelectorAll(".plan-row").length > 1) row.remove();
  });
})();
</script>';

layout_end();
