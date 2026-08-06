<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';

function caja_schema_ok(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $st = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_movimiento'"
    );
    $ok = $st !== false && (int) $st->fetchColumn() > 0;

    return $ok;
}

function caja_tiene_pago_id(PDO $pdo): bool
{
    return caja_schema_ok($pdo) && db_has_column($pdo, 'caja_movimiento', 'pago_id');
}

function caja_cierre_schema_ok(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $st = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_cierre'"
    );
    $ok = $st !== false && (int) $st->fetchColumn() > 0;

    return $ok;
}

/**
 * @return array<string,mixed>|null
 */
function caja_obtener_cierre(PDO $pdo, string $fechaYmd): ?array
{
    if (!caja_cierre_schema_ok($pdo) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaYmd) !== 1) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM caja_cierre WHERE fecha = ? LIMIT 1');
    $st->execute([$fechaYmd]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function caja_esta_cerrada(PDO $pdo, string $fechaYmd): bool
{
    return caja_obtener_cierre($pdo, $fechaYmd) !== null;
}

function caja_arqueo_schema_ok(PDO $pdo): bool
{
    return caja_cierre_schema_ok($pdo) && db_has_column($pdo, 'caja_cierre', 'arqueo_json');
}

/** @return array<string, string> slug => etiqueta */
function caja_medios_catalogo(): array
{
    return [
        'efectivo' => 'Efectivo',
        'transferencia' => 'Transferencia',
        'tarjeta' => 'Tarjeta',
        'cheque' => 'Cheque',
        'otro' => 'Otro',
    ];
}

function caja_label_medio(string $slug): string
{
    $cat = caja_medios_catalogo();

    return $cat[$slug] ?? ucfirst($slug);
}

/**
 * Etiquetas de formas de pago para el reporte de cierre (separando débito/crédito).
 *
 * @return array<string, string> codigo => etiqueta
 */
function caja_formas_pago_labels_reporte(): array
{
    return [
        'efectivo' => 'Efectivo',
        'transferencia' => 'Transferencia',
        'debito' => 'Tarjeta de débito',
        'tarjeta' => 'Tarjeta de crédito',
        'cheque' => 'Cheque',
        'otro' => 'Otro',
    ];
}

function caja_label_forma_pago_reporte(string $codigo, ?string $nombreCatalogo = null): string
{
    $codigo = strtolower(trim($codigo));
    if ($nombreCatalogo !== null && trim($nombreCatalogo) !== '') {
        return trim($nombreCatalogo);
    }
    $map = caja_formas_pago_labels_reporte();

    return $map[$codigo] ?? ($codigo !== '' ? ucfirst($codigo) : 'Otro');
}

/**
 * Totales del día por forma de pago real del cobro (débito y crédito separados).
 * Incluye movimientos manuales de caja (sin pago_id) agrupados por medio de caja.
 *
 * @return list<array{codigo:string,label:string,ingresos:float,egresos:float,neto:float,cantidad:int}>
 */
function caja_totales_por_forma_pago(PDO $pdo, string $fechaYmd): array
{
    $acc = [];
    if (!caja_schema_ok($pdo) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaYmd) !== 1) {
        return [];
    }

    $bump = static function (string $codigo, string $label, float $ing, float $egr, int $cant) use (&$acc): void {
        $codigo = strtolower(trim($codigo));
        if ($codigo === '' || $codigo === 'cuenta_corriente') {
            return;
        }
        if (!isset($acc[$codigo])) {
            $acc[$codigo] = [
                'codigo' => $codigo,
                'label' => $label,
                'ingresos' => 0.0,
                'egresos' => 0.0,
                'neto' => 0.0,
                'cantidad' => 0,
            ];
        }
        $acc[$codigo]['ingresos'] = round($acc[$codigo]['ingresos'] + $ing, 2);
        $acc[$codigo]['egresos'] = round($acc[$codigo]['egresos'] + $egr, 2);
        $acc[$codigo]['neto'] = round($acc[$codigo]['ingresos'] - $acc[$codigo]['egresos'], 2);
        $acc[$codigo]['cantidad'] += $cant;
        if ($label !== '' && ($acc[$codigo]['label'] === '' || $acc[$codigo]['label'] === ucfirst($codigo))) {
            $acc[$codigo]['label'] = $label;
        }
    };

    $nombresFp = [];
    if (db_has_column($pdo, 'formas_pago', 'codigo')) {
        $stN = $pdo->query('SELECT codigo, nombre FROM formas_pago');
        if ($stN) {
            foreach ($stN->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $nombresFp[strtolower((string) $row['codigo'])] = (string) $row['nombre'];
            }
        }
    }

    if (caja_tiene_pago_id($pdo)) {
        $exprFecha = caja_sql_expr_fecha_operativa();
        $sqlIng = caja_sql_importe_ingreso_vigente($pdo);
        $joinFp = db_has_column($pdo, 'pago_registrado', 'forma_pago_id')
            && db_has_column($pdo, 'formas_pago', 'codigo')
            ? 'LEFT JOIN formas_pago fp ON fp.id = pr.forma_pago_id'
            : '';
        $exprForma = $joinFp !== ''
            ? "LOWER(TRIM(COALESCE(NULLIF(pr.medio, ''), fp.codigo, cm.medio, 'otro')))"
            : "LOWER(TRIM(COALESCE(NULLIF(pr.medio, ''), cm.medio, 'otro')))";
        $sql = "SELECT {$exprForma} AS forma,
                       COALESCE(SUM({$sqlIng}), 0) AS ingresos,
                       COALESCE(SUM(" . caja_sql_importe_egreso() . "), 0) AS egresos,
                       SUM(CASE WHEN cm.tipo = 'ingreso' THEN 1 ELSE 0 END) AS cant_ing
                FROM caja_movimiento cm
                LEFT JOIN pago_registrado pr ON pr.id = cm.pago_id
                {$joinFp}
                WHERE {$exprFecha} = ?
                GROUP BY {$exprForma}";
        $st = $pdo->prepare($sql);
        $st->execute([$fechaYmd]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cod = strtolower(trim((string) ($row['forma'] ?? 'otro')));
            $label = caja_label_forma_pago_reporte($cod, $nombresFp[$cod] ?? null);
            $bump(
                $cod,
                $label,
                (float) ($row['ingresos'] ?? 0),
                (float) ($row['egresos'] ?? 0),
                (int) ($row['cant_ing'] ?? 0)
            );
        }
    } else {
        $st = $pdo->prepare(
            "SELECT medio AS forma,
                    COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN importe ELSE 0 END), 0) AS ingresos,
                    COALESCE(SUM(CASE WHEN tipo = 'egreso' THEN importe ELSE 0 END), 0) AS egresos,
                    SUM(CASE WHEN tipo = 'ingreso' THEN 1 ELSE 0 END) AS cant_ing
             FROM caja_movimiento
             WHERE DATE(fecha_hora) = ?
             GROUP BY medio"
        );
        $st->execute([$fechaYmd]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cod = strtolower(trim((string) ($row['forma'] ?? 'otro')));
            $bump(
                $cod,
                caja_label_forma_pago_reporte($cod, $nombresFp[$cod] ?? null),
                (float) ($row['ingresos'] ?? 0),
                (float) ($row['egresos'] ?? 0),
                (int) ($row['cant_ing'] ?? 0)
            );
        }
    }

    $orden = array_flip(array_keys(caja_formas_pago_labels_reporte()));
    uasort($acc, static function (array $a, array $b) use ($orden): int {
        $oa = $orden[$a['codigo']] ?? 500;
        $ob = $orden[$b['codigo']] ?? 500;
        if ($oa !== $ob) {
            return $oa <=> $ob;
        }

        return strcmp($a['label'], $b['label']);
    });

    return array_values(array_filter(
        $acc,
        static fn (array $r): bool => abs($r['ingresos']) > 0.005
            || abs($r['egresos']) > 0.005
            || $r['cantidad'] > 0
    ));
}

/**
 * Rango de numeración de facturas electrónicas autorizadas del día (por fecha de recibo).
 *
 * @return array{
 *   rangos: list<array{punto_venta:int,desde:int,hasta:int,cantidad:int,desde_fmt:string,hasta_fmt:string}>,
 *   cantidad_total: int
 * }
 */
function caja_rango_facturacion_dia(PDO $pdo, string $fechaYmd): array
{
    $vacío = ['rangos' => [], 'cantidad_total' => 0];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaYmd) !== 1) {
        return $vacío;
    }
    if (!db_has_column($pdo, 'pago_registrado', 'comprobante_id')) {
        return $vacío;
    }
    try {
        $pdo->query('SELECT 1 FROM comprobante_electronico LIMIT 1');
    } catch (Throwable $e) {
        return $vacío;
    }

    $filtroAnul = db_has_column($pdo, 'pago_registrado', 'anulado_en')
        ? ' AND pr.anulado_en IS NULL'
        : '';

    $sql = "SELECT c.punto_venta,
                   MIN(c.numero) AS desde,
                   MAX(c.numero) AS hasta,
                   COUNT(*) AS cantidad
            FROM pago_registrado pr
            INNER JOIN comprobante c ON c.id = pr.comprobante_id
            INNER JOIN comprobante_electronico ce ON ce.comprobante_id = c.id
            WHERE pr.fecha_pago = ?
              AND ce.estado = 'autorizado'
              AND COALESCE(pr.medio, '') NOT IN ('legacy', 'excel')
              {$filtroAnul}
            GROUP BY c.punto_venta
            ORDER BY c.punto_venta";
    $st = $pdo->prepare($sql);
    $st->execute([$fechaYmd]);
    $rangos = [];
    $total = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pv = (int) ($row['punto_venta'] ?? 0);
        $desde = (int) ($row['desde'] ?? 0);
        $hasta = (int) ($row['hasta'] ?? 0);
        $cant = (int) ($row['cantidad'] ?? 0);
        $total += $cant;
        $rangos[] = [
            'punto_venta' => $pv,
            'desde' => $desde,
            'hasta' => $hasta,
            'cantidad' => $cant,
            'desde_fmt' => sprintf('%05d-%08d', $pv, $desde),
            'hasta_fmt' => sprintf('%05d-%08d', $pv, $hasta),
        ];
    }

    return ['rangos' => $rangos, 'cantidad_total' => $total];
}

/**
 * Datos de reporte para un cierre: snapshot guardado o cálculo en vivo.
 *
 * @param array<string,mixed>|null $cierreRow
 * @return array{
 *   formas_pago: list<array{codigo:string,label:string,ingresos:float,egresos:float,neto:float,cantidad:int}>,
 *   facturacion: array{rangos:list<array<string,mixed>>,cantidad_total:int},
 *   desde_snapshot: bool
 * }
 */
function caja_datos_reporte_cierre(PDO $pdo, string $fechaYmd, ?array $cierreRow = null): array
{
    $arqueo = caja_decodificar_arqueo($cierreRow);
    if (is_array($arqueo)
        && isset($arqueo['formas_pago'], $arqueo['facturacion'])
        && is_array($arqueo['formas_pago'])
        && is_array($arqueo['facturacion'])
    ) {
        return [
            'formas_pago' => array_values($arqueo['formas_pago']),
            'facturacion' => [
                'rangos' => array_values($arqueo['facturacion']['rangos'] ?? []),
                'cantidad_total' => (int) ($arqueo['facturacion']['cantidad_total'] ?? 0),
            ],
            'desde_snapshot' => true,
        ];
    }

    return [
        'formas_pago' => caja_totales_por_forma_pago($pdo, $fechaYmd),
        'facturacion' => caja_rango_facturacion_dia($pdo, $fechaYmd),
        'desde_snapshot' => false,
    ];
}

/**
 * Bloque HTML del reporte: numeración FE + totales por forma de pago.
 *
 * @param array{
 *   formas_pago?: list<array<string,mixed>>,
 *   facturacion?: array{rangos?:list<array<string,mixed>>,cantidad_total?:int}
 * } $datos
 */
function caja_render_bloque_reporte(array $datos, string $tituloClass = 'caja-arqueo-titulo'): void
{
    $fact = is_array($datos['facturacion'] ?? null) ? $datos['facturacion'] : [];
    $rangos = is_array($fact['rangos'] ?? null) ? $fact['rangos'] : [];
    $formas = is_array($datos['formas_pago'] ?? null) ? $datos['formas_pago'] : [];

    echo '<h3 class="' . h($tituloClass) . '">Numeración de facturación</h3>';
    if ($rangos === []) {
        echo '<p class="muted">Sin facturas electrónicas autorizadas en esta fecha.</p>';
    } else {
        echo '<table class="table caja-reporte-facturacion"><thead><tr>';
        echo '<th>Punto de venta</th><th>Desde</th><th>Hasta</th><th class="num">Cantidad</th>';
        echo '</tr></thead><tbody>';
        foreach ($rangos as $r) {
            $pv = (int) ($r['punto_venta'] ?? 0);
            $desdeFmt = (string) ($r['desde_fmt'] ?? sprintf('%05d-%08d', $pv, (int) ($r['desde'] ?? 0)));
            $hastaFmt = (string) ($r['hasta_fmt'] ?? sprintf('%05d-%08d', $pv, (int) ($r['hasta'] ?? 0)));
            echo '<tr>';
            echo '<td>' . h((string) $pv) . '</td>';
            echo '<td><code>' . h($desdeFmt) . '</code></td>';
            echo '<td><code>' . h($hastaFmt) . '</code></td>';
            echo '<td class="num">' . (int) ($r['cantidad'] ?? 0) . '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        $cantTotal = (int) ($fact['cantidad_total'] ?? 0);
        if ($cantTotal > 0) {
            echo '<tfoot><tr><th colspan="3">Total facturas</th><td class="num">' . $cantTotal . '</td></tr></tfoot>';
        }
        echo '</table>';
    }

    echo '<h3 class="' . h($tituloClass) . '">Totalización por medio de pago</h3>';
    if ($formas === []) {
        echo '<p class="muted">Sin movimientos por medio de pago en esta fecha.</p>';

        return;
    }

    echo '<table class="table caja-reporte-medios"><thead><tr>';
    echo '<th>Medio de pago</th><th class="num">Ingresos</th><th class="num">Egresos</th><th class="num">Neto</th><th class="num">Cant.</th>';
    echo '</tr></thead><tbody>';
    $sumIng = 0.0;
    $sumEgr = 0.0;
    $sumCant = 0;
    foreach ($formas as $f) {
        $ing = (float) ($f['ingresos'] ?? 0);
        $egr = (float) ($f['egresos'] ?? 0);
        $neto = (float) ($f['neto'] ?? ($ing - $egr));
        $cant = (int) ($f['cantidad'] ?? 0);
        $sumIng += $ing;
        $sumEgr += $egr;
        $sumCant += $cant;
        echo '<tr>';
        echo '<td>' . h((string) ($f['label'] ?? caja_label_forma_pago_reporte((string) ($f['codigo'] ?? '')))) . '</td>';
        echo '<td class="num">$ ' . number_format($ing, 2, ',', '.') . '</td>';
        echo '<td class="num">$ ' . number_format($egr, 2, ',', '.') . '</td>';
        echo '<td class="num"><strong>$ ' . number_format($neto, 2, ',', '.') . '</strong></td>';
        echo '<td class="num">' . $cant . '</td>';
        echo '</tr>';
    }
    echo '</tbody><tfoot><tr>';
    echo '<th>Total</th>';
    echo '<td class="num">$ ' . number_format($sumIng, 2, ',', '.') . '</td>';
    echo '<td class="num">$ ' . number_format($sumEgr, 2, ',', '.') . '</td>';
    echo '<td class="num"><strong>$ ' . number_format($sumIng - $sumEgr, 2, ',', '.') . '</strong></td>';
    echo '<td class="num">' . $sumCant . '</td>';
    echo '</tr></tfoot></table>';
}

/**
 * Totales del día por medio (ingresos − egresos = neto esperado en caja).
 *
 * @return array<string, array{ingresos: float, egresos: float, neto: float}>
 */
function caja_resumen_por_medio(PDO $pdo, string $fechaYmd): array
{
    $out = [];
    foreach (array_keys(caja_medios_catalogo()) as $m) {
        $out[$m] = ['ingresos' => 0.0, 'egresos' => 0.0, 'neto' => 0.0];
    }
    if (!caja_schema_ok($pdo) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaYmd) !== 1) {
        return $out;
    }

    if (caja_tiene_pago_id($pdo)) {
        $exprFecha = caja_sql_expr_fecha_operativa();
        $sqlIng = caja_sql_importe_ingreso_vigente($pdo);
        $sql = "SELECT cm.medio,
                COALESCE(SUM({$sqlIng}), 0) AS ingresos,
                COALESCE(SUM(" . caja_sql_importe_egreso() . "), 0) AS egresos
             FROM caja_movimiento cm
             LEFT JOIN pago_registrado pr ON pr.id = cm.pago_id
             WHERE {$exprFecha} = ?
             GROUP BY cm.medio";
    } else {
        $sql = "SELECT medio,
                COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN importe ELSE 0 END), 0) AS ingresos,
                COALESCE(SUM(CASE WHEN tipo = 'egreso' THEN importe ELSE 0 END), 0) AS egresos
             FROM caja_movimiento
             WHERE DATE(fecha_hora) = ?
             GROUP BY medio";
    }
    $st = $pdo->prepare($sql);
    $st->execute([$fechaYmd]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $medio = (string) ($row['medio'] ?? 'otro');
        if (!isset($out[$medio])) {
            $out[$medio] = ['ingresos' => 0.0, 'egresos' => 0.0, 'neto' => 0.0];
        }
        $ing = round((float) ($row['ingresos'] ?? 0), 2);
        $egr = round((float) ($row['egresos'] ?? 0), 2);
        $out[$medio] = [
            'ingresos' => $ing,
            'egresos' => $egr,
            'neto' => round($ing - $egr, 2),
        ];
    }

    return $out;
}

/**
 * @param array<string, float> $contadoPorMedio Importes contados a mano (solo medios informados).
 * @return array{json: string, diferencia: float|null, lineas: array<string, array<string, mixed>>}
 */
function caja_armar_arqueo_cierre(PDO $pdo, string $fechaYmd, array $contadoPorMedio): array
{
    $esperado = caja_resumen_por_medio($pdo, $fechaYmd);
    $lineas = [];
    $diffTotal = 0.0;
    $hayContado = false;

    foreach (caja_medios_catalogo() as $slug => $label) {
        $esp = $esperado[$slug]['neto'] ?? 0.0;
        $cont = array_key_exists($slug, $contadoPorMedio)
            ? round((float) $contadoPorMedio[$slug], 2)
            : null;
        $dif = null;
        if ($cont !== null) {
            $hayContado = true;
            $dif = round($cont - $esp, 2);
            $diffTotal += $dif;
        }
        $lineas[$slug] = [
            'label' => $label,
            'ingresos' => $esperado[$slug]['ingresos'] ?? 0.0,
            'egresos' => $esperado[$slug]['egresos'] ?? 0.0,
            'esperado' => $esp,
            'contado' => $cont,
            'diferencia' => $dif,
        ];
    }

    $formasPago = caja_totales_por_forma_pago($pdo, $fechaYmd);
    $facturacion = caja_rango_facturacion_dia($pdo, $fechaYmd);

    return [
        'json' => json_encode(
            [
                'medios' => $lineas,
                'diferencia_total' => $hayContado ? round($diffTotal, 2) : null,
                'formas_pago' => $formasPago,
                'facturacion' => $facturacion,
            ],
            JSON_UNESCAPED_UNICODE
        ),
        'diferencia' => $hayContado ? round($diffTotal, 2) : null,
        'lineas' => $lineas,
        'formas_pago' => $formasPago,
        'facturacion' => $facturacion,
    ];
}

/**
 * @param array<string, mixed> $post
 * @return array<string, float>
 */
function caja_arqueo_contado_desde_post(array $post): array
{
    $contado = [];
    foreach (array_keys(caja_medios_catalogo()) as $slug) {
        $key = 'contado_' . $slug;
        if (!isset($post[$key]) || trim((string) $post[$key]) === '') {
            continue;
        }
        $v = (float) str_replace(',', '.', (string) $post[$key]);
        if ($v >= 0) {
            $contado[$slug] = round($v, 2);
        }
    }

    return $contado;
}

/**
 * @param array<string, mixed>|null $cierreRow Fila de caja_cierre
 * @return array<string, mixed>|null
 */
function caja_decodificar_arqueo(?array $cierreRow): ?array
{
    if ($cierreRow === null || empty($cierreRow['arqueo_json'])) {
        return null;
    }
    $data = json_decode((string) $cierreRow['arqueo_json'], true);

    return is_array($data) ? $data : null;
}

/**
 * @param array<string, float> $contadoPorMedio
 * @return array{ok:bool,msg?:string,arqueo_diferencia?:float|null}
 */
function caja_cerrar_dia(PDO $pdo, string $fechaYmd, string $observaciones = '', array $contadoPorMedio = []): array
{
    if (!caja_cierre_schema_ok($pdo)) {
        return ['ok' => false, 'msg' => 'Ejecutá migración 28_caja_cierre.sql'];
    }
    if (!caja_schema_ok($pdo)) {
        return ['ok' => false, 'msg' => 'Falta tabla caja_movimiento.'];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaYmd) !== 1) {
        return ['ok' => false, 'msg' => 'Fecha inválida.'];
    }
    if (caja_esta_cerrada($pdo, $fechaYmd)) {
        return ['ok' => false, 'msg' => 'Esa fecha ya está cerrada.'];
    }

    caja_sincronizar_cobros_fecha($pdo, $fechaYmd);
    $resumen = caja_resumen_dia($pdo, $fechaYmd);
    $obs = trim($observaciones);
    if (mb_strlen($obs) > 500) {
        $obs = mb_substr($obs, 0, 500);
    }

    $arqueoJson = null;
    $arqueoDif = null;
    if (caja_arqueo_schema_ok($pdo)) {
        $arq = caja_armar_arqueo_cierre($pdo, $fechaYmd, $contadoPorMedio);
        $arqueoJson = $arq['json'];
        $arqueoDif = $arq['diferencia'];
    }

    if (caja_arqueo_schema_ok($pdo)) {
        $st = $pdo->prepare(
            'INSERT INTO caja_cierre (fecha, cerrado_en, ingresos, egresos, saldo, cantidad_movimientos, observaciones, arqueo_json, arqueo_diferencia)
             VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $fechaYmd,
            $resumen['ingresos'],
            $resumen['egresos'],
            $resumen['saldo'],
            $resumen['cantidad'],
            $obs !== '' ? $obs : null,
            $arqueoJson,
            $arqueoDif,
        ]);
    } else {
        $st = $pdo->prepare(
            'INSERT INTO caja_cierre (fecha, cerrado_en, ingresos, egresos, saldo, cantidad_movimientos, observaciones)
             VALUES (?, NOW(), ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $fechaYmd,
            $resumen['ingresos'],
            $resumen['egresos'],
            $resumen['saldo'],
            $resumen['cantidad'],
            $obs !== '' ? $obs : null,
        ]);
    }

    return ['ok' => true, 'arqueo_diferencia' => $arqueoDif];
}

/**
 * @return list<array<string,mixed>>
 */
function caja_listar_cierres(PDO $pdo, int $limite = 90): array
{
    if (!caja_cierre_schema_ok($pdo)) {
        return [];
    }
    $limite = max(1, min(365, $limite));
    $st = $pdo->query(
        'SELECT * FROM caja_cierre ORDER BY fecha DESC LIMIT ' . (int) $limite
    );

    return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

/**
 * Fechas con movimientos de caja pero sin cierre (últimos N días).
 *
 * @return list<string> Fechas Y-m-d
 */
function caja_fechas_abiertas_recientes(PDO $pdo, int $dias = 45): array
{
    if (!caja_schema_ok($pdo) || !caja_cierre_schema_ok($pdo)) {
        return [];
    }
    $dias = max(7, min(120, $dias));
    $desde = date('Y-m-d', strtotime('-' . $dias . ' days'));

    if (caja_tiene_pago_id($pdo)) {
        $expr = caja_sql_expr_fecha_operativa();
        $sql = "SELECT DISTINCT {$expr} AS f
                FROM caja_movimiento cm
                LEFT JOIN pago_registrado pr ON pr.id = cm.pago_id
                WHERE {$expr} >= ?
                ORDER BY f DESC";
    } else {
        $sql = 'SELECT DISTINCT DATE(fecha_hora) AS f
                FROM caja_movimiento
                WHERE DATE(fecha_hora) >= ?
                ORDER BY f DESC';
    }
    $st = $pdo->prepare($sql);
    $st->execute([$desde]);
    $fechas = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $f) {
        $fy = (string) $f;
        if ($fy !== '' && !caja_esta_cerrada($pdo, $fy)) {
            $fechas[] = $fy;
        }
    }

    return $fechas;
}

/** Fecha operativa del movimiento: fecha del recibo si es cobro web, si no fecha_hora. */
function caja_sql_expr_fecha_operativa(): string
{
    return 'DATE(COALESCE(pr.fecha_pago, cm.fecha_hora))';
}

/** Ingresos de recibos anulados no suman en arqueo / totales operativos. */
function caja_tiene_filtro_anulados(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $ok = caja_tiene_pago_id($pdo) && db_has_column($pdo, 'pago_registrado', 'anulado_en');

    return $ok;
}

function caja_sql_importe_ingreso_vigente(PDO $pdo): string
{
    if (!caja_tiene_filtro_anulados($pdo)) {
        return "CASE WHEN cm.tipo = 'ingreso' THEN cm.importe ELSE 0 END";
    }

    return "CASE
        WHEN cm.tipo = 'ingreso' AND cm.pago_id IS NOT NULL AND pr.anulado_en IS NOT NULL THEN 0
        WHEN cm.tipo = 'ingreso' THEN cm.importe
        ELSE 0
    END";
}

function caja_sql_importe_egreso(): string
{
    return "CASE WHEN cm.tipo = 'egreso' THEN cm.importe ELSE 0 END";
}

/**
 * @return array{estado:string,msg:string}
 *   estado: registrado | omitido_cierre | sin_impacto | sin_ingreso | duplicado
 */
function caja_registrar_egreso_por_anulacion(
    PDO $pdo,
    int $pagoId,
    int $alumnoId,
    string $fechaCobroYmd,
    float $importe,
    string $medioSlug,
    string $motivo
): array {
    if (!caja_schema_ok($pdo) || $pagoId <= 0 || $importe <= 0.00001) {
        return ['estado' => 'sin_impacto', 'msg' => ''];
    }
    if (!caja_cobro_impacta_caja($medioSlug)) {
        return ['estado' => 'sin_impacto', 'msg' => 'Cobro en cuenta corriente: no impacta caja física.'];
    }

    $refAnul = 'ANUL:PAGO:' . $pagoId;
    $stDup = $pdo->prepare(
        'SELECT id FROM caja_movimiento WHERE referencia = ? AND tipo = \'egreso\' LIMIT 1'
    );
    $stDup->execute([$refAnul]);
    if ($stDup->fetch()) {
        return ['estado' => 'duplicado', 'msg' => ''];
    }

    $habiaIngreso = false;
    if (caja_tiene_pago_id($pdo)) {
        $stIng = $pdo->prepare(
            'SELECT id FROM caja_movimiento WHERE pago_id = ? AND tipo = \'ingreso\' LIMIT 1'
        );
        $stIng->execute([$pagoId]);
        $habiaIngreso = (bool) $stIng->fetch();
    }
    if (!$habiaIngreso) {
        return ['estado' => 'sin_ingreso', 'msg' => 'No había movimiento de caja vinculado a este recibo.'];
    }

    $fechaCobro = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaCobroYmd) === 1
        ? $fechaCobroYmd
        : date('Y-m-d');

    if (caja_cierre_schema_ok($pdo) && caja_esta_cerrada($pdo, $fechaCobro)) {
        $ts = strtotime($fechaCobro);
        $txt = $ts !== false ? date('d/m/Y', $ts) : $fechaCobro;

        return [
            'estado' => 'omitido_cierre',
            'msg' => 'La caja del ' . $txt . ' ya está cerrada: no se agregó egreso automático. '
                . 'El ingreso del recibo deja de contar en totales operativos; el cierre impreso conserva el histórico.',
        ];
    }

    $fechaHora = $fechaCobro . ' ' . date('H:i:s');
    $medio = caja_medio_desde_cobro($medioSlug);
    $obs = 'Anulación recibo Nº ' . $pagoId;
    $motivoTrim = trim($motivo);
    if ($motivoTrim !== '') {
        $obs .= ' — ' . $motivoTrim;
    }
    if (mb_strlen($obs) > 250) {
        $obs = mb_substr($obs, 0, 250);
    }

    $ins = $pdo->prepare(
        'INSERT INTO caja_movimiento (comprobante_id, alumno_id, fecha_hora, tipo, medio, referencia, importe, observaciones)
         VALUES (NULL, ?, ?, \'egreso\', ?, ?, ?, ?)'
    );
    $ins->execute([
        $alumnoId > 0 ? $alumnoId : null,
        $fechaHora,
        $medio,
        $refAnul,
        round($importe, 2),
        $obs,
    ]);

    return [
        'estado' => 'registrado',
        'msg' => 'Se registró egreso en la caja del día del cobro (compensa el ingreso del recibo).',
    ];
}

/**
 * Crea movimientos de caja para cobros web de una fecha que aún no impactaron caja.
 */
function caja_sincronizar_cobros_fecha(PDO $pdo, string $fechaYmd): int
{
    if (!caja_schema_ok($pdo) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaYmd) !== 1) {
        return 0;
    }

    $joinCaja = caja_tiene_pago_id($pdo)
        ? 'LEFT JOIN caja_movimiento cm ON cm.pago_id = p.id'
        : 'LEFT JOIN caja_movimiento cm ON cm.referencia = p.referencia AND cm.tipo = \'ingreso\'';

    $sql = "SELECT p.id, p.alumno_id, p.fecha_pago, p.importe, p.medio, p.referencia, p.nota
            FROM pago_registrado p
            {$joinCaja}
            WHERE p.fecha_pago = ?
              AND COALESCE(p.medio, '') NOT IN ('legacy', 'excel')
              AND COALESCE(p.importe, 0) > 0.005
              AND cm.id IS NULL";
    $st = $pdo->prepare($sql);
    $st->execute([$fechaYmd]);
    $creados = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $medio = trim((string) ($p['medio'] ?? ''));
        if ($medio === '' && db_has_column($pdo, 'pago_registrado', 'forma_pago_id')) {
            $stF = $pdo->prepare(
                'SELECT fp.codigo FROM pago_registrado pr
                 JOIN formas_pago fp ON fp.id = pr.forma_pago_id
                 WHERE pr.id = ? LIMIT 1'
            );
            $stF->execute([(int) $p['id']]);
            $cod = $stF->fetchColumn();
            if ($cod !== false) {
                $medio = (string) $cod;
            }
        }
        if (caja_registrar_ingreso_por_cobro(
            $pdo,
            (int) $p['id'],
            (int) ($p['alumno_id'] ?? 0),
            (string) $p['fecha_pago'],
            (float) $p['importe'],
            $medio !== '' ? $medio : 'otro',
            (string) ($p['referencia'] ?? '')
        )) {
            $creados++;
        }
    }

    return $creados;
}

/**
 * Mapea medio de cobro / forma de pago al ENUM de caja_movimiento.
 */
function caja_medio_desde_cobro(string $medioSlug): string
{
    $m = strtolower(trim($medioSlug));
    if (in_array($m, ['efectivo', 'transferencia', 'tarjeta', 'cheque', 'otro'], true)) {
        return $m;
    }
    if ($m === 'debito') {
        return 'tarjeta';
    }

    return 'otro';
}

/**
 * Cobros en cuenta corriente no impactan caja física.
 */
function caja_cobro_impacta_caja(string $medioSlug): bool
{
    return strtolower(trim($medioSlug)) !== 'cuenta_corriente';
}

/** Texto legible en movimientos de caja por un cobro (sin parámetros técnicos). */
function caja_observacion_cobro(int $pagoId): string
{
    return 'Cobro recibo Nº ' . $pagoId;
}

/**
 * Oculta el detalle técnico antiguo (PV, mora coef, etc.) al mostrar en pantalla.
 */
function caja_observacion_mostrar(string $observaciones): string
{
    $obs = trim($observaciones);
    if (preg_match('/^Cobro recibo #(\d+)/u', $obs, $m)) {
        return caja_observacion_cobro((int) $m[1]);
    }
    if (preg_match('/^Cobro recibo Nº (\d+)/u', $obs, $m)) {
        return caja_observacion_cobro((int) $m[1]);
    }

    return $obs;
}

/**
 * Registra ingreso por un cobro web (idempotente si existe pago_id).
 */
function caja_registrar_ingreso_por_cobro(
    PDO $pdo,
    int $pagoId,
    int $alumnoId,
    string $fechaPagoYmd,
    float $importe,
    string $medioSlug,
    string $referencia = ''
): bool {
    if (!caja_schema_ok($pdo) || $pagoId <= 0 || $importe <= 0.00001) {
        return false;
    }
    if (!caja_cobro_impacta_caja($medioSlug)) {
        return false;
    }

    if (caja_tiene_pago_id($pdo)) {
        $stChk = $pdo->prepare('SELECT id FROM caja_movimiento WHERE pago_id = ? LIMIT 1');
        $stChk->execute([$pagoId]);
        if ($stChk->fetch()) {
            return false;
        }
    }

    $fechaHora = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaPagoYmd) === 1
        ? $fechaPagoYmd . ' ' . date('H:i:s')
        : date('Y-m-d H:i:s');
    $medio = caja_medio_desde_cobro($medioSlug);
    $obs = caja_observacion_cobro($pagoId);
    $ref = $referencia !== '' ? $referencia : ('PAGO:' . $pagoId);

    if (caja_tiene_pago_id($pdo)) {
        $ins = $pdo->prepare(
            'INSERT INTO caja_movimiento (pago_id, comprobante_id, alumno_id, fecha_hora, tipo, medio, referencia, importe, observaciones)
             VALUES (?, NULL, ?, ?, \'ingreso\', ?, ?, ?, ?)'
        );
        $ins->execute([$pagoId, $alumnoId > 0 ? $alumnoId : null, $fechaHora, $medio, $ref, round($importe, 2), $obs]);
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO caja_movimiento (comprobante_id, alumno_id, fecha_hora, tipo, medio, referencia, importe, observaciones)
             VALUES (NULL, ?, ?, \'ingreso\', ?, ?, ?, ?)'
        );
        $ins->execute([$alumnoId > 0 ? $alumnoId : null, $fechaHora, $medio, $ref, round($importe, 2), $obs]);
    }

    return true;
}

/**
 * @return array{ok:bool,msg?:string,id?:int}
 */
function caja_registrar_manual(
    PDO $pdo,
    string $fechaYmd,
    string $tipo,
    float $importe,
    string $medio,
    string $observaciones,
    ?int $alumnoId = null
): array {
    if (!caja_schema_ok($pdo)) {
        return ['ok' => false, 'msg' => 'Tabla caja_movimiento no existe. Ejecutá migración 04.'];
    }
    if (!in_array($tipo, ['ingreso', 'egreso'], true)) {
        return ['ok' => false, 'msg' => 'Tipo inválido.'];
    }
    if ($importe <= 0.00001) {
        return ['ok' => false, 'msg' => 'Importe debe ser mayor a cero.'];
    }
    if (trim($observaciones) === '') {
        return ['ok' => false, 'msg' => 'Indicá un concepto u observación.'];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaYmd) !== 1) {
        return ['ok' => false, 'msg' => 'Fecha inválida.'];
    }
    if (caja_esta_cerrada($pdo, $fechaYmd)) {
        return ['ok' => false, 'msg' => 'La caja de esa fecha ya está cerrada. No se pueden agregar movimientos manuales.'];
    }

    $medioNorm = caja_medio_desde_cobro($medio);
    $fechaHora = $fechaYmd . ' ' . date('H:i:s');

    $ins = $pdo->prepare(
        'INSERT INTO caja_movimiento (comprobante_id, alumno_id, fecha_hora, tipo, medio, referencia, importe, observaciones)
         VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([
        $alumnoId !== null && $alumnoId > 0 ? $alumnoId : null,
        $fechaHora,
        $tipo,
        $medioNorm,
        'MANUAL:' . date('YmdHis'),
        round($importe, 2),
        trim($observaciones),
    ]);

    return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
}

/**
 * @return array{ingresos:float,egresos:float,saldo:float,cantidad:int}
 */
function caja_resumen_dia(PDO $pdo, string $fechaYmd): array
{
    $vacío = ['ingresos' => 0.0, 'egresos' => 0.0, 'saldo' => 0.0, 'cantidad' => 0];
    if (!caja_schema_ok($pdo) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaYmd) !== 1) {
        return $vacío;
    }

    if (caja_tiene_pago_id($pdo)) {
        $exprFecha = caja_sql_expr_fecha_operativa();
        $sqlIng = caja_sql_importe_ingreso_vigente($pdo);
        $st = $pdo->prepare(
            "SELECT
                COALESCE(SUM({$sqlIng}), 0) AS ingresos,
                COALESCE(SUM(" . caja_sql_importe_egreso() . "), 0) AS egresos,
                COUNT(*) AS cantidad
             FROM caja_movimiento cm
             LEFT JOIN pago_registrado pr ON pr.id = cm.pago_id
             WHERE {$exprFecha} = ?"
        );
    } else {
        $st = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN importe ELSE 0 END), 0) AS ingresos,
                COALESCE(SUM(CASE WHEN tipo = 'egreso' THEN importe ELSE 0 END), 0) AS egresos,
                COUNT(*) AS cantidad
             FROM caja_movimiento
             WHERE DATE(fecha_hora) = ?"
        );
    }
    $st->execute([$fechaYmd]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $ing = round((float) ($row['ingresos'] ?? 0), 2);
    $egr = round((float) ($row['egresos'] ?? 0), 2);

    return [
        'ingresos' => $ing,
        'egresos' => $egr,
        'saldo' => round($ing - $egr, 2),
        'cantidad' => (int) ($row['cantidad'] ?? 0),
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function caja_listar_dia(PDO $pdo, string $fechaYmd): array
{
    if (!caja_schema_ok($pdo) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaYmd) !== 1) {
        return [];
    }

    $exprFecha = caja_sql_expr_fecha_operativa();
    if (caja_tiene_pago_id($pdo)) {
        $colsAnul = caja_tiene_filtro_anulados($pdo) ? ', pr.anulado_en' : ', NULL AS anulado_en';
        $sql = "SELECT cm.id, cm.fecha_hora, cm.tipo, cm.medio, cm.referencia, cm.importe, cm.observaciones,
                       cm.pago_id, COALESCE(cm.alumno_id, pr.alumno_id) AS alumno_id_link,
                       pr.fecha_pago{$colsAnul},
                       a.nombre_completo AS alumno_nombre
                FROM caja_movimiento cm
                LEFT JOIN pago_registrado pr ON pr.id = cm.pago_id
                LEFT JOIN alumnos a ON a.id = COALESCE(cm.alumno_id, pr.alumno_id)
                WHERE {$exprFecha} = ?
                ORDER BY cm.fecha_hora DESC, cm.id DESC";
    } else {
        $sql = "SELECT cm.id, cm.fecha_hora, cm.tipo, cm.medio, cm.referencia, cm.importe, cm.observaciones,
                       NULL AS pago_id, cm.alumno_id AS alumno_id_link,
                       NULL AS fecha_pago,
                       a.nombre_completo AS alumno_nombre
                FROM caja_movimiento cm
                LEFT JOIN alumnos a ON a.id = cm.alumno_id
                WHERE DATE(cm.fecha_hora) = ?
                ORDER BY cm.fecha_hora DESC, cm.id DESC";
    }
    $st = $pdo->prepare($sql);
    $st->execute([$fechaYmd]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
