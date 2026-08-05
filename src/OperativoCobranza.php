<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';

function operativo_schema_ok(PDO $pdo): bool
{
    return db_has_column($pdo, 'parametros_cobranza', 'operativo_periodo_desde');
}

/**
 * @return array{anio:int, mes:int}|null
 */
function operativo_periodo_parse(?string $periodo): ?array
{
    $p = trim((string) ($periodo ?? ''));
    if ($p === '' || preg_match('/^(\d{4})-(\d{2})$/', $p, $m) !== 1) {
        return null;
    }
    $anio = (int) $m[1];
    $mes = (int) $m[2];
    if ($anio < 2000 || $anio > 2100 || $mes < 1 || $mes > 12) {
        return null;
    }

    return ['anio' => $anio, 'mes' => $mes];
}

function operativo_periodo_normalizar_input(?string $raw): ?string
{
    $raw = trim((string) ($raw ?? ''));
    if ($raw === '') {
        return null;
    }
    $parsed = operativo_periodo_parse($raw);

    return $parsed !== null
        ? sprintf('%04d-%02d', $parsed['anio'], $parsed['mes'])
        : null;
}

function operativo_periodo_desde_bd(PDO $pdo): ?string
{
    static $cache = [];
    $key = spl_object_id($pdo);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    if (!operativo_schema_ok($pdo)) {
        $cache[$key] = null;

        return null;
    }
    $st = $pdo->query('SELECT operativo_periodo_desde FROM parametros_cobranza WHERE id = 1 LIMIT 1');
    $raw = $st !== false ? $st->fetchColumn() : false;
    $cache[$key] = operativo_periodo_parse($raw !== false ? (string) $raw : null)
        ? operativo_periodo_normalizar_input((string) $raw)
        : null;

    return $cache[$key];
}

/**
 * Período YYYY-MM efectivo. Con migración 39 solo usa BD; sin migración, respaldo env.
 */
function operativo_periodo_desde(PDO $pdo): ?string
{
    $bd = operativo_periodo_desde_bd($pdo);
    if (operativo_schema_ok($pdo)) {
        return $bd;
    }
    if ($bd !== null) {
        return $bd;
    }
    $corte = operativo_saldo_corte_env();
    if ($corte === null) {
        return null;
    }
    $parsed = operativo_periodo_parse(substr($corte, 0, 7));

    return $parsed !== null
        ? sprintf('%04d-%02d', $parsed['anio'], $parsed['mes'])
        : null;
}

function operativo_fecha_desde_periodo(?string $periodo): ?string
{
    $parsed = operativo_periodo_parse($periodo);
    if ($parsed === null) {
        return null;
    }

    return sprintf('%04d-%02d-01', $parsed['anio'], $parsed['mes']);
}

function operativo_fecha_corte_desde_bd(PDO $pdo): ?string
{
    return operativo_fecha_desde_periodo(operativo_periodo_desde_bd($pdo));
}

/**
 * Fecha Y-m-d desde la cual cuenta saldo/CC operativo (día 1 del mes configurado).
 */
function saldo_corte_desde(PDO $pdo): ?string
{
    if (operativo_schema_ok($pdo)) {
        return operativo_fecha_corte_desde_bd($pdo);
    }

    $periodo = operativo_periodo_desde($pdo);
    $desdePeriodo = operativo_fecha_desde_periodo($periodo);
    if ($desdePeriodo !== null) {
        return $desdePeriodo;
    }

    return operativo_saldo_corte_env();
}

function cobranza_anio_operativo_desde(PDO $pdo): int
{
    $bd = operativo_periodo_desde_bd($pdo);
    if ($bd !== null) {
        $parsed = operativo_periodo_parse($bd);

        return $parsed !== null ? $parsed['anio'] : 2026;
    }

    if (operativo_schema_ok($pdo)) {
        $raw = getenv('OPERATIVO_ANIO_DESDE');
        if ($raw !== false && trim((string) $raw) !== '') {
            $y = (int) trim((string) $raw);

            return $y >= 2000 && $y <= 2100 ? $y : 2026;
        }

        return 2026;
    }

    $periodo = operativo_periodo_desde($pdo);
    if ($periodo !== null) {
        $parsed = operativo_periodo_parse($periodo);

        return $parsed !== null ? $parsed['anio'] : 2026;
    }

    $raw = getenv('OPERATIVO_ANIO_DESDE');
    if ($raw === false || trim((string) $raw) === '') {
        $corte = operativo_saldo_corte_env();
        if ($corte !== null) {
            return (int) substr($corte, 0, 4);
        }

        return 2026;
    }
    $y = (int) trim((string) $raw);

    return $y >= 2000 && $y <= 2100 ? $y : 2026;
}

function operativo_cuota_dentro_periodo(PDO $pdo, int $anio, int $mes): bool
{
    $periodo = operativo_periodo_desde($pdo);
    if ($periodo === null) {
        return $anio >= cobranza_anio_operativo_desde($pdo);
    }
    $parsed = operativo_periodo_parse($periodo);
    if ($parsed === null) {
        return true;
    }
    if ($anio > $parsed['anio']) {
        return true;
    }
    if ($anio < $parsed['anio']) {
        return false;
    }

    return $mes >= $parsed['mes'];
}

/**
 * Fragmento SQL AND … para filtrar cuota_mensual (alias por defecto cm).
 */
function operativo_sql_filtro_cuota(PDO $pdo, string $alias = 'cm'): string
{
    $periodo = operativo_periodo_desde($pdo);
    if ($periodo === null) {
        $anio = cobranza_anio_operativo_desde($pdo);

        return ' AND ' . $alias . '.anio >= ' . $anio;
    }
    $parsed = operativo_periodo_parse($periodo);
    if ($parsed === null) {
        return '';
    }
    $y = (int) $parsed['anio'];
    $m = (int) $parsed['mes'];

    return ' AND ((' . $alias . '.anio > ' . $y . ') OR (' . $alias . '.anio = ' . $y . ' AND ' . $alias . '.mes >= ' . $m . '))';
}

function operativo_etiqueta_vista(PDO $pdo): string
{
    $periodo = operativo_periodo_desde($pdo);
    if ($periodo !== null) {
        $parsed = operativo_periodo_parse($periodo);
        if ($parsed !== null) {
            $meses = [
                1 => 'ene', 2 => 'feb', 3 => 'mar', 4 => 'abr', 5 => 'may', 6 => 'jun',
                7 => 'jul', 8 => 'ago', 9 => 'sep', 10 => 'oct', 11 => 'nov', 12 => 'dic',
            ];
            $mesNom = $meses[$parsed['mes']] ?? (string) $parsed['mes'];

            return $mesNom . ' ' . $parsed['anio'];
        }
    }

    return 'año ' . cobranza_anio_operativo_desde($pdo);
}

function operativo_saldo_corte_env(): ?string
{
    $raw = trim((string) getenv('SALDO_CORTE_DESDE'));
    if ($raw === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
        return null;
    }

    return $raw;
}

function operativo_fecha_formato_es(?string $fechaYmd): ?string
{
    if ($fechaYmd === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fechaYmd, $m) !== 1) {
        return null;
    }
    $meses = [
        '01' => 'enero', '02' => 'febrero', '03' => 'marzo', '04' => 'abril',
        '05' => 'mayo', '06' => 'junio', '07' => 'julio', '08' => 'agosto',
        '09' => 'septiembre', '10' => 'octubre', '11' => 'noviembre', '12' => 'diciembre',
    ];
    $mesNom = $meses[$m[2]] ?? $m[2];

    return (int) $m[3] . ' de ' . $mesNom . ' de ' . $m[1];
}

/**
 * @return array{origen:string, periodo:?string, fecha_corte:?string, fecha_corte_txt:?string, etiqueta:string, configurado:bool}
 */
function operativo_resumen_config(PDO $pdo): array
{
    $bd = operativo_periodo_desde_bd($pdo);
    $periodo = operativo_periodo_desde($pdo);
    $corte = saldo_corte_desde($pdo);
    $configurado = $bd !== null;

    if ($configurado) {
        $origen = 'parámetros de cobranza (esta pantalla)';
    } elseif (operativo_schema_ok($pdo)) {
        $origen = 'sin mes configurado — elija abajo y guarde (ej. 2026-06)';
    } elseif (operativo_saldo_corte_env() !== null || getenv('OPERATIVO_ANIO_DESDE')) {
        $origen = 'variables de entorno del servidor';
    } else {
        $origen = 'predeterminado (año 2026)';
    }

    return [
        'origen' => $origen,
        'periodo' => $periodo,
        'fecha_corte' => $corte,
        'fecha_corte_txt' => operativo_fecha_formato_es($corte),
        'etiqueta' => operativo_etiqueta_vista($pdo),
        'configurado' => $configurado,
    ];
}
