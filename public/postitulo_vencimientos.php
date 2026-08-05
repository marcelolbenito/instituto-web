<?php
declare(strict_types=1);

/**
 * Vencimientos de postítulo por período (administrador y secretaría).
 *
 * Los alumnos de postítulo (artículos cuyo detalle empieza con "POST.") no llevan
 * descuento de pronto pago y su fecha de vencimiento varía cada mes. Acá se carga
 * la fecha de vencimiento que corresponde a cada período (año/mes).
 */
$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/Postitulo.php';
require_once dirname(__DIR__) . '/src/Auth.php';

$pdo = web_init($config);
auth_require_write();

$schemaOk = postitulo_schema_ok($pdo);

$meses = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
    7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth_require_write();
    $action = (string) ($_POST['action'] ?? '');

    if (!$schemaOk) {
        header('Location: postitulo_vencimientos.php?err=' . rawurlencode(
            'Falta la tabla postitulo_vencimiento. Ejecutá sql/migracion/40_postitulo_vencimiento_compat.sql'
        ));
        exit;
    }

    try {
        if ($action === 'guardar') {
            $anio = (int) ($_POST['anio'] ?? 0);
            $mes = (int) ($_POST['mes'] ?? 0);
            $fecha = trim((string) ($_POST['fecha_vencimiento'] ?? ''));
            postitulo_vencimiento_guardar($pdo, $anio, $mes, $fecha);
            header('Location: postitulo_vencimientos.php?ok=' . rawurlencode(
                sprintf('Vencimiento guardado para %04d-%02d.', $anio, $mes)
            ));
            exit;
        }

        if ($action === 'eliminar') {
            $anio = (int) ($_POST['anio'] ?? 0);
            $mes = (int) ($_POST['mes'] ?? 0);
            postitulo_vencimiento_eliminar($pdo, $anio, $mes);
            header('Location: postitulo_vencimientos.php?ok=' . rawurlencode(
                sprintf('Vencimiento eliminado de %04d-%02d (vuelve al tope estándar, sin descuento).', $anio, $mes)
            ));
            exit;
        }
    } catch (Throwable $e) {
        header('Location: postitulo_vencimientos.php?err=' . rawurlencode($e->getMessage()));
        exit;
    }

    header('Location: postitulo_vencimientos.php');
    exit;
}

// Artículos detectados como postítulo (para identificar el universo afectado).
$articulosPost = [];
$cantAlumnosPost = 0;
try {
    $articulosPost = $pdo->query(
        'SELECT a.id, a.detalle, a.importe_referencia, a.activo,
                (SELECT COUNT(DISTINCT aa.alumno_id)
                   FROM alumno_articulo aa
                   JOIN alumnos al ON al.id = aa.alumno_id
                  WHERE aa.articulo_id = a.id AND al.activo = 1) AS alumnos_activos
         FROM articulos a
         WHERE UPPER(TRIM(a.detalle)) LIKE \'POST.%\'
         ORDER BY a.detalle'
    )->fetchAll();

    $stCant = $pdo->query(
        'SELECT COUNT(DISTINCT aa.alumno_id) AS c
         FROM alumno_articulo aa
         JOIN articulos a ON a.id = aa.articulo_id
         JOIN alumnos al ON al.id = aa.alumno_id
         WHERE al.activo = 1 AND a.activo = 1 AND UPPER(TRIM(a.detalle)) LIKE \'POST.%\''
    );
    $cantAlumnosPost = (int) $stCant->fetchColumn();
} catch (Throwable $e) {
    $articulosPost = [];
}

$vencimientos = $schemaOk ? postitulo_vencimientos_listar($pdo) : [];

$hoy = new DateTimeImmutable('now');
$anioDefault = (int) $hoy->format('Y');
$mesDefault = (int) $hoy->format('n');

layout_start($config, 'Vencimientos de postítulo');
echo '<h1>Vencimientos de postítulo</h1>';
echo '<p class="muted">Los alumnos de <strong>postítulo</strong> se identifican por sus artículos cuyo '
    . 'detalle empieza con <code>POST.</code>. A estos alumnos <strong>no se les aplica el descuento de '
    . 'pronto pago</strong> y su <strong>fecha de vencimiento varía cada mes</strong>: cargala acá para cada período. '
    . 'Si un período no tiene fecha cargada, se usa el tope estándar (pero igual sin descuento).</p>';

if (isset($_GET['ok'])) {
    flash_ok((string) $_GET['ok']);
}
if (isset($_GET['err'])) {
    flash_err((string) $_GET['err']);
}

if (!$schemaOk) {
    echo '<p class="err">Falta la tabla <code>postitulo_vencimiento</code>. Ejecutá '
        . '<code>sql/migracion/40_postitulo_vencimiento_compat.sql</code> en la base de datos y volvé a entrar.</p>';
}

// --- Artículos detectados ---
echo '<section class="card"><h2 style="margin-top:0">Artículos de postítulo detectados</h2>';
if (count($articulosPost) === 0) {
    echo '<p class="muted">No se encontraron artículos con detalle que empiece con <code>POST.</code>.</p>';
} else {
    echo '<p class="muted">' . count($articulosPost) . ' artículo(s) coinciden. '
        . 'Alumnos activos con al menos un artículo de postítulo: <strong>' . $cantAlumnosPost . '</strong>.</p>';
    echo '<div style="overflow-x:auto"><table class="table"><thead><tr>';
    echo '<th>Artículo (detalle)</th><th>Importe ref.</th><th>Activo</th><th>Alumnos activos</th>';
    echo '</tr></thead><tbody>';
    foreach ($articulosPost as $a) {
        echo '<tr>';
        echo '<td>' . h((string) $a['detalle']) . '</td>';
        echo '<td>$ ' . h(number_format((float) $a['importe_referencia'], 2, ',', '.')) . '</td>';
        echo '<td>' . ((int) $a['activo'] === 1 ? 'Sí' : 'No') . '</td>';
        echo '<td>' . (int) $a['alumnos_activos'] . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';

// --- Form de carga ---
if ($schemaOk) {
    echo '<section class="card"><h2 style="margin-top:0">Cargar / actualizar vencimiento del mes</h2>';
    echo '<form method="post" class="form" style="max-width:30rem">';
    echo '<input type="hidden" name="action" value="guardar">';

    echo '<label>Año<br><input type="number" name="anio" min="2000" max="2100" step="1" value="' . $anioDefault . '" required></label>';

    echo '<label>Mes<br><select name="mes" required>';
    foreach ($meses as $num => $nombre) {
        $sel = $num === $mesDefault ? ' selected' : '';
        echo '<option value="' . $num . '"' . $sel . '>' . h($nombre) . '</option>';
    }
    echo '</select></label>';

    echo '<label>Fecha de vencimiento<br><input type="date" name="fecha_vencimiento" required></label>';

    echo '<div class="form-actions"><button type="submit" class="btn-primary">Guardar vencimiento</button></div>';
    echo '<p class="muted" style="margin:0">Si pagan hasta esa fecha, no hay recargo (ni descuento). '
        . 'Si pagan después, se aplica el recargo por mora contado desde el vencimiento.</p>';
    echo '</form></section>';
}

// --- Listado de vencimientos cargados ---
echo '<section class="card"><h2 style="margin-top:0">Vencimientos cargados</h2>';
if (count($vencimientos) === 0) {
    echo '<p class="muted">Todavía no hay vencimientos cargados.</p>';
} else {
    echo '<div style="overflow-x:auto"><table class="table"><thead><tr>';
    echo '<th>Período</th><th>Mes</th><th>Vencimiento</th><th data-nosort="1"></th>';
    echo '</tr></thead><tbody>';
    foreach ($vencimientos as $v) {
        $ts = strtotime($v['fecha_vencimiento']);
        $fechaTxt = $ts !== false ? date('d/m/Y', $ts) : $v['fecha_vencimiento'];
        echo '<tr>';
        echo '<td>' . sprintf('%04d-%02d', $v['anio'], $v['mes']) . '</td>';
        echo '<td>' . h($meses[$v['mes']] ?? (string) $v['mes']) . ' ' . $v['anio'] . '</td>';
        echo '<td>' . h($fechaTxt) . '</td>';
        echo '<td><form method="post" style="margin:0" onsubmit="return confirm(\'¿Eliminar el vencimiento de '
            . sprintf('%04d-%02d', $v['anio'], $v['mes']) . '?\');">';
        echo '<input type="hidden" name="action" value="eliminar">';
        echo '<input type="hidden" name="anio" value="' . (int) $v['anio'] . '">';
        echo '<input type="hidden" name="mes" value="' . (int) $v['mes'] . '">';
        echo '<button type="submit" class="btn-secondary">Eliminar</button>';
        echo '</form></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';

echo '<p><a href="index.php">Inicio</a></p>';

layout_end();
