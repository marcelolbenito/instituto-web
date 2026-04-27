<?php
declare(strict_types=1);

/**
 * N-ésimo día hábil del mes (lun–vie), empezando a contar desde el día 1 del calendario.
 * El día 1 cuenta como intento 1: si cae sábado/domingo, no suma hasta el primer hábil.
 */
function cobranza_fecha_tope_pronto_pago(int $anio, int $mes, int $diasHabiles): DateTimeImmutable
{
    $d = new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes));
    $habiles = 0;
    while ($habiles < $diasHabiles) {
        $n = (int) $d->format('N');
        if ($n < 6) {
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
 * @param array<string,mixed> $cuota Fila cuota_mensual
 * @return array{
 *   fecha_tope_pronto:string,
 *   dentro_pronto:bool,
 *   dias_mora:int,
 *   saldo_cuota:float,
 *   importe_descuento:float,
 *   importe_recargo_variable:float,
 *   importe_recargo_fijo:float,
 *   importe_capital:float,
 *   total_linea:float
 * }
 */
function cobranza_calcular_linea_cuota(array $param, array $cuota, string $fechaPagoYmd): array
{
    $anio = (int) $cuota['anio'];
    $mes = (int) $cuota['mes'];
    $saldo = cobranza_saldo_impago_cuota($cuota);

    $diasHabiles = max(1, (int) ($param['dias_habiles_tope_pronto_pago'] ?? 5));
    $coefDiario = max(0.0, (float) ($param['recargo_coeficiente'] ?? 0));
    $descFijo = max(0.0, (float) ($param['bonificacion_pronto_pago'] ?? 0));
    $interesFijoMora = max(0.0, (float) ($param['importe_interes_mora_fijo'] ?? 0));

    $tope = cobranza_fecha_tope_pronto_pago($anio, $mes, $diasHabiles);
    $fp = new DateTimeImmutable($fechaPagoYmd);
    $dentro = $fp <= $tope;
    $diasMora = $dentro ? 0 : cobranza_dias_mora_calendario($tope, $fp);

    $desc = 0.0;
    $recVar = 0.0;
    $recFijo = 0.0;
    if ($dentro) {
        $desc = min($descFijo, $saldo);
        $capital = round($saldo - $desc, 2);
    } else {
        $recVar = round($saldo * $coefDiario * $diasMora, 2);
        $recFijo = $diasMora > 0 ? $interesFijoMora : 0.0;
        $capital = $saldo;
    }

    $total = round($capital + $recVar + $recFijo, 2);

    return [
        'fecha_tope_pronto' => $tope->format('Y-m-d'),
        'dentro_pronto' => $dentro,
        'dias_mora' => $diasMora,
        'saldo_cuota' => $saldo,
        'importe_descuento' => round($desc, 2),
        'importe_recargo_variable' => $recVar,
        'importe_recargo_fijo' => $recFijo,
        'importe_capital' => $capital,
        'total_linea' => $total,
    ];
}
