<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/Cobranza.php';
require_once dirname(__DIR__) . '/src/Saldos.php';

/**
 * Misma regla que cuenta corriente: ceros como celda vacía.
 */
function cobro_fmt_money(float $value): string
{
    if (abs($value) < 0.00001) {
        return '';
    }

    return '$ ' . number_format($value, 2, ',', '.');
}

/**
 * Feriados aplicables para una jurisdicción (nacional + provincia + ciudad).
 *
 * @return string[] Fechas Y-m-d
 */
function cobro_fechas_feriado(PDO $pdo, ?string $provincia, ?string $ciudad): array
{
    if (!db_has_column($pdo, 'feriados', 'fecha')) {
        return [];
    }
    $prov = trim((string) ($provincia ?? ''));
    $ciu = trim((string) ($ciudad ?? ''));
    $params = [];
    $sql = "SELECT fecha FROM feriados WHERE ambito = 'nacional'";
    if ($prov !== '') {
        $sql .= " OR (ambito = 'provincia' AND UPPER(COALESCE(provincia, '')) = UPPER(?))";
        $params[] = $prov;
    }
    if ($ciu !== '') {
        if ($prov !== '') {
            $sql .= " OR (ambito = 'ciudad' AND UPPER(COALESCE(provincia, '')) = UPPER(?) AND UPPER(COALESCE(ciudad, '')) = UPPER(?))";
            $params[] = $prov;
            $params[] = $ciu;
        } else {
            $sql .= " OR (ambito = 'ciudad' AND UPPER(COALESCE(ciudad, '')) = UPPER(?))";
            $params[] = $ciu;
        }
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($rows as $f) {
        $v = trim((string) $f);
        if ($v !== '') {
            $out[$v] = true;
        }
    }
    return array_keys($out);
}

$pdo = Db::pdo($config);
$fechaCorteCobro = saldo_corte_desde();

$hasPacDetalle = db_has_column($pdo, 'pago_aplica_cuota', 'importe_capital')
    && db_has_column($pdo, 'pago_aplica_cuota', 'importe_recargo')
    && db_has_column($pdo, 'pago_aplica_cuota', 'importe_descuento');

$hasPagoComponentes = db_has_column($pdo, 'pago_registrado', 'importe_capital');
$hasBecaRegla = db_has_column($pdo, 'cuota_mensual', 'importe_diferencia_beca')
    && db_has_column($pdo, 'pago_registrado', 'importe_beca_perdida')
    && db_has_column($pdo, 'pago_aplica_cuota', 'importe_beca_perdida');

$alumnoId = isset($_GET['alumno_id']) ? (int) $_GET['alumno_id'] : 0;
$buscar = trim((string) ($_GET['q'] ?? ''));
$fechaPago = trim((string) ($_GET['fecha_pago'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaPago)) {
    $fechaPago = date('Y-m-d');
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

$param = $pdo->query('SELECT * FROM parametros_cobranza WHERE id = 1')->fetch() ?: [];
$coef = (float) ($param['recargo_coeficiente'] ?? 0);
$stAbonoRef = $pdo->query(
    "SELECT COALESCE(MAX(importe_referencia), 0)
     FROM articulos
     WHERE activo = 1
       AND es_abono = 1
       AND importe_referencia > 0
       AND UPPER(detalle) NOT LIKE '%BECA%'
       AND UPPER(detalle) NOT LIKE '%DESCUENTO%'"
);
$abonoCompletoRefBeca = $stAbonoRef ? round((float) $stAbonoRef->fetchColumn(), 2) : 0.0;
$param['abono_completo_referencia_beca'] = $abonoCompletoRefBeca;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $alumnoId = (int) ($_POST['alumno_id'] ?? 0);
    $fechaPago = trim((string) ($_POST['fecha_pago'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaPago)) {
        header('Location: registrar_cobro.php?alumno_id=' . $alumnoId . '&err=' . rawurlencode('Fecha de pago inválida.'));
        exit;
    }

    if (!$hasPacDetalle || !$hasPagoComponentes || !$hasBecaRegla) {
        header(
            'Location: registrar_cobro.php?alumno_id=' . $alumnoId . '&fecha_pago=' . rawurlencode($fechaPago)
                . '&err=' . rawurlencode('Falta migración 14, 16 y/o 17 (pagos, detalle por cuota y BECA fuera de término).')
        );
        exit;
    }

    $medio = trim((string) ($_POST['medio'] ?? 'efectivo'));
    if (strlen($medio) > 40) {
        $medio = substr($medio, 0, 40);
    }

    if (empty($_POST['confirmar_cobro'])) {
        header('Location: registrar_cobro.php?alumno_id=' . $alumnoId . '&fecha_pago=' . rawurlencode($fechaPago) . '&err=' . rawurlencode('Confirmación inválida.'));
        exit;
    }

    $ids = $_POST['cuota_id'] ?? [];
    if (!is_array($ids) || count($ids) === 0) {
        header('Location: registrar_cobro.php?alumno_id=' . $alumnoId . '&fecha_pago=' . rawurlencode($fechaPago) . '&err=' . rawurlencode('Seleccioná al menos una cuota.'));
        exit;
    }

    $stAl = $pdo->prepare('SELECT id, nombre_completo, documento, activo, provincia, ciudad FROM alumnos WHERE id = ?');
    $stAl->execute([$alumnoId]);
    $al = $stAl->fetch();
    if (!$al || (int) ($al['activo'] ?? 0) !== 1) {
        header('Location: registrar_cobro.php?err=' . rawurlencode('Alumno inexistente o inactivo.'));
        exit;
    }
    $param['fechas_feriado'] = cobro_fechas_feriado($pdo, (string) ($al['provincia'] ?? ''), (string) ($al['ciudad'] ?? ''));

    $stCu = $pdo->prepare(
        'SELECT cm.*, COALESCE(pa.aplicado, 0) AS aplicado_acum, COALESCE(pl.haber_legacy, 0) AS haber_legacy_acum,
                EXISTS(
                    SELECT 1
                    FROM alumno_articulo aa_b
                    JOIN articulos ar_b ON ar_b.id = aa_b.articulo_id
                    WHERE aa_b.alumno_id = cm.alumno_id
                      AND ar_b.activo = 1
                      AND UPPER(ar_b.detalle) LIKE \'%BECA%\'
                ) AS tiene_beca
         FROM cuota_mensual cm
         LEFT JOIN (
            SELECT cuota_id, SUM(importe_aplicado) AS aplicado
            FROM pago_aplica_cuota
            GROUP BY cuota_id
         ) pa ON pa.cuota_id = cm.id'
        . cobranza_sql_join_legacy_haber_por_periodo() . '
         WHERE cm.alumno_id = ? AND cm.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
           AND cm.estado <> \'anulada\'
           AND cm.anio >= ' . (int) cobranza_anio_operativo_desde() . '
           AND ' . cobranza_sql_expr_saldo_impago() . ' > 0.005'
    );
    $stCu->execute(array_merge([$alumnoId], array_map('intval', $ids)));
    $cuotas = $stCu->fetchAll();
    if (count($cuotas) !== count($ids)) {
        header('Location: registrar_cobro.php?alumno_id=' . $alumnoId . '&fecha_pago=' . rawurlencode($fechaPago) . '&err=' . rawurlencode('Alguna cuota no está disponible para cobrar.'));
        exit;
    }

    $lineas = [];
    $sumCap = 0.0;
    $sumRec = 0.0;
    $sumDesc = 0.0;
    $sumBeca = 0.0;
    foreach ($cuotas as $c) {
        $lineas[] = ['cuota' => $c, 'calc' => cobranza_calcular_linea_cuota($param, $c, $fechaPago)];
    }
    foreach ($lineas as $L) {
        $sumCap += $L['calc']['importe_capital'];
        $sumRec += $L['calc']['importe_recargo_variable'] + $L['calc']['importe_recargo_fijo'];
        $sumDesc += $L['calc']['importe_descuento'];
        $sumBeca += $L['calc']['importe_beca_perdida'];
    }
    $total = round($sumCap + $sumRec + $sumBeca, 2);

    $ref = 'COBRO:' . $fechaPago . ':' . implode('-', array_map(static fn ($x) => (string) $x['cuota']['id'], $lineas));

    $pdo->beginTransaction();
    try {
        $insP = $pdo->prepare(
            'INSERT INTO pago_registrado (alumno_id, fecha_pago, importe, importe_capital, importe_interes, importe_beca_perdida, importe_descuento, medio, referencia, nota)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $nota = 'Cobro múltiple; recargo diario coef=' . (string) $coef;
        $insP->execute([
            $alumnoId,
            $fechaPago,
            $total,
            round($sumCap, 2),
            round($sumRec, 2),
            round($sumBeca, 2),
            round($sumDesc, 2),
            $medio,
            $ref,
            $nota,
        ]);
        $pagoId = (int) $pdo->lastInsertId();

        $upd = $pdo->prepare(
            'UPDATE cuota_mensual
             SET saldo = 0,
                 estado = \'pagada\',
                 importe_diferencia_beca = CASE
                    WHEN ? > 0 AND COALESCE(importe_diferencia_beca, 0) <= 0 THEN ?
                    ELSE importe_diferencia_beca
                 END
             WHERE id = ?'
        );
        $insA = $pdo->prepare(
            'INSERT INTO pago_aplica_cuota (pago_id, cuota_id, importe_aplicado, importe_capital, dias_mora, importe_recargo, importe_descuento, importe_beca_perdida)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($lineas as $L) {
            $c = $L['cuota'];
            $calc = $L['calc'];
            $cid = (int) $c['id'];
            $upd->execute([
                round($calc['importe_beca_perdida'], 2),
                round($calc['importe_beca_perdida'], 2),
                $cid,
            ]);
            $recTot = round($calc['importe_recargo_variable'] + $calc['importe_recargo_fijo'], 2);
            $insA->execute([
                $pagoId,
                $cid,
                round($calc['importe_capital'], 2),
                round($calc['importe_capital'], 2),
                $calc['dias_mora'],
                $recTot,
                $calc['importe_descuento'],
                $calc['importe_beca_perdida'],
            ]);
        }

        $pdo->commit();
        recalcular_saldo_alumnos($pdo, $alumnoId);
        header('Location: registrar_cobro.php?alumno_id=' . $alumnoId . '&pago_id=' . $pagoId . '&ok=1');
        exit;
    } catch (Throwable $e) {
        $pdo->rollBack();
        header('Location: registrar_cobro.php?alumno_id=' . $alumnoId . '&fecha_pago=' . rawurlencode($fechaPago) . '&err=' . rawurlencode($e->getMessage()));
        exit;
    }
}

$alumno = null;
$cuotasPendientes = [];
$cuotasLiquidadas = [];
$lineasCalc = [];
$calcError = null;
$pagoRecibo = null;
$lineasRecibo = [];

$paso = trim((string) ($_GET['paso'] ?? ''));
$cuotaSelGet = $_GET['cuota_sel'] ?? [];
if (!is_array($cuotaSelGet)) {
    $cuotaSelGet = $cuotaSelGet !== '' && $cuotaSelGet !== null ? [(string) $cuotaSelGet] : [];
}
$cuotaSelGet = array_values(array_unique(array_filter(array_map('intval', $cuotaSelGet), static fn (int $v): bool => $v > 0)));

if ($alumnoId > 0) {
    $stAl = $pdo->prepare('SELECT id, nombre_completo, codigo_legacy, documento, activo, provincia, ciudad FROM alumnos WHERE id = ?');
    $stAl->execute([$alumnoId]);
    $alumno = $stAl->fetch();
    if ($alumno && (int) ($alumno['activo'] ?? 0) === 1) {
        $param['fechas_feriado'] = cobro_fechas_feriado($pdo, (string) ($alumno['provincia'] ?? ''), (string) ($alumno['ciudad'] ?? ''));
        $anioOp = cobranza_anio_operativo_desde();
        $sqlPend = 'SELECT
                cm.*,
                COALESCE(pa.aplicado, 0) AS aplicado_acum,
                COALESCE(pl.haber_legacy, 0) AS haber_legacy_acum,
                EXISTS(
                    SELECT 1
                    FROM alumno_articulo aa_b
                    JOIN articulos ar_b ON ar_b.id = aa_b.articulo_id
                    WHERE aa_b.alumno_id = cm.alumno_id
                      AND ar_b.activo = 1
                      AND UPPER(ar_b.detalle) LIKE \'%BECA%\'
                ) AS tiene_beca,
                COALESCE(
                    cm.fecha_vencimiento,
                    STR_TO_DATE(CONCAT(cm.anio, "-", LPAD(cm.mes, 2, "0"), "-01"), "%Y-%m-%d")
                ) AS fecha_mov,
                CASE
                    WHEN COALESCE(cm.importe_original, 0) > 0
                        THEN cm.importe_original
                    ELSE COALESCE(cm.saldo, 0) + COALESCE(pa.aplicado, 0)
                END AS debe_cc
             FROM cuota_mensual cm
             LEFT JOIN (
                SELECT cuota_id, SUM(importe_aplicado) AS aplicado
                FROM pago_aplica_cuota
                GROUP BY cuota_id
             ) pa ON pa.cuota_id = cm.id'
            . cobranza_sql_join_legacy_haber_por_periodo() . '
             WHERE cm.alumno_id = ?
               AND cm.estado <> \'anulada\'
               AND cm.anio >= ' . (int) $anioOp . '
               AND ' . cobranza_sql_expr_saldo_impago() . ' > 0.005';
        $paramsPend = [$alumnoId];
        // Cobro no usa SALDO_CORTE_DESDE: el corte es para CC/legacy; acá solo anio operativo (2026+).
        $sqlPend .= ' ORDER BY cm.anio, cm.mes';
        $stC = $pdo->prepare($sqlPend);
        $stC->execute($paramsPend);
        $cuotasPendientes = [];
        foreach ($stC->fetchAll() as $row) {
            if (cobranza_saldo_impago_cuota($row) <= 0.005) {
                continue;
            }
            $cuotasPendientes[] = $row;
        }

        $sqlLiq = 'SELECT
                cm.*,
                COALESCE(pa.aplicado, 0) AS aplicado_acum,
                COALESCE(pl.haber_legacy, 0) AS haber_legacy_acum,
                EXISTS(
                    SELECT 1
                    FROM alumno_articulo aa_b
                    JOIN articulos ar_b ON ar_b.id = aa_b.articulo_id
                    WHERE aa_b.alumno_id = cm.alumno_id
                      AND ar_b.activo = 1
                      AND UPPER(ar_b.detalle) LIKE \'%BECA%\'
                ) AS tiene_beca,
                COALESCE(
                    cm.fecha_vencimiento,
                    STR_TO_DATE(CONCAT(cm.anio, "-", LPAD(cm.mes, 2, "0"), "-01"), "%Y-%m-%d")
                ) AS fecha_mov,
                CASE
                    WHEN COALESCE(cm.importe_original, 0) > 0
                        THEN cm.importe_original
                    ELSE COALESCE(cm.saldo, 0) + COALESCE(pa.aplicado, 0)
                END AS debe_cc
             FROM cuota_mensual cm
             LEFT JOIN (
                SELECT cuota_id, SUM(importe_aplicado) AS aplicado
                FROM pago_aplica_cuota
                GROUP BY cuota_id
             ) pa ON pa.cuota_id = cm.id'
            . cobranza_sql_join_legacy_haber_por_periodo() . '
             WHERE cm.alumno_id = ?
               AND cm.estado <> \'anulada\'
               AND cm.anio >= ' . (int) $anioOp . '
               AND ' . cobranza_sql_expr_saldo_impago() . ' <= 0.005';
        $paramsLiq = [$alumnoId];
        $sqlLiq .= ' ORDER BY cm.anio, cm.mes';
        $stLiq = $pdo->prepare($sqlLiq);
        $stLiq->execute($paramsLiq);
        $cuotasLiquidadas = $stLiq->fetchAll();

        $pendIds = array_map(static fn (array $c): int => (int) $c['id'], $cuotasPendientes);
        $cuotaSelGet = array_values(array_intersect($cuotaSelGet, $pendIds));

        if ($paso === 'calc') {
            if (count($cuotaSelGet) === 0) {
                $calcError = 'Marcá al menos una cuota impaga y volvé a calcular.';
            } else {
                $placeholders = implode(',', array_fill(0, count($cuotaSelGet), '?'));
                $stCu = $pdo->prepare(
                    "SELECT cm.*, COALESCE(pa.aplicado, 0) AS aplicado_acum, COALESCE(pl.haber_legacy, 0) AS haber_legacy_acum,
                            EXISTS(
                                SELECT 1
                                FROM alumno_articulo aa_b
                                JOIN articulos ar_b ON ar_b.id = aa_b.articulo_id
                                WHERE aa_b.alumno_id = cm.alumno_id
                                  AND ar_b.activo = 1
                                  AND UPPER(ar_b.detalle) LIKE '%BECA%'
                            ) AS tiene_beca
                     FROM cuota_mensual cm
                     LEFT JOIN (
                        SELECT cuota_id, SUM(importe_aplicado) AS aplicado
                        FROM pago_aplica_cuota
                        GROUP BY cuota_id
                     ) pa ON pa.cuota_id = cm.id"
                    . cobranza_sql_join_legacy_haber_por_periodo() . "
                     WHERE cm.alumno_id = ? AND cm.id IN ($placeholders)
                       AND cm.estado <> 'anulada'
                       AND cm.anio >= " . (int) cobranza_anio_operativo_desde() . "
                       AND " . cobranza_sql_expr_saldo_impago() . " > 0.005
                     ORDER BY cm.anio, cm.mes"
                );
                $stCu->execute(array_merge([$alumnoId], $cuotaSelGet));
                $cuotasSel = $stCu->fetchAll();
                if (count($cuotasSel) !== count($cuotaSelGet)) {
                    $calcError = 'Alguna cuota seleccionada ya no está disponible.';
                    $lineasCalc = [];
                } else {
                    foreach ($cuotasSel as $c) {
                        $lineasCalc[] = [
                            'cuota' => $c,
                            'calc' => cobranza_calcular_linea_cuota($param, $c, $fechaPago),
                        ];
                    }
                }
            }
        }
    }
}

$pagoId = isset($_GET['pago_id']) ? (int) $_GET['pago_id'] : 0;
if ($pagoId > 0 && $alumnoId > 0) {
    $stP = $pdo->prepare(
        'SELECT * FROM pago_registrado WHERE id = ? AND alumno_id = ?'
    );
    $stP->execute([$pagoId, $alumnoId]);
    $pagoRecibo = $stP->fetch();
    if ($pagoRecibo) {
        $stL = $pdo->prepare(
            'SELECT pac.*, cm.anio, cm.mes
             FROM pago_aplica_cuota pac
             JOIN cuota_mensual cm ON cm.id = pac.cuota_id
             WHERE pac.pago_id = ?
             ORDER BY cm.anio, cm.mes'
        );
        $stL->execute([$pagoId]);
        $lineasRecibo = $stL->fetchAll();
    }
}

layout_start($config, 'Registrar cobro');
if (isset($_GET['ok'])) {
    flash_ok('Cobro registrado. Podés imprimir el recibo abajo.');
}
if (isset($_GET['err'])) {
    flash_err((string) $_GET['err']);
}

echo '<h1>Registrar cobro / recibo</h1>';
echo '<p class="muted" style="max-width:48rem">La grilla con <strong>casillas (Pagar)</strong> aparece <strong>después de elegir un alumno</strong> (búsqueda abajo o ícono 💵 desde '
    . '<a href="cuenta_corriente.php">cuenta corriente</a>). Las columnas de esa tabla repiten el criterio de la cuenta corriente (Fecha, Debe, Haber, Saldo final), más la columna para marcar qué liquidar.</p>';
echo '<ol class="muted" style="max-width:48rem;line-height:1.55">';
echo '<li><strong>Elegí la fecha de pago</strong> y marcá <strong>una o varias cuotas impagas</strong> (solo esas se liquidan).</li>';
echo '<li>Pulsá <strong>Calcular importes</strong>: ahí se aplican descuento (pronto pago, estilo marca <strong>P</strong> Fox) o recargo por mora.</li>';
echo '<li>Revisá el detalle y confirmá con <strong>Registrar cobro</strong>.</li>';
echo '</ol>';
echo '<p class="muted">Recargo variable: <code>saldo × coef. diario × días de mora</code> (días calendario tras el tope en días hábiles). '
    . 'Descuento: monto fijo de <a href="parametros_cobranza.php">parámetros</a> si la fecha de pago entra en pronto pago del mes de esa cuota. '
    . 'Días hábiles consideran feriados configurados en <a href="feriados.php">Feriados</a>.</p>';

if (!$hasPacDetalle || !$hasPagoComponentes || !$hasBecaRegla) {
    echo '<p class="err">Requiere migraciones <code>14_pagos_componentes_interes_descuento.sql</code>, <code>16_pago_aplica_cuota_detalle.sql</code> y <code>17_beca_fuera_termino_quinto_habil.sql</code>.</p>';
}

echo '<form method="get" class="search-form">';
echo '<div class="search-title">Buscar alumno</div>';
echo '<div class="search-input-row">';
echo '<input name="q" value="' . h($buscar) . '" placeholder="Nombre, DNI o código legacy (ej: Perez, 32123456, 1502)">';
echo '<button type="submit" class="search-submit" aria-label="Buscar alumno">Buscar</button>';
echo '</div>';
echo '</form>';

if ($alumnoId <= 0 && $buscar !== '') {
    if (count($coincidencias) === 0) {
        echo '<p class="muted">Sin resultados para la búsqueda.</p>';
    } else {
        echo '<p class="muted">Resultados: ' . count($coincidencias) . '. En la columna 💵 abrís el alumno y ves la tabla tipo cuenta corriente con checkboxes.</p>';
        echo '<table class="table js-data-table"><thead><tr><th>Id</th><th>Legacy</th><th>Alumno</th><th>DNI</th><th data-nosort="1">Ir a cobro</th></tr></thead><tbody>';
        foreach ($coincidencias as $c) {
            $href = 'registrar_cobro.php?alumno_id=' . (int) $c['id'] . '&fecha_pago=' . rawurlencode($fechaPago);
            echo '<tr>';
            echo '<td>' . (int) $c['id'] . '</td>';
            echo '<td>' . h((string) ($c['codigo_legacy'] ?? '')) . '</td>';
            echo '<td>' . h((string) $c['nombre_completo']) . '</td>';
            echo '<td>' . h((string) ($c['documento'] ?? '')) . '</td>';
            echo '<td><a class="action-icon" href="' . h($href) . '" title="Registrar cobro">💵</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}

if ($alumnoId > 0) {
    echo '<form method="get" class="form" style="max-width:42rem;margin:1rem 0">';
    echo '<input type="hidden" name="alumno_id" value="' . (int) $alumnoId . '">';
    echo '<label>Fecha de pago <input name="fecha_pago" type="date" value="' . h($fechaPago) . '" required></label> ';
    echo '<button type="submit">Actualizar fecha</button> ';
    echo '<a class="muted" href="registrar_cobro.php">Otro alumno</a>';
    echo '</form>';
}

if ($alumnoId > 0 && !$alumno) {
    echo '<p class="err">Alumno no encontrado.</p>';
} elseif ($alumno && (int) ($alumno['activo'] ?? 0) !== 1) {
    echo '<p class="err">Alumno inactivo: no se registran cobros desde la app.</p>';
} elseif ($alumno) {
    echo '<p><strong>' . h((string) $alumno['nombre_completo']) . '</strong> · legacy ' . (int) ($alumno['codigo_legacy'] ?? 0);
    if (!empty($alumno['documento'])) {
        echo ' · DNI ' . h((string) $alumno['documento']);
    }
    echo ' · coef. diario: <code>' . h((string) $coef) . '</code>';
    echo ' <span class="student-actions"><a class="action-icon" href="cuenta_corriente.php?alumno_id=' . (int) $alumnoId . '" title="Ver cuenta corriente del mismo alumno">💳</a></span></p>';

        echo '<section class="card">';
        echo '<h2 style="margin-top:0">1) Cuotas a cobrar desde ' . (int) cobranza_anio_operativo_desde() . '</h2>';
        echo '<p class="muted">Solo períodos <strong>anio &ge; ' . (int) cobranza_anio_operativo_desde() . '</strong> (<code>OPERATIVO_ANIO_DESDE</code>). '
            . 'Saldo a cobrar = <code>importe_original − pago_aplica_cuota − pagos migrados PAGOS:…:período</code> (si el estado dice pagada pero no hay pago de ese mes, igual aparece acá). '
            . 'Marca <strong>Q</strong> = abonos por app con detalle. Abajo, <strong>L</strong> = liquidada. La <strong>P</strong> del paso 2 es pronto pago. '
            . '<strong>Saldo final</strong> acumula saldos pendientes. Marcá casillas y pulsá <strong>Calcular importes</strong>.</p>';

        if (count($cuotasPendientes) > 0 && $calcError !== null) {
            echo '<p class="err">' . h($calcError) . '</p>';
        }
        if (count($cuotasPendientes) === 0 && $paso === 'calc' && $calcError !== null) {
            echo '<p class="err">' . h($calcError) . '</p>';
        }

        if (count($cuotasPendientes) > 0) {
            echo '<form method="get" class="form">';
            echo '<input type="hidden" name="alumno_id" value="' . (int) $alumnoId . '">';
            echo '<input type="hidden" name="fecha_pago" value="' . h($fechaPago) . '">';
            echo '<input type="hidden" name="paso" value="calc">';
        }
        echo '<div style="overflow-x:auto">';
        echo '<table class="table js-data-table"><thead><tr>';
        echo '<th data-nosort="1">Pagar</th><th>Fecha</th><th>Período</th><th>Concepto</th><th data-nosort="1">Marca</th><th>Debe</th><th>Haber</th><th>Saldo final</th><th>Estado (cobro)</th>';
        echo '</tr></thead><tbody>';
        if (count($cuotasPendientes) === 0) {
            $msgVacío = 'No hay cuotas con saldo pendiente desde el año ' . (int) cobranza_anio_operativo_desde() . ' (excluye anuladas y liquidadas). Revisá la tabla de abajo si figuran como liquidadas (marca L).';
            if ($fechaCorteCobro !== null) {
                $msgVacío .= ' (El listado de cobro no aplica SALDO_CORTE_DESDE; si falta algo, revisá año/mes en cuota_mensual.)';
            }
            echo '<tr><td colspan="9" class="muted">' . h($msgVacío) . '</td></tr>';
        } else {
            $saldoCorrido = 0.0;
            foreach ($cuotasPendientes as $c) {
                $per = (int) $c['anio'] . '-' . str_pad((string) ((int) $c['mes']), 2, '0', STR_PAD_LEFT);
                $chk = in_array((int) $c['id'], $cuotaSelGet, true) ? ' checked' : '';
                $fechaTxt = '';
                if (!empty($c['fecha_mov'])) {
                    $ts = strtotime((string) $c['fecha_mov']);
                    $fechaTxt = $ts !== false ? date('d/m/Y', $ts) : (string) $c['fecha_mov'];
                }
                $debeCc = (float) ($c['debe_cc'] ?? 0);
                $saldoImp = cobranza_saldo_impago_cuota($c);
                $saldoCorrido += $saldoImp;
                echo '<tr>';
                echo '<td><input type="checkbox" name="cuota_sel[]" value="' . (int) $c['id'] . '"' . $chk . '></td>';
                echo '<td>' . h($fechaTxt) . '</td>';
                echo '<td>' . h($per) . '</td>';
                echo '<td>ABONO/CUOTA</td>';
                echo '<td>' . cobranza_badge_abono_parcial_html($c) . '</td>';
                echo '<td>' . h(cobro_fmt_money($debeCc)) . '</td>';
                echo '<td></td>';
                echo '<td>' . h(cobro_fmt_money($saldoCorrido)) . '</td>';
                echo '<td>' . cobranza_estado_visual_cobro_html($c) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>';
        if (count($cuotasPendientes) > 0) {
            echo '<div class="form-actions" style="margin-top:0.75rem">';
            echo '<button type="submit" class="btn-secondary">Calcular importes (descuento / recargo)</button>';
            if ($paso === 'calc') {
                echo ' <a class="muted" href="registrar_cobro.php?alumno_id=' . (int) $alumnoId . '&fecha_pago=' . rawurlencode($fechaPago) . '">Volver a la selección</a>';
            }
            echo '</div></form>';
        }
        echo '</section>';

        echo '<section class="card" style="margin-top:1.25rem">';
        echo '<h2 style="margin-top:0">Liquidaciones desde ' . (int) cobranza_anio_operativo_desde() . ' (solo referencia)</h2>';
        echo '<p class="muted">Cuotas <strong>sin saldo pendiente</strong> según importe − aplicaciones en app − <strong>pagos legacy</strong> del mismo período (<code>PAGOS:…:YYYY-MM</code>). Marca <strong>L</strong>. Sin casilla <em>Pagar</em>.</p>';
        echo '<div style="overflow-x:auto">';
        echo '<table class="table js-data-table"><thead><tr>';
        echo '<th>Fecha</th><th>Período</th><th>Concepto</th><th data-nosort="1">Marca</th><th>Debe</th><th>Aplicado</th><th>Legacy período</th><th>Estado</th>';
        echo '</tr></thead><tbody>';
        if (count($cuotasLiquidadas) === 0) {
            echo '<tr><td colspan="8" class="muted">Ninguna cuota cerrada en el período operativo para este alumno.</td></tr>';
        } else {
            foreach ($cuotasLiquidadas as $cl) {
                $perL = (int) $cl['anio'] . '-' . str_pad((string) ((int) $cl['mes']), 2, '0', STR_PAD_LEFT);
                $fechaTxtL = '';
                if (!empty($cl['fecha_mov'])) {
                    $tsL = strtotime((string) $cl['fecha_mov']);
                    $fechaTxtL = $tsL !== false ? date('d/m/Y', $tsL) : (string) $cl['fecha_mov'];
                }
                $debeL = (float) ($cl['debe_cc'] ?? 0);
                $aplicL = (float) ($cl['aplicado_acum'] ?? 0);
                $legL = (float) ($cl['haber_legacy_acum'] ?? 0);
                echo '<tr>';
                echo '<td>' . h($fechaTxtL) . '</td>';
                echo '<td>' . h($perL) . '</td>';
                echo '<td>ABONO/CUOTA</td>';
                echo '<td>' . cobranza_badge_liquidada_html() . '</td>';
                echo '<td>' . h(cobro_fmt_money($debeL)) . '</td>';
                echo '<td>' . h(cobro_fmt_money($aplicL)) . '</td>';
                echo '<td>' . h(cobro_fmt_money($legL)) . '</td>';
                echo '<td>' . h((string) ($cl['estado'] ?? '')) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div></section>';

        if ($paso === 'calc' && $calcError === null && count($lineasCalc) > 0) {
            $sumCap = 0.0;
            $sumRec = 0.0;
            $sumDesc = 0.0;
            $sumBeca = 0.0;
            $sumTot = 0.0;
            foreach ($lineasCalc as $L) {
                $x = $L['calc'];
                $sumCap += $x['importe_capital'];
                $sumRec += $x['importe_recargo_variable'] + $x['importe_recargo_fijo'];
                $sumDesc += $x['importe_descuento'];
                $sumBeca += $x['importe_beca_perdida'];
                $sumTot += $x['total_linea'];
            }
            $sumTot = round($sumTot, 2);

            echo '<h2>2) Detalle del cobro (fecha ' . h($fechaPago) . ')</h2>';
            echo '<p class="muted"><strong>P</strong> = pronto pago (aplica descuento fijo según parámetros). Sin <strong>P</strong> = fuera del tope (mora / recargo diario). '
                . 'Si la cuota tiene BECA, después del <strong>5° día hábil</strong> se suma diferencia de BECA.</p>';
            echo '<table class="table js-data-table"><thead><tr>';
            echo '<th>Período</th><th>P</th><th>Saldo base</th><th>Tope pronto</th><th>Mora (días)</th>';
            echo '<th>Desc.</th><th>Recargo var.</th><th>Rec. fijo</th><th>Dif. BECA</th><th>Capital</th><th>Total línea</th>';
            echo '</tr></thead><tbody>';
            foreach ($lineasCalc as $row) {
                $c = $row['cuota'];
                $x = $row['calc'];
                $per = (int) $c['anio'] . '-' . str_pad((string) ((int) $c['mes']), 2, '0', STR_PAD_LEFT);
                $marcaP = $x['dentro_pronto']
                    ? '<span class="badge badge-ok" title="Pronto pago (estilo marca P Fox)">P</span>'
                    : '<span class="muted">—</span>';
                echo '<tr>';
                echo '<td>' . h($per) . '</td>';
                echo '<td>' . $marcaP . '</td>';
                echo '<td>$ ' . number_format($x['saldo_cuota'], 2, ',', '.') . '</td>';
                echo '<td>' . h($x['fecha_tope_pronto']) . '</td>';
                echo '<td>' . (int) $x['dias_mora'] . '</td>';
                echo '<td>$ ' . number_format($x['importe_descuento'], 2, ',', '.') . '</td>';
                echo '<td>$ ' . number_format($x['importe_recargo_variable'], 2, ',', '.') . '</td>';
                echo '<td>$ ' . number_format($x['importe_recargo_fijo'], 2, ',', '.') . '</td>';
                echo '<td>$ ' . number_format($x['importe_beca_perdida'], 2, ',', '.') . '</td>';
                echo '<td>$ ' . number_format($x['importe_capital'], 2, ',', '.') . '</td>';
                echo '<td><strong>$ ' . number_format($x['total_linea'], 2, ',', '.') . '</strong></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '<p><strong>Total a cobrar:</strong> $ ' . number_format($sumTot, 2, ',', '.') . ' '
                . '(capital $ ' . number_format($sumCap, 2, ',', '.') . ' + recargos $ ' . number_format($sumRec, 2, ',', '.') . ' + dif. BECA $ ' . number_format($sumBeca, 2, ',', '.') . ' — descuentos $ ' . number_format($sumDesc, 2, ',', '.') . ')</p>';

            if ($hasPacDetalle && $hasPagoComponentes && $hasBecaRegla) {
                echo '<h2>3) Confirmar</h2>';
                echo '<form method="post" class="form form-grid" style="max-width:22rem">';
                echo '<input type="hidden" name="confirmar_cobro" value="1">';
                echo '<input type="hidden" name="alumno_id" value="' . (int) $alumnoId . '">';
                echo '<input type="hidden" name="fecha_pago" value="' . h($fechaPago) . '">';
                foreach ($lineasCalc as $row) {
                    echo '<input type="hidden" name="cuota_id[]" value="' . (int) $row['cuota']['id'] . '">';
                }
                echo '<label>Medio de pago <select name="medio">';
                foreach (['efectivo', 'transferencia', 'tarjeta', 'cheque', 'otro'] as $m) {
                    echo '<option value="' . h($m) . '">' . h($m) . '</option>';
                }
                echo '</select></label>';
                echo '<div class="form-actions"><button type="submit">Registrar cobro</button></div>';
                echo '</form>';
            }
        }
}

if ($pagoRecibo && count($lineasRecibo) > 0) {
    echo '<h2 id="recibo">Recibo #' . (int) $pagoRecibo['id'] . '</h2>';
    echo '<p>Fecha pago: ' . h((string) $pagoRecibo['fecha_pago']) . ' · Medio: ' . h((string) ($pagoRecibo['medio'] ?? '')) . '</p>';
    echo '<table class="table"><thead><tr><th>Período</th><th>Capital</th><th>Recargo</th><th>Dif. BECA</th><th>Desc.</th><th>Días mora</th></tr></thead><tbody>';
    foreach ($lineasRecibo as $lr) {
        $per = (int) $lr['anio'] . '-' . str_pad((string) ((int) $lr['mes']), 2, '0', STR_PAD_LEFT);
        $rec = (float) ($lr['importe_recargo'] ?? 0);
        echo '<tr><td>' . h($per) . '</td><td>$ ' . number_format((float) ($lr['importe_capital'] ?? 0), 2, ',', '.') . '</td>';
        echo '<td>$ ' . number_format($rec, 2, ',', '.') . '</td>';
        echo '<td>$ ' . number_format((float) ($lr['importe_beca_perdida'] ?? 0), 2, ',', '.') . '</td>';
        echo '<td>$ ' . number_format((float) ($lr['importe_descuento'] ?? 0), 2, ',', '.') . '</td>';
        echo '<td>' . (int) ($lr['dias_mora'] ?? 0) . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<p><strong>Total cobrado:</strong> $ ' . number_format((float) $pagoRecibo['importe'], 2, ',', '.') . '</p>';
    echo '<p><button type="button" class="btn-secondary" onclick="window.print()">Imprimir recibo</button></p>';
}

echo '<p><a href="index.php">Inicio</a> · <a href="alumnos.php">Alumnos</a>';
if ($alumnoId > 0) {
    echo ' · <a href="cuenta_corriente.php?alumno_id=' . (int) $alumnoId . '">Cuenta corriente</a>';
}
echo ' · <a href="parametros_cobranza.php">Parámetros cobranza</a></p>';

layout_end();
