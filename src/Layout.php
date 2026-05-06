<?php
declare(strict_types=1);

function layout_start(array $config, string $pageTitle = ''): void
{
    $app = h($config['app']['name']);
    $title = $pageTitle === '' ? $app : h($pageTitle) . ' — ' . $app;
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    $cssPath = dirname(__DIR__) . '/public/assets/app.css';
    $cssVer = is_file($cssPath) ? (string) filemtime($cssPath) : '1';
    echo '<title>' . $title . '</title><link rel="stylesheet" href="assets/app.css?v=' . h($cssVer) . '"></head><body>';
    nav_main();
    echo '<main class="main">';
}

function layout_end(): void
{
    $jsPath = dirname(__DIR__) . '/public/assets/app.js';
    $jsVer = is_file($jsPath) ? (string) filemtime($jsPath) : '1';
    echo '</main><script src="assets/app.js?v=' . h($jsVer) . '"></script></body></html>';
}

function nav_main(): void
{
    $current = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    echo '<header class="site-header"><div class="brand"><a href="index.php">Instituto</a></div><nav class="nav nav-main">';
    $links = [
        ['index.php', 'Inicio', '🏠'],
        ['barrios.php', 'Barrios', '📍'],
        ['rubros.php', 'Rubros', '🗂️'],
        ['alumnos.php', 'Alumnos / clientes', '👥'],
        ['cuenta_corriente.php', 'Cuenta corriente', '💳'],
        ['registrar_cobro.php', 'Cobro', '💵'],
        ['articulos.php', 'Artículos', '📦'],
        ['conceptos_alumno.php', 'Conceptos', '✅'],
        ['generar_cuotas.php', 'Cuotas', '🧾'],
        ['parametros_cobranza.php', 'Parámetros', '⚙️'],
        ['feriados.php', 'Feriados', '📅'],
    ];
    foreach ($links as [$href, $label, $icon]) {
        $isActive = $current === $href ? ' is-active' : '';
        echo '<a class="nav-item' . $isActive . '" href="' . h($href) . '" aria-label="' . h($label) . '" title="' . h($label) . '">';
        echo '<span class="nav-item-icon" aria-hidden="true">' . h($icon) . '</span>';
        echo '<span class="nav-item-label">' . h($label) . '</span>';
        echo '</a>';
    }
    echo '</nav></header>';
}

function flash_ok(string $msg): void
{
    echo '<p class="ok flash">' . h($msg) . '</p>';
}

function flash_err(string $msg): void
{
    echo '<p class="err flash">' . h($msg) . '</p>';
}
