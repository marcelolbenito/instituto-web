<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/FormasPago.php';

$pdo = web_init($config);

if (!formas_pago_schema_ok($pdo)) {
    layout_start($config, 'Formas de pago');
    flash_err('Falta la migración 25 (formas de pago y tarjetas).');
    echo '<p class="muted">Ejecutá <code>sql/migracion/25_formas_pago_tarjetas.sql</code>.</p>';
    layout_end();
    exit;
}

$tipos = [
    'efectivo' => 'Efectivo',
    'tarjeta' => 'Tarjeta (crédito)',
    'debito' => 'Débito',
    'transferencia' => 'Transferencia',
    'cheque' => 'Cheque',
    'cuenta_corriente' => 'Cuenta corriente',
    'otro' => 'Otro',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $tipo = (string) ($_POST['tipo'] ?? 'otro');
        $recargo = max(0.0, (float) str_replace(',', '.', (string) ($_POST['recargo_pct'] ?? '0')));
        $orden = max(0, (int) ($_POST['orden'] ?? 0));
        $activo = !empty($_POST['activo']) ? 1 : 0;
        $permiteDesc = !empty($_POST['permite_descuento_pct']) ? 1 : 0;
        $usaPlanes = !empty($_POST['usa_planes_tarjeta']) ? 1 : 0;
        $reqRef = !empty($_POST['requiere_referencia']) ? 1 : 0;
        $pideTarjeta = !empty($_POST['pide_datos_tarjeta']) ? 1 : 0;

        if ($nombre === '') {
            header('Location: formas_pago.php?err=' . rawurlencode('El nombre es obligatorio.'));
            exit;
        }
        if (!isset($tipos[$tipo])) {
            $tipo = 'otro';
        }
        if ($usaPlanes) {
            $recargo = 0.0;
            $permiteDesc = 0;
            $pideTarjeta = 1;
        }

        if ($id > 0) {
            $st = $pdo->prepare(
                'UPDATE formas_pago SET nombre = ?, tipo = ?, recargo_pct = ?, permite_descuento_pct = ?,
                    usa_planes_tarjeta = ?, requiere_referencia = ?, pide_datos_tarjeta = ?, activo = ?, orden = ?
                 WHERE id = ?'
            );
            $st->execute([$nombre, $tipo, $recargo, $permiteDesc, $usaPlanes, $reqRef, $pideTarjeta, $activo, $orden, $id]);
        } else {
            $codigo = strtolower(preg_replace('/[^a-z0-9]+/', '_', $nombre) ?? 'forma');
            $codigo = trim($codigo, '_');
            if ($codigo === '') {
                $codigo = 'forma_' . time();
            }
            $st = $pdo->prepare(
                'INSERT INTO formas_pago (codigo, nombre, tipo, recargo_pct, permite_descuento_pct,
                    usa_planes_tarjeta, requiere_referencia, pide_datos_tarjeta, activo, orden)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            try {
                $st->execute([$codigo, $nombre, $tipo, $recargo, $permiteDesc, $usaPlanes, $reqRef, $pideTarjeta, $activo, $orden]);
            } catch (Throwable $e) {
                header('Location: formas_pago.php?err=' . rawurlencode('No se pudo crear (código duplicado).'));
                exit;
            }
        }
        header('Location: formas_pago.php?ok=1');
        exit;
    }
}

$edit = null;
if (isset($_GET['id'])) {
    $st = $pdo->prepare('SELECT * FROM formas_pago WHERE id = ?');
    $st->execute([(int) $_GET['id']]);
    $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$rows = $pdo->query(
    'SELECT * FROM formas_pago ORDER BY orden, nombre'
)->fetchAll(PDO::FETCH_ASSOC);

layout_start($config, 'Formas de pago');
if (isset($_GET['ok'])) {
    flash_ok('Guardado correctamente.');
}
if (isset($_GET['err'])) {
    flash_err((string) $_GET['err']);
}

echo '<h1>Formas de pago</h1>';
echo '<p class="muted">Qué medio elige caja al confirmar un cobro (efectivo, tarjeta, transferencia, etc.).</p>';

echo '<div class="help-box">';
echo '<h3>¿Cómo se calcula el recargo?</h3>';
echo '<ul>';
echo '<li><strong>Mora / pronto pago de cuotas</strong> → no se define acá; va en '
    . '<a href="parametros_cobranza.php">Parámetros de cobranza</a>.</li>';
echo '<li><strong>Tarjeta de crédito</strong> (flag <em>planes tarjeta</em>) → el % <strong>no</strong> es el de la columna Recargo %; '
    . 'al cobrar se elige <strong>marca</strong> (TARJETA, NARANJA…) y <strong>cuotas</strong>, y el sistema usa los % de '
    . '<a href="tarjetas.php">Tarjetas y planes</a>.</li>';
echo '<li><strong>Débito, transferencia u otras</strong> sin planes → el % fijo es el de <strong>Recargo %</strong> en esta pantalla (si lo cargás).</li>';
echo '<li><strong>Efectivo</strong> → puede aplicar <strong>descuento %</strong> al cobrar (tope en parámetros de cobranza).</li>';
echo '</ul>';
echo '<div class="help-example"><strong>Ejemplo:</strong> Forma «Tarjeta de crédito» + marca «TARJETA» + 3 cuotas → '
    . 'recargo del cobro = subtotal del recibo × <strong>12,20%</strong> (según lo grabado en Tarjetas).</div>';
echo '</div>';

echo '<div class="toolbar">';
echo '<button type="button" class="btn-secondary" data-open-modal="forma-modal">Nueva forma</button>';
echo '<a class="btn-secondary" href="tarjetas.php">Tarjetas y % por cuotas</a>';
echo '</div>';

echo '<dialog id="forma-modal" class="app-modal"><div class="app-modal-content">';
echo '<div class="app-modal-head"><h3>' . ($edit ? 'Editar forma de pago' : 'Nueva forma de pago') . '</h3>';
echo '<button type="button" class="app-modal-close" data-close-modal="forma-modal">Cerrar</button></div>';
echo '<form method="post" class="form form-grid">';
echo '<input type="hidden" name="action" value="save">';
if ($edit) {
    echo '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">';
    echo '<p class="muted">Código interno: <code>' . h((string) $edit['codigo']) . '</code> (no editable)</p>';
}
echo '<label>Nombre <input name="nombre" required maxlength="80" value="' . h($edit['nombre'] ?? '') . '"></label>';
echo '<label>Tipo <select name="tipo">';
foreach ($tipos as $k => $lbl) {
  $sel = ($edit['tipo'] ?? '') === $k ? ' selected' : '';
  echo '<option value="' . h($k) . '"' . $sel . '>' . h($lbl) . '</option>';
}
echo '</select></label>';
echo '<label>Recargo fijo % <input name="recargo_pct" type="number" step="0.01" min="0" max="100" value="'
    . h(isset($edit['recargo_pct']) ? number_format((float) $edit['recargo_pct'], 2, '.', '') : '0') . '">';
echo '<span class="hint">Solo si no usa planes de tarjeta (ej. débito).</span></label>';
echo '<label>Orden <input name="orden" type="number" min="0" value="' . (int) ($edit['orden'] ?? 0) . '"></label>';
echo '<label class="checkbox"><input type="checkbox" name="activo" value="1"' . (empty($edit) || !empty($edit['activo']) ? ' checked' : '') . '> Activa</label>';
echo '<label class="checkbox"><input type="checkbox" name="permite_descuento_pct" value="1"' . (!empty($edit['permite_descuento_pct']) ? ' checked' : '') . '> Permite descuento % al cobrar (efectivo)</label>';
echo '<label class="checkbox"><input type="checkbox" name="usa_planes_tarjeta" value="1"' . (!empty($edit['usa_planes_tarjeta']) ? ' checked' : '') . '> Usa tarjetas y % por cuotas</label>';
echo '<p class="hint" style="grid-column:1/-1;margin:-0.25rem 0 0.5rem">Si está marcado, el recargo lo define '
    . '<a href="tarjetas.php">Tarjetas</a> (marca + cuotas). El campo «Recargo fijo %» no se usa.</p>';
echo '<label class="checkbox"><input type="checkbox" name="requiere_referencia" value="1"' . (!empty($edit['requiere_referencia']) ? ' checked' : '') . '> Requiere referencia / comprobante</label>';
echo '<label class="checkbox"><input type="checkbox" name="pide_datos_tarjeta" value="1"' . (!empty($edit['pide_datos_tarjeta']) ? ' checked' : '') . '> Pide lote, autorización, últimos dígitos</label>';
echo '<div class="form-actions"><button type="submit">Guardar</button></div>';
echo '</form></div></dialog>';
if ($edit) {
    echo '<span data-auto-open="forma-modal"></span>';
}

echo '<h2>Listado</h2>';
echo '<table class="table js-data-table"><thead><tr>';
echo '<th>Orden</th><th>Nombre</th><th>Tipo</th><th>Recargo %<br><span class="muted" style="font-weight:normal;font-size:0.85em">fijo o → Tarjetas</span></th><th>Comportamiento</th><th>Activa</th><th data-nosort="1"></th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    $flags = [];
    if (!empty($r['permite_descuento_pct'])) {
        $flags[] = 'descuento';
    }
    if (!empty($r['usa_planes_tarjeta'])) {
        $flags[] = '% por marca y cuotas (Tarjetas)';
    }
    if (!empty($r['requiere_referencia'])) {
        $flags[] = 'referencia';
    }
    if (!empty($r['pide_datos_tarjeta'])) {
        $flags[] = 'datos tarjeta';
    }
    echo '<tr>';
    echo '<td>' . (int) $r['orden'] . '</td>';
    echo '<td>' . h($r['nombre']) . '<br><span class="muted"><code>' . h($r['codigo']) . '</code></span></td>';
    echo '<td>' . h($tipos[$r['tipo']] ?? $r['tipo']) . '</td>';
    if (!empty($r['usa_planes_tarjeta'])) {
        echo '<td class="muted" title="Ver tarjetas.php">→ <a href="tarjetas.php">Tarjetas</a></td>';
    } else {
        echo '<td>' . number_format((float) $r['recargo_pct'], 2, ',', '.') . '%</td>';
    }
    echo '<td class="muted">' . h($flags !== [] ? implode(', ', $flags) : '—') . '</td>';
    echo '<td>' . (!empty($r['activo']) ? 'Sí' : 'No') . '</td>';
    echo '<td class="nowrap"><a class="action-icon" href="formas_pago.php?id=' . (int) $r['id'] . '" title="Editar">✏️</a></td>';
    echo '</tr>';
}
echo '</tbody></table>';

layout_end();
