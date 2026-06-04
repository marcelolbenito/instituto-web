<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';

/**
 * Columnas de `usuarios` (init: login/hash_password; migración 34+: username/password_hash).
 *
 * @return array{user: string, pass: string, has_nombre: bool, has_alumno_id: bool}
 */
function usuarios_columnas(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $user = db_has_column($pdo, 'usuarios', 'username') ? 'username' : 'login';
    $pass = db_has_column($pdo, 'usuarios', 'password_hash') ? 'password_hash' : 'hash_password';
    $cache = [
        'user' => $user,
        'pass' => $pass,
        'has_nombre' => db_has_column($pdo, 'usuarios', 'nombre_completo'),
        'has_alumno_id' => db_has_column($pdo, 'usuarios', 'alumno_id'),
    ];

    return $cache;
}

/** @return list<string> */
function usuarios_roles_permitidos(): array
{
    return ['admin', 'secretaria', 'consulta', 'alumno'];
}
