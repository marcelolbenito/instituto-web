<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/Auth.php';
require_once dirname(__DIR__) . '/src/UsuariosSchema.php';

$pdo = web_init($config);
auth_require_admin();
$uCols = usuarios_columnas($pdo);
$userCol = $uCols['user'];
$passCol = $uCols['pass'];
$hasNombre = $uCols['has_nombre'];
$hasAlumnoId = $uCols['has_alumno_id'];

$vincularAlumno = static function (int $usuarioId, string $rol, int $alumnoIdPost) use ($pdo, $hasAlumnoId): void {
    if (!$hasAlumnoId) {
        return;
    }
    $aid = $rol === 'alumno' ? ($alumnoIdPost > 0 ? $alumnoIdPost : null) : null;
    $pdo->prepare('UPDATE usuarios SET alumno_id = ? WHERE id = ?')->execute([$aid, $usuarioId]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth_require_write();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $username = trim((string) ($_POST['login'] ?? ''));
        $nombre = $hasNombre ? trim((string) ($_POST['nombre_completo'] ?? '')) : $username;
        $rol = (string) ($_POST['rol'] ?? 'secretaria');
        if (!in_array($rol, usuarios_roles_permitidos(), true)) {
            $rol = 'secretaria';
        }
        $alumnoIdPost = (int) ($_POST['alumno_id'] ?? 0);
        $activo = isset($_POST['activo']) ? 1 : 0;
        $pass = (string) ($_POST['password'] ?? '');
        if ($username === '' || $nombre === '') {
            header('Location: usuarios.php?err=' . rawurlencode('Usuario y nombre son obligatorios.'));
            exit;
        }
        if ($rol === 'alumno') {
            if (!$hasAlumnoId) {
                header('Location: usuarios.php?err=' . rawurlencode('Ejecute la migración 35_usuarios_rol_alumno_compat.sql.'));
                exit;
            }
            if ($alumnoIdPost <= 0) {
                header('Location: usuarios.php?err=' . rawurlencode('El rol alumno debe vincularse a una ficha.'));
                exit;
            }
        }
        if ($id > 0) {
            $stDup = $pdo->prepare('SELECT id FROM usuarios WHERE ' . $userCol . ' = ? AND id <> ?');
            $stDup->execute([$username, $id]);
            if ($stDup->fetch()) {
                header('Location: usuarios.php?err=' . rawurlencode('Ese nombre de usuario ya existe.'));
                exit;
            }
            if ($pass !== '') {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                if ($hasNombre) {
                    $pdo->prepare(
                        "UPDATE usuarios SET {$userCol} = ?, nombre_completo = ?, rol = ?, activo = ?, {$passCol} = ? WHERE id = ?"
                    )->execute([$username, $nombre, $rol, $activo, $hash, $id]);
                } else {
                    $pdo->prepare(
                        "UPDATE usuarios SET {$userCol} = ?, rol = ?, activo = ?, {$passCol} = ? WHERE id = ?"
                    )->execute([$username, $rol, $activo, $hash, $id]);
                }
            } elseif ($hasNombre) {
                $pdo->prepare(
                    "UPDATE usuarios SET {$userCol} = ?, nombre_completo = ?, rol = ?, activo = ? WHERE id = ?"
                )->execute([$username, $nombre, $rol, $activo, $id]);
            } else {
                $pdo->prepare(
                    "UPDATE usuarios SET {$userCol} = ?, rol = ?, activo = ? WHERE id = ?"
                )->execute([$username, $rol, $activo, $id]);
            }
            $vincularAlumno($id, $rol, $alumnoIdPost);
            header('Location: usuarios.php?ok=' . rawurlencode('Usuario actualizado.'));
            exit;
        }
        if ($pass === '') {
            header('Location: usuarios.php?err=' . rawurlencode('La contraseña es obligatoria al crear un usuario.'));
            exit;
        }
        $stDup = $pdo->prepare('SELECT id FROM usuarios WHERE ' . $userCol . ' = ?');
        $stDup->execute([$username]);
        if ($stDup->fetch()) {
            header('Location: usuarios.php?err=' . rawurlencode('Ese nombre de usuario ya existe.'));
            exit;
        }
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        if ($hasNombre) {
            $pdo->prepare(
                "INSERT INTO usuarios ({$userCol}, {$passCol}, nombre_completo, rol, activo) VALUES (?, ?, ?, ?, ?)"
            )->execute([$username, $hash, $nombre, $rol, $activo]);
        } else {
            $pdo->prepare(
                "INSERT INTO usuarios ({$userCol}, {$passCol}, rol, activo) VALUES (?, ?, ?, ?)"
            )->execute([$username, $hash, $rol, $activo]);
        }
        $newId = (int) $pdo->lastInsertId();
        $vincularAlumno($newId, $rol, $alumnoIdPost);
        header('Location: usuarios.php?ok=' . rawurlencode('Usuario creado.'));
        exit;
    }
}

$alumnoSel = $hasAlumnoId ? ', u.alumno_id' : '';
$alumnoJoin = $hasAlumnoId ? ' LEFT JOIN alumnos a ON a.id = u.alumno_id' : '';
$alumnoNom = $hasAlumnoId ? ', a.nombre_completo AS alumno_nombre' : '';
$nombreSel = $hasNombre ? ', u.nombre_completo' : '';
$rows = $pdo->query(
    "SELECT u.id, u.{$userCol} AS login_user{$nombreSel}, u.rol, u.activo, u.creado_en{$alumnoSel}{$alumnoNom}
     FROM usuarios u{$alumnoJoin}
     ORDER BY u.{$userCol}"
)->fetchAll();

$alumnosLista = [];
if ($hasAlumnoId) {
    $alumnosLista = $pdo->query(
        "SELECT id, nombre_completo, documento FROM alumnos WHERE activo = 1 ORDER BY nombre_completo"
    )->fetchAll();
}

$rolBadgeClass = static function (string $rol): string {
    $map = [
        'admin' => 'badge-info',
        'secretaria' => 'badge-ok',
        'consulta' => 'badge-muted',
        'alumno' => 'badge-warn',
    ];

    return $map[$rol] ?? 'badge-muted';
};

$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$edit = null;
if ($editId > 0) {
    $st = $pdo->prepare(
        "SELECT u.id, u.{$userCol} AS login_user{$nombreSel}, u.rol, u.activo{$alumnoSel}
         FROM usuarios u WHERE u.id = ?"
    );
    $st->execute([$editId]);
    $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

layout_start($config, 'Usuarios');
if (isset($_GET['ok'])) {
    flash_ok((string) $_GET['ok']);
}
if (isset($_GET['err'])) {
    flash_err((string) $_GET['err']);
}

echo '<h1>Usuarios del sistema</h1>';
echo '<p class="muted">Cuentas de acceso al panel. Solo el administrador gestiona usuarios y contraseñas.</p>';

echo '<div class="help-box">';
echo '<h3>Roles disponibles</h3>';
echo '<ul>';
echo '<li><span class="badge badge-info">Administrador</span> — acceso completo, parámetros y esta pantalla.</li>';
echo '<li><span class="badge badge-ok">Secretaría</span> — cobros, caja, archivos e informes; sin utilitarios ni usuarios.</li>';
echo '<li><span class="badge badge-muted">Consulta</span> — solo lectura: alumnos, cuenta corriente e informes.</li>';
echo '<li><span class="badge badge-warn">Alumno (portal)</span> — solo su ficha y CC; ingreso por DNI: <em>próximamente</em>.</li>';
echo '</ul>';
echo '</div>';

echo '<div class="toolbar">';
echo '<button type="button" class="btn-secondary" data-open-modal="usuario-modal">Nuevo usuario</button>';
if ($edit) {
    echo ' <span class="muted">Editando: <strong>' . h((string) ($edit['login_user'] ?? '')) . '</strong></span>';
    echo ' <a class="btn-secondary" href="usuarios.php">Cancelar edición</a>';
}
echo '</div>';

$editRol = (string) ($edit['rol'] ?? 'secretaria');
echo '<dialog id="usuario-modal" class="app-modal"><div class="app-modal-content">';
echo '<div class="app-modal-head"><h3>' . ($edit ? 'Editar usuario' : 'Nuevo usuario') . '</h3>';
echo '<button type="button" class="app-modal-close" data-close-modal="usuario-modal">Cerrar</button></div>';
echo '<form method="post" class="form form-grid" id="form-usuario">';
echo '<input type="hidden" name="action" value="save">';
if ($edit) {
    echo '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">';
    echo '<p class="muted">ID interno: ' . (int) $edit['id'] . '</p>';
}
echo '<label>Usuario (login) <input name="login" required maxlength="60" autocomplete="off" value="' . h($edit['login_user'] ?? '') . '"></label>';
if ($hasNombre) {
    echo '<label>Nombre para mostrar <input name="nombre_completo" required maxlength="120" value="' . h($edit['nombre_completo'] ?? '') . '"></label>';
}
echo '<label>Rol <select name="rol" id="usuario-rol">';
foreach (usuarios_roles_permitidos() as $r) {
    $sel = $editRol === $r ? ' selected' : '';
    echo '<option value="' . h($r) . '"' . $sel . '>' . h(auth_rol_label($r)) . '</option>';
}
echo '</select></label>';
if ($hasAlumnoId) {
    echo '<label id="wrap-alumno-id" class="' . ($editRol === 'alumno' ? '' : 'hidden') . '">Ficha de alumno vinculada';
    echo '<select name="alumno_id" id="usuario-alumno-id">';
    echo '<option value="0">— Elegir alumno —</option>';
    foreach ($alumnosLista as $al) {
        $aid = (int) $al['id'];
        $lbl = (string) $al['nombre_completo'];
        $doc = trim((string) ($al['documento'] ?? ''));
        if ($doc !== '') {
            $lbl .= ' · DNI ' . $doc;
        }
        $sel = (int) ($edit['alumno_id'] ?? 0) === $aid ? ' selected' : '';
        echo '<option value="' . $aid . '"' . $sel . '>' . h($lbl) . '</option>';
    }
    echo '</select>';
    echo '<span class="hint">Obligatorio si el rol es Alumno (portal).</span></label>';
}
echo '<label>Contraseña <input type="password" name="password" autocomplete="new-password"' . ($edit ? '' : ' required') . '>';
if ($edit) {
    echo '<span class="hint">Dejar vacío para mantener la contraseña actual.</span>';
} else {
    echo '<span class="hint">Mínimo recomendado: 8 caracteres.</span>';
}
echo '</label>';
$act = $edit === null || (int) ($edit['activo'] ?? 1) === 1;
echo '<label class="checkbox"><input type="checkbox" name="activo" value="1"' . ($act ? ' checked' : '') . '> Usuario activo</label>';
echo '<div class="form-actions"><button type="submit">Guardar</button></div>';
echo '</form></div></dialog>';
if ($edit) {
    echo '<span data-auto-open="usuario-modal"></span>';
}

echo '<h2>Usuarios registrados</h2>';
echo '<table class="table js-data-table"><thead><tr><th>Usuario</th>';
if ($hasNombre) {
    echo '<th>Nombre</th>';
}
echo '<th>Rol</th>';
if ($hasAlumnoId) {
    echo '<th>Ficha alumno</th>';
}
echo '<th>Estado</th><th data-nosort="1"></th></tr></thead><tbody>';
foreach ($rows as $r) {
    $rol = (string) ($r['rol'] ?? '');
    $activo = (int) ($r['activo'] ?? 0) === 1;
    echo '<tr>';
    echo '<td><code>' . h((string) $r['login_user']) . '</code></td>';
    if ($hasNombre) {
        echo '<td>' . h((string) ($r['nombre_completo'] ?? '')) . '</td>';
    }
    echo '<td><span class="badge ' . h($rolBadgeClass($rol)) . '">' . h(auth_rol_label($rol)) . '</span></td>';
    if ($hasAlumnoId) {
        $vn = trim((string) ($r['alumno_nombre'] ?? ''));
        echo '<td>' . ($vn !== '' ? h($vn) : '<span class="muted">—</span>') . '</td>';
    }
    echo '<td>';
    if ($activo) {
        echo '<span class="badge badge-ok">Activo</span>';
    } else {
        echo '<span class="badge badge-bad">Inactivo</span>';
    }
    echo '</td>';
    echo '<td class="nowrap"><a class="action-icon" href="usuarios.php?id=' . (int) $r['id'] . '" title="Editar">✏️</a></td>';
    echo '</tr>';
}
echo '</tbody></table>';

if ($hasAlumnoId) {
    echo '<script>
(() => {
  const rol = document.getElementById("usuario-rol");
  const wrap = document.getElementById("wrap-alumno-id");
  const sel = document.getElementById("usuario-alumno-id");
  if (!rol || !wrap || !sel) return;
  const sync = () => {
    const esAlumno = rol.value === "alumno";
    wrap.classList.toggle("hidden", !esAlumno);
    sel.required = esAlumno;
  };
  rol.addEventListener("change", sync);
  sync();
})();
</script>';
}

layout_end();
