<?php
declare(strict_types=1);

/**
 * N-ésimo día hábil del mes (lun–vie), empezando a contar desde el día 1 del calendario.
 * El día 1 cuenta como intento 1: si cae sábado/domingo, no suma hasta el primer hábil.
 */
function cobranza_fecha_tope_pronto_pago(int $anio, int $mes, int $diasHabiles, array $fechasFeriado = []): DateTimeImmutable
{
    $d = new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes));
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

/**
 * Expresión SQL: saldo a cobrar. Requiere alias cm, pa (pago_aplica agregado) y pl (haber legacy por período).
 * importe_original > 0 → orig − aplicaciones_app − haber_legacy_mismo_período; si no, saldo en fila.
 */
function cobranza_sql_expr_saldo_impago(): string
{
    return '(CASE '
        . 'WHEN COALESCE(cm.importe_original, 0) > 0.00001 THEN '
        . 'GREATEST(0, cm.importe_original - COALESCE(pa.aplicado, 0) - COALESCE(pl.haber_legacy, 0)) '
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
        LEFT JOIN (
            SELECT cuota_id, SUM(importe_aplicado) AS aplicado
            FROM pago_aplica_cuota
            GROUP BY cuota_id
        ) pa ON pa.cuota_id = cm.id
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
 * Repara cobros históricos sin contramovimiento de mora/beca (saldo CC negativo).
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
                   pr.alumno_id, pr.fecha_pago, cm.anio, cm.mes
            FROM pago_aplica_cuota pac
            INNER JOIN pago_registrado pr ON pr.id = pac.pago_id
            INNER JOIN cuota_mensual cm ON cm.id = pac.cuota_id
            WHERE (COALESCE(pac.importe_recargo, 0) > 0.005 OR COALESCE(pac.importe_beca_perdida, 0) > 0.005)';
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

function cobranza_cuotas_pendientes_alumno(PDO $pdo, int $alumnoId): array
{
    if ($alumnoId <= 0) {
        return [];
    }
    $anioOp = cobranza_anio_operativo_desde();
    $expr = cobranza_sql_expr_saldo_impago();
    $sql = '
        SELECT cm.*,
               COALESCE(pa.aplicado, 0) AS aplicado_acum,
               COALESCE(pl.haber_legacy, 0) AS haber_legacy_acum,
               ' . $expr . ' AS saldo_impago
        FROM cuota_mensual cm
        LEFT JOIN (
            SELECT cuota_id, SUM(importe_aplicado) AS aplicado
            FROM pago_aplica_cuota
            GROUP BY cuota_id
        ) pa ON pa.cuota_id = cm.id
        ' . cobranza_sql_join_legacy_haber_por_periodo() . "
        WHERE cm.alumno_id = ?
          AND cm.anio >= {$anioOp}
          AND cm.estado <> 'anulada'
          AND {$expr} > 0.005
        ORDER BY cm.anio, cm.mes
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$alumnoId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function cobranza_saldo_impago_cuota(array $cuota): float
{
    $orig = (float) ($cuota['importe_original'] ?? 0);
    $aplicado = (float) ($cuota['aplicado_acum'] ?? 0);
    $leg = (float) ($cuota['haber_legacy_acum'] ?? 0);
    $saldoRow = (float) ($cuota['saldo'] ?? 0);
    if ($orig > 0.00001) {
        return max(0.0, round($orig - $aplicado - $leg, 2));
    }

    return max(0.0, round($saldoRow, 2));
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
    float $difBeca = 0.0
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

    $desc = 0.0;
    $recVar = 0.0;
    $recFijo = 0.0;
    if ($dentro) {
        $desc = min($descFijo, $saldo);
        $capital = round($saldo - $desc, 2);
    } else {
        // Fox: INTDIA = RECARGO/30 (%/día), INTFIN = INTDIA×días, RECA = DEBE×INTFIN/100.
        $recVar = round($saldo * $coefDiario * $diasMora / 100, 2);
        $recFijo = $diasMora > 0 ? $interesFijoMora : 0.0;
        $capital = $saldo;
    }

    $impBecaPerdida = $pierdeBeca ? round($difBeca, 2) : 0.0;
    $total = round($capital + $recVar + $recFijo + $impBecaPerdida, 2);

    return [
        'fecha_tope_pronto' => $tope->format('Y-m-d'),
        'dentro_pronto' => $dentro,
        'dias_mora' => $diasMora,
        'saldo_cuota' => $saldo,
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

    return cobranza_calcular_linea_saldo($param, $anio, $mes, $saldo, $fechaPagoYmd, $tieneBeca, $difBeca);
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
    bool $tieneBecaAlumno = false
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
        0.0
    );
}
