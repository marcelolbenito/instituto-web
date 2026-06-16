<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/Auth.php';

$pdo = web_init($config);

if (!auth_is_alumno()) {
    auth_forbidden('Esta pantalla es solo para alumnos.');
}

$alumnoId = auth_alumno_id();
$hasContactoAlumno = db_has_column($pdo, 'alumnos', 'email')
    && db_has_column($pdo, 'alumnos', 'telefono_whatsapp');

$msgOk = '';
$msgErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_contacto') {
    if ($alumnoId <= 0) {
        $msgErr = 'Su usuario no está vinculado a una ficha de alumno.';
    } elseif (!$hasContactoAlumno) {
        $msgErr = 'La actualización de contacto aún no está disponible.';
    } else {
        $emailNorm = normalize_email($_POST['email'] ?? null);
        if ($emailNorm === false) {
            $msgErr = 'Email inválido.';
        } else {
            $telNorm = normalize_telefono_whatsapp($_POST['telefono_whatsapp'] ?? null);
            if ($telNorm === false) {
                $msgErr = 'Teléfono WhatsApp inválido (use solo dígitos, + opcional).';
            } else {
                $stUp = $pdo->prepare(
                    'UPDATE alumnos SET email = ?, telefono_whatsapp = ? WHERE id = ? LIMIT 1'
                );
                $stUp->execute([$emailNorm, $telNorm, $alumnoId]);
                $msgOk = 'Datos de contacto actualizados.';
            }
        }
    }
}

$alumno = null;
if ($alumnoId > 0) {
    $cols = 'id, nombre_completo, documento, saldo_cc, activo';
    if ($hasContactoAlumno) {
        $cols .= ', email, telefono_whatsapp';
    }
    $st = $pdo->prepare("SELECT {$cols} FROM alumnos WHERE id = ? LIMIT 1");
    $st->execute([$alumnoId]);
    $alumno = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

layout_start($config, 'Mi cuenta');
echo '<h1>Portal del alumno</h1>';

if ($msgOk !== '') {
    flash_ok($msgOk);
}
if ($msgErr !== '') {
    flash_err($msgErr);
}

if ($alumno === null) {
    echo '<p class="warn">Su usuario aún no está vinculado a una ficha de alumno. Solicite la vinculación en administración.</p>';
    layout_end();
    exit;
}

$nombre = (string) ($alumno['nombre_completo'] ?? '');
$doc = trim((string) ($alumno['documento'] ?? ''));
$saldo = (float) ($alumno['saldo_cc'] ?? 0);

echo '<p class="muted">Bienvenido/a, <strong>' . h($nombre) . '</strong>.</p>';

echo '<section class="card portal-alumno-resumen">';
echo '<h2>Resumen</h2>';
echo '<ul class="portal-alumno-datos">';
if ($doc !== '') {
    echo '<li><span class="muted">Documento</span> ' . h($doc) . '</li>';
}
echo '<li><span class="muted">Saldo cuenta corriente</span> <strong class="'
    . ($saldo > 0.009 ? 'text-debe' : '') . '">$ ' . h(number_format($saldo, 2, ',', '.')) . '</strong></li>';
echo '</ul>';
echo '<p><a class="btn-secondary" href="cuenta_corriente.php?alumno_id=' . (int) $alumnoId . '">Ver cuenta corriente</a></p>';
echo '</section>';

if ($hasContactoAlumno) {
    echo '<section class="card" style="margin-top:1.25rem">';
    echo '<h2>Datos de contacto</h2>';
    echo '<p class="muted">Actualice su email y teléfono con WhatsApp para recibir avisos del instituto.</p>';
    echo '<form method="post" class="form form-grid" style="max-width:28rem">';
    echo '<input type="hidden" name="action" value="save_contacto">';
    echo '<label>Email <input name="email" type="email" maxlength="120" autocomplete="email" placeholder="alumno@ejemplo.com" value="'
        . h((string) ($alumno['email'] ?? '')) . '"></label>';
    echo '<label>Teléfono WhatsApp <input name="telefono_whatsapp" type="tel" maxlength="40" inputmode="tel" autocomplete="tel" placeholder="+54911..." value="'
        . h((string) ($alumno['telefono_whatsapp'] ?? '')) . '"></label>';
    echo '<p class="muted" style="grid-column:1/-1;margin:0">Opcional. Deje vacío si no desea informarlo.</p>';
    echo '<div class="form-actions"><button type="submit" class="btn-primary">Guardar contacto</button></div>';
    echo '</form></section>';
}

layout_end();
