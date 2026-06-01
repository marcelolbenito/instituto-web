<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/Cobranza.php';
require_once dirname(__DIR__) . '/src/Saldos.php';
require_once dirname(__DIR__) . '/src/FormasPago.php';
require_once dirname(__DIR__) . '/src/ReciboHtml.php';
require_once dirname(__DIR__) . '/src/Caja.php';

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
 * Desglose legible del cálculo (pronto pago / mora) para vista previa y paso 2.
 *
 * @param array<string,mixed> $calc Resultado de cobranza_calcular_linea_*()
 */
function cobro_query_caja_ctx(string $desdeCajaFecha): string
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desdeCajaFecha) !== 1) {
        return '';
    }

    return '&desde_caja_fecha=' . rawurlencode($desdeCajaFecha);
}

function cobro_detalle_calculo_html(array $calc): string
{
    if (!empty($calc['importe_fijo_sin_mora'])) {
        return '<div class="cobro-calc-detail muted" style="font-size:0.88em;margin-top:0.25rem">Importe fijo, sin recargo ni descuento por mora.</div>';
    }

    $parts = [];
    $tope = (string) ($calc['fecha_tope_pronto'] ?? '');
    if ($tope !== '') {
        $ts = strtotime($tope);
        $topeTxt = $ts !== false ? date('d/m/Y', $ts) : $tope;
    } else {
        $topeTxt = '—';
    }

    if (!empty($calc['dentro_pronto'])) {
        $parts[] = '<strong>P</strong> Pronto pago (pagó antes del tope ' . h($topeTxt) . ')';
    } else {
        $dias = (int) ($calc['dias_mora'] ?? 0);
        $parts[] = 'Fuera de plazo · tope pronto ' . h($topeTxt) . ' · <strong>' . $dias . '</strong> día(s) de mora';
        $pctMes = (float) ($calc['recargo_mensual_pct'] ?? 0);
        $coef = (float) ($calc['coef_diario_pct'] ?? 0);
        $pctAcum = (float) ($calc['pct_mora_acumulado'] ?? 0);
        if ($pctMes > 0.000001 && $dias > 0) {
            $parts[] = 'Recargo mensual <strong>' . number_format($pctMes, 2, ',', '.') . '%</strong>'
                . ' → ' . number_format($coef, 3, ',', '.') . '%/día × ' . $dias . ' día(s)'
                . ' = <strong>' . number_format($pctAcum, 2, ',', '.') . '%</strong> sobre la base';
        }
    }

    $parts[] = 'Base: <strong>' . h(cobro_fmt_money((float) ($calc['saldo_cuota'] ?? 0))) . '</strong>';

    $desc = (float) ($calc['importe_descuento'] ?? 0);
    if ($desc > 0.00001) {
        $parts[] = 'Descuento: −' . h(cobro_fmt_money($desc));
    }
    $recVar = (float) ($calc['importe_recargo_variable'] ?? 0);
    if ($recVar > 0.00001) {
        $parts[] = 'Recargo variable: +' . h(cobro_fmt_money($recVar));
    }
    $recFijo = (float) ($calc['importe_recargo_fijo'] ?? 0);
    if ($recFijo > 0.00001) {
        $parts[] = 'Mora fija (parámetro web): +' . h(cobro_fmt_money($recFijo));
    }
    $beca = (float) ($calc['importe_beca_perdida'] ?? 0);
    if ($beca > 0.00001) {
        $parts[] = 'Dif. beca: +' . h(cobro_fmt_money($beca));
    }

    $parts[] = 'Total línea: <strong>' . h(cobro_fmt_money((float) ($calc['total_linea'] ?? 0))) . '</strong>';

    return '<div class="cobro-calc-detail muted" style="font-size:0.88em;margin-top:0.35rem;line-height:1.45">'
        . implode('<br>', $parts) . '</div>';
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

/**
 * @return array<int,array{articulo_id:int,cantidad:float}>
 */
function cobro_normalizar_items($rawIds, $rawCantidades): array
{
    if (!is_array($rawIds)) {
        $rawIds = $rawIds !== null && $rawIds !== '' ? [$rawIds] : [];
    }
    if (!is_array($rawCantidades)) {
        $rawCantidades = $rawCantidades !== null && $rawCantidades !== '' ? [$rawCantidades] : [];
    }
    $n = min(count($rawIds), count($rawCantidades));
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $aid = (int) $rawIds[$i];
        $qty = max(0.0, (float) str_replace(',', '.', (string) $rawCantidades[$i]));
        if ($aid > 0 && $qty > 0.00001) {
            $out[] = ['articulo_id' => $aid, 'cantidad' => round($qty, 2)];
        }
    }
    return $out;
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
$hasCobroItems = db_has_column($pdo, 'pago_item_articulo', 'importe_total')
    && db_has_column($pdo, 'cc_ajuste_debe', 'debe');
$hasFormasPago = formas_pago_schema_ok($pdo);

$alumnoId = isset($_GET['alumno_id']) ? (int) $_GET['alumno_id'] : 0;
$buscar = trim((string) ($_GET['q'] ?? ''));
$fechaPago = trim((string) ($_GET['fecha_pago'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaPago)) {
    $fechaPago = date('Y-m-d');
}
$desdeCajaFecha = trim((string) ($_GET['desde_caja_fecha'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desdeCajaFecha)) {
    $desdeCajaFecha = '';
} else {
    $fechaPago = $desdeCajaFecha;
}
$cajaCtxQ = cobro_query_caja_ctx($desdeCajaFecha);

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
    $desdeCajaFechaPost = trim((string) ($_POST['desde_caja_fecha'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desdeCajaFechaPost)) {
        $desdeCajaFechaPost = '';
    }
    $cajaCtxQ = cobro_query_caja_ctx($desdeCajaFechaPost);
    $fechaPago = trim((string) ($_POST['fecha_pago'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaPago)) {
        header('Location: registrar_cobro.php?alumno_id=' . $alumnoId . $cajaCtxQ . '&err=' . rawurlencode('Fecha de pago inválida.'));
        exit;
    }
    if ($desdeCajaFechaPost !== '' && $fechaPago !== $desdeCajaFechaPost) {
        header(
            'Location: registrar_cobro.php?alumno_id=' . $alumnoId . '&fecha_pago=' . rawurlencode($desdeCajaFechaPost) . $cajaCtxQ
                . '&err=' . rawurlencode('Desde caja del día: la fecha del recibo debe ser ' . $desdeCajaFechaPost . '.')
        );
        exit;
    }

    if (!$hasPacDetalle || !$hasPagoComponentes || !$hasBecaRegla || !$hasCobroItems) {
        header(
            'Location: registrar_cobro.php?alumno_id=' . $alumnoId . '&fecha_pago=' . rawurlencode($fechaPago) . $cajaCtxQ
                . '&err=' . rawurlencode('Falta migración 14, 16, 17 y/o 22 (detalle de cobro y contramovimiento en cuenta corriente).')
        );
        exit;
    }

    $puntoVenta = max(1, (int) ($_POST['punto_venta'] ?? 1));
    $formaPagoId = (int) ($_POST['forma_pago_id'] ?? 0);
    $tarjetaIdPost = isset($_POST['tarjeta_id']) && $_POST['tarjeta_id'] !== '' ? (int) $_POST['tarjeta_id'] : null;
    $tarjetaCuotasPost = isset($_POST['tarjeta_cuotas']) && $_POST['tarjeta_cuotas'] !== '' ? (int) $_POST['tarjeta_cuotas'] : null;
    $descuentoMedioPct = max(0.0, (float) str_replace(',', '.', (string) ($_POST['descuento_medio_pct'] ?? '0')));
    $referenciaMedio = trim((string) ($_POST['referencia_medio'] ?? ''));
    $nroLote = trim((string) ($_POST['nro_lote'] ?? ''));
    $codAutorizacion = trim((string) ($_POST['cod_autorizacion'] ?? ''));
    $ultimosDigitos = preg_replace('/\D/', '', (string) ($_POST['ultimos_digitos'] ?? ''));
    if (strlen($ultimosDigitos) > 4) {
        $ultimosDigitos = substr($ultimosDigitos, -4);
    }
    $medio = trim((string) ($_POST['medio'] ?? 'efectivo'));
    if (strlen($medio) > 40) {
        $medio = substr($medio, 0, 40);
    }

    if (empty($_POST['confirmar_cobro'])) {
        header('Location: registrar_cobro.php?alumno_id=' . $alumnoId . '&fecha_pago=' . rawurlencode($fechaPago) . $cajaCtxQ . '&err=' . rawurlencode('Confirmación inválida.'));
        exit;
    }

    $ids = $_POST['cuota_id'] ?? [];
    if (!is_array($ids)) {
        $ids = [];
    }

    $stAl = $pdo->prepare('SELECT id, nombre_completo, documento, activo, provincia, ciudad, saldo_cc FROM alumnos WHERE id = ?');
    $stAl->execute([$alumnoId]);
    $al = $stAl->fetch();
    if (!$al || (int) ($al['activo'] ?? 0) !== 1) {
        header('Location: registrar_cobro.php?err=' . rawurlencode('Alumno inexistente o inactivo.'));
        exit;
    }
    $param['fechas_feriado'] = cobro_fechas_feriado($pdo, (string) ($al['provincia'] ?? ''), (string) ($al['ciudad'] ?? ''));
    $tieneBecaAlumno = cobranza_alumno_tiene_beca($pdo, $alumnoId);

    $cuotas = [];
    if (count($ids) > 0) {
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
    }

    $itemsInput = cobro_normalizar_items($_POST['item_articulo_id'] ?? [], $_POST['item_cantidad'] ?? []);
    $itemsExtra = [];
    foreach ($itemsInput as $itIn) {
        $stArt = $pdo->prepare(
            "SELECT id, detalle, importe_referencia
             FROM articulos
             WHERE id = ?
               AND activo = 1
               AND UPPER(detalle) NOT LIKE '%ABONO%'
               AND UPPER(detalle) NOT LIKE '%BECA%'"
        );
        $stArt->execute([(int) $itIn['articulo_id']]);
        $art = $stArt->fetch();
        if (!$art) {
            header('Location: registrar_cobro.php?alumno_id=' . $alumnoId . '&fecha_pago=' . rawurlencode($fechaPago) . '&err=' . rawurlencode('Uno de los ítems adicionales no es válido para facturar en recibo.'));
            exit;
        }
        $unit = round((float) ($art['importe_referencia'] ?? 0), 2);
        if ($unit <= 0) {
            header('Location: registrar_cobro.php?alumno_id=' . $alumnoId . '&fecha_pago=' . rawurlencode($fechaPago) . '&err=' . rawurlencode('Uno de los ítems adicionales tiene importe inválido.'));
            exit;
        }
        $qty = round((float) $itIn['cantidad'], 2);
        $itemsExtra[] = [
            'articulo_id' => (int) $art['id'],
            'descripcion' => (string) $art['detalle'],
            'cantidad' => $qty,
            'importe_unitario' => $unit,
            'importe_total' => round($unit * $qty, 2),
        ];
    }

    $ajusteIds = $_POST['ajuste_id'] ?? [];
    if (!is_array($ajusteIds)) {
        $ajusteIds = [];
    }
    $ajusteIds = array_values(array_unique(array_filter(array_map('intval', $ajusteIds), static fn (int $v): bool => $v > 0)));
    $ajustesCobro = [];
    if (count($ajusteIds) > 0) {
        $ph = implode(',', array_fill(0, count($ajusteIds), '?'));
        $stAdj = $pdo->prepare(
            "SELECT id, concepto, debe, fecha_mov
             FROM cc_ajuste_debe
             WHERE alumno_id = ? AND pago_id IS NULL AND id IN ($ph)"
        );
        $stAdj->execute(array_merge([$alumnoId], $ajusteIds));
        $ajustesCobro = $stAdj->fetchAll();
        if (count($ajustesCobro) !== count($ajusteIds)) {
            header('Location: registrar_cobro.php?alumno_id=' . $alumnoId . '&fecha_pago=' . rawurlencode($fechaPago) . '&err=' . rawurlencode('Algún concepto con saldo ya no está disponible para cobrar.'));
            exit;
        }
    }

    if (count($ids) === 0 && count($itemsExtra) === 0 && count($ajustesCobro) === 0) {
        header('Location: registrar_cobro.php?alumno_id=' . $alumnoId . '&fecha_pago=' . rawurlencode($fechaPago) . '&err=' . rawurlencode('Seleccioná cuota(s), concepto(s) con saldo y/o ítem adicional.'));
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
    $extraTotal = 0.0;
    foreach ($itemsExtra as $it) {
        $extraTotal += (float) $it['importe_total'];
    }
    $extraTotal = round($extraTotal, 2);
    $lineasAjustePost = [];
    foreach ($ajustesCobro as $adj) {
        $calcAdj = cobranza_calcular_linea_debe_pendiente($param, $adj, $fechaPago, $tieneBecaAlumno);
        $lineasAjustePost[] = ['adj' => $adj, 'calc' => $calcAdj];
        $sumCap += $calcAdj['importe_capital'];
        $sumRec += $calcAdj['importe_recargo_variable'] + $calcAdj['importe_recargo_fijo'];
        $sumDesc += $calcAdj['importe_descuento'];
        $sumBeca += $calcAdj['importe_beca_perdida'];
    }
    $subtotalMedio = round($sumCap + $sumRec + $sumBeca + $extraTotal, 2);
    $total = $subtotalMedio;
    $importeRecargoMedio = 0.0;
    $importeDescuentoMedio = 0.0;
    $recargoMedioPct = null;
    $formaPagoRow = null;
    $tarjetaIdGuardar = null;
    $tarjetaCuotasGuardar = null;

    if ($hasFormasPago) {
        $formaPagoRow = $formaPagoId > 0 ? formas_pago_por_id($pdo, $formaPagoId) : null;
        if ($formaPagoRow === null) {
            header(
                'Location: registrar_cobro.php?alumno_id=' . $alumnoId . '&fecha_pago=' . rawurlencode($fechaPago)
                    . '&err=' . rawurlencode('Seleccione una forma de pago válida.')
            );
            exit;
        }
        $maxDescEfectivo = (float) ($param['bonificacion_pronto_pago'] ?? 0);
        $ajusteMedio = formas_pago_calcular_ajuste_medio(
            $pdo,
            $formaPagoRow,
            $subtotalMedio,
            $tarjetaIdPost,
            $tarjetaCuotasPost,
            $descuentoMedioPct,
            $maxDescEfectivo
        );
        if ($ajusteMedio['error'] !== null) {
            header(
                'Location: registrar_cobro.php?alumno_id=' . $alumnoId . '&fecha_pago=' . rawurlencode($fechaPago)
                    . '&err=' . rawurlencode((string) $ajusteMedio['error'])
            );
            exit;
        }
        if (!empty($formaPagoRow['requiere_referencia']) && $referenciaMedio === '') {
            header(
                'Location: registrar_cobro.php?alumno_id=' . $alumnoId . '&fecha_pago=' . rawurlencode($fechaPago)
                    . '&err=' . rawurlencode('Indique referencia o comprobante del pago.')
            );
            exit;
        }
        $importeRecargoMedio = $ajusteMedio['recargo_importe'];
        $importeDescuentoMedio = $ajusteMedio['descuento_importe'];
        $recargoMedioPct = $ajusteMedio['recargo_pct'] > 0.00001 ? $ajusteMedio['recargo_pct'] : null;
        $tarjetaIdGuardar = $ajusteMedio['tarjeta_id'];
        $tarjetaCuotasGuardar = $ajusteMedio['tarjeta_cuotas'];
        $medio = (string) $formaPagoRow['codigo'];
        $total = round($subtotalMedio + $importeRecargoMedio - $importeDescuentoMedio, 2);
    }

    $refParts = [];
    if (count($lineas) > 0) {
        $refParts[] = 'C-' . implode('-', array_map(static fn ($x) => (string) $x['cuota']['id'], $lineas));
    }
    if (count($ajustesCobro) > 0) {
        $refParts[] = 'A-' . implode('-', array_map(static fn ($x) => (string) $x['id'], $ajustesCobro));
    }
    if (count($itemsExtra) > 0) {
        $refParts[] = 'ITEM';
    }
    $ref = 'COBRO:' . $fechaPago . ':' . ($refParts !== [] ? implode('_', $refParts) : '0');

    $pdo->beginTransaction();
    try {
        $nota = 'Cobro/recibo PV ' . $puntoVenta . '; mora coef=' . (string) $coef;
        if ($importeRecargoMedio > 0.00001) {
            $nota .= '; recargo medio $' . number_format($importeRecargoMedio, 2, '.', '');
        }
        if ($importeDescuentoMedio > 0.00001) {
            $nota .= '; desc. medio $' . number_format($importeDescuentoMedio, 2, '.', '');
        }
        if ($hasFormasPago && $formaPagoRow !== null) {
            $insP = $pdo->prepare(
                'INSERT INTO pago_registrado (
                    alumno_id, fecha_pago, importe, importe_capital, importe_interes,
                    importe_recargo_medio, importe_descuento_medio, importe_beca_perdida, importe_descuento,
                    medio, forma_pago_id, tarjeta_id, tarjeta_cuotas, recargo_medio_pct,
                    referencia, nro_lote, cod_autorizacion, ultimos_digitos, referencia_medio, nota
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insP->execute([
                $alumnoId,
                $fechaPago,
                $total,
                round($sumCap + $extraTotal, 2),
                round($sumRec, 2),
                round($importeRecargoMedio, 2),
                round($importeDescuentoMedio, 2),
                round($sumBeca, 2),
                round($sumDesc + $importeDescuentoMedio, 2),
                $medio,
                $formaPagoId,
                $tarjetaIdGuardar,
                $tarjetaCuotasGuardar,
                $recargoMedioPct,
                $ref,
                $nroLote !== '' ? $nroLote : null,
                $codAutorizacion !== '' ? $codAutorizacion : null,
                $ultimosDigitos !== '' ? $ultimosDigitos : null,
                $referenciaMedio !== '' ? $referenciaMedio : null,
                $nota,
            ]);
        } else {
            $insP = $pdo->prepare(
                'INSERT INTO pago_registrado (alumno_id, fecha_pago, importe, importe_capital, importe_interes, importe_beca_perdida, importe_descuento, medio, referencia, nota)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insP->execute([
                $alumnoId,
                $fechaPago,
                $total,
                round($sumCap + $extraTotal, 2),
                round($sumRec, 2),
                round($sumBeca, 2),
                round($sumDesc, 2),
                $medio,
                $ref,
                $nota,
            ]);
        }
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
        $insItem = $pdo->prepare(
            'INSERT INTO pago_item_articulo (pago_id, articulo_id, descripcion, cantidad, importe_unitario, importe_total)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insDebe = $pdo->prepare(
            'INSERT INTO cc_ajuste_debe (alumno_id, fecha_mov, concepto, debe, referencia, pago_id)
             VALUES (?, ?, ?, ?, ?, ?)'
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
        foreach ($itemsExtra as $itemExtra) {
            $insItem->execute([
                $pagoId,
                (int) $itemExtra['articulo_id'],
                (string) $itemExtra['descripcion'],
                (float) $itemExtra['cantidad'],
                (float) $itemExtra['importe_unitario'],
                (float) $itemExtra['importe_total'],
            ]);
            $insDebe->execute([
                $alumnoId,
                $fechaPago,
                'FACT/REC: ' . (string) $itemExtra['descripcion'],
                (float) $itemExtra['importe_total'],
                'RECIBO_ITEM:' . $pagoId,
                $pagoId,
            ]);
        }
        foreach ($lineas as $L) {
            $c = $L['cuota'];
            $calc = $L['calc'];
            $per = sprintf('%04d-%02d', (int) $c['anio'], (int) $c['mes']);
            $cid = (int) $c['id'];
            $mora = round($calc['importe_recargo_variable'] + $calc['importe_recargo_fijo'], 2);
            cobranza_registrar_incremento_cc_cobro(
                $pdo,
                $alumnoId,
                $fechaPago,
                $pagoId,
                'Mora/recargo cuota ' . $per,
                $mora,
                'MORA-C' . $cid
            );
            cobranza_registrar_incremento_cc_cobro(
                $pdo,
                $alumnoId,
                $fechaPago,
                $pagoId,
                'Beca fuera de término ' . $per,
                (float) $calc['importe_beca_perdida'],
                'BECA-C' . $cid
            );
        }
        foreach ($lineasAjustePost as $La) {
            $adj = $La['adj'];
            $calcAdj = $La['calc'];
            $aid = (int) $adj['id'];
            $tsAj = strtotime((string) ($adj['fecha_mov'] ?? $fechaPago));
            $perAj = $tsAj !== false ? date('Y-m', $tsAj) : $fechaPago;
            $moraAdj = round($calcAdj['importe_recargo_variable'] + $calcAdj['importe_recargo_fijo'], 2);
            cobranza_registrar_incremento_cc_cobro(
                $pdo,
                $alumnoId,
                $fechaPago,
                $pagoId,
                'Mora/recargo obligación ' . $perAj,
                $moraAdj,
                'MORA-A' . $aid
            );
            cobranza_registrar_incremento_cc_cobro(
                $pdo,
                $alumnoId,
                $fechaPago,
                $pagoId,
                'Beca fuera de término obligación ' . $perAj,
                (float) $calcAdj['importe_beca_perdida'],
                'BECA-A' . $aid
            );
        }
        if ($importeRecargoMedio > 0.00001) {
            cobranza_registrar_incremento_cc_cobro(
                $pdo,
                $alumnoId,
                $fechaPago,
                $pagoId,
                'Recargo por forma de pago',
                $importeRecargoMedio,
                'MEDIO-R'
            );
        }
        if (count($ajustesCobro) > 0) {
            $phAdj = implode(',', array_fill(0, count($ajustesCobro), '?'));
            $updAdj = $pdo->prepare(
                "UPDATE cc_ajuste_debe SET pago_id = ? WHERE alumno_id = ? AND pago_id IS NULL AND id IN ($phAdj)"
            );
            $updAdj->execute(array_merge([$pagoId, $alumnoId], array_map(static fn ($a) => (int) $a['id'], $ajustesCobro)));
        }

        caja_registrar_ingreso_por_cobro($pdo, $pagoId, $alumnoId, $fechaPago, $total, $medio, $ref, $nota);

        $pdo->commit();
        recalcular_saldo_alumnos($pdo, $alumnoId);
        $qOk = 'alumno_id=' . $alumnoId . '&pago_id=' . $pagoId . '&ok=1&fecha_pago=' . rawurlencode($fechaPago) . $cajaCtxQ;
        if (caja_cierre_schema_ok($pdo) && caja_esta_cerrada($pdo, $fechaPago)) {
            $qOk .= '&aviso_caja=1';
        }
        header('Location: registrar_cobro.php?' . $qOk);
        exit;
    } catch (Throwable $e) {
        $pdo->rollBack();
        header('Location: registrar_cobro.php?alumno_id=' . $alumnoId . '&fecha_pago=' . rawurlencode($fechaPago) . '&err=' . rawurlencode($e->getMessage()));
        exit;
    }
}

$alumno = null;
$tieneBecaAlumno = false;
$cuotasPendientes = [];
$cuotasLiquidadas = [];
$ajustesPendientes = [];
$lineasCalc = [];
$lineasAjusteCalc = [];
$calcError = null;
$datosRecibo = null;

$paso = trim((string) ($_GET['paso'] ?? ''));
$cuotaSelGet = $_GET['cuota_sel'] ?? [];
if (!is_array($cuotaSelGet)) {
    $cuotaSelGet = $cuotaSelGet !== '' && $cuotaSelGet !== null ? [(string) $cuotaSelGet] : [];
}
$cuotaSelGet = array_values(array_unique(array_filter(array_map('intval', $cuotaSelGet), static fn (int $v): bool => $v > 0)));
$ajusteSelGet = $_GET['ajuste_sel'] ?? [];
if (!is_array($ajusteSelGet)) {
    $ajusteSelGet = $ajusteSelGet !== '' && $ajusteSelGet !== null ? [(string) $ajusteSelGet] : [];
}
$ajusteSelGet = array_values(array_unique(array_filter(array_map('intval', $ajusteSelGet), static fn (int $v): bool => $v > 0)));
$itemsGet = cobro_normalizar_items($_GET['item_articulo_id'] ?? [], $_GET['item_cantidad'] ?? []);
$nuevoItemIdGet = isset($_GET['nuevo_item_id']) ? (int) $_GET['nuevo_item_id'] : 0;
$nuevoItemCantidadGet = max(0.0, (float) str_replace(',', '.', (string) ($_GET['nuevo_item_cantidad'] ?? '0')));
$quitarItemIndexGet = isset($_GET['quitar_item_idx']) ? (int) $_GET['quitar_item_idx'] : -1;
$itemEditarIdGet = 0;
$itemEditarCantidadGet = 1.0;
if ($quitarItemIndexGet >= 0 && isset($itemsGet[$quitarItemIndexGet])) {
    $itemEditarIdGet = (int) $itemsGet[$quitarItemIndexGet]['articulo_id'];
    $itemEditarCantidadGet = (float) $itemsGet[$quitarItemIndexGet]['cantidad'];
    array_splice($itemsGet, $quitarItemIndexGet, 1);
}
if ($nuevoItemIdGet > 0 && $nuevoItemCantidadGet > 0.00001) {
    $itemsGet[] = ['articulo_id' => $nuevoItemIdGet, 'cantidad' => round($nuevoItemCantidadGet, 2)];
}
$puntoVentaGet = max(1, (int) ($_GET['punto_venta'] ?? 1));
$articulosExtra = $pdo->query(
    "SELECT id, detalle, importe_referencia
     FROM articulos
     WHERE activo = 1
       AND UPPER(detalle) NOT LIKE '%ABONO%'
       AND UPPER(detalle) NOT LIKE '%BECA%'
     ORDER BY detalle"
)->fetchAll();
$articulosExtraMap = [];
foreach ($articulosExtra as $ae) {
    $articulosExtraMap[(int) $ae['id']] = $ae;
}

if ($alumnoId > 0) {
    $stAl = $pdo->prepare('SELECT id, nombre_completo, codigo_legacy, documento, activo, provincia, ciudad, saldo_cc FROM alumnos WHERE id = ?');
    $stAl->execute([$alumnoId]);
    $alumno = $stAl->fetch();
    if ($alumno && (int) ($alumno['activo'] ?? 0) === 1) {
        $param['fechas_feriado'] = cobro_fechas_feriado($pdo, (string) ($alumno['provincia'] ?? ''), (string) ($alumno['ciudad'] ?? ''));
        $tieneBecaAlumno = cobranza_alumno_tiene_beca($pdo, $alumnoId);
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
                STR_TO_DATE(CONCAT(cm.anio, "-", LPAD(cm.mes, 2, "0"), "-01"), "%Y-%m-%d") AS fecha_mov,
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
                STR_TO_DATE(CONCAT(cm.anio, "-", LPAD(cm.mes, 2, "0"), "-01"), "%Y-%m-%d") AS fecha_mov,
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

        $ajustesPendientes = cobranza_ajustes_debe_pendientes($pdo, $alumnoId);
        $pendAjusteIds = array_map(static fn (array $a): int => (int) $a['id'], $ajustesPendientes);
        $ajusteSelGet = array_values(array_intersect($ajusteSelGet, $pendAjusteIds));

        if ($paso === 'calc') {
            if (count($cuotaSelGet) === 0 && count($itemsGet) === 0 && count($ajusteSelGet) === 0) {
                $calcError = 'Marcá al menos una cuota o concepto con saldo (o agregá un artículo al recibo) y volvé a calcular.';
            } else {
                $placeholders = implode(',', array_fill(0, count($cuotaSelGet), '?'));
                $cuotasSel = [];
                if (count($cuotaSelGet) > 0) {
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
                }
                if (count($cuotasSel) !== count($cuotaSelGet)) {
                    $calcError = 'Alguna cuota seleccionada ya no está disponible.';
                    $lineasCalc = [];
                } else {
                    if (count($itemsGet) > 0) {
                        foreach ($itemsGet as $it) {
                            if (!isset($articulosExtraMap[(int) $it['articulo_id']])) {
                                $calcError = 'Uno de los ítems adicionales seleccionados no es válido.';
                                break;
                            }
                        }
                    }
                    foreach ($cuotasSel as $c) {
                        $lineasCalc[] = [
                            'cuota' => $c,
                            'calc' => cobranza_calcular_linea_cuota($param, $c, $fechaPago),
                        ];
                    }
                    if ($calcError === null && count($ajusteSelGet) > 0) {
                        foreach ($ajustesPendientes as $adj) {
                            if (!in_array((int) $adj['id'], $ajusteSelGet, true)) {
                                continue;
                            }
                            $lineasAjusteCalc[] = [
                                'adj' => $adj,
                                'calc' => cobranza_calcular_linea_debe_pendiente($param, $adj, $fechaPago, $tieneBecaAlumno),
                            ];
                        }
                        if (count($lineasAjusteCalc) !== count($ajusteSelGet)) {
                            $calcError = 'Algún concepto con saldo seleccionado ya no está disponible.';
                            $lineasAjusteCalc = [];
                        }
                    }
                }
            }
        }
    }
}

$pagoId = isset($_GET['pago_id']) ? (int) $_GET['pago_id'] : 0;
if ($pagoId > 0 && $alumnoId > 0) {
    $datosRecibo = recibo_cargar_por_pago($pdo, $pagoId, $alumnoId);
}

layout_start($config, 'Registrar cobro');
if (isset($_GET['ok'])) {
    echo '<p class="ok flash no-print">Cobro registrado. Podés imprimir el recibo abajo.</p>';
    if (isset($_GET['aviso_caja'])) {
        echo '<p class="help-box no-print">La <strong>caja de la fecha de este recibo ya estaba cerrada</strong>. '
            . 'El cobro igual se registró y sumó a caja. Revisá <a href="caja.php?fecha='
            . h((string) ($_GET['fecha_pago'] ?? date('Y-m-d'))) . '">Caja del día</a> si los totales no coinciden con el cierre impreso.</p>';
    }
}
if (isset($_GET['err'])) {
    echo '<p class="err flash no-print">' . h((string) $_GET['err']) . '</p>';
}

echo '<h1 class="cobro-title no-print">Registrar cobro / recibo</h1>';

if (!$hasPacDetalle || !$hasPagoComponentes || !$hasBecaRegla || !$hasCobroItems) {
    echo '<p class="err">Requiere migraciones <code>14_pagos_componentes_interes_descuento.sql</code>, <code>16_pago_aplica_cuota_detalle.sql</code>, <code>17_beca_fuera_termino_quinto_habil.sql</code> y <code>22_cobro_items_y_contramovimiento_cc.sql</code>.</p>';
}

if ($desdeCajaFecha !== '') {
    $tsCj = strtotime($desdeCajaFecha);
    $txtCj = $tsCj !== false ? date('d/m/Y', $tsCj) : $desdeCajaFecha;
    echo '<div class="help-box" style="border-color:#7eb8e8;background:#f0f8ff">';
    echo '<h3 style="margin-top:0">Cobro para caja del ' . h($txtCj) . '</h3>';
    echo '<p style="margin:0">Los recibos cargados desde acá deben usar <strong>fecha del recibo = '
        . h($txtCj) . '</strong> para sumar en esa caja. '
        . '<a href="caja.php?fecha=' . h($desdeCajaFecha) . '">Volver a caja</a></p>';
    echo '</div>';
}

echo '<form method="get" class="search-form">';
if ($desdeCajaFecha !== '') {
    echo '<input type="hidden" name="desde_caja_fecha" value="' . h($desdeCajaFecha) . '">';
}
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
            $href = 'registrar_cobro.php?alumno_id=' . (int) $c['id'] . '&fecha_pago=' . rawurlencode($fechaPago) . $cajaCtxQ;
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
    echo '<form method="get" class="form cobro-fecha-form" style="max-width:44rem;margin:1rem 0">';
    echo '<input type="hidden" name="alumno_id" value="' . (int) $alumnoId . '">';
    if ($desdeCajaFecha !== '') {
        echo '<input type="hidden" name="desde_caja_fecha" value="' . h($desdeCajaFecha) . '">';
    }
    echo '<label style="display:block;font-weight:600">Fecha del recibo ';
    $roFecha = $desdeCajaFecha !== '' ? ' readonly' : '';
    echo '<input name="fecha_pago" type="date" value="' . h($fechaPago) . '" required style="margin-left:0.35rem"' . $roFecha . '>';
    echo '</label>';
    if ($desdeCajaFecha !== '') {
        echo '<p class="muted" style="margin:0.35rem 0 0.65rem">Fijada por la <strong>caja del día</strong> (no se puede cambiar desde este acceso).</p>';
    } else {
        echo '<p class="muted" style="margin:0.35rem 0 0.65rem">Usá la fecha en que el alumno pagó (no necesariamente hoy). '
            . 'Esa fecha define descuento por pronto pago y recargos por mora en las <strong>cuotas mensuales</strong>.</p>';
    }
    echo '<button type="submit" class="btn-secondary">Aplicar fecha</button> ';
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
    echo ' · recargo mensual: <code>' . h((string) $coef) . '%</code>';
    echo ' · saldo ref.: <strong>$ ' . number_format((float) ($alumno['saldo_cc'] ?? 0), 2, ',', '.') . '</strong>';
    echo ' <span class="student-actions"><a class="action-icon" href="cuenta_corriente.php?alumno_id=' . (int) $alumnoId . '" title="Ver cuenta corriente del mismo alumno">💳</a></span></p>';

        $anioOpUi = (int) cobranza_anio_operativo_desde();
        $hayCuotasPend = count($cuotasPendientes) > 0;
        $hayConceptosPend = $hasCobroItems && count($ajustesPendientes) > 0;
        $hayAlgoPend = $hayCuotasPend || $hayConceptosPend;

        echo '<section class="card cobro-card cobro-card-primary">';
        echo '<h2 class="cobro-section-title" style="margin-top:0">1) Cuotas y conceptos con saldo (desde ' . $anioOpUi . ')</h2>';
        echo '<p class="muted" style="margin-bottom:0.5rem">Marcá lo que vas a cobrar y pulsá <strong>Calcular importes</strong>. '
            . 'Las cuotas mensuales pueden llevar descuento o recargo según la <strong>fecha del recibo</strong> de arriba.</p>';
        echo '<details class="cobro-ayuda" style="margin:0 0 1rem;font-size:0.92rem">';
        echo '<summary style="cursor:pointer;color:#163d74;font-weight:600">Ayuda rápida (marcas y períodos)</summary>';
        echo '<ul class="muted" style="margin:0.5rem 0 0 1.1rem;line-height:1.45">';
        echo '<li>Se muestran obligaciones desde el año <strong>' . $anioOpUi . '</strong> con saldo pendiente.</li>';
        echo '<li><strong>Cuota mensual</strong>: abono del mes; el importe puede cambiar al calcular (pronto pago / mora).</li>';
        echo '<li><strong>Obligación vencida</strong>: deuda en cuenta corriente (ej. inscripción); aplica pronto pago / mora como la cuota del mes de la fecha del movimiento.</li>';
        echo '<li><strong>Debe manual</strong>: cargado en <a href="ajuste_debe.php?alumno_id=' . (int) $alumnoId . '">Cargar debe manual</a> (importe fijo, sin recargo).</li>';
        echo '<li><strong>Q</strong> en una cuota: hubo pagos parciales y aún queda saldo. En el paso 2, <strong>P</strong> = pronto pago.</li>';
        echo '<li>El botón <em>Cuotas ya pagadas</em> abre solo consulta (marca <strong>L</strong> = liquidada).</li>';
        echo '</ul></details>';

        if ($hayAlgoPend && $calcError !== null) {
            echo '<p class="err">' . h($calcError) . '</p>';
        }
        if (!$hayAlgoPend && $paso === 'calc' && $calcError !== null) {
            echo '<p class="err">' . h($calcError) . '</p>';
        }

        echo '<form method="get" class="form">';
        echo '<input type="hidden" name="alumno_id" value="' . (int) $alumnoId . '">';
        echo '<input type="hidden" name="fecha_pago" value="' . h($fechaPago) . '">';
        if ($desdeCajaFecha !== '') {
            echo '<input type="hidden" name="desde_caja_fecha" value="' . h($desdeCajaFecha) . '">';
        }
        echo '<input type="hidden" name="paso" value="calc">';
        echo '<div style="overflow-x:auto">';
        echo '<table class="table js-data-table"><thead><tr>';
        echo '<th data-nosort="1">Pagar</th><th>Fecha mov.</th><th>Período</th><th>Tipo</th><th>Concepto</th>';
        echo '<th>Total estimado <span class="muted" style="font-weight:400">(fecha recibo ' . h($fechaPago) . ')</span></th><th>Notas</th>';
        echo '</tr></thead><tbody>';
        if (!$hayAlgoPend) {
            echo '<tr><td colspan="7" class="muted">No hay cuotas ni conceptos con saldo desde ' . $anioOpUi . '. '
                . 'Si el alumno pagó todo, revisá <em>Cuotas ya pagadas</em> abajo.</td></tr>';
        } else {
            foreach ($cuotasPendientes as $c) {
                $per = (int) $c['anio'] . '-' . str_pad((string) ((int) $c['mes']), 2, '0', STR_PAD_LEFT);
                $chk = in_array((int) $c['id'], $cuotaSelGet, true) ? ' checked' : '';
                $fechaTxt = '';
                if (!empty($c['fecha_mov'])) {
                    $ts = strtotime((string) $c['fecha_mov']);
                    $fechaTxt = $ts !== false ? date('d/m/Y', $ts) : (string) $c['fecha_mov'];
                }
                $saldoImp = cobranza_saldo_impago_cuota($c);
                $prevCuota = cobranza_calcular_linea_cuota($param, $c, $fechaPago);
                $notasCuota = cobranza_badge_abono_parcial_html($c);
                $estVis = cobranza_estado_visual_cobro_html($c);
                if ($estVis !== '' && $estVis !== '—') {
                    $notasCuota .= ($notasCuota !== '' ? ' ' : '') . $estVis;
                }
                echo '<tr>';
                echo '<td><input type="checkbox" name="cuota_sel[]" value="' . (int) $c['id'] . '"' . $chk . '></td>';
                echo '<td>' . h($fechaTxt) . '</td>';
                echo '<td>' . h($per) . '</td>';
                echo '<td><span class="badge" style="background:#e8f0fa;color:#163d74">Cuota mensual</span></td>';
                echo '<td>Abono / cuota</td>';
                echo '<td><strong>' . h(cobro_fmt_money((float) $prevCuota['total_linea'])) . '</strong>';
                if (abs((float) $prevCuota['total_linea'] - $saldoImp) > 0.009) {
                    echo '<br><span class="muted" style="font-size:0.88em">Saldo base ' . h(cobro_fmt_money($saldoImp)) . '</span>';
                }
                echo cobro_detalle_calculo_html($prevCuota);
                echo '</td>';
                echo '<td>' . $notasCuota . '</td>';
                echo '</tr>';
            }
            if ($hayConceptosPend) {
                foreach ($ajustesPendientes as $adj) {
                    $pres = cobranza_debe_pendiente_presentacion($adj);
                    $prevAdj = cobranza_calcular_linea_debe_pendiente($param, $adj, $fechaPago, $tieneBecaAlumno);
                    $aid = (int) $adj['id'];
                    $chk = in_array($aid, $ajusteSelGet, true) ? ' checked' : '';
                    $f = (string) ($adj['fecha_mov'] ?? '');
                    $ts = strtotime($f);
                    $fTxt = $ts !== false ? date('d/m/Y', $ts) : $f;
                    $perAdj = $ts !== false ? date('Y-m', $ts) : '';
                    $impBase = (float) ($adj['debe'] ?? 0);
                    echo '<tr>';
                    echo '<td><input type="checkbox" name="ajuste_sel[]" value="' . $aid . '"' . $chk . '></td>';
                    echo '<td>' . h($fTxt) . '</td>';
                    echo '<td>' . h($perAdj) . '</td>';
                    echo '<td><span class="badge" style="background:#f4f0e8;color:#5c4a32">' . h($pres['etiqueta_tipo']) . '</span></td>';
                    echo '<td>' . h($pres['concepto']) . '</td>';
                    echo '<td><strong>' . h(cobro_fmt_money((float) $prevAdj['total_linea'])) . '</strong>';
                    if (!empty($prevAdj['importe_fijo_sin_mora'])) {
                        echo '<br><span class="muted" style="font-size:0.88em">Sin recargo (debe manual)</span>';
                    } else {
                        echo cobro_detalle_calculo_html($prevAdj);
                    }
                    echo '</td>';
                    echo '<td>' . $pres['nota_html'] . '</td>';
                    echo '</tr>';
                }
            }
        }
        echo '</tbody></table>';
        echo '</div>';

        echo '<fieldset class="fieldset cobro-items-fieldset" style="margin-top:0.75rem">';
        echo '<legend>Agregar conceptos nuevos al recibo (opcional)</legend>';
        echo '<p class="muted">Artículos que no son cuota mensual (materiales, inscripción, etc.). Se suman al total del recibo.</p>';
        if (count($itemsGet) > 0) {
            echo '<table class="table"><thead><tr><th>Artículo</th><th>Cantidad</th><th>Unitario</th><th>Total</th><th></th></tr></thead><tbody>';
            foreach ($itemsGet as $idx => $it) {
                $ae = $articulosExtraMap[(int) $it['articulo_id']] ?? null;
                if ($ae === null) {
                    continue;
                }
                $qty = (float) $it['cantidad'];
                $unit = (float) ($ae['importe_referencia'] ?? 0);
                $tot = round($qty * $unit, 2);
                $urlQuitar = 'registrar_cobro.php?' . http_build_query([
                    'alumno_id' => (int) $alumnoId,
                    'fecha_pago' => $fechaPago,
                    'paso' => $paso === 'calc' ? 'calc' : '',
                    'cuota_sel' => $cuotaSelGet,
                    'ajuste_sel' => $ajusteSelGet,
                    'item_articulo_id' => array_column($itemsGet, 'articulo_id'),
                    'item_cantidad' => array_column($itemsGet, 'cantidad'),
                    'quitar_item_idx' => $idx,
                    'punto_venta' => $puntoVentaGet,
                ]);
                echo '<tr>';
                echo '<td>' . h((string) $ae['detalle']) . '</td>';
                echo '<td>' . h(number_format($qty, 2, ',', '.')) . '</td>';
                echo '<td>$ ' . number_format($unit, 2, ',', '.') . '</td>';
                echo '<td>$ ' . number_format($tot, 2, ',', '.') . '</td>';
                echo '<td><a class="btn-secondary" href="' . h($urlQuitar) . '">Quitar</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        foreach ($itemsGet as $it) {
            echo '<input type="hidden" name="item_articulo_id[]" value="' . (int) $it['articulo_id'] . '">';
            echo '<input type="hidden" name="item_cantidad[]" value="' . h(number_format((float) $it['cantidad'], 2, '.', '')) . '">';
        }
        echo '<h3 style="margin:0 0 0.45rem;font-size:1.05rem;color:#163d74">Agregar artículo al recibo</h3>';
        echo '<div style="display:flex;gap:0.75rem;align-items:flex-end;flex-wrap:wrap">';
        echo '<label style="min-width:24rem;font-weight:700">Agregar artículo <select style="font-size:1rem;padding:0.5rem 0.55rem" name="nuevo_item_id"><option value="0">— seleccionar —</option>';
        foreach ($articulosExtra as $ae) {
            $aid = (int) $ae['id'];
            $sel = $aid === $itemEditarIdGet ? ' selected' : '';
            echo '<option value="' . $aid . '"' . $sel . '>' . h((string) $ae['detalle']) . ' ($ ' . number_format((float) ($ae['importe_referencia'] ?? 0), 2, ',', '.') . ')</option>';
        }
        echo '</select></label>';
        echo '<label style="width:8rem;font-weight:700">Cantidad <input style="font-size:1rem;padding:0.5rem 0.45rem" type="number" step="0.01" min="0" name="nuevo_item_cantidad" value="' . h(number_format($itemEditarIdGet > 0 ? $itemEditarCantidadGet : 1, 2, '.', '')) . '"></label>';
        echo '<label style="width:10rem;font-weight:700">Punto de venta <input style="font-size:1rem;padding:0.5rem 0.45rem" type="number" min="1" name="punto_venta" value="' . (int) $puntoVentaGet . '"></label>';
        echo '</div>';
        echo '</fieldset>';
        echo '<div class="form-actions" style="margin-top:0.75rem">';
        echo '<button type="submit" class="btn-secondary">Calcular importes (descuento / recargo / ítems)</button>';
        if ($paso === 'calc') {
            echo ' <a class="muted" href="registrar_cobro.php?alumno_id=' . (int) $alumnoId . '&fecha_pago=' . rawurlencode($fechaPago) . '">Volver a la selección</a>';
        }
        echo '</div></form>';
        echo '</section>';

        echo '<div class="toolbar" style="margin-top:1rem"><button type="button" class="btn-secondary" data-open-modal="modal-liquidaciones">Cuotas ya pagadas (consulta)</button></div>';
        echo '<dialog id="modal-liquidaciones" class="app-modal"><div class="app-modal-content">';
        echo '<div class="app-modal-head"><h3>Cuotas ya pagadas desde ' . $anioOpUi . '</h3>';
        echo '<button type="button" class="app-modal-close" data-close-modal="modal-liquidaciones">Cerrar</button></div>';
        echo '<p class="muted">Solo lectura: períodos sin saldo pendiente. Marca <strong>L</strong> = liquidada. No se cobran desde acá.</p>';
        echo '<div style="overflow-x:auto;max-height:60vh">';
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
        echo '</tbody></table></div></div></dialog>';

        $hayItemSoloCalc = count($itemsGet) > 0;
        $hayAjusteCalc = count($lineasAjusteCalc) > 0;
        if ($paso === 'calc' && $calcError === null && (count($lineasCalc) > 0 || $hayItemSoloCalc || $hayAjusteCalc)) {
            $sumCap = 0.0;
            $sumRec = 0.0;
            $sumDesc = 0.0;
            $sumBeca = 0.0;
            $sumItem = 0.0;
            $sumTot = 0.0;
            foreach ($lineasCalc as $L) {
                $x = $L['calc'];
                $sumCap += $x['importe_capital'];
                $sumRec += $x['importe_recargo_variable'] + $x['importe_recargo_fijo'];
                $sumDesc += $x['importe_descuento'];
                $sumBeca += $x['importe_beca_perdida'];
                $sumTot += $x['total_linea'];
            }
            foreach ($lineasAjusteCalc as $rowAdj) {
                $x = $rowAdj['calc'];
                $sumCap += $x['importe_capital'];
                $sumRec += $x['importe_recargo_variable'] + $x['importe_recargo_fijo'];
                $sumDesc += $x['importe_descuento'];
                $sumBeca += $x['importe_beca_perdida'];
                $sumTot += $x['total_linea'];
            }
            $itemsCalc = [];
            foreach ($itemsGet as $it) {
                $ae = $articulosExtraMap[(int) $it['articulo_id']] ?? null;
                if ($ae === null) {
                    continue;
                }
                $qty = (float) $it['cantidad'];
                $unit = (float) ($ae['importe_referencia'] ?? 0);
                $tot = round($qty * $unit, 2);
                if ($tot <= 0) {
                    continue;
                }
                $itemsCalc[] = [
                    'articulo_id' => (int) $ae['id'],
                    'detalle' => (string) $ae['detalle'],
                    'cantidad' => $qty,
                    'unitario' => $unit,
                    'total' => $tot,
                ];
                $sumItem += $tot;
                $sumTot += $tot;
            }
            $sumTot = round($sumTot, 2);

            echo '<section class="card cobro-card cobro-card-detail">';
            echo '<h2 class="cobro-section-title">2) Detalle del cobro · fecha del recibo ' . h($fechaPago) . '</h2>';
            echo '<p class="muted"><strong>P</strong> = dentro del plazo de pronto pago (descuento). '
                . 'Sin <strong>P</strong> = fuera de plazo (mora y recargos). Aplica a <strong>cuotas mensuales</strong> y a <strong>obligaciones vencidas</strong> '
                . '(período = mes de la fecha del movimiento). Los <strong>debes manuales</strong> van aparte, sin recargo.</p>';
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
            foreach ($lineasAjusteCalc as $rowAdj) {
                $adj = $rowAdj['adj'];
                $x = $rowAdj['calc'];
                if (!empty($x['importe_fijo_sin_mora'])) {
                    continue;
                }
                $per = '';
                $perDatos = cobranza_periodo_desde_fecha_mov($adj);
                if ($perDatos !== null) {
                    $per = $perDatos['anio'] . '-' . str_pad((string) $perDatos['mes'], 2, '0', STR_PAD_LEFT);
                }
                $marcaP = $x['dentro_pronto']
                    ? '<span class="badge badge-ok" title="Pronto pago">P</span>'
                    : '<span class="muted">—</span>';
                $pres = cobranza_debe_pendiente_presentacion($adj);
                echo '<tr>';
                echo '<td>' . h($per !== '' ? $per : '—') . ' · ' . h($pres['concepto']) . '</td>';
                echo '<td>' . $marcaP . '</td>';
                echo '<td colspan="9">' . cobro_detalle_calculo_html($x) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            $ajustesFijosCalc = [];
            foreach ($lineasAjusteCalc as $rowAdj) {
                if (!empty($rowAdj['calc']['importe_fijo_sin_mora'])) {
                    $ajustesFijosCalc[] = $rowAdj;
                }
            }
            if (count($ajustesFijosCalc) > 0) {
                echo '<h3>Debe manual (importe fijo, sin recargo)</h3>';
                echo '<table class="table"><thead><tr><th>Fecha</th><th>Concepto</th><th>Importe</th></tr></thead><tbody>';
                foreach ($ajustesFijosCalc as $rowAdj) {
                    $adj = $rowAdj['adj'];
                    $pres = cobranza_debe_pendiente_presentacion($adj);
                    $f = (string) ($adj['fecha_mov'] ?? '');
                    $ts = strtotime($f);
                    $fTxt = $ts !== false ? date('d/m/Y', $ts) : $f;
                    echo '<tr><td>' . h($fTxt) . '</td><td>' . h($pres['concepto']) . '</td>';
                    echo '<td>$ ' . number_format((float) ($adj['debe'] ?? 0), 2, ',', '.') . '</td></tr>';
                }
                echo '</tbody></table>';
            }
            if (count($itemsCalc) > 0 && $sumItem > 0.00001) {
                echo '<h3>Ítems adicionales de recibo/factura</h3>';
                echo '<table class="table"><thead><tr><th>Artículo</th><th>Cantidad</th><th>Unitario</th><th>Total</th></tr></thead><tbody>';
                foreach ($itemsCalc as $itc) {
                    echo '<tr>';
                    echo '<td>' . h($itc['detalle']) . '</td>';
                    echo '<td>' . h(number_format((float) $itc['cantidad'], 2, ',', '.')) . '</td>';
                    echo '<td>$ ' . number_format((float) $itc['unitario'], 2, ',', '.') . '</td>';
                    echo '<td>$ ' . number_format((float) $itc['total'], 2, ',', '.') . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
            echo '<p><strong>Total a cobrar:</strong> $ ' . number_format($sumTot, 2, ',', '.') . '</p>';
            echo '<p class="muted" style="margin-top:0.25rem">Desglose: capital $ ' . number_format($sumCap, 2, ',', '.')
                . ' · recargos $ ' . number_format($sumRec, 2, ',', '.')
                . ' · dif. beca $ ' . number_format($sumBeca, 2, ',', '.')
                . ' · artículos nuevos $ ' . number_format($sumItem, 2, ',', '.')
                . ' · descuentos −$ ' . number_format($sumDesc, 2, ',', '.') . '</p>';

            if ($hasPacDetalle && $hasPagoComponentes && $hasBecaRegla) {
                echo '<h2 class="cobro-section-title">3) Forma de pago y confirmar</h2>';
                echo '<form method="post" class="form" id="form-confirmar-cobro">';
                echo '<input type="hidden" name="confirmar_cobro" value="1">';
                echo '<input type="hidden" name="alumno_id" value="' . (int) $alumnoId . '">';
                echo '<input type="hidden" name="fecha_pago" value="' . h($fechaPago) . '">';
                if ($desdeCajaFecha !== '') {
                    echo '<input type="hidden" name="desde_caja_fecha" value="' . h($desdeCajaFecha) . '">';
                }
                foreach ($lineasCalc as $row) {
                    echo '<input type="hidden" name="cuota_id[]" value="' . (int) $row['cuota']['id'] . '">';
                }
                foreach ($itemsCalc as $itc) {
                    echo '<input type="hidden" name="item_articulo_id[]" value="' . (int) $itc['articulo_id'] . '">';
                    echo '<input type="hidden" name="item_cantidad[]" value="' . h(number_format((float) $itc['cantidad'], 2, '.', '')) . '">';
                }
                foreach ($lineasAjusteCalc as $rowAdj) {
                    echo '<input type="hidden" name="ajuste_id[]" value="' . (int) $rowAdj['adj']['id'] . '">';
                }
                echo '<input type="hidden" name="punto_venta" value="' . (int) $puntoVentaGet . '">';

                if ($hasFormasPago) {
                    $formasActivas = formas_pago_listar_activas($pdo);
                    $tarjetasJson = tarjetas_listar_con_planes($pdo);
                    $maxDescEfectivoUi = (float) ($param['bonificacion_pronto_pago'] ?? 0);
                    $formasJson = array_map(static function (array $f): array {
                        return [
                            'id' => (int) $f['id'],
                            'nombre' => (string) $f['nombre'],
                            'recargo_pct' => (float) $f['recargo_pct'],
                            'permite_descuento_pct' => (bool) $f['permite_descuento_pct'],
                            'usa_planes_tarjeta' => (bool) $f['usa_planes_tarjeta'],
                            'requiere_referencia' => (bool) $f['requiere_referencia'],
                            'pide_datos_tarjeta' => (bool) $f['pide_datos_tarjeta'],
                        ];
                    }, $formasActivas);

                    echo '<p class="muted" style="font-size:0.9em;margin-bottom:0.5rem">'
                        . '<strong>Tarjeta de crédito:</strong> el recargo lo define la marca y las cuotas '
                        . '(<a href="tarjetas.php" target="_blank">Tarjetas</a>). '
                        . 'No es el recargo por mora de cuotas vencidas.</p>';
                    echo '<div id="cobro-medio-pago" class="cobro-medio-panel" data-subtotal="'
                        . h(number_format($sumTot, 2, '.', '')) . '" data-max-descuento="'
                        . h(number_format($maxDescEfectivoUi, 2, '.', '')) . '" data-formas="'
                        . h(json_encode($formasJson, JSON_UNESCAPED_UNICODE)) . '" data-tarjetas="'
                        . h(json_encode($tarjetasJson, JSON_UNESCAPED_UNICODE)) . '">';
                    echo '<div class="form-grid" style="max-width:36rem">';
                    echo '<label>Forma de pago <select name="forma_pago_id" required>';
                    foreach ($formasActivas as $fp) {
                        echo '<option value="' . (int) $fp['id'] . '">' . h((string) $fp['nombre']) . '</option>';
                    }
                    echo '</select></label>';

                    echo '<div class="cobro-medio-tarjeta" hidden>';
                    echo '<label>Tarjeta <select name="tarjeta_id"><option value="">— Elegir —</option>';
                    foreach ($tarjetasJson as $tj) {
                        echo '<option value="' . (int) $tj['id'] . '">' . h((string) $tj['nombre']) . '</option>';
                    }
                    echo '</select></label>';
                    echo '<label>Cuotas <select name="tarjeta_cuotas"><option value="">—</option></select></label>';
                    echo '</div>';

                    echo '<div class="cobro-medio-efectivo" hidden>';
                    echo '<label>Descuento % <input name="descuento_medio_pct" type="number" step="0.01" min="0" max="100" value="0">';
                    echo '<span class="hint">Máximo autorizado: ' . number_format($maxDescEfectivoUi, 2, ',', '.') . '% (parámetros cobranza).</span></label>';
                    echo '</div>';

                    echo '<div class="cobro-medio-referencia" hidden>';
                    echo '<label>Referencia / comprobante <input name="referencia_medio" maxlength="100" placeholder="Nº transferencia, cheque, etc."></label>';
                    echo '</div>';

                    echo '<div class="cobro-medio-datos-tarjeta" hidden>';
                    echo '<label>Nº lote <input name="nro_lote" maxlength="40"></label>';
                    echo '<label>Cód. autorización <input name="cod_autorizacion" maxlength="40"></label>';
                    echo '<label>Últimos 4 dígitos <input name="ultimos_digitos" maxlength="4" inputmode="numeric" pattern="[0-9]{0,4}"></label>';
                    echo '</div>';
                    echo '</div>';

                    echo '<p class="cobro-medio-resumen muted" style="margin:0.5rem 0"></p>';
                    echo '<p><strong>Total a registrar:</strong> <span class="cobro-medio-total">$ '
                        . number_format($sumTot, 2, ',', '.') . '</span></p>';
                    echo '</div>';
                    $jsMedio = dirname(__DIR__) . '/public/assets/cobro-medio-pago.js';
                    $jsMedioVer = is_file($jsMedio) ? (string) filemtime($jsMedio) : '1';
                    echo '<script src="assets/cobro-medio-pago.js?v=' . h($jsMedioVer) . '"></script>';
                } else {
                    echo '<div class="form-grid" style="max-width:22rem">';
                    echo '<label>Medio de pago <select name="medio">';
                    foreach (['efectivo', 'transferencia', 'tarjeta', 'cheque', 'otro'] as $m) {
                        echo '<option value="' . h($m) . '">' . h($m) . '</option>';
                    }
                    echo '</select></label>';
                    echo '<p class="muted">Para recargos por tarjeta, ejecutá migración 25.</p>';
                }

                echo '<div class="form-actions"><button type="submit" class="cobro-btn-primary">Registrar cobro</button></div>';
                echo '</form>';
            }
            echo '</section>';
        }
}

if ($datosRecibo !== null) {
    recibo_render_html($pdo, $datosRecibo, $alumnoId, $hasFormasPago, true, true, true);
}

echo '<p class="no-print"><a href="index.php">Inicio</a> · <a href="alumnos.php">Alumnos</a>';
if ($alumnoId > 0) {
    echo ' · <a href="cuenta_corriente.php?alumno_id=' . (int) $alumnoId . '">Cuenta corriente</a>';
}
echo ' · <a href="parametros_cobranza.php">Parámetros cobranza</a></p>';

layout_end();
