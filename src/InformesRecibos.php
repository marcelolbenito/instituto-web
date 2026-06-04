<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/PagoAnulacion.php';
require_once __DIR__ . '/FacturaElectronica.php';
require_once __DIR__ . '/FormasPago.php';

/**
 * @param array{
 *   fecha_desde?: string,
 *   fecha_hasta?: string,
 *   medio?: string,
 *   alumno_id?: int,
 *   q?: string,
 *   incluir_anulados?: bool
 * } $filtros
 * @return list<array<string, mixed>>
 */
function informes_listar_recibos(PDO $pdo, array $filtros = []): array
{
    $fechaDesde = trim((string) ($filtros['fecha_desde'] ?? ''));
    $fechaHasta = trim((string) ($filtros['fecha_hasta'] ?? ''));
    if ($fechaDesde === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde) !== 1) {
        $fechaDesde = date('Y-m-01');
    }
    if ($fechaHasta === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta) !== 1) {
        $fechaHasta = date('Y-m-d');
    }
    if ($fechaDesde > $fechaHasta) {
        [$fechaDesde, $fechaHasta] = [$fechaHasta, $fechaDesde];
    }

    $incluirAnulados = !empty($filtros['incluir_anulados']);
    $alumnoId = (int) ($filtros['alumno_id'] ?? 0);
    $medio = trim((string) ($filtros['medio'] ?? ''));
    $q = trim((string) ($filtros['q'] ?? ''));

    $where = ['pr.fecha_pago BETWEEN ? AND ?'];
    $params = [$fechaDesde, $fechaHasta];

    if (!$incluirAnulados && pago_anulacion_schema_ok($pdo)) {
        $where[] = pago_sql_solo_vigentes('pr');
    }

    $where[] = "COALESCE(pr.medio, '') NOT IN ('legacy', 'excel')";

    if ($alumnoId > 0) {
        $where[] = 'pr.alumno_id = ?';
        $params[] = $alumnoId;
    }
    if ($medio !== '') {
        $where[] = 'LOWER(COALESCE(pr.medio, \'\')) = ?';
        $params[] = strtolower($medio);
    }
    if ($q !== '') {
        if (preg_match('/^\d+$/', $q)) {
            $where[] = '(pr.id = ? OR a.documento LIKE ? OR a.codigo_legacy = ?)';
            $params[] = (int) $q;
            $params[] = '%' . $q . '%';
            $params[] = $q;
        } else {
            $where[] = '(a.nombre_completo LIKE ? OR a.documento LIKE ? OR a.codigo_legacy LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
    }

    $colsAnul = pago_anulacion_schema_ok($pdo)
        ? 'pr.anulado_en, pr.motivo_anulacion,'
        : 'NULL AS anulado_en, NULL AS motivo_anulacion,';

    $joinFe = fe_schema_ok($pdo)
        ? 'LEFT JOIN comprobante c ON c.id = pr.comprobante_id
           LEFT JOIN comprobante_electronico ce ON ce.comprobante_id = c.id'
        : '';
    $colsFe = fe_schema_ok($pdo)
        ? 'pr.comprobante_id, ce.estado AS fe_estado, ce.cae, c.punto_venta, c.numero,'
        : 'NULL AS comprobante_id, NULL AS fe_estado, NULL AS cae, NULL AS punto_venta, NULL AS numero,';

    $colsForma = db_has_column($pdo, 'pago_registrado', 'forma_pago_id')
        ? 'pr.forma_pago_id, pr.tarjeta_id, pr.tarjeta_cuotas,'
        : 'NULL AS forma_pago_id, NULL AS tarjeta_id, NULL AS tarjeta_cuotas,';

    $sql = '
        SELECT
            pr.id,
            pr.alumno_id,
            pr.fecha_pago,
            pr.importe,
            pr.medio,
            ' . $colsForma . '
            pr.referencia,
            pr.nota,
            ' . $colsAnul . '
            ' . $colsFe . '
            a.nombre_completo,
            a.documento,
            a.codigo_legacy
        FROM pago_registrado pr
        INNER JOIN alumnos a ON a.id = pr.alumno_id
        ' . $joinFe . '
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY pr.fecha_pago DESC, pr.id DESC
        LIMIT 2000
    ';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }

    $hasFormas = formas_pago_schema_ok($pdo);
    foreach ($rows as &$row) {
        $row['medio_etiqueta'] = $hasFormas
            ? formas_pago_etiqueta_cobro($pdo, $row)
            : (string) ($row['medio'] ?? '');
        $row['anulado'] = trim((string) ($row['anulado_en'] ?? '')) !== '';
        $feEst = (string) ($row['fe_estado'] ?? '');
        if ($row['comprobante_id'] === null || (int) $row['comprobante_id'] <= 0) {
            $row['fe_label'] = 'Sin FE';
        } elseif ($feEst === 'autorizado') {
            $pv = (int) ($row['punto_venta'] ?? 0);
            $num = (int) ($row['numero'] ?? 0);
            $row['fe_label'] = 'FE ' . $pv . '-' . str_pad((string) $num, 8, '0', STR_PAD_LEFT);
        } else {
            $row['fe_label'] = $feEst !== '' ? ucfirst($feEst) : 'FE pendiente';
        }
    }
    unset($row);

    return $rows;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{count:int,total:float,anulados:int}
 */
function informes_totales_recibos(array $rows): array
{
    $total = 0.0;
    $anulados = 0;
    foreach ($rows as $r) {
        if (!empty($r['anulado'])) {
            ++$anulados;
            continue;
        }
        $total += (float) ($r['importe'] ?? 0);
    }

    return ['count' => count($rows), 'total' => $total, 'anulados' => $anulados];
}

/**
 * Medios distintos en recibos web (para filtro).
 *
 * @return list<string>
 */
function informes_recibos_medios(PDO $pdo): array
{
    $extra = pago_anulacion_schema_ok($pdo) ? ' AND ' . pago_sql_solo_vigentes() : '';
    $st = $pdo->query(
        "SELECT DISTINCT LOWER(TRIM(medio)) AS m
         FROM pago_registrado
         WHERE COALESCE(medio, '') NOT IN ('legacy', 'excel')
           AND TRIM(COALESCE(medio, '')) <> ''
           {$extra}
         ORDER BY m"
    );
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $m) {
        $s = trim((string) $m);
        if ($s !== '') {
            $out[] = $s;
        }
    }

    return $out;
}
