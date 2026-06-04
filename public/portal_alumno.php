<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/web_init.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Layout.php';
require_once dirname(__DIR__) . '/src/Auth.php';

$pdo = web_init($config);
$alumnoId = auth_alumno_id();
$alumno = null;
if ($alumnoId > 0) {
    $st = $pdo->prepare(
        'SELECT id, nombre_completo, documento, saldo_cc, activo FROM alumnos WHERE id = ? LIMIT 1'
    );
    $st->execute([$alumnoId]);
    $alumno = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

layout_start($config, 'Mi cuenta');
echo '<h1>Portal del alumno</h1>';

if ($alumno === null) {
    echo '<p class="warn">Su usuario aún no está vinculado a una ficha de alumno. Solicite la vinculación en administración.</p>';
    layout_end();
    exit;
}

$nombre = (string) ($alumno['nombre_completo'] ?? '');
$doc = trim((string) ($alumno['documento'] ?? ''));
$saldo = (float) ($alumno['saldo_cc'] ?? 0);

echo '<p class="muted">Bienvenido/a, <strong>' . h($nombre) . '</strong>.</p>';
echo '<p class="muted">Ingreso por <strong>DNI</strong> y autogestión ampliada: <em>próximamente</em>. Por ahora use el usuario y contraseña asignados.</p>';

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

layout_end();
