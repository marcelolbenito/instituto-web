<?php
declare(strict_types=1);

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/UsuariosSchema.php';

function usuario_alumno_schema_ok(PDO $pdo): bool
{
    return auth_schema_ok($pdo) && usuarios_columnas($pdo)['has_alumno_id'];
}

/** DNI normalizado para login (solo dígitos, 6–11). */
function usuario_alumno_login_desde_dni(?string $documento): ?string
{
    $d = preg_replace('/\D/', '', trim((string) ($documento ?? '')));

    if ($d === '' || strlen($d) < 6 || strlen($d) > 11) {
        return null;
    }

    return $d;
}

/**
 * Crea o actualiza el usuario portal (rol alumno) para una ficha.
 *
 * @return array{ok:bool, created:bool, msg:string, usuario_id?:int}
 */
function usuario_alumno_ensure(PDO $pdo, int $alumnoId): array
{
    if (!usuario_alumno_schema_ok($pdo) || $alumnoId <= 0) {
        return ['ok' => false, 'created' => false, 'msg' => 'Portal alumno no configurado (migración 35).'];
    }

    $st = $pdo->prepare(
        'SELECT id, documento, nombre_completo, activo FROM alumnos WHERE id = ? LIMIT 1'
    );
    $st->execute([$alumnoId]);
    $al = $st->fetch(PDO::FETCH_ASSOC);
    if (!$al) {
        return ['ok' => false, 'created' => false, 'msg' => 'Alumno inexistente.'];
    }

    $cols = usuarios_columnas($pdo);
    $userCol = $cols['user'];
    $passCol = $cols['pass'];

    if ((int) ($al['activo'] ?? 0) !== 1) {
        $pdo->prepare(
            'UPDATE usuarios SET activo = 0 WHERE alumno_id = ? AND rol = \'alumno\''
        )->execute([$alumnoId]);

        return ['ok' => true, 'created' => false, 'msg' => 'Usuario portal desactivado (alumno inactivo).'];
    }

    $login = usuario_alumno_login_desde_dni($al['documento'] ?? null);
    if ($login === null) {
        return ['ok' => false, 'created' => false, 'msg' => 'Sin DNI válido para crear usuario portal.'];
    }

    $nombre = trim((string) ($al['nombre_completo'] ?? ''));

    $stByAlumno = $pdo->prepare(
        "SELECT id, {$userCol} AS login_user, rol FROM usuarios WHERE alumno_id = ? LIMIT 1"
    );
    $stByAlumno->execute([$alumnoId]);
    $existente = $stByAlumno->fetch(PDO::FETCH_ASSOC);

    if ($existente) {
        $uid = (int) $existente['id'];
        $loginActual = (string) ($existente['login_user'] ?? '');
        if ($loginActual !== $login) {
            $stDup = $pdo->prepare("SELECT id FROM usuarios WHERE {$userCol} = ? AND id <> ? LIMIT 1");
            $stDup->execute([$login, $uid]);
            if ($stDup->fetch()) {
                return [
                    'ok' => false,
                    'created' => false,
                    'msg' => "El DNI {$login} ya está en uso por otro usuario.",
                ];
            }
        }
        if ($cols['has_nombre']) {
            $pdo->prepare(
                "UPDATE usuarios SET {$userCol} = ?, nombre_completo = ?, rol = 'alumno', activo = 1 WHERE id = ?"
            )->execute([$login, $nombre, $uid]);
        } else {
            $pdo->prepare(
                "UPDATE usuarios SET {$userCol} = ?, rol = 'alumno', activo = 1 WHERE id = ?"
            )->execute([$login, $uid]);
        }

        return [
            'ok' => true,
            'created' => false,
            'msg' => 'Usuario portal actualizado.',
            'usuario_id' => $uid,
        ];
    }

    $stDup = $pdo->prepare("SELECT id, alumno_id, rol FROM usuarios WHERE {$userCol} = ? LIMIT 1");
    $stDup->execute([$login]);
    $otro = $stDup->fetch(PDO::FETCH_ASSOC);
    if ($otro) {
        $aid = $otro['alumno_id'] !== null ? (int) $otro['alumno_id'] : 0;
        if ($aid > 0 && $aid !== $alumnoId) {
            return [
                'ok' => false,
                'created' => false,
                'msg' => "El usuario {$login} ya existe vinculado a otro alumno.",
            ];
        }
        if (($otro['rol'] ?? '') !== 'alumno' && ($otro['rol'] ?? '') !== '') {
            return [
                'ok' => false,
                'created' => false,
                'msg' => "El usuario {$login} ya existe con rol " . ($otro['rol'] ?? '') . '.',
            ];
        }
        $uid = (int) $otro['id'];
        $hash = password_hash($login, PASSWORD_DEFAULT);
        if ($cols['has_nombre']) {
            $pdo->prepare(
                "UPDATE usuarios SET {$passCol} = ?, nombre_completo = ?, rol = 'alumno', activo = 1, alumno_id = ? WHERE id = ?"
            )->execute([$hash, $nombre, $alumnoId, $uid]);
        } else {
            $pdo->prepare(
                "UPDATE usuarios SET {$passCol} = ?, rol = 'alumno', activo = 1, alumno_id = ? WHERE id = ?"
            )->execute([$hash, $alumnoId, $uid]);
        }

        return [
            'ok' => true,
            'created' => true,
            'msg' => 'Usuario portal vinculado (contraseña inicial = DNI).',
            'usuario_id' => $uid,
        ];
    }

    $hash = password_hash($login, PASSWORD_DEFAULT);
    if ($cols['has_nombre']) {
        $pdo->prepare(
            "INSERT INTO usuarios ({$userCol}, {$passCol}, nombre_completo, rol, activo, alumno_id)
             VALUES (?, ?, ?, 'alumno', 1, ?)"
        )->execute([$login, $hash, $nombre, $alumnoId]);
    } else {
        $pdo->prepare(
            "INSERT INTO usuarios ({$userCol}, {$passCol}, rol, activo, alumno_id)
             VALUES (?, ?, 'alumno', 1, ?)"
        )->execute([$login, $hash, $alumnoId]);
    }

    return [
        'ok' => true,
        'created' => true,
        'msg' => 'Usuario portal creado (usuario y contraseña inicial = DNI).',
        'usuario_id' => (int) $pdo->lastInsertId(),
    ];
}

/**
 * @return array{creados:int, actualizados:int, omitidos:int, errores:list<string>}
 */
function usuario_alumno_provisionar_activos(PDO $pdo): array
{
    $res = ['creados' => 0, 'actualizados' => 0, 'omitidos' => 0, 'errores' => []];
    if (!usuario_alumno_schema_ok($pdo)) {
        $res['errores'][] = 'Ejecute migraciones 34 y 35.';

        return $res;
    }

    $ids = $pdo->query(
        "SELECT id FROM alumnos WHERE activo = 1 AND documento IS NOT NULL AND TRIM(documento) <> '' ORDER BY id"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($ids as $rawId) {
        $aid = (int) $rawId;
        $r = usuario_alumno_ensure($pdo, $aid);
        if (!$r['ok']) {
            $stNom = $pdo->prepare('SELECT nombre_completo, documento FROM alumnos WHERE id = ?');
            $stNom->execute([$aid]);
            $row = $stNom->fetch(PDO::FETCH_ASSOC) ?: [];
            $res['errores'][] = 'ID ' . $aid . ' ' . trim((string) ($row['nombre_completo'] ?? ''))
                . ': ' . $r['msg'];
            $res['omitidos']++;
            continue;
        }
        if (!empty($r['created'])) {
            $res['creados']++;
        } else {
            $res['actualizados']++;
        }
    }

    return $res;
}
