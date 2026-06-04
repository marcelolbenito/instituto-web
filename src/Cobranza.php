<?php
declare(strict_types=1);

/**
 * N-ésimo día hábil (lun–vie) desde una fecha inclusive.
 * El día inicial cuenta como intento 1 si es hábil; si no, se avanza hasta el primer hábil.
 */
function cobranza_fecha_nesimo_dia_habil_desde(
    DateTimeImmutable $desde,
    int $diasHabiles,
    array $fechasFeriado = []
): DateTimeImmutable {
    $d = $desde;
    $habiles = 0;
    $feriadosSet = [];
    foreach ($fechasFeriado as $f) {
        $feriadosSet[(string) $f] = true;
    }
    while ($habiles < $diasHabiles) {
        $n = (int) $d->format('N');
        $ymd = $d->format('Y-m-d');
        $esFeriado = isset($feriadosSet[$ymd]);
        if ($n < 6 && !$esFeriado) {
            $habiles++;
            if ($habiles === $diasHabiles) {
                return $d;
            }
        }
        $d = $d->modify('+1 day');
    }

    return $d;
}

/**
 * N-ésimo día hábil del mes del período, contando desde el día 1 del calendario.
 */
function cobranza_fecha_tope_pronto_pago(int $anio, int $mes, int $diasHabiles, array $fechasFeriado = []): DateTimeImmutable
{
    return cobranza_fecha_nesimo_dia_habil_desde(
        new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes)),
        $diasHabiles,
        $fechasFeriado
    );
}

/**
 * Fecha del cargo en cuenta corriente / cobros: inicio del período (día 1 del mes de la cuota).
 */
function cobranza_fecha_mov_periodo_cuota(int $anio, int $mes): string
{
    $mes = max(1, min(12, $mes));

    return sprintf('%04d-%02d-01', $anio, $mes);
}

/**
 * Fecha guardada al generar cuota_mensual (parametros_cobranza.dia_generacion_cuota, 1–28).
 */
function cobranza_fecha_generacion_cuota(PDO $pdo, int $anio, int $mes): string
{
    $mes = max(1, min(12, $mes));
    $dia = 1;
    $st = $pdo->query('SELECT dia_generacion_cuota FROM parametros_cobranza WHERE id = 1 LIMIT 1');
    if ($st !== false) {
        $v = $st->fetchColumn();
        if ($v !== false) {
            $dia = max(1, min(28, (int) $v));
        }
    }
    $dt = new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes));
    $ultimo = (int) $dt->format('t');
    $dia = min($dia, $ultimo);

    return $dt->setDate($anio, $mes, $dia)->format('Y-m-d');
}

/**
 * Días de mora en calendario: desde el día siguiente al tope pronto hasta la fecha de pago (inclusive fin).
 */
function cobranza_dias_mora_calendario(DateTimeImmutable $fechaTopePronto, DateTimeImmutable $fechaPago): int
{
    $inicioMora = $fechaTopePronto->modify('+1 day');
    if ($fechaPago < $inicioMora) {
        return 0;
    }
    return (int) $inicioMora->diff($fechaPago)->days + 1;
}

/**
 * Año mínimo de cuotas en cobranza operativa (solo períodos desde aquí).
 * Variable de entorno OPERATIVO_ANIO_DESDE (ej. 2026); por defecto 2026.
 */
function cobranza_anio_operativo_desde(): int
{
    $raw = getenv('OPERATIVO_ANIO_DESDE');
    if ($raw === false || trim((string) $raw) === '') {
        return 2026;
    }
    $y = (int) trim((string) $raw);

    return $y >= 2000 && $y <= 2100 ? $y : 2026;
}

/**
 * JOIN a haber legacy por período (PAGOS:ncuenta=…:YYYY-MM en referencia). Requiere alias cm.
 */
function cobranza_sql_join_legacy_haber_por_periodo(): string
{
    return ' LEFT JOIN (
        SELECT pr.alumno_id AS _al_id,
          RIGHT(TRIM(pr.referencia), 7) AS _periodo_ref,
          SUM(COALESCE(NULLIF(pr.importe_capital, 0), pr.importe)) AS haber_legacy
        FROM pago_registrado pr
        WHERE pr.medio = \'legacy\'
          AND pr.referencia LIKE \'PAGOS:ncuenta=%:%\'
          AND CHAR_LENGTH(TRIM(pr.referencia)) >= 7
          AND RIGHT(TRIM(pr.referencia), 7) REGEXP \'^[0-9]{4}-[0-9]{2}$\'
        GROUP BY pr.alumno_id, RIGHT(TRIM(pr.referencia), 7)
    ) pl ON pl._al_id = cm.alumno_id
       AND pl._periodo_ref = CONCAT(cm.anio, \'-\', LPAD(cm.mes, 2, \'0\'))';
}

/** Tope de descuento % adicional en efectivo al cobrar (no confundir con bonificación pronto pago en ARS). */
function cobranza_max_descuento_efectivo_pct(): float
{
    return 100.0;
}

/**
 * JOIN agregado pago_aplica_cuota (capital + descuentos pronto pago). Requiere alias cm.
 */
function cobranza_sql_join_pago_aplica_cuota_agregado(): string
{
    return ' LEFT JOIN (
        SELECT cuota_id,
               SUM(importe_aplicado) AS aplicado,
               SUM(COALESCE(importe_descuento, 0)) AS descuento_acum
        FROM pago_aplica_cuota
        GROUP BY cuota_id
    ) pa ON pa.cuota_id = cm.id';
}

/**
 * Expresión SQL: saldo a cobrar. Requiere alias cm, pa (pago_aplica agregado) y pl (haber legacy por período).
 * importe_original > 0 → orig − capital aplicado − descuentos pronto − haber_legacy; si no, saldo en fila.
 */
function cobranza_sql_expr_saldo_impago(): string
{
    return '(CASE '
        . 'WHEN COALESCE(cm.importe_original, 0) > 0.00001 THEN '
        . 'GREATEST(0, cm.importe_original - COALESCE(pa.aplicado, 0) - COALESCE(pa.descuento_acum, 0) - COALESCE(pl.haber_legacy, 0)) '
        . 'ELSE GREATEST(0, COALESCE(cm.saldo, 0)) END)';
}

/**
 * HTML: marca Q (abonos parciales con detalle y aún hay saldo). Estilo referencia Fox.
 */
function cobranza_badge_abono_parcial_html(array $cuota): string
{
    $aplicado = (float) ($cuota['aplicado_acum'] ?? 0);
    if ($aplicado <= 0.00001) {
        return '';
    }
    if (cobranza_saldo_impago_cuota($cuota) <= 0.00001) {
        return '';
    }
    $t = 'Abonos parciales registrados (pago_aplica_cuota); sigue con saldo a cobrar.';

    return '<span class="badge badge-warn" title="' . htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Q</span>';
}

/**
 * HTML para columna Estado en listado de cobro: si hay saldo pero la fila dice pagada, no mostrar "pagada" tal cual.
 */
function cobranza_estado_visual_cobro_html(array $cuota): string
{
    $raw = trim((string) ($cuota['estado'] ?? ''));
    $imp = cobranza_saldo_impago_cuota($cuota);
    if ($imp > 0.005 && strtolower($raw) === 'pagada') {
        $tip = 'En cuota_mensual figura pagada, pero aún hay saldo según cobros legacy/aplicaciones; podés cobrarla y conviene corregir el estado en datos.';

        return '<span class="badge badge-warn" title="' . htmlspecialchars($tip, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">A cobrar</span>';
    }

    $out = $raw !== '' ? $raw : '—';

    return htmlspecialchars($out, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * HTML: marca L (liquidada en cobro / sin saldo pendiente).
 */
function cobranza_badge_liquidada_html(): string
{
    $t = 'Liquidada: sin saldo pendiente en esta pantalla (período operativo).';

    return '<span class="badge badge-ok" title="' . htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">L</span>';
}

/**
 * Saldo pendiente de la cuota para cobranza (alineado al SQL del listado).
 *
 * @param array<string,mixed> $cuota aplicado_acum, haber_legacy_acum desde los JOIN del listado.
 */
/**
 * Último período (YYYY-MM) con cuota sin saldo pendiente en año operativo.
 */
function cobranza_ultimo_periodo_pagado(PDO $pdo, int $alumnoId): ?string
{
    if ($alumnoId <= 0) {
        return null;
    }
    $anioOp = cobranza_anio_operativo_desde();
    $expr = cobranza_sql_expr_saldo_impago();
    $sql = '
        SELECT cm.anio, cm.mes
        FROM cuota_mensual cm
        ' . cobranza_sql_join_pago_aplica_cuota_agregado() . '
        ' . cobranza_sql_join_legacy_haber_por_periodo() . "
        WHERE cm.alumno_id = ?
          AND cm.anio >= {$anioOp}
          AND cm.estado <> 'anulada'
          AND {$expr} <= 0.005
        ORDER BY cm.anio DESC, cm.mes DESC
        LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$alumnoId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return sprintf('%04d-%02d', (int) $row['anio'], (int) $row['mes']);
}

/**
 * Cuotas con saldo pendiente (año operativo en adelante).
 *
 * @return list<array<string,mixed>>
 */
/**
 * Ajustes de debe cargados a mano (sin cobro asociado aún).
 *
 * @return list<array<string,mixed>>
 */
function cobranza_ajustes_debe_pendientes(PDO $pdo, int $alumnoId): array
{
    if ($alumnoId <= 0 || !db_has_column($pdo, 'cc_ajuste_debe', 'debe')) {
        return [];
    }
    $st = $pdo->prepare(
        "SELECT id, fecha_mov, concepto, debe, referencia, creado_en
         FROM cc_ajuste_debe
         WHERE alumno_id = ?
           AND pago_id IS NULL
           AND COALESCE(debe, 0) > 0.005
         ORDER BY fecha_mov DESC, id DESC"
    );
    $st->execute([$alumnoId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Textos para mostrar un debe pendiente (cc_ajuste_debe) en cobro / CC.
 *
 * @param array<string,mixed> $adj
 * @return array{tipo:string, etiqueta_tipo:string, concepto:string, nota_html:string}
 */
/**
 * Debe manual explícito (AJUSTE:manual) no mora; el resto de saldos en CC se tratan como obligación vencida.
 */
function cobranza_debe_pendiente_aplica_recargo(array $adj): bool
{
    $ref = trim((string) ($adj['referencia'] ?? ''));

    return !str_starts_with($ref, 'AJUSTE:manual:');
}

/** Referencia de contramovimiento por mora/beca/recargo en recibo (impacta saldo y CC). */
function cobranza_referencia_es_incremento_cobro(string $referencia): bool
{
    return str_starts_with(trim($referencia), 'RECIBO_INC:');
}

/** Referencia de bonificación/descuento en recibo (debe negativo en CC; cuadra el haber del pago). */
function cobranza_referencia_es_descuento_cobro(string $referencia): bool
{
    return str_starts_with(trim($referencia), 'RECIBO_DEC:');
}

/** Debe en CC espejo de un ítem en pago_item_articulo (no duplicar en detalle recibo/FE). */
function cobranza_referencia_es_espejo_item_recibo(string $referencia): bool
{
    return str_starts_with(trim($referencia), 'RECIBO_ITEM:');
}

/**
 * @param array<string, mixed> $ajuste Fila cc_ajuste_debe (debe incluir referencia si se filtra).
 */
function cobranza_ajuste_es_espejo_item_recibo(array $ajuste): bool
{
    return cobranza_referencia_es_espejo_item_recibo((string) ($ajuste['referencia'] ?? ''));
}

/**
 * Debe en CC por incrementos del recibo (mora, beca fuera de término, recargo por medio).
 * El haber del pago ya incluye estos importes; sin este movimiento el saldo queda negativo.
 */
function cobranza_registrar_incremento_cc_cobro(
    PDO $pdo,
    int $alumnoId,
    string $fechaPagoYmd,
    int $pagoId,
    string $concepto,
    float $importe,
    string $refClave
): bool {
    if ($alumnoId <= 0 || $pagoId <= 0 || !db_has_column($pdo, 'cc_ajuste_debe', 'debe')) {
        return false;
    }
    $importe = round($importe, 2);
    if ($importe < 0.005) {
        return false;
    }
    $concepto = trim($concepto);
    if ($concepto === '') {
        return false;
    }
    if (mb_strlen($concepto) > 200) {
        $concepto = mb_substr($concepto, 0, 200);
    }
    $clave = preg_replace('/[^A-Za-z0-9_\-]/', '', $refClave) ?? '';
    if ($clave === '') {
        $clave = 'X';
    }
    $ref = 'RECIBO_INC:' . $clave . ':' . $pagoId;
    if (mb_strlen($ref) > 80) {
        $ref = mb_substr($ref, 0, 80);
    }
    $st = $pdo->prepare(
        'INSERT INTO cc_ajuste_debe (alumno_id, fecha_mov, concepto, debe, referencia, pago_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $st->execute([$alumnoId, $fechaPagoYmd, $concepto, $importe, $ref, $pagoId]);

    return true;
}

/**
 * Bonificación en CC por descuentos del recibo (pronto pago, descuento por medio).
 * Debe negativo: reduce la deuda como contrapartida del haber neto del pago.
 */
function cobranza_registrar_descuento_cc_cobro(
    PDO $pdo,
    int $alumnoId,
    string $fechaPagoYmd,
    int $pagoId,
    string $concepto,
    float $importe,
    string $refClave
): bool {
    if ($alumnoId <= 0 || $pagoId <= 0 || !db_has_column($pdo, 'cc_ajuste_debe', 'debe')) {
        return false;
    }
    $importe = round(abs($importe), 2);
    if ($importe < 0.005) {
        return false;
    }
    $concepto = trim($concepto);
    if ($concepto === '') {
        return false;
    }
    if (mb_strlen($concepto) > 200) {
        $concepto = mb_substr($concepto, 0, 200);
    }
    $clave = preg_replace('/[^A-Za-z0-9_\-]/', '', $refClave) ?? '';
    if ($clave === '') {
        $clave = 'X';
    }
    $ref = 'RECIBO_DEC:' . $clave . ':' . $pagoId;
    if (mb_strlen($ref) > 80) {
        $ref = mb_substr($ref, 0, 80);
    }
    $st = $pdo->prepare(
        'INSERT INTO cc_ajuste_debe (alumno_id, fecha_mov, concepto, debe, referencia, pago_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $st->execute([$alumnoId, $fechaPagoYmd, $concepto, -$importe, $ref, $pagoId]);

    return true;
}

function cobranza_existe_descuento_cc_cobro(PDO $pdo, int $pagoId, string $refClave): bool
{
    if ($pagoId <= 0 || !db_has_column($pdo, 'cc_ajuste_debe', 'debe')) {
        return false;
    }
    $clave = preg_replace('/[^A-Za-z0-9_\-]/', '', $refClave) ?? '';
    $ref = 'RECIBO_DEC:' . ($clave !== '' ? $clave : 'X') . ':' . $pagoId;
    if (mb_strlen($ref) > 80) {
        $ref = mb_substr($ref, 0, 80);
    }
    $st = $pdo->prepare('SELECT id FROM cc_ajuste_debe WHERE referencia = ? LIMIT 1');
    $st->execute([$ref]);

    return (bool) $st->fetchColumn();
}

function cobranza_existe_incremento_cc_cobro(PDO $pdo, int $pagoId, string $refClave): bool
{
    if ($pagoId <= 0 || !db_has_column($pdo, 'cc_ajuste_debe', 'debe')) {
        return false;
    }
    $clave = preg_replace('/[^A-Za-z0-9_\-]/', '', $refClave) ?? '';
    $ref = 'RECIBO_INC:' . ($clave !== '' ? $clave : 'X') . ':' . $pagoId;
    if (mb_strlen($ref) > 80) {
        $ref = mb_substr($ref, 0, 80);
    }
    $st = $pdo->prepare('SELECT id FROM cc_ajuste_debe WHERE referencia = ? LIMIT 1');
    $st->execute([$ref]);

    return (bool) $st->fetchColumn();
}

/**
 * Repara cobros históricos sin contramovimiento de mora/beca/descuento (saldo CC desbalanceado).
 *
 * @return array{creados:int,omitidos:int}
 */
function cobranza_backfill_incrementos_cc_desde_pagos(PDO $pdo, ?int $alumnoId = null): array
{
    $creados = 0;
    $omitidos = 0;
    if (!db_has_column($pdo, 'cc_ajuste_debe', 'debe') || !db_has_column($pdo, 'pago_aplica_cuota', 'importe_recargo')) {
        return ['creados' => 0, 'omitidos' => 0];
    }

    $sql = 'SELECT pac.cuota_id, pac.pago_id, pac.importe_recargo, pac.importe_beca_perdida,
                   pac.importe_descuento,
                   pr.alumno_id, pr.fecha_pago, cm.anio, cm.mes
            FROM pago_aplica_cuota pac
            INNER JOIN pago_registrado pr ON pr.id = pac.pago_id
            INNER JOIN cuota_mensual cm ON cm.id = pac.cuota_id
            WHERE (COALESCE(pac.importe_recargo, 0) > 0.005 OR COALESCE(pac.importe_beca_perdida, 0) > 0.005
                OR COALESCE(pac.importe_descuento, 0) > 0.005)';
    $params = [];
    if ($alumnoId !== null && $alumnoId > 0) {
        $sql .= ' AND pr.alumno_id = ?';
        $params[] = $alumnoId;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pagoId = (int) $row['pago_id'];
        $alId = (int) $row['alumno_id'];
        $fecha = (string) $row['fecha_pago'];
        $per = sprintf('%04d-%02d', (int) $row['anio'], (int) $row['mes']);
        $cid = (int) $row['cuota_id'];
        $rec = round((float) ($row['importe_recargo'] ?? 0), 2);
        $beca = round((float) ($row['importe_beca_perdida'] ?? 0), 2);
        $desc = round((float) ($row['importe_descuento'] ?? 0), 2);
        if (cobranza_existe_incremento_cc_cobro($pdo, $pagoId, 'MORA-C' . $cid)) {
            ++$omitidos;
        } elseif (cobranza_registrar_incremento_cc_cobro($pdo, $alId, $fecha, $pagoId, 'Mora/recargo cuota ' . $per, $rec, 'MORA-C' . $cid)) {
            ++$creados;
        }
        if ($beca > 0.005) {
            if (cobranza_existe_incremento_cc_cobro($pdo, $pagoId, 'BECA-C' . $cid)) {
                ++$omitidos;
            } elseif (cobranza_registrar_incremento_cc_cobro($pdo, $alId, $fecha, $pagoId, 'Beca fuera de término ' . $per, $beca, 'BECA-C' . $cid)) {
                ++$creados;
            }
        }
        if ($desc > 0.005) {
            if (cobranza_existe_descuento_cc_cobro($pdo, $pagoId, 'DESC-C' . $cid)) {
                ++$omitidos;
            } elseif (cobranza_registrar_descuento_cc_cobro($pdo, $alId, $fecha, $pagoId, 'Descuento pronto pago cuota ' . $per, $desc, 'DESC-C' . $cid)) {
                ++$creados;
            }
        }
    }

    if (db_has_column($pdo, 'pago_registrado', 'importe_recargo_medio')) {
        $sqlMed = 'SELECT id, alumno_id, fecha_pago, importe_recargo_medio
                   FROM pago_registrado
                   WHERE COALESCE(importe_recargo_medio, 0) > 0.005';
        if ($alumnoId !== null && $alumnoId > 0) {
            $sqlMed .= ' AND alumno_id = ?';
            $stMed = $pdo->prepare($sqlMed);
            $stMed->execute([$alumnoId]);
        } else {
            $stMed = $pdo->query($sqlMed);
        }
        if ($stMed !== false) {
            foreach ($stMed->fetchAll(PDO::FETCH_ASSOC) as $pr) {
                $pagoId = (int) $pr['id'];
                if (cobranza_existe_incremento_cc_cobro($pdo, $pagoId, 'MEDIO-R')) {
                    ++$omitidos;
                    continue;
                }
                if (cobranza_registrar_incremento_cc_cobro(
                    $pdo,
                    (int) $pr['alumno_id'],
                    (string) $pr['fecha_pago'],
                    $pagoId,
                    'Recargo por forma de pago',
                    (float) $pr['importe_recargo_medio'],
                    'MEDIO-R'
                )) {
                    ++$creados;
                }
            }
        }
    }

    if (db_has_column($pdo, 'pago_registrado', 'importe_descuento_medio')) {
        $sqlDescMed = 'SELECT id, alumno_id, fecha_pago, importe_descuento_medio
                       FROM pago_registrado
                       WHERE COALESCE(importe_descuento_medio, 0) > 0.005';
        if ($alumnoId !== null && $alumnoId > 0) {
            $stDescMed = $pdo->prepare($sqlDescMed . ' AND alumno_id = ?');
            $stDescMed->execute([$alumnoId]);
        } else {
            $stDescMed = $pdo->query($sqlDescMed);
        }
        if ($stDescMed !== false) {
            foreach ($stDescMed->fetchAll(PDO::FETCH_ASSOC) as $pr) {
                $pagoId = (int) $pr['id'];
                if (cobranza_existe_descuento_cc_cobro($pdo, $pagoId, 'MEDIO-D')) {
                    ++$omitidos;
                    continue;
                }
                if (cobranza_registrar_descuento_cc_cobro(
                    $pdo,
                    (int) $pr['alumno_id'],
                    (string) $pr['fecha_pago'],
                    $pagoId,
                    'Descuento por forma de pago',
                    (float) $pr['importe_descuento_medio'],
                    'MEDIO-D'
                )) {
                    ++$creados;
                }
            }
        }
    }

    return ['creados' => $creados, 'omitidos' => $omitidos];
}

/**
 * @return array{anio:int,mes:int}|null
 */
function cobranza_periodo_desde_fecha_mov(array $adj): ?array
{
    $f = trim((string) ($adj['fecha_mov'] ?? ''));
    if (!preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $f, $m)) {
        return null;
    }
    $mes = (int) $m[2];
    if ($mes < 1 || $mes > 12) {
        return null;
    }

    return ['anio' => (int) $m[1], 'mes' => $mes];
}

function cobranza_alumno_tiene_beca(PDO $pdo, int $alumnoId): bool
{
    if ($alumnoId <= 0) {
        return false;
    }
    $st = $pdo->prepare(
        "SELECT EXISTS(
            SELECT 1
            FROM alumno_articulo aa_b
            JOIN articulos ar_b ON ar_b.id = aa_b.articulo_id
            WHERE aa_b.alumno_id = ?
              AND ar_b.activo = 1
              AND UPPER(ar_b.detalle) LIKE '%BECA%'
        ) AS tiene_beca"
    );
    $st->execute([$alumnoId]);

    return (int) $st->fetchColumn() === 1;
}

/** Nombres de artículos BECA activos asignados al alumno (ej. "BECA 25%"). */
function cobranza_alumno_articulos_beca_label(PDO $pdo, int $alumnoId): string
{
    if ($alumnoId <= 0) {
        return '';
    }
    $st = $pdo->prepare(
        "SELECT GROUP_CONCAT(DISTINCT ar_b.detalle ORDER BY ar_b.detalle SEPARATOR ', ')
         FROM alumno_articulo aa_b
         INNER JOIN articulos ar_b ON ar_b.id = aa_b.articulo_id
         WHERE aa_b.alumno_id = ?
           AND ar_b.activo = 1
           AND UPPER(ar_b.detalle) LIKE '%BECA%'"
    );
    $st->execute([$alumnoId]);
    $raw = $st->fetchColumn();

    return is_string($raw) ? trim($raw) : '';
}

/** Subconsulta: detalle de artículos BECA activos del alumno de la fila cuota. */
function cobranza_sql_select_articulos_beca_detalle(string $alumnoIdSql = 'cm.alumno_id'): string
{
    return '(SELECT GROUP_CONCAT(DISTINCT ar_b.detalle ORDER BY ar_b.detalle SEPARATOR \', \')
        FROM alumno_articulo aa_b
        INNER JOIN articulos ar_b ON ar_b.id = aa_b.articulo_id
        WHERE aa_b.alumno_id = ' . $alumnoIdSql . '
          AND ar_b.activo = 1
          AND UPPER(ar_b.detalle) LIKE \'%BECA%\') AS articulos_beca_detalle';
}

function cobranza_debe_pendiente_presentacion(array $adj): array
{
    $ref = trim((string) ($adj['referencia'] ?? ''));
    $conceptoRaw = trim((string) ($adj['concepto'] ?? ''));
    $concepto = $conceptoRaw;
    if (str_starts_with($conceptoRaw, 'FACT/REC:')) {
        $concepto = trim(substr($conceptoRaw, 9));
    }
    if ($concepto === '') {
        $concepto = 'Concepto pendiente';
    }

    if (cobranza_referencia_es_incremento_cobro($ref)) {
        return [
            'tipo' => 'incremento_recibo',
            'etiqueta_tipo' => 'Cargo por recibo',
            'concepto' => $concepto,
            'nota_html' => '<span class="muted" title="Mora, beca o recargo del recibo; cuadra el haber del pago">Incremento cobrado</span>',
        ];
    }
    if (cobranza_referencia_es_descuento_cobro($ref)) {
        return [
            'tipo' => 'descuento_recibo',
            'etiqueta_tipo' => 'Bonificación por recibo',
            'concepto' => $concepto,
            'nota_html' => '<span class="muted" title="Descuento pronto pago o por medio; cuadra el haber neto del pago">Bonificación cobrada</span>',
        ];
    }
    if (str_starts_with($ref, 'AJUSTE:manual:')) {
        return [
            'tipo' => 'manual',
            'etiqueta_tipo' => 'Debe manual',
            'concepto' => $concepto,
            'nota_html' => '<span class="muted" title="Cargado desde Comprobantes → Cargar debe manual">Importe fijo · sin recargo</span>',
        ];
    }
    if (cobranza_debe_pendiente_aplica_recargo($adj)) {
        return [
            'tipo' => 'obligacion_vencida',
            'etiqueta_tipo' => 'Obligación vencida',
            'concepto' => $concepto,
            'nota_html' => '<span class="muted" title="Fecha del movimiento en CC; mora según mes del período y fecha del recibo">Mov. CC</span>',
        ];
    }

    return [
        'tipo' => 'concepto_saldo',
        'etiqueta_tipo' => 'Concepto con saldo',
        'concepto' => $concepto,
        'nota_html' => '<span class="muted">Importe fijo · sin recargo</span>',
    ];
}

/**
 * @return string[] Fechas Y-m-d (feriados nacionales para informes masivos).
 */
function cobranza_fechas_feriado_nacionales(PDO $pdo): array
{
    if (!db_has_column($pdo, 'feriados', 'fecha')) {
        return [];
    }
    $st = $pdo->query("SELECT fecha FROM feriados WHERE ambito = 'nacional'");
    if ($st === false) {
        return [];
    }
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $f) {
        $v = trim((string) $f);
        if ($v !== '') {
            $out[$v] = true;
        }
    }

    return array_keys($out);
}

/**
 * @return array<string, mixed>
 */
function cobranza_cargar_parametros(PDO $pdo): array
{
    $param = $pdo->query('SELECT * FROM parametros_cobranza WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($param)) {
        $param = [];
    }
    $param['fechas_feriado'] = cobranza_fechas_feriado_nacionales($pdo);

    return $param;
}

/**
 * Fecha en que se generó la cuota (guardada en fecha_vencimiento al crearla).
 */
function cobranza_cuota_fecha_generacion(PDO $pdo, array $cuota): string
{
    $fv = trim((string) ($cuota['fecha_vencimiento'] ?? ''));
    if ($fv !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fv) === 1) {
        return $fv;
    }

    return cobranza_fecha_generacion_cuota($pdo, (int) ($cuota['anio'] ?? 0), (int) ($cuota['mes'] ?? 0));
}

/**
 * Último día de pronto pago: N días hábiles desde la fecha de generación de la cuota.
 */
function cobranza_cuota_fecha_tope_pronto_desde_generacion(PDO $pdo, array $cuota, array $param): DateTimeImmutable
{
    $dias = max(1, (int) ($param['dias_habiles_tope_pronto_pago'] ?? 5));
    $feriados = is_array($param['fechas_feriado'] ?? null) ? $param['fechas_feriado'] : [];
    $desde = new DateTimeImmutable(cobranza_cuota_fecha_generacion($pdo, $cuota));

    return cobranza_fecha_nesimo_dia_habil_desde($desde, $dias, $feriados);
}

/**
 * Cuota impaga y con tope de pronto pago vencido (para informe de morosos).
 */
function cobranza_cuota_vencida_para_moroso(
    PDO $pdo,
    array $cuota,
    array $param,
    ?DateTimeImmutable $fechaRef = null
): bool {
    if (cobranza_saldo_impago_cuota($cuota) <= 0.005) {
        return false;
    }
    $fechaRef = $fechaRef ?? new DateTimeImmutable('today');
    $tope = cobranza_cuota_fecha_tope_pronto_desde_generacion($pdo, $cuota, $param);

    return $fechaRef > $tope;
}

/**
 * Alumnos con al menos una cuota mensual impaga y fuera de plazo de pronto pago.
 *
 * @return list<int>
 */
function cobranza_alumno_ids_con_cuotas_vencidas(PDO $pdo, ?DateTimeImmutable $fechaRef = null): array
{
    $param = cobranza_cargar_parametros($pdo);
    $fechaRef = $fechaRef ?? new DateTimeImmutable('today');
    $anioOp = cobranza_anio_operativo_desde();
    $expr = cobranza_sql_expr_saldo_impago();
    $sql = '
        SELECT cm.*,
               COALESCE(pa.aplicado, 0) AS aplicado_acum,
               COALESCE(pa.descuento_acum, 0) AS descuento_acum,
               COALESCE(pl.haber_legacy, 0) AS haber_legacy_acum,
               ' . $expr . ' AS saldo_impago
        FROM cuota_mensual cm
        INNER JOIN alumnos al ON al.id = cm.alumno_id
        ' . cobranza_sql_join_pago_aplica_cuota_agregado() . '
        ' . cobranza_sql_join_legacy_haber_por_periodo() . "
        WHERE cm.anio >= {$anioOp}
          AND cm.estado <> 'anulada'
          AND {$expr} > 0.005
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }
    $ids = [];
    foreach ($rows as $cm) {
        if (!cobranza_cuota_vencida_para_moroso($pdo, $cm, $param, $fechaRef)) {
            continue;
        }
        $aid = (int) ($cm['alumno_id'] ?? 0);
        if ($aid > 0) {
            $ids[$aid] = true;
        }
    }

    return array_map('intval', array_keys($ids));
}

/**
 * @return list<array<string, mixed>>
 */
function cobranza_listar_cuotas_impagas(PDO $pdo, ?int $alumnoId = null): array
{
    $anioOp = cobranza_anio_operativo_desde();
    $expr = cobranza_sql_expr_saldo_impago();
    $sql = '
        SELECT cm.*,
               COALESCE(pa.aplicado, 0) AS aplicado_acum,
               COALESCE(pa.descuento_acum, 0) AS descuento_acum,
               COALESCE(pl.haber_legacy, 0) AS haber_legacy_acum,
               ' . $expr . ' AS saldo_impago
        FROM cuota_mensual cm
        ' . cobranza_sql_join_pago_aplica_cuota_agregado() . '
        ' . cobranza_sql_join_legacy_haber_por_periodo() . "
        WHERE cm.anio >= {$anioOp}
          AND cm.estado <> 'anulada'
          AND {$expr} > 0.005
    ";
    $params = [];
    if ($alumnoId !== null && $alumnoId > 0) {
        $sql .= ' AND cm.alumno_id = ?';
        $params[] = $alumnoId;
    }
    $sql .= ' ORDER BY cm.alumno_id, cm.anio, cm.mes';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
}

function cobranza_cuotas_pendientes_alumno(PDO $pdo, int $alumnoId): array
{
    if ($alumnoId <= 0) {
        return [];
    }

    return cobranza_listar_cuotas_impagas($pdo, $alumnoId);
}

function cobranza_saldo_impago_cuota(array $cuota): float
{
    $orig = (float) ($cuota['importe_original'] ?? 0);
    $aplicado = (float) ($cuota['aplicado_acum'] ?? 0);
    $desc = (float) ($cuota['descuento_acum'] ?? 0);
    $leg = (float) ($cuota['haber_legacy_acum'] ?? 0);
    $saldoRow = (float) ($cuota['saldo'] ?? 0);
    if ($orig > 0.00001) {
        return max(0.0, round($orig - $aplicado - $desc - $leg, 2));
    }

    return max(0.0, round($saldoRow, 2));
}

/** Descuento pronto pago registrado en las líneas de cuota del recibo. */
function cobranza_pago_desc_pronto_en_lineas(array $lineasRecibo): float
{
    $total = 0.0;
    foreach ($lineasRecibo as $lr) {
        $total += (float) ($lr['importe_descuento'] ?? 0);
    }

    return round($total, 2);
}

/**
 * Importe bruto de una cuota en recibo/FE (capital neto + descuento pronto de la línea).
 */
function cobranza_pago_cuota_bruto_linea(array $pacLinea, float $descProntoExtra = 0.0): float
{
    $net = round((float) ($pacLinea['importe_capital'] ?? 0), 2);
    $desc = round((float) ($pacLinea['importe_descuento'] ?? 0), 2);
    if ($desc < 0.00001 && $descProntoExtra > 0.00001) {
        $desc = round($descProntoExtra, 2);
    }

    return round($net + $desc, 2);
}

/**
 * Descuento pronto pago del recibo (total menos descuento por forma de pago).
 *
 * @param array<string,mixed> $pago
 */
function cobranza_pago_desc_pronto_cuotas(array $pago): float
{
    $descMedio = round((float) ($pago['importe_descuento_medio'] ?? 0), 2);
    $descTotal = round((float) ($pago['importe_descuento'] ?? 0), 2);

    return round(max(0.0, $descTotal - $descMedio), 2);
}

/**
 * Detalle unificado para recibo provisorio y factura electrónica.
 * Cuotas al importe bruto; descuento pronto pago en fila aparte (cuadra con total cobrado).
 *
 * @param array<string,mixed> $pago
 * @param list<array<string,mixed>> $lineasRecibo
 * @param list<array<string,mixed>> $ajustesRecibo
 * @param list<array<string,mixed>> $itemsRecibo
 * @return list<array{concepto:string, importe:float}>
 */
function cobranza_pago_lineas_detalle_recibo(
    array $pago,
    array $lineasRecibo,
    array $ajustesRecibo,
    array $itemsRecibo
): array {
    $filas = [];
    $descProntoEnLineas = cobranza_pago_desc_pronto_en_lineas($lineasRecibo);
    $descCuotas = cobranza_pago_desc_pronto_cuotas($pago);
    $descHuerfano = $descCuotas > 0.009 && $descProntoEnLineas < 0.009;
    $cuotaUnica = count($lineasRecibo) === 1;

    foreach ($lineasRecibo as $lr) {
        $anio = (int) ($lr['anio'] ?? 0);
        $mes = (int) ($lr['mes'] ?? 0);
        $per = sprintf('%04d-%02d', $anio, $mes);
        $extraDesc = ($descHuerfano && $cuotaUnica) ? $descCuotas : 0.0;
        $bruto = cobranza_pago_cuota_bruto_linea($lr, $extraDesc);
        if ($bruto > 0.00001) {
            $filas[] = ['concepto' => 'Cuota mensual ' . $per, 'importe' => $bruto];
        }
    }

    foreach ($ajustesRecibo as $ar) {
        if (cobranza_ajuste_es_espejo_item_recibo($ar)) {
            continue;
        }
        $concepto = trim((string) ($ar['concepto'] ?? 'Obligación'));
        if (str_starts_with($concepto, 'FACT/REC:')) {
            $concepto = trim(substr($concepto, 9));
        }
        if ($concepto === '') {
            $concepto = 'Obligación';
        }
        $base = round((float) ($ar['debe'] ?? 0), 2);
        if ($base > 0.00001) {
            $filas[] = ['concepto' => $concepto, 'importe' => $base];
        }
    }

    foreach ($itemsRecibo as $it) {
        $tot = round((float) ($it['importe_total'] ?? 0), 2);
        if ($tot > 0.00001) {
            $filas[] = [
                'concepto' => (string) ($it['descripcion'] ?? 'Ítem'),
                'importe' => $tot,
            ];
        }
    }

    $recMora = round((float) ($pago['importe_interes'] ?? 0), 2);
    if ($recMora > 0.00001) {
        $filas[] = ['concepto' => 'Recargo por mora', 'importe' => $recMora];
    }
    $beca = round((float) ($pago['importe_beca_perdida'] ?? 0), 2);
    if ($beca > 0.00001) {
        $filas[] = ['concepto' => 'Diferencia BECA', 'importe' => $beca];
    }
    if ($descCuotas > 0.00001) {
        $filas[] = ['concepto' => 'Descuento (pronto pago)', 'importe' => -$descCuotas];
    }
    $recMedio = round((float) ($pago['importe_recargo_medio'] ?? 0), 2);
    if ($recMedio > 0.00001) {
        $filas[] = ['concepto' => 'Recargo por forma de pago', 'importe' => $recMedio];
    }
    $descMedio = round((float) ($pago['importe_descuento_medio'] ?? 0), 2);
    if ($descMedio > 0.00001) {
        $filas[] = ['concepto' => 'Descuento en efectivo', 'importe' => -$descMedio];
    }

    return $filas;
}

/**
 * @param array<string,mixed> $param Fila parametros_cobranza
 * @return array{
 *   fecha_tope_pronto:string,
 *   dentro_pronto:bool,
 *   dias_mora:int,
 *   saldo_cuota:float,
 *   importe_descuento:float,
 *   importe_recargo_variable:float,
 *   importe_recargo_fijo:float,
 *   pierde_beca:bool,
 *   fecha_tope_beca:string,
 *   importe_beca_perdida:float,
 *   importe_capital:float,
 *   total_linea:float,
 *   importe_fijo_sin_mora:bool
 * }
 */
function cobranza_calcular_linea_saldo(
    array $param,
    int $anio,
    int $mes,
    float $saldo,
    string $fechaPagoYmd,
    bool $tieneBeca = false,
    float $difBeca = 0.0,
    string $articuloBecaDetalle = ''
): array {
    $saldo = max(0.0, round($saldo, 2));

    $diasHabiles = max(1, (int) ($param['dias_habiles_tope_pronto_pago'] ?? 5));
    // recargo_coeficiente = % mensual Fox (PORCEN.RECARGO, ej. 5 → 5% por mes).
    $recargoMensualPct = max(0.0, (float) ($param['recargo_coeficiente'] ?? 0));
    $coefDiario = $recargoMensualPct / 30.0;
    $descFijo = max(0.0, (float) ($param['bonificacion_pronto_pago'] ?? 0));
    $interesFijoMora = max(0.0, (float) ($param['importe_interes_mora_fijo'] ?? 0));
    $difBeca = max(0.0, $difBeca);
    $abonoCompletoRef = max(0.0, (float) ($param['abono_completo_referencia_beca'] ?? 0));

    $fechasFeriado = is_array($param['fechas_feriado'] ?? null) ? $param['fechas_feriado'] : [];
    $tope = cobranza_fecha_tope_pronto_pago($anio, $mes, $diasHabiles, $fechasFeriado);
    $topeBeca = cobranza_fecha_tope_pronto_pago($anio, $mes, 5, $fechasFeriado);
    $fp = new DateTimeImmutable($fechaPagoYmd);
    $dentro = $fp <= $tope;
    if ($difBeca <= 0.00001 && $tieneBeca && $abonoCompletoRef > 0.00001) {
        $difBeca = max(0.0, round($abonoCompletoRef - $saldo, 2));
    }
    $pierdeBeca = $difBeca > 0.00001 && $fp > $topeBeca;
    $diasMora = $dentro ? 0 : cobranza_dias_mora_calendario($tope, $fp);

    // Beca fuera del 5.º día hábil: deuda = abono mensual completo; mora sobre ese monto (no beca + dif. + mora sobre beca).
    $abonoMensual = 0.0;
    $becaEnAbonoCompleto = false;
    if ($tieneBeca && $pierdeBeca) {
        $abonoMensual = round($saldo + $difBeca, 2);
        if ($abonoCompletoRef > $abonoMensual + 0.005) {
            $abonoMensual = round($abonoCompletoRef, 2);
        }
        $becaEnAbonoCompleto = true;
    }

    $desc = 0.0;
    $recVar = 0.0;
    $recFijo = 0.0;
    if ($dentro) {
        if ($becaEnAbonoCompleto) {
            $desc = min($descFijo, $abonoMensual);
            $capital = round($abonoMensual - $desc, 2);
        } else {
            $desc = min($descFijo, $saldo);
            $capital = round($saldo - $desc, 2);
        }
    } else {
        if ($becaEnAbonoCompleto) {
            // Fox: INTDIA = RECARGO/30 (%/día), INTFIN = INTDIA×días, RECA = DEBE×INTFIN/100.
            $recVar = round($abonoMensual * $coefDiario * $diasMora / 100, 2);
            $recFijo = $diasMora > 0 ? $interesFijoMora : 0.0;
            $capital = $abonoMensual;
        } else {
            $recVar = round($saldo * $coefDiario * $diasMora / 100, 2);
            $recFijo = $diasMora > 0 ? $interesFijoMora : 0.0;
            $capital = $saldo;
        }
    }

    $impBecaPerdida = $becaEnAbonoCompleto ? 0.0 : ($pierdeBeca ? round($difBeca, 2) : 0.0);
    $total = round($capital + $recVar + $recFijo + $impBecaPerdida, 2);
    $baseCalculo = $becaEnAbonoCompleto ? $abonoMensual : $saldo;
    $articuloBecaDetalle = $tieneBeca ? trim($articuloBecaDetalle) : '';

    return [
        'fecha_tope_pronto' => $tope->format('Y-m-d'),
        'dentro_pronto' => $dentro,
        'dias_mora' => $diasMora,
        'saldo_cuota' => $saldo,
        'tiene_beca' => $tieneBeca,
        'articulo_beca_detalle' => $articuloBecaDetalle,
        'abono_mensual_base' => $abonoMensual,
        'beca_en_abono_completo' => $becaEnAbonoCompleto,
        'base_calculo' => $baseCalculo,
        'importe_descuento' => round($desc, 2),
        'importe_recargo_variable' => $recVar,
        'importe_recargo_fijo' => $recFijo,
        'pierde_beca' => $pierdeBeca,
        'fecha_tope_beca' => $topeBeca->format('Y-m-d'),
        'importe_beca_perdida' => $impBecaPerdida,
        'importe_capital' => $capital,
        'total_linea' => $total,
        'importe_fijo_sin_mora' => false,
        'recargo_mensual_pct' => $recargoMensualPct,
        'coef_diario_pct' => round($coefDiario, 5),
        'pct_mora_acumulado' => $dentro ? 0.0 : round($coefDiario * $diasMora, 2),
    ];
}

/**
 * @param array<string,mixed> $param
 * @param array<string,mixed> $cuota Fila cuota_mensual
 */
function cobranza_calcular_linea_cuota(array $param, array $cuota, string $fechaPagoYmd): array
{
    $anio = (int) $cuota['anio'];
    $mes = (int) $cuota['mes'];
    $saldo = cobranza_saldo_impago_cuota($cuota);
    $difBeca = max(0.0, (float) ($cuota['importe_diferencia_beca'] ?? 0));
    $tieneBeca = (int) ($cuota['tiene_beca'] ?? 0) === 1;
    $artBeca = trim((string) ($cuota['articulos_beca_detalle'] ?? ''));

    return cobranza_calcular_linea_saldo($param, $anio, $mes, $saldo, $fechaPagoYmd, $tieneBeca, $difBeca, $artBeca);
}

/**
 * Aplica un abono a una línea calculada (cobro parcial). Reparto proporcional entre componentes.
 *
 * @param array<string,mixed> $calc
 * @return array<string,mixed> calc ajustado + importe_abonado, es_parcial, saldo_remanente_linea, total_linea_original
 */
function cobranza_aplicar_abono_linea(array $calc, float $abono): array
{
    $total = round((float) ($calc['total_linea'] ?? 0), 2);
    $abono = round(max(0.0, $abono), 2);
    if ($total <= 0.00001) {
        throw new InvalidArgumentException('Línea sin importe a cobrar.');
    }
    if ($abono <= 0.00001) {
        throw new InvalidArgumentException('El abono debe ser mayor a cero.');
    }
    if ($abono > $total + 0.009) {
        throw new InvalidArgumentException(
            'El abono no puede superar el total de la línea ($ ' . number_format($total, 2, ',', '.') . ').'
        );
    }

    if ($abono >= $total - 0.009) {
        $out = $calc;
        $out['total_linea_original'] = $total;
        $out['importe_abonado'] = $total;
        $out['es_parcial'] = false;
        $out['saldo_remanente_linea'] = 0.0;

        return $out;
    }

    $ratio = $abono / $total;
    $out = $calc;
    $out['total_linea_original'] = $total;
    $out['importe_capital'] = round((float) ($calc['importe_capital'] ?? 0) * $ratio, 2);
    $out['importe_recargo_variable'] = round((float) ($calc['importe_recargo_variable'] ?? 0) * $ratio, 2);
    $out['importe_recargo_fijo'] = round((float) ($calc['importe_recargo_fijo'] ?? 0) * $ratio, 2);
    $out['importe_beca_perdida'] = round((float) ($calc['importe_beca_perdida'] ?? 0) * $ratio, 2);
    $out['importe_descuento'] = round((float) ($calc['importe_descuento'] ?? 0) * $ratio, 2);

    $sumParts = round(
        $out['importe_capital'] + $out['importe_recargo_variable']
        + $out['importe_recargo_fijo'] + $out['importe_beca_perdida'],
        2
    );
    $diff = round($abono - $sumParts, 2);
    if (abs($diff) >= 0.005) {
        $out['importe_capital'] = round($out['importe_capital'] + $diff, 2);
    }

    $out['total_linea'] = $abono;
    $out['importe_abonado'] = $abono;
    $out['es_parcial'] = true;
    $out['saldo_remanente_linea'] = round($total - $abono, 2);

    return $out;
}

/**
 * @param array<string, mixed|string> $raw POST abono_cuota / abono_ajuste
 */
function cobranza_parse_abono_post(array $raw, int $id, float $totalLinea): float
{
    $key = (string) $id;
    if (!array_key_exists($key, $raw) && !array_key_exists($id, $raw)) {
        return round($totalLinea, 2);
    }
    $val = $raw[$key] ?? $raw[$id] ?? $totalLinea;
    $s = str_replace([' ', '.'], ['', ''], trim((string) $val));
    $s = str_replace(',', '.', $s);
    if ($s === '' || !is_numeric($s)) {
        return round($totalLinea, 2);
    }

    return round((float) $s, 2);
}

/**
 * @param array<string,mixed> $cuota
 */
function cobranza_actualizar_cuota_post_cobro(PDO $pdo, array $cuota, array $calcAplicada): void
{
    $cid = (int) ($cuota['id'] ?? 0);
    if ($cid <= 0) {
        return;
    }
    $saldoImpago = cobranza_saldo_impago_cuota($cuota);
    $reduccion = round(
        (float) ($calcAplicada['importe_capital'] ?? 0) + (float) ($calcAplicada['importe_descuento'] ?? 0),
        2
    );
    $nuevoSaldo = max(0.0, round($saldoImpago - $reduccion, 2));
    $estado = $nuevoSaldo <= 0.005 ? 'pagada' : 'parcial';
    if ($nuevoSaldo <= 0.005) {
        $nuevoSaldo = 0.0;
    }

    $becaPerdida = round((float) ($calcAplicada['importe_beca_perdida'] ?? 0), 2);
    $esParcial = !empty($calcAplicada['es_parcial']);
    $setBeca = !$esParcial && $becaPerdida > 0.005;

    if (db_has_column($pdo, 'cuota_mensual', 'importe_diferencia_beca')) {
        $st = $pdo->prepare(
            'UPDATE cuota_mensual
             SET saldo = ?,
                 estado = ?,
                 importe_diferencia_beca = CASE
                    WHEN ? = 1 AND COALESCE(importe_diferencia_beca, 0) <= 0 THEN ?
                    ELSE importe_diferencia_beca
                 END
             WHERE id = ?'
        );
        $st->execute([
            $nuevoSaldo,
            $estado,
            $setBeca ? 1 : 0,
            $becaPerdida,
            $cid,
        ]);
    } else {
        $st = $pdo->prepare('UPDATE cuota_mensual SET saldo = ?, estado = ? WHERE id = ?');
        $st->execute([$nuevoSaldo, $estado, $cid]);
    }
}

/**
 * Obligación en cc_ajuste_debe: mora como cuota (período = mes de fecha_mov) salvo debe manual.
 *
 * @param array<string,mixed> $param
 * @param array<string,mixed> $adj Fila cc_ajuste_debe
 */
function cobranza_calcular_linea_debe_pendiente(
    array $param,
    array $adj,
    string $fechaPagoYmd,
    bool $tieneBecaAlumno = false,
    string $articuloBecaDetalle = ''
): array {
    $saldo = max(0.0, round((float) ($adj['debe'] ?? 0), 2));
    if ($saldo <= 0.00001) {
        return [
            'fecha_tope_pronto' => '',
            'dentro_pronto' => false,
            'dias_mora' => 0,
            'saldo_cuota' => 0.0,
            'importe_descuento' => 0.0,
            'importe_recargo_variable' => 0.0,
            'importe_recargo_fijo' => 0.0,
            'pierde_beca' => false,
            'fecha_tope_beca' => '',
            'importe_beca_perdida' => 0.0,
            'importe_capital' => 0.0,
            'total_linea' => 0.0,
            'importe_fijo_sin_mora' => true,
        ];
    }

    if (!cobranza_debe_pendiente_aplica_recargo($adj)) {
        return [
            'fecha_tope_pronto' => '',
            'dentro_pronto' => false,
            'dias_mora' => 0,
            'saldo_cuota' => $saldo,
            'importe_descuento' => 0.0,
            'importe_recargo_variable' => 0.0,
            'importe_recargo_fijo' => 0.0,
            'pierde_beca' => false,
            'fecha_tope_beca' => '',
            'importe_beca_perdida' => 0.0,
            'importe_capital' => $saldo,
            'total_linea' => $saldo,
            'importe_fijo_sin_mora' => true,
        ];
    }

    $per = cobranza_periodo_desde_fecha_mov($adj);
    if ($per === null) {
        return [
            'fecha_tope_pronto' => '',
            'dentro_pronto' => false,
            'dias_mora' => 0,
            'saldo_cuota' => $saldo,
            'importe_descuento' => 0.0,
            'importe_recargo_variable' => 0.0,
            'importe_recargo_fijo' => 0.0,
            'pierde_beca' => false,
            'fecha_tope_beca' => '',
            'importe_beca_perdida' => 0.0,
            'importe_capital' => $saldo,
            'total_linea' => $saldo,
            'importe_fijo_sin_mora' => true,
        ];
    }

    return cobranza_calcular_linea_saldo(
        $param,
        $per['anio'],
        $per['mes'],
        $saldo,
        $fechaPagoYmd,
        $tieneBecaAlumno,
        0.0,
        $articuloBecaDetalle
    );
}
