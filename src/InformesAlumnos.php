<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/RegularidadAlumno.php';
require_once __DIR__ . '/Cobranza.php';
require_once __DIR__ . '/PagoAnulacion.php';

/**
 * @param array{activo?: string, barrio_id?: int, min_saldo?: float, solo_morosos?: bool} $filtros
 * @return list<array<string, mixed>>
 */
function informes_listar_alumnos(PDO $pdo, array $filtros = []): array
{
    $activoFiltro = (string) ($filtros['activo'] ?? 'activos');
    if (!in_array($activoFiltro, ['activos', 'inactivos', 'todos'], true)) {
        $activoFiltro = 'activos';
    }
    $where = [];
    $params = [];
    if ($activoFiltro === 'activos') {
        $where[] = 'a.activo = 1';
    } elseif ($activoFiltro === 'inactivos') {
        $where[] = 'a.activo = 0';
    }
    $barrioId = (int) ($filtros['barrio_id'] ?? 0);
    if ($barrioId > 0) {
        $where[] = 'a.barrio_id = ?';
        $params[] = $barrioId;
    }
    $minSaldo = (float) ($filtros['min_saldo'] ?? 0.0);
    if ($minSaldo > 0) {
        $where[] = 'COALESCE(a.saldo_cc, 0) >= ?';
        $params[] = $minSaldo;
    }
    $soloMorosos = !empty($filtros['solo_morosos']);
    if ($soloMorosos) {
        $idsCuotasVencidas = cobranza_alumno_ids_con_cuotas_vencidas($pdo);
        if ($idsCuotasVencidas === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($idsCuotasVencidas), '?'));
        $where[] = 'a.id IN (' . $placeholders . ')';
        foreach ($idsCuotasVencidas as $idCv) {
            $params[] = $idCv;
        }
    }
    $sqlWhere = $where !== [] ? (' WHERE ' . implode(' AND ', $where)) : '';

    $filtroPagoVigente = pago_anulacion_schema_ok($pdo) ? (' AND ' . pago_sql_solo_vigentes()) : '';
    $sql = '
        SELECT
            a.id,
            a.codigo_legacy,
            a.nombre_completo,
            a.activo,
            a.saldo_cc,
            a.documento,
            b.nombre AS barrio_nombre,
            up.ultimo_pago
        FROM alumnos a
        LEFT JOIN barrios b ON b.id = a.barrio_id
        LEFT JOIN (
            SELECT alumno_id, MAX(fecha_pago) AS ultimo_pago
            FROM pago_registrado
            WHERE 1=1' . $filtroPagoVigente . '
            GROUP BY alumno_id
        ) up ON up.alumno_id = a.id
        ' . $sqlWhere . '
        ORDER BY a.nombre_completo
    ';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }

    $regFiltro = $filtros['regularidad'] ?? [];
    if (!is_array($regFiltro)) {
        $regFiltro = $regFiltro !== '' ? [(string) $regFiltro] : [];
    }
    $regFiltro = array_values(array_intersect(
        ['regular', 'riesgo', 'irregular', 'sin_pagos', 'no_activo'],
        array_map('strval', $regFiltro)
    ));
    if ($regFiltro === []) {
        return $rows;
    }

    $out = [];
    foreach ($rows as $r) {
        $reg = regularidad_clasificar((int) ($r['activo'] ?? 0) === 1, $r['ultimo_pago'] ?? null);
        if (regularidad_coincide($regFiltro, $reg['key'])) {
            $r['_regularidad'] = $reg;
            $out[] = $r;
        }
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{count: int, total_saldo: float, morosos: int}
 */
function informes_totales_saldo(array $rows): array
{
    $total = 0.0;
    $morosos = 0;
    foreach ($rows as $r) {
        $s = (float) ($r['saldo_cc'] ?? 0);
        $total += $s;
        if ($s > 0.009) {
            ++$morosos;
        }
    }

    return ['count' => count($rows), 'total_saldo' => $total, 'morosos' => $morosos];
}

/**
 * @param list<array<string, mixed>> $rows
 */
function informes_csv_salida(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        return;
    }
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers, ';');
    foreach ($rows as $line) {
        fputcsv($out, $line, ';');
    }
    fclose($out);
    exit;
}
