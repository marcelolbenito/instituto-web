<?php
declare(strict_types=1);

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Solo dígitos; vacío devuelve null (para CUIT opcional). */
function normalize_cuit(?string $raw): ?string
{
    if ($raw === null || trim($raw) === '') {
        return null;
    }
    $d = preg_replace('/\D/', '', $raw);
    if ($d === '' || $d === null) {
        return null;
    }
    return strlen($d) <= 13 ? $d : substr($d, 0, 13);
}

function cuit_ok(?string $digits): bool
{
    if ($digits === null || $digits === '') {
        return false;
    }
    return strlen($digits) === 11;
}

/**
 * Verifica si existe una columna en una tabla (cacheado por request).
 */
function db_has_column(\PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $st = $pdo->prepare(
        'SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1'
    );
    $st->execute([$table, $column]);
    $cache[$key] = (bool) $st->fetchColumn();
    return $cache[$key];
}
