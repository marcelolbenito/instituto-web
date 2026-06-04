<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/UsuariosSchema.php';

/** @var list<string> */
const AUTH_PUBLIC_PAGES = ['login.php', 'logout.php'];

/** @var list<string> */
const AUTH_CONSULTA_PAGES = [
    'index.php',
    'alumnos.php',
    'cuenta_corriente.php',
    'informes_saldos.php',
    'informes_morosos.php',
    'informes_recibos.php',
];

/** @var list<string> Pantallas del portal alumno (solo su ficha; login por DNI pendiente). */
const AUTH_ALUMNO_PAGES = [
    'portal_alumno.php',
    'cuenta_corriente.php',
];

/** @var list<string> */
const AUTH_SECRETARIA_DENIED = [
    'usuarios.php',
    'parametros_cobranza.php',
    'parametros_factura_electronica.php',
    'formas_pago.php',
    'tarjetas.php',
    'feriados.php',
    'reparar_cc_incrementos.php',
];

function auth_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function auth_schema_ok(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT 1 FROM usuarios LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

function auth_is_required(PDO $pdo): bool
{
    if (getenv('AUTH_DISABLED') === 'true') {
        return false;
    }

    return auth_schema_ok($pdo);
}

/**
 * @return array<string, mixed>|null
 */
function auth_user(): ?array
{
    $u = $_SESSION['usuario'] ?? null;
    if (!is_array($u)) {
        return null;
    }
    if (($u['rol'] ?? '') === 'caja') {
        $u['rol'] = 'secretaria';
    }

    return $u;
}

function auth_is_logged_in(): bool
{
    return auth_user() !== null;
}

function auth_current_script(): string
{
    return basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
}

function auth_login_url(): string
{
    return 'login.php';
}

function auth_home_url(): string
{
    $user = auth_user();
    if ($user !== null && ($user['rol'] ?? '') === 'alumno') {
        return 'portal_alumno.php';
    }

    return 'index.php';
}

function auth_is_alumno(): bool
{
    $user = auth_user();

    return $user !== null && ($user['rol'] ?? '') === 'alumno';
}

function auth_alumno_id(): int
{
    $user = auth_user();
    if ($user === null) {
        return 0;
    }

    return (int) ($user['alumno_id'] ?? 0);
}

/**
 * Rol alumno: fuerza consulta solo de su propia ficha en cuenta corriente.
 */
function auth_enforce_alumno_cc_scope(int &$alumnoId): void
{
    if (!auth_is_alumno()) {
        return;
    }
    $own = auth_alumno_id();
    if ($own <= 0) {
        auth_forbidden('Su usuario no está vinculado a una ficha de alumno. Contacte al instituto.');
    }
    if ($alumnoId > 0 && $alumnoId !== $own) {
        auth_forbidden('Solo puede ver su propia cuenta corriente.');
    }
    $alumnoId = $own;
}

function auth_require_login(PDO $pdo): void
{
    $script = auth_current_script();
    if (in_array($script, AUTH_PUBLIC_PAGES, true)) {
        return;
    }
    if (!auth_is_required($pdo)) {
        return;
    }
    if (auth_is_logged_in()) {
        auth_assert_page_access();

        return;
    }
    $next = (string) ($_SERVER['REQUEST_URI'] ?? $script);
    $q = $next !== '' ? ('?next=' . rawurlencode($next)) : '';
    header('Location: ' . auth_login_url() . $q);
    exit;
}

function auth_assert_page_access(): void
{
    $user = auth_user();
    if ($user === null) {
        return;
    }
    $rol = (string) ($user['rol'] ?? '');
    $page = auth_current_script();
    if ($rol === 'admin') {
        return;
    }
    if ($rol === 'consulta' && !in_array($page, AUTH_CONSULTA_PAGES, true)) {
        auth_forbidden('Su perfil solo permite consultas (listados y cuenta corriente).');
    }
    if ($rol === 'secretaria' && in_array($page, AUTH_SECRETARIA_DENIED, true)) {
        auth_forbidden('Los parámetros y usuarios están reservados al administrador.');
    }
    if ($rol === 'alumno' && !in_array($page, AUTH_ALUMNO_PAGES, true)) {
        auth_forbidden('El portal de alumnos solo permite ver su propia información.');
    }
}

function auth_forbidden(string $msg): void
{
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Acceso denegado</title>';
    echo '<link rel="stylesheet" href="assets/app.css"></head><body><main class="main">';
    echo '<h1>Acceso denegado</h1><p class="err">' . h($msg) . '</p>';
    echo '<p><a href="index.php">Volver al inicio</a></p></main></body></html>';
    exit;
}

function auth_can_write(): bool
{
    $user = auth_user();
    if ($user === null) {
        return true;
    }
    $rol = (string) ($user['rol'] ?? '');

    return $rol !== 'consulta' && $rol !== 'alumno';
}

function auth_require_write(): void
{
    if (!auth_can_write()) {
        auth_forbidden('Su perfil es de solo consulta.');
    }
}

/**
 * @return array{ok: bool, error?: string}
 */
function auth_attempt_login(PDO $pdo, string $username, string $password): array
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return ['ok' => false, 'error' => 'Usuario y contraseña son obligatorios.'];
    }
    if (!auth_schema_ok($pdo)) {
        return ['ok' => false, 'error' => 'Ejecute la migración sql/migracion/34_usuarios_auth_compat.sql.'];
    }
    $cols = usuarios_columnas($pdo);
    $nombreSel = $cols['has_nombre'] ? ', nombre_completo' : '';
    $alumnoSel = $cols['has_alumno_id'] ? ', alumno_id' : '';
    $st = $pdo->prepare(
        'SELECT id, ' . $cols['user'] . ' AS login_user, ' . $cols['pass'] . ' AS pass_hash, rol, activo'
        . $nombreSel . $alumnoSel . ' FROM usuarios WHERE ' . $cols['user'] . ' = ? LIMIT 1'
    );
    $st->execute([$username]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int) ($row['activo'] ?? 0) !== 1) {
        return ['ok' => false, 'error' => 'Usuario o contraseña incorrectos.'];
    }
    if (!password_verify($password, (string) ($row['pass_hash'] ?? ''))) {
        return ['ok' => false, 'error' => 'Usuario o contraseña incorrectos.'];
    }
    session_regenerate_id(true);
    $sessionUser = [
        'id' => (int) $row['id'],
        'username' => (string) ($row['login_user'] ?? ''),
        'nombre_completo' => (string) ($row['nombre_completo'] ?? $row['login_user'] ?? ''),
        'rol' => (string) $row['rol'],
    ];
    if ($cols['has_alumno_id'] && isset($row['alumno_id']) && $row['alumno_id'] !== null) {
        $sessionUser['alumno_id'] = (int) $row['alumno_id'];
    }
    $_SESSION['usuario'] = $sessionUser;

    return ['ok' => true];
}

function auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) ($p['secure'] ?? false), (bool) ($p['httponly'] ?? true));
    }
    session_destroy();
}

function auth_require_admin(): void
{
    $user = auth_user();
    if ($user === null || ($user['rol'] ?? '') !== 'admin') {
        auth_forbidden('Solo administradores.');
    }
}

function auth_display_name(): string
{
    $user = auth_user();
    if ($user === null) {
        return '';
    }
    $nom = trim((string) ($user['nombre_completo'] ?? ''));
    if ($nom !== '') {
        return $nom;
    }

    return (string) ($user['username'] ?? '');
}

function auth_rol_label(string $rol): string
{
    $labels = [
        'admin' => 'Administrador',
        'secretaria' => 'Secretaría',
        'consulta' => 'Consulta',
        'alumno' => 'Alumno (portal)',
    ];

    return $labels[$rol] ?? $rol;
}
