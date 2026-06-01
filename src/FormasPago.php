<?php
declare(strict_types=1);

/**
 * Formas de pago y recargo por tarjeta/cuotas (ex Fox FORPAGO / TARRECA).
 */

function formas_pago_schema_ok(PDO $pdo): bool
{
    return db_has_column($pdo, 'pago_registrado', 'forma_pago_id')
        && db_has_column($pdo, 'formas_pago', 'codigo');
}

/**
 * @return list<array<string,mixed>>
 */
function formas_pago_listar_activas(PDO $pdo): array
{
    if (!db_has_column($pdo, 'formas_pago', 'codigo')) {
        return [];
    }
    $st = $pdo->query(
        'SELECT id, codigo, nombre, tipo, recargo_pct, permite_descuento_pct,
                usa_planes_tarjeta, requiere_referencia, pide_datos_tarjeta, orden
         FROM formas_pago
         WHERE activo = 1
         ORDER BY orden, nombre'
    );

    return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
}

/**
 * @return array<string,mixed>|null
 */
function formas_pago_por_id(PDO $pdo, int $id): ?array
{
    if ($id <= 0 || !db_has_column($pdo, 'formas_pago', 'codigo')) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM formas_pago WHERE id = ? AND activo = 1 LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Tarjetas activas con planes de cuotas.
 *
 * @return list<array{id:int,nombre:string,planes:list<array{cuotas:int,recargo_pct:float}>}>
 */
function tarjetas_listar_con_planes(PDO $pdo): array
{
    if (!db_has_column($pdo, 'tarjetas', 'nombre')) {
        return [];
    }
    $st = $pdo->query(
        'SELECT id, nombre FROM tarjetas WHERE activo = 1 ORDER BY nombre'
    );
    $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    $out = [];
    foreach ($rows as $t) {
        $tid = (int) $t['id'];
        $stP = $pdo->prepare(
            'SELECT cuotas, recargo_pct FROM tarjeta_recargo_cuota WHERE tarjeta_id = ? ORDER BY cuotas'
        );
        $stP->execute([$tid]);
        $planes = [];
        foreach ($stP->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $planes[] = [
                'cuotas' => (int) $p['cuotas'],
                'recargo_pct' => (float) $p['recargo_pct'],
            ];
        }
        $out[] = [
            'id' => $tid,
            'nombre' => (string) $t['nombre'],
            'planes' => $planes,
        ];
    }

    return $out;
}

/**
 * @return array{recargo_pct:float,recargo_importe:float,descuento_pct:float,descuento_importe:float}|null
 */
function formas_pago_recargo_plan(PDO $pdo, int $tarjetaId, int $cuotas): ?array
{
    $st = $pdo->prepare(
        'SELECT recargo_pct FROM tarjeta_recargo_cuota WHERE tarjeta_id = ? AND cuotas = ? LIMIT 1'
    );
    $st->execute([$tarjetaId, $cuotas]);
    $pct = $st->fetchColumn();
    if ($pct === false) {
        return null;
    }

    return ['recargo_pct' => (float) $pct];
}

/**
 * Calcula recargo o descuento por forma de pago sobre el subtotal de líneas (ex Fox TT).
 *
 * @param array<string,mixed> $forma Fila formas_pago
 * @return array{
 *   recargo_pct: float,
 *   recargo_importe: float,
 *   descuento_pct: float,
 *   descuento_importe: float,
 *   tarjeta_id: ?int,
 *   tarjeta_cuotas: ?int,
 *   error: ?string
 * }
 */
function formas_pago_calcular_ajuste_medio(
    PDO $pdo,
    array $forma,
    float $subtotal,
    ?int $tarjetaId,
    ?int $cuotas,
    float $descuentoPctSolicitado,
    float $maxDescuentoEfectivoPct
): array {
    $subtotal = round(max(0.0, $subtotal), 2);
    $out = [
        'recargo_pct' => 0.0,
        'recargo_importe' => 0.0,
        'descuento_pct' => 0.0,
        'descuento_importe' => 0.0,
        'tarjeta_id' => null,
        'tarjeta_cuotas' => null,
        'error' => null,
    ];

    if (!empty($forma['usa_planes_tarjeta'])) {
        if ($tarjetaId === null || $tarjetaId <= 0 || $cuotas === null || $cuotas <= 0) {
            $out['error'] = 'Seleccione tarjeta y cantidad de cuotas.';

            return $out;
        }
        $plan = formas_pago_recargo_plan($pdo, $tarjetaId, $cuotas);
        if ($plan === null) {
            $out['error'] = 'No hay recargo configurado para esa tarjeta y cantidad de cuotas.';

            return $out;
        }
        $out['recargo_pct'] = $plan['recargo_pct'];
        $out['tarjeta_id'] = $tarjetaId;
        $out['tarjeta_cuotas'] = $cuotas;
    } elseif ((float) ($forma['recargo_pct'] ?? 0) > 0.00001) {
        $out['recargo_pct'] = (float) $forma['recargo_pct'];
    }

    if (!empty($forma['permite_descuento_pct']) && $descuentoPctSolicitado > 0.00001) {
        if ($descuentoPctSolicitado > $maxDescuentoEfectivoPct + 0.00001) {
            $out['error'] = 'El descuento en efectivo no puede superar '
                . number_format($maxDescuentoEfectivoPct, 2, ',', '.') . '%.';

            return $out;
        }
        $out['descuento_pct'] = $descuentoPctSolicitado;
    }

    $out['recargo_importe'] = round($subtotal * $out['recargo_pct'] / 100, 2);
    $out['descuento_importe'] = round($subtotal * $out['descuento_pct'] / 100, 2);

    return $out;
}

/**
 * Etiqueta legible del cobro (forma + tarjeta si aplica).
 *
 * @param array<string,mixed> $pago Fila pago_registrado
 */
function formas_pago_etiqueta_cobro(PDO $pdo, array $pago): string
{
    $medio = trim((string) ($pago['medio'] ?? ''));
    if (!formas_pago_schema_ok($pdo) || empty($pago['forma_pago_id'])) {
        return $medio !== '' ? $medio : '—';
    }
    $st = $pdo->prepare('SELECT nombre FROM formas_pago WHERE id = ? LIMIT 1');
    $st->execute([(int) $pago['forma_pago_id']]);
    $nombre = (string) ($st->fetchColumn() ?: $medio);
    $tid = (int) ($pago['tarjeta_id'] ?? 0);
    if ($tid > 0) {
        $stT = $pdo->prepare('SELECT nombre FROM tarjetas WHERE id = ? LIMIT 1');
        $stT->execute([$tid]);
        $tar = (string) ($stT->fetchColumn() ?: '');
        $cuo = (int) ($pago['tarjeta_cuotas'] ?? 0);
        if ($tar !== '') {
            $nombre .= ' · ' . $tar;
            if ($cuo > 0) {
                $nombre .= ' ' . $cuo . ' cuota' . ($cuo === 1 ? '' : 's');
            }
        }
    }

    return $nombre;
}
