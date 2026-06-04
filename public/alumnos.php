<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/Auth.php';
require_once dirname(__DIR__) . '/src/Saldos.php';

$pdo = web_init($config);
$hasTipoAlumno = db_has_column($pdo, 'alumnos', 'tipo_alumno');

try {
    $pdo->query('SELECT condicion_iva, saldo_cc FROM alumnos LIMIT 1');
} catch (Throwable $e) {
    layout_start($config, 'Alumnos');
    flash_err('Ejecute la migración sql/init/04_schema_modo_operativo.sql y sql/migracion/09_alumnos_saldo_cc.sql.');
    echo '<p class="muted">' . h($e->getMessage()) . '</p>';
    layout_end();
    exit;
}

$barrios = $pdo->query('SELECT id, nombre FROM barrios ORDER BY nombre')->fetchAll();

$condiciones = [
 'consumidor_final' => 'Consumidor final',
    'inscripto' => 'Inscripto',
    'no_inscripto' => 'No inscripto',
    'exento' => 'Exento',
    'monotributo' => 'Monotributo',
];
$estados = [
    'activo' => 'Activo',
    'desconectado' => 'Desconectado',
    'inactivo' => 'Inactivo',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth_require_write();
    $action = $_POST['action'] ?? '';
    if ($action === 'recalc_saldos') {
        recalcular_saldo_alumnos($pdo);
        $corte = saldo_corte_desde();
        $msg = $corte !== null
            ? ('Saldos recalculados (desde ' . $corte . ').')
            : 'Saldos recalculados (histórico completo).';
        header('Location: alumnos.php?ok=' . rawurlencode($msg));
        exit;
    }
    if ($action === 'reactivar') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            header('Location: alumnos.php?err=' . rawurlencode('Alumno inválido.'));
            exit;
        }
        $st = $pdo->prepare('SELECT id, nombre_completo, activo FROM alumnos WHERE id = ?');
        $st->execute([$id]);
        $al = $st->fetch();
        if (!$al) {
            header('Location: alumnos.php?err=' . rawurlencode('Alumno inexistente.'));
            exit;
        }
        if ((int) ($al['activo'] ?? 0) === 1) {
            header('Location: alumnos.php?ok=' . rawurlencode('El alumno ya estaba activo.'));
            exit;
        }
        $pdo->prepare(
            "UPDATE alumnos
             SET activo = 1,
                 estado_cuenta = 'activo',
                 fecha_inactivacion = NULL
             WHERE id = ?"
        )->execute([$id]);
        $nom = trim((string) ($al['nombre_completo'] ?? ''));
        header(
            'Location: alumnos.php?activo=activos&ok='
                . rawurlencode($nom !== '' ? "Alumno reactivado: {$nom}." : 'Alumno reactivado.')
        );
        exit;
    }
    if ($action === 'save') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id > 0) {
            $stAct = $pdo->prepare('SELECT activo FROM alumnos WHERE id = ?');
            $stAct->execute([$id]);
            $act = $stAct->fetchColumn();
            if ($act === false) {
                header('Location: alumnos.php?err=' . rawurlencode('Alumno inexistente.'));
                exit;
            }
            if ((int) $act !== 1) {
                header('Location: alumnos.php?err=' . rawurlencode('Alumno inactivo: no se permiten cambios desde la app.'));
                exit;
            }
        }
        $codigoLegacy = isset($_POST['codigo_legacy']) && $_POST['codigo_legacy'] !== ''
            ? (int) $_POST['codigo_legacy'] : null;
        $nombre = trim((string) ($_POST['nombre_completo'] ?? ''));
        $direccion = trim((string) ($_POST['direccion'] ?? ''));
        $documento = trim((string) ($_POST['documento'] ?? '')) ?: null;
        $condicion = (string) ($_POST['condicion_iva'] ?? 'consumidor_final');
        if (!isset($condiciones[$condicion])) {
            $condicion = 'consumidor_final';
        }
        $cuit = normalize_cuit($_POST['cuit'] ?? null);
        if ($condicion !== 'consumidor_final') {
            if (!cuit_ok($cuit)) {
                $q = $id ? ('id=' . $id . '&') : '';
                header('Location: alumnos.php?' . $q . 'err=' . rawurlencode('CUIT obligatorio y válido (11 dígitos) para esta condición IVA.'));
                exit;
            }
        }
        $fechaIng = trim((string) ($_POST['fecha_ingreso'] ?? ''));
        $fechaIngSql = $fechaIng !== '' ? $fechaIng : null;
        $fechaIna = trim((string) ($_POST['fecha_inactivacion'] ?? ''));
        $fechaInaSql = $fechaIna !== '' ? $fechaIna : null;
        $estadoCuenta = (string) ($_POST['estado_cuenta'] ?? 'activo');
        if (!isset($estados[$estadoCuenta])) {
            $estadoCuenta = 'activo';
        }
        $observaciones = trim((string) ($_POST['observaciones'] ?? '')) ?: null;
        $ordenRef = trim((string) ($_POST['orden_referencia'] ?? '')) ?: null;
        $haceFactura = isset($_POST['hace_factura']) ? 1 : 0;
        $curso = trim((string) ($_POST['curso'] ?? '')) ?: null;
        $tipoAlumno = (string) ($_POST['tipo_alumno'] ?? 'regular');
        if (!in_array($tipoAlumno, ['regular', 'postgrado'], true)) {
            $tipoAlumno = 'regular';
        }
        $barrioId = isset($_POST['barrio_id']) && $_POST['barrio_id'] !== '' ? (int) $_POST['barrio_id'] : null;
        $provincia = trim((string) ($_POST['provincia'] ?? '')) ?: null;
        $ciudad = trim((string) ($_POST['ciudad'] ?? '')) ?: null;
        $activo = $estadoCuenta === 'activo' ? 1 : 0;

        if ($nombre === '') {
            header('Location: alumnos.php?err=' . rawurlencode('Nombre completo obligatorio.'));
            exit;
        }

        try {
            if ($id > 0) {
                $sql = 'UPDATE alumnos SET codigo_legacy = ?, nombre_completo = ?, direccion = ?, documento = ?,
                  condicion_iva = ?, cuit = ?, fecha_ingreso = ?, fecha_inactivacion = ?, estado_cuenta = ?,
                  observaciones = ?, orden_referencia = ?, hace_factura = ?, curso = ?, '
                    . ($hasTipoAlumno ? 'tipo_alumno = ?, ' : '')
                    . 'barrio_id = ?, provincia = ?, ciudad = ?, activo = ?
                  WHERE id = ?';
                $st = $pdo->prepare($sql);
                $params = [
                    $codigoLegacy, $nombre, $direccion ?: null, $documento,
                    $condicion, $cuit, $fechaIngSql, $fechaInaSql, $estadoCuenta,
                    $observaciones, $ordenRef, $haceFactura, $curso,
                ];
                if ($hasTipoAlumno) {
                    $params[] = $tipoAlumno;
                }
                $params = array_merge($params, [$barrioId, $provincia, $ciudad, $activo, $id]);
                $st->execute($params);
            } else {
                $sql = 'INSERT INTO alumnos (codigo_legacy, nombre_completo, direccion, documento, condicion_iva, cuit,
                  fecha_ingreso, fecha_inactivacion, estado_cuenta, observaciones, orden_referencia, hace_factura, curso, '
                  . ($hasTipoAlumno ? 'tipo_alumno, ' : '')
                  . 'barrio_id, provincia, ciudad, activo)
                  VALUES (' . ($hasTipoAlumno ? '?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?' : '?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?') . ')';
                $st = $pdo->prepare($sql);
                $params = [
                    $codigoLegacy, $nombre, $direccion ?: null, $documento,
                    $condicion, $cuit, $fechaIngSql, $fechaInaSql, $estadoCuenta,
                    $observaciones, $ordenRef, $haceFactura, $curso,
                ];
                if ($hasTipoAlumno) {
                    $params[] = $tipoAlumno;
                }
                $params = array_merge($params, [$barrioId, $provincia, $ciudad, $activo]);
                $st->execute($params);
            }
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), '1062') || str_contains($e->getMessage(), 'Duplicate')) {
                $q = $id ? ('id=' . $id . '&') : '';
                header('Location: alumnos.php?' . $q . 'err=' . rawurlencode('CUIT duplicado u otro dato único repetido.'));
                exit;
            }
            header('Location: alumnos.php?err=' . rawurlencode('Error al guardar: ' . $e->getMessage()));
            exit;
        }
        header('Location: alumnos.php?ok=1');
        exit;
    }
}

$edit = null;
if (isset($_GET['id'])) {
    $st = $pdo->prepare('SELECT * FROM alumnos WHERE id = ?');
    $st->execute([(int) $_GET['id']]);
    $edit = $st->fetch();
    if ($edit && (int) ($edit['activo'] ?? 0) !== 1) {
        header('Location: alumnos.php?err=' . rawurlencode('Alumno inactivo: solo se permite consultar.'));
        exit;
    }
}

$activoFiltro = (string) ($_GET['activo'] ?? 'activos'); // default: operar con activos
if (!in_array($activoFiltro, ['activos', 'inactivos', 'todos'], true)) {
    $activoFiltro = 'activos';
}
$whereActivo = '';
if ($activoFiltro === 'activos') {
    $whereActivo = ' WHERE a.activo = 1';
} elseif ($activoFiltro === 'inactivos') {
    $whereActivo = ' WHERE a.activo = 0';
}

$usaComponentesPago = db_has_column($pdo, 'pago_registrado', 'importe_capital')
    && db_has_column($pdo, 'pago_registrado', 'importe_interes')
    && db_has_column($pdo, 'pago_registrado', 'importe_beca_perdida')
    && db_has_column($pdo, 'pago_registrado', 'importe_descuento');
$haberExpr = $usaComponentesPago
    ? 'COALESCE(NULLIF(importe_capital, 0), COALESCE(importe, 0)) + COALESCE(importe_interes, 0) + COALESCE(importe_beca_perdida, 0) - COALESCE(importe_descuento, 0)'
    : 'COALESCE(importe, 0)';
$hasCcAjusteDebe = db_has_column($pdo, 'cc_ajuste_debe', 'debe');
$joinDebeAjuste = $hasCcAjusteDebe
    ? 'LEFT JOIN (
       SELECT alumno_id, SUM(COALESCE(debe, 0)) AS debe_ajuste
       FROM cc_ajuste_debe
       GROUP BY alumno_id
     ) da ON da.alumno_id = a.id'
    : '';
$exprDebeAjuste = $hasCcAjusteDebe ? 'COALESCE(da.debe_ajuste, 0)' : '0';

$sql = '
    SELECT
        a.*,
        b.nombre AS barrio_nombre,
        up.ultimo_pago,
        (COALESCE(d.debe_total, 0) + ' . $exprDebeAjuste . ') AS debe_total,
        COALESCE(h.haber_total, 0) AS haber_total
     FROM alumnos a
     LEFT JOIN barrios b ON b.id = a.barrio_id
     LEFT JOIN (
       SELECT alumno_id, MAX(fecha_pago) AS ultimo_pago
       FROM pago_registrado
       GROUP BY alumno_id
     ) up ON up.alumno_id = a.id
     LEFT JOIN (
       SELECT
         cm.alumno_id,
         SUM(
           CASE
             WHEN COALESCE(cm.importe_original, 0) > 0 THEN cm.importe_original
             ELSE COALESCE(cm.saldo, 0)
           END
         ) AS debe_total
       FROM cuota_mensual cm
       GROUP BY cm.alumno_id
     ) d ON d.alumno_id = a.id
     ' . $joinDebeAjuste . '
     LEFT JOIN (
       SELECT
         alumno_id,
         SUM(' . $haberExpr . ') AS haber_total
       FROM pago_registrado
       GROUP BY alumno_id
     ) h ON h.alumno_id = a.id
     ' . $whereActivo . '
     ORDER BY a.nombre_completo
';
$rows = $pdo->query($sql)->fetchAll();

$regAllowed = ['regular', 'riesgo', 'irregular', 'sin_pagos', 'no_activo'];
$regModo = (string) ($_GET['reg_modo'] ?? '');
if ($regModo === '' && isset($_GET['reg'])) {
    $regModo = 'filtrar';
} elseif ($regModo === '') {
    $regModo = 'todos';
}
if (!in_array($regModo, ['todos', 'filtrar'], true)) {
    $regModo = 'todos';
}
$filtrarRegularidad = $regModo === 'filtrar';
$selectedReg = [];
if ($filtrarRegularidad) {
    $rawReg = $_GET['reg'] ?? [];
    if (!is_array($rawReg)) {
        $rawReg = $rawReg !== '' ? [(string) $rawReg] : [];
    }
    $selectedReg = array_values(array_intersect($regAllowed, array_map('strval', $rawReg)));
}
$incluirHeredados = isset($_GET['incluir_heredados']) && $_GET['incluir_heredados'] === '1';

layout_start($config, 'Alumnos');
if (isset($_GET['ok'])) {
    $okMsg = (string) $_GET['ok'];
    flash_ok($okMsg !== '' && $okMsg !== '1' ? $okMsg : 'Guardado correctamente.');
}
if (isset($_GET['err'])) {
    flash_err((string) $_GET['err']);
}

echo '<h1>Alumnos / clientes</h1>';
echo '<p class="muted">Ficha alineada al modo operativo (Archivos → Clientes → Ficha de Cliente).</p>';

echo '<div class="toolbar">';
echo '<button type="button" class="btn-secondary" data-open-modal="alumno-modal">Nuevo alumno</button>';
echo '<form method="post" class="inline"><input type="hidden" name="action" value="recalc_saldos">';
echo '<button type="submit" class="btn-secondary" title="Recalcular saldo para todos los alumnos">Recalcular saldos</button></form>';
echo '</div>';

echo '<form method="get" class="form alumnos-filtro-form">';
echo '<fieldset class="fieldset"><legend>Filtros del listado</legend>';
echo '<div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));gap:0.75rem 1.25rem">';
echo '<label>Estado del alumno <select name="activo">';
echo '<option value="activos"' . ($activoFiltro === 'activos' ? ' selected' : '') . '>Activos (operación)</option>';
echo '<option value="inactivos"' . ($activoFiltro === 'inactivos' ? ' selected' : '') . '>Inactivos (reactivar)</option>';
echo '<option value="todos"' . ($activoFiltro === 'todos' ? ' selected' : '') . '>Todos</option>';
echo '</select></label>';
echo '<label>Regularidad de pago <select name="reg_modo" id="reg-modo-select">';
echo '<option value="todos"' . ($regModo === 'todos' ? ' selected' : '') . '>Todos</option>';
echo '<option value="filtrar"' . ($regModo === 'filtrar' ? ' selected' : '') . '>Solo categorías marcadas…</option>';
echo '</select></label>';
echo '</div>';
echo '<div id="reg-checks" class="reg-checks-grid"' . ($regModo === 'todos' ? ' data-disabled="1"' : '') . '>';
echo '<p class="muted small" style="grid-column:1/-1;margin:0">Solo aplica si elegiste «Solo categorías marcadas».</p>';
echo '<label class="check"><input type="checkbox" name="reg[]" value="regular"' . (in_array('regular', $selectedReg, true) ? ' checked' : '') . '> Regular (último pago ≤ 45 días)</label>';
echo '<label class="check"><input type="checkbox" name="reg[]" value="riesgo"' . (in_array('riesgo', $selectedReg, true) ? ' checked' : '') . '> Riesgo (46-90 días)</label>';
echo '<label class="check"><input type="checkbox" name="reg[]" value="irregular"' . (in_array('irregular', $selectedReg, true) ? ' checked' : '') . '> Irregular (&gt; 90 días)</label>';
echo '<label class="check"><input type="checkbox" name="reg[]" value="sin_pagos"' . (in_array('sin_pagos', $selectedReg, true) ? ' checked' : '') . '> Sin pagos</label>';
echo '<label class="check"><input type="checkbox" name="reg[]" value="no_activo"' . (in_array('no_activo', $selectedReg, true) ? ' checked' : '') . '> Inactivo (alumno)</label>';
echo '</div>';
echo '<label class="check" style="margin-top:0.5rem"><input type="checkbox" name="incluir_heredados" value="1"' . ($incluirHeredados ? ' checked' : '') . '> Incluir casos heredados (haber sin debe)</label>';
if ($activoFiltro === 'inactivos' || $activoFiltro === 'todos') {
    echo '<p class="muted" style="margin:0.35rem 0 0">Alumnos inactivos: usá <strong>Reactivar</strong> en la fila '
        . '(o al editar un activo, <em>Estado cuenta</em> distinto de Activo lo da de baja).</p>';
}
echo '<div class="form-actions"><button type="submit">Aplicar</button> ';
echo '<a href="alumnos.php?activo=activos&amp;reg_modo=todos">Ver todos (activos)</a></div>';
echo '</fieldset></form>';
echo '<script>(function(){var s=document.getElementById("reg-modo-select"),b=document.getElementById("reg-checks");if(!s||!b)return;function t(){var d=s.value==="todos";b.classList.toggle("is-muted",d);b.querySelectorAll("input[type=checkbox]").forEach(function(i){i.disabled=d;});}s.addEventListener("change",t);t();})();</script>';
echo '<dialog id="alumno-modal" class="app-modal"><div class="app-modal-content">';
echo '<div class="app-modal-head"><h3>' . ($edit ? 'Editar alumno' : 'Nuevo alumno') . '</h3>';
echo '<button type="button" class="app-modal-close" data-close-modal="alumno-modal">Cerrar</button></div>';
echo '<form method="post" class="form form-grid">';
echo '<input type="hidden" name="action" value="save">';
if ($edit) {
    echo '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">';
}

$row = $edit ?: [];

echo '<label>Código legacy <input name="codigo_legacy" type="number" value="' . h((string) ($row['codigo_legacy'] ?? '')) . '"></label>';
echo '<label>Nombre completo * <input name="nombre_completo" required maxlength="120" value="' . h($row['nombre_completo'] ?? '') . '"></label>';
echo '<label>Dirección <input name="direccion" maxlength="200" value="' . h($row['direccion'] ?? '') . '"></label>';
echo '<label>Barrio <select name="barrio_id"><option value="">—</option>';
foreach ($barrios as $b) {
    $sel = isset($row['barrio_id']) && (int) $row['barrio_id'] === (int) $b['id'] ? ' selected' : '';
    echo '<option value="' . (int) $b['id'] . '"' . $sel . '>' . h($b['nombre']) . '</option>';
}
echo '</select></label>';
echo '<label>Provincia <input name="provincia" maxlength="80" value="' . h($row['provincia'] ?? '') . '"></label>';
echo '<label>Ciudad <input name="ciudad" maxlength="80" value="' . h($row['ciudad'] ?? '') . '"></label>';
echo '<label>Documento <input name="documento" maxlength="20" value="' . h($row['documento'] ?? '') . '"></label>';
echo '<label>Condición IVA <select name="condicion_iva">';
foreach ($condiciones as $k => $lab) {
    $sel = ($row['condicion_iva'] ?? 'consumidor_final') === $k ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($lab) . '</option>';
}
echo '</select></label>';
echo '<label>CUIT (11 dígitos) <input name="cuit" maxlength="13" placeholder="20999999991" value="' . h($row['cuit'] ?? '') . '"></label>';
echo '<label>Fecha ingreso <input name="fecha_ingreso" type="date" value="' . h($row['fecha_ingreso'] ?? '') . '"></label>';
echo '<label>Estado cuenta <select name="estado_cuenta">';
foreach ($estados as $k => $lab) {
    $sel = ($row['estado_cuenta'] ?? 'activo') === $k ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($lab) . '</option>';
}
echo '</select></label>';
echo '<label>Fecha baja / inactivación <input name="fecha_inactivacion" type="date" value="' . h($row['fecha_inactivacion'] ?? '') . '"></label>';
echo '<label>Orden (referencia) <input name="orden_referencia" maxlength="12" value="' . h($row['orden_referencia'] ?? '') . '"></label>';
echo '<label>Curso / texto libre corto <textarea name="curso" rows="2" maxlength="120">' . h($row['curso'] ?? '') . '</textarea></label>';
if ($hasTipoAlumno) {
    $tipoSel = (string) ($row['tipo_alumno'] ?? 'regular');
    echo '<label>Tipo de alumno <select name="tipo_alumno">';
    echo '<option value="regular"' . ($tipoSel === 'regular' ? ' selected' : '') . '>Regular</option>';
    echo '<option value="postgrado"' . ($tipoSel === 'postgrado' ? ' selected' : '') . '>Postgrado</option>';
    echo '</select></label>';
}
echo '<label>Observaciones <textarea name="observaciones" rows="3" maxlength="500">' . h($row['observaciones'] ?? '') . '</textarea></label>';
$hf = !empty($row['hace_factura']);
echo '<label class="check"><input type="checkbox" name="hace_factura" value="1"' . ($hf ? ' checked' : '') . '> Hace factura</label>';
echo '<div class="form-actions"><button type="submit">Guardar</button></div>';
echo '</form>';
if ($edit) {
    echo '<p><a href="conceptos_alumno.php?alumno_id=' . (int) $edit['id'] . '">Conceptos / abonos de este alumno</a></p>';
}
echo '</div></dialog>';
if ($edit) {
    echo '<span data-auto-open="alumno-modal"></span>';
}

if ($filtrarRegularidad && count($selectedReg) === 0) {
    echo '<p class="warn">Elegí «Todos» en regularidad, o marcá al menos una categoría abajo.</p>';
}

$listadoCount = 0;
echo '<h2>Listado</h2>';
echo '<p class="muted" id="alumnos-listado-resumen"></p>';
echo '<table class="table js-data-table"><thead><tr><th>Id</th><th>Legacy</th><th>Nombre</th><th>Barrio</th><th>Saldo</th><th>Último pago</th><th>Regularidad</th><th>Obs.</th><th data-nosort="1"></th></tr></thead><tbody>';
foreach ($rows as $r) {
    $isActive = (int) ($r['activo'] ?? 0) === 1;
    $saldo = (float) ($r['saldo_cc'] ?? 0);
    $ultimoPago = $r['ultimo_pago'] ?? null;
    $ultimoPagoTxt = '';
    if (!empty($ultimoPago)) {
        $ts = strtotime((string) $ultimoPago);
        $ultimoPagoTxt = $ts !== false ? date('d/m/Y', $ts) : (string) $ultimoPago;
    }
    $badgeClass = 'badge-muted';
    $badgeLabel = 'Sin pagos';
    $regKey = 'sin_pagos';
    if (!$isActive) {
        $badgeClass = 'badge-muted';
        $badgeLabel = 'Inactivo (alumno)';
        $regKey = 'no_activo';
    } elseif (!empty($ultimoPago)) {
        $dias = (int) floor((time() - (strtotime((string) $ultimoPago) ?: time())) / 86400);
        if ($dias <= 45) {
            $badgeClass = 'badge-ok';
            $badgeLabel = 'Regular';
            $regKey = 'regular';
        } elseif ($dias <= 90) {
            $badgeClass = 'badge-warn';
            $badgeLabel = 'Riesgo';
            $regKey = 'riesgo';
        } else {
            $badgeClass = 'badge-bad';
            $badgeLabel = 'Irregular';
            $regKey = 'irregular';
        }
    } else {
        $badgeClass = 'badge-bad';
        $badgeLabel = 'Sin pagos';
        $regKey = 'sin_pagos';
    }

    $debeTotal = (float) ($r['debe_total'] ?? 0);
    $haberTotal = (float) ($r['haber_total'] ?? 0);
    $esHeredado = $debeTotal <= 0.00001 && $haberTotal > 0.00001;

    if ($esHeredado && !$incluirHeredados) {
        continue;
    }

    if ($filtrarRegularidad && !in_array($regKey, $selectedReg, true)) {
        continue;
    }
    $listadoCount++;
    echo '<tr><td>' . (int) $r['id'] . '</td><td>' . h((string) ($r['codigo_legacy'] ?? '')) . '</td><td>' . h($r['nombre_completo']) . '</td>';
    echo '<td>' . h($r['barrio_nombre'] ?? '') . '</td><td>$ ' . number_format($saldo, 2, ',', '.') . '</td>';
    echo '<td>' . h($ultimoPagoTxt) . '</td><td><span class="badge ' . h($badgeClass) . '">' . h($badgeLabel) . '</span></td>';
    if ($esHeredado) {
        echo '<td><span class="badge badge-info">Heredado</span></td>';
    } else {
        echo '<td></td>';
    }
    echo '<td class="nowrap">';
    echo '<span class="action-icons">';
    if ($isActive) {
        echo '<a class="action-icon" href="alumnos.php?id=' . (int) $r['id'] . '" title="Editar alumno">✏️</a>';
        echo '<a class="action-icon" href="conceptos_alumno.php?alumno_id=' . (int) $r['id'] . '" title="Conceptos del alumno">✅</a>';
    } else {
        echo '<form method="post" class="inline-reactivar-form" style="display:inline;margin:0" '
            . 'onsubmit="return confirm(\'¿Reactivar este alumno? Volverá a aparecer en operación (cobro, conceptos, etc.).\');">';
        echo '<input type="hidden" name="action" value="reactivar">';
        echo '<input type="hidden" name="id" value="' . (int) $r['id'] . '">';
        echo '<button type="submit" class="action-icon action-icon-btn" title="Reactivar alumno">↩️</button>';
        echo '</form>';
        echo '<span class="action-icon is-disabled" title="Editar disponible tras reactivar">✏️</span>';
        echo '<span class="action-icon is-disabled" title="Conceptos disponible tras reactivar">✅</span>';
    }
    echo '<a class="action-icon" href="cuenta_corriente.php?alumno_id=' . (int) $r['id'] . '" title="Cuenta corriente">💳</a>';
    if ($esHeredado) {
        echo '<a class="action-icon" href="cuenta_corriente.php?alumno_id=' . (int) $r['id'] . '" title="Revisar / ajustar manualmente">🛠️</a>';
    }
    echo '</span></td></tr>';
}
echo '</tbody></table>';
echo '<script>(function(){var e=document.getElementById("alumnos-listado-resumen");if(e)e.textContent="Mostrando '
    . (int) $listadoCount . ' alumno' . ($listadoCount === 1 ? '' : 's') . '.";})();</script>';

layout_end();
