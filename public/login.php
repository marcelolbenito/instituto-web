<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Auth.php';
require_once dirname(__DIR__) . '/src/InstitutoLogo.php';

auth_session_start();
$pdo = Db::pdo($config);

if (auth_is_logged_in()) {
    header('Location: ' . auth_home_url());
    exit;
}

$error = '';
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? 'index.php');
if ($next === '' || strpos($next, 'login.php') !== false) {
    $next = 'index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = auth_attempt_login($pdo, (string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
    if ($res['ok']) {
        $dest = auth_home_url();
        if ($next !== 'index.php' && strpos($next, 'login.php') === false) {
            $dest = $next;
        }
        header('Location: ' . $dest);
        exit;
    }
    $error = (string) ($res['error'] ?? 'No se pudo iniciar sesión.');
}

$appName = (string) ($config['app']['name'] ?? 'Instituto');
$appTitle = h($appName);
$schemaOk = auth_schema_ok($pdo);
$logoUrl = $schemaOk ? instituto_logo_url($pdo) : null;

header('Content-Type: text/html; charset=UTF-8');
$cssPath = __DIR__ . '/assets/app.css';
$cssVer = is_file($cssPath) ? (string) filemtime($cssPath) : '1';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingresar — <?= $appTitle ?></title>
    <link rel="stylesheet" href="assets/app.css?v=<?= h($cssVer) ?>">
</head>
<body class="login-page">
<div class="login-shell">
    <aside class="login-brand" aria-hidden="true">
        <div class="login-brand-inner">
            <p class="login-brand-eyebrow">Gestión de cuotas</p>
            <h1 class="login-brand-title"><?= $appTitle ?></h1>
            <p class="login-brand-tagline">Cobranzas, cuenta corriente y comprobantes en un solo panel.</p>
        </div>
    </aside>

    <main class="login-panel">
        <div class="login-form-card">
            <header class="login-form-head">
                <?php if ($logoUrl !== null) { ?>
                    <div class="login-logo-wrap">
                        <img src="<?= h($logoUrl) ?>" alt="" class="instituto-logo-print login-logo" width="200" height="72">
                    </div>
                <?php } ?>
                <h2>Iniciar sesión</h2>
                <p class="muted">Use el usuario asignado por el instituto.</p>
            </header>

            <?php if (!$schemaOk) { ?>
                <p class="err flash login-alert" role="alert">El acceso aún no está configurado. Contacte al administrador del sistema.</p>
            <?php } elseif ($error !== '') { ?>
                <p class="err flash login-alert" role="alert"><?= h($error) ?></p>
            <?php } ?>

            <form method="post" class="form login-form" autocomplete="on" novalidate>
                <input type="hidden" name="next" value="<?= h($next) ?>">
                <label for="login-user">Usuario</label>
                <input
                    type="text"
                    name="username"
                    id="login-user"
                    class="login-input"
                    autocomplete="username"
                    autocapitalize="none"
                    spellcheck="false"
                    required
                    autofocus
                    <?= $schemaOk ? '' : ' disabled' ?>
                >
                <label for="login-pass">Contraseña</label>
                <div class="login-pass-wrap">
                    <input
                        type="password"
                        name="password"
                        id="login-pass"
                        class="login-input"
                        autocomplete="current-password"
                        required
                        <?= $schemaOk ? '' : ' disabled' ?>
                    >
                    <button type="button" class="login-pass-toggle" id="login-pass-toggle" aria-label="Mostrar contraseña" title="Mostrar contraseña">Ver</button>
                </div>
                <div class="form-actions login-form-actions">
                    <button type="submit" class="login-submit" <?= $schemaOk ? '' : ' disabled' ?>>Ingresar</button>
                </div>
            </form>
        </div>
        <p class="login-foot muted">© <?= (int) date('Y') ?> · <?= $appTitle ?></p>
    </main>
</div>
<script>
(() => {
  const pass = document.getElementById('login-pass');
  const btn = document.getElementById('login-pass-toggle');
  if (!pass || !btn) return;
  btn.addEventListener('click', () => {
    const show = pass.type === 'password';
    pass.type = show ? 'text' : 'password';
    btn.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
    btn.setAttribute('title', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
    btn.textContent = show ? 'Ocultar' : 'Ver';
  });
})();
</script>
</body>
</html>
