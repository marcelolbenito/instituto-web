<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';

/**
 * Postítulo: los artículos cuyo detalle empieza con "POST." identifican a estos
 * alumnos. No llevan descuento de pronto pago y su fecha de vencimiento se carga
 * manualmente por período (anio/mes) en la tabla postitulo_vencimiento.
 */

/** Prefijo (en mayúsculas, sin espacios al inicio) que identifica artículos de postítulo. */
const POSTITULO_PREFIJO_DETALLE = 'POST.';

/** ¿La tabla de vencimientos de postítulo existe en la base? */
function postitulo_schema_ok(PDO $pdo): bool
{
    static $cache = [];
    $key = spl_object_id($pdo);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $st = $pdo->prepare(
            'SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1'
        );
        $st->execute(['postitulo_vencimiento']);
        $cache[$key] = (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

/** ¿El detalle de un artículo corresponde a postítulo? (empieza con "POST.") */
function postitulo_articulo_detalle_es(?string $detalle): bool
{
    $d = strtoupper(trim((string) ($detalle ?? '')));

    return $d !== '' && strpos($d, POSTITULO_PREFIJO_DETALLE) === 0;
}

/**
 * Columnas SQL para marcar una cuota como postítulo y traer su vencimiento propio.
 * Empieza con coma y NO termina con coma. Pensado para alias de cuota_mensual (por
 * defecto "cm"). Usa alias internos (aa_p/ar_p/pv_post) que no colisionan con beca.
 */
function postitulo_sql_select_cols(PDO $pdo, string $alias = 'cm'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'cm';
    $es = '(EXISTS('
        . 'SELECT 1 FROM alumno_articulo aa_p '
        . 'JOIN articulos ar_p ON ar_p.id = aa_p.articulo_id '
        . "WHERE aa_p.alumno_id = {$a}.alumno_id AND ar_p.activo = 1 "
        . "AND UPPER(TRIM(ar_p.detalle)) LIKE 'POST.%'"
        . ')) AS es_postitulo';

    if (postitulo_schema_ok($pdo)) {
        return ', ' . $es . ', pv_post.fecha_vencimiento AS fecha_vencimiento_postitulo';
    }

    return ', ' . $es . ', NULL AS fecha_vencimiento_postitulo';
}

/** JOIN para traer el vencimiento de postítulo del período de la cuota. */
function postitulo_sql_join(PDO $pdo, string $alias = 'cm'): string
{
    if (!postitulo_schema_ok($pdo)) {
        return '';
    }
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'cm';

    return " LEFT JOIN postitulo_vencimiento pv_post ON pv_post.anio = {$a}.anio AND pv_post.mes = {$a}.mes ";
}

/** ¿El alumno tiene al menos un artículo de postítulo asignado y activo? */
function postitulo_alumno_es(PDO $pdo, int $alumnoId): bool
{
    if ($alumnoId <= 0) {
        return false;
    }
    $st = $pdo->prepare(
        'SELECT 1
         FROM alumno_articulo aa
         JOIN articulos ar ON ar.id = aa.articulo_id
         WHERE aa.alumno_id = ?
           AND ar.activo = 1
           AND UPPER(TRIM(ar.detalle)) LIKE \'POST.%\'
         LIMIT 1'
    );
    $st->execute([$alumnoId]);

    return (bool) $st->fetchColumn();
}

/** Fecha de vencimiento (Y-m-d) configurada para postítulo en ese período, o null. */
function postitulo_vencimiento_periodo(PDO $pdo, int $anio, int $mes): ?string
{
    if (!postitulo_schema_ok($pdo)) {
        return null;
    }
    static $cache = [];
    $key = $anio . '-' . $mes;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $st = $pdo->prepare(
        'SELECT fecha_vencimiento FROM postitulo_vencimiento WHERE anio = ? AND mes = ? LIMIT 1'
    );
    $st->execute([$anio, $mes]);
    $val = $st->fetchColumn();
    $cache[$key] = ($val === false || $val === null) ? null : (string) $val;

    return $cache[$key];
}

/**
 * Listado de vencimientos cargados (más recientes primero).
 *
 * @return array<int,array{anio:int,mes:int,fecha_vencimiento:string}>
 */
function postitulo_vencimientos_listar(PDO $pdo): array
{
    if (!postitulo_schema_ok($pdo)) {
        return [];
    }
    $rows = $pdo->query(
        'SELECT anio, mes, fecha_vencimiento
         FROM postitulo_vencimiento
         ORDER BY anio DESC, mes DESC'
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'anio' => (int) $r['anio'],
            'mes' => (int) $r['mes'],
            'fecha_vencimiento' => (string) $r['fecha_vencimiento'],
        ];
    }

    return $out;
}

/** Inserta o actualiza el vencimiento de un período. Lanza excepción si la tabla no existe. */
function postitulo_vencimiento_guardar(PDO $pdo, int $anio, int $mes, string $fechaYmd): void
{
    if (!postitulo_schema_ok($pdo)) {
        throw new RuntimeException('Falta la tabla postitulo_vencimiento. Ejecutá la migración 40.');
    }
    if ($anio < 2000 || $anio > 2100 || $mes < 1 || $mes > 12) {
        throw new InvalidArgumentException('Período inválido.');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaYmd) !== 1) {
        throw new InvalidArgumentException('Fecha de vencimiento inválida.');
    }
    $st = $pdo->prepare(
        'INSERT INTO postitulo_vencimiento (anio, mes, fecha_vencimiento)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE fecha_vencimiento = VALUES(fecha_vencimiento)'
    );
    $st->execute([$anio, $mes, $fechaYmd]);
}

/** Elimina el vencimiento cargado para un período. */
function postitulo_vencimiento_eliminar(PDO $pdo, int $anio, int $mes): void
{
    if (!postitulo_schema_ok($pdo)) {
        return;
    }
    $st = $pdo->prepare('DELETE FROM postitulo_vencimiento WHERE anio = ? AND mes = ?');
    $st->execute([$anio, $mes]);
}
