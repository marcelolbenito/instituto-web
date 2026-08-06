<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/Auth.php';
require_once dirname(__DIR__) . '/src/InformesEstadoCuenta.php';

$pdo = web_init($config);

$alumnoId = isset($_GET['alumno_id']) ? (int) $_GET['alumno_id'] : 0;
auth_enforce_alumno_cc_scope($alumnoId);
$esPortalAlumno = auth_is_alumno();
$buscar = $esPortalAlumno ? '' : trim((string) ($_GET['q'] ?? ''));
$fechaConsulta = trim((string) ($_GET['fecha'] ?? date('Y-m-d')));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaConsulta) !== 1) {
    $fechaConsulta = date('Y-m-d');
}

$coincidencias = [];
if (!$esPortalAlumno && $alumnoId <= 0 && $buscar !== '') {
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
    $coincidencias = $stBuscar->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$reporte = $alumnoId > 0 ? informes_estado_cuenta_armar($pdo, $alumnoId, $fechaConsulta) : null;
$error = ($alumnoId > 0 && $reporte === null) ? 'Alumno inexistente.' : null;

layout_start($config, 'Estado de cuenta');
echo '<h1>Informe · Estado de cuenta por alumno</h1>';
echo '<p class="muted">Consulta la situación del alumno a una fecha: datos identificatorios, cuotas adeudadas, '
    . 'monto original, monto actualizado con recargos/intereses y total general. '
    . 'Para PDF: abrí la impresión y usá <strong>Guardar como PDF</strong> del navegador.</p>';

if ($error !== null) {
    flash_err($error);
}

if (!$esPortalAlumno) {
    echo '<form method="get" class="form form-grid" style="max-width:44rem">';
    echo '<label>Buscar alumno <input name="q" value="' . h($buscar) . '" placeholder="Nombre, DNI o código" '
        . ($alumnoId > 0 ? '' : 'required') . '></label>';
    echo '<label>Fecha de consulta <input type="date" name="fecha" value="' . h($fechaConsulta) . '"></label>';
    if ($alumnoId > 0) {
        echo '<input type="hidden" name="alumno_id" value="' . $alumnoId . '">';
    }
    echo '<div class="form-actions" style="grid-column:1/-1"><button type="submit">Consultar</button></div>';
    echo '</form>';
} else {
    echo '<form method="get" class="form form-grid" style="max-width:20rem">';
    echo '<input type="hidden" name="alumno_id" value="' . $alumnoId . '">';
    echo '<label>Fecha de consulta <input type="date" name="fecha" value="' . h($fechaConsulta) . '"></label>';
    echo '<div class="form-actions"><button type="submit">Actualizar</button></div>';
    echo '</form>';
}

if (!$esPortalAlumno && $alumnoId <= 0 && $buscar !== '') {
    if (count($coincidencias) === 0) {
        echo '<p class="muted">Sin resultados para la búsqueda.</p>';
    } else {
        echo '<table class="table js-data-table"><thead><tr>';
        echo '<th>Código</th><th>Alumno</th><th>DNI</th><th data-nosort="1"></th></tr></thead><tbody>';
        foreach ($coincidencias as $c) {
            $href = 'informes_estado_cuenta.php?alumno_id=' . (int) $c['id'] . '&fecha=' . rawurlencode($fechaConsulta);
            echo '<tr>';
            echo '<td>' . h((string) ($c['codigo_legacy'] ?? '')) . '</td>';
            echo '<td>' . h((string) ($c['nombre_completo'] ?? '')) . '</td>';
            echo '<td>' . h((string) ($c['documento'] ?? '')) . '</td>';
            echo '<td><a class="btn-secondary" href="' . h($href) . '">Ver estado de cuenta</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}

if ($reporte !== null) {
    echo '<div class="toolbar">';
    echo '<a class="btn-secondary" href="imprimir_estado_cuenta.php?alumno_id=' . (int) $alumnoId
        . '&amp;fecha=' . h($fechaConsulta) . '" target="_blank" rel="noopener">🖨️ Imprimir / PDF</a> ';
    if (!$esPortalAlumno) {
        echo '<a class="btn-secondary" href="cuenta_corriente.php?alumno_id=' . (int) $alumnoId . '">Cuenta corriente</a> ';
        echo '<a class="btn-secondary" href="registrar_cobro.php?alumno_id=' . (int) $alumnoId . '">Registrar cobro</a>';
    }
    echo '</div>';
    informes_estado_cuenta_render_bloque($reporte, false);
}

echo '<p class="muted"><a href="informes_morosos.php">Morosidad</a> · <a href="cuenta_corriente.php">Cuenta corriente</a></p>';
layout_end();
