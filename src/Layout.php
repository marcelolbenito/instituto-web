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
    echo '<header class="site-header"><div class="brand"><a href="index.php">Instituto</a></div><nav class="nav nav-main nav-main-short">';

    $archivos = [
        ['alumnos.php', 'Clientes / alumnos', '👥'],
        ['articulos.php', 'Artículos', '📦'],
        ['rubros.php', 'Rubros', '🗂️'],
        ['barrios.php', 'Barrios', '📍'],
        ['conceptos_alumno.php', 'Conceptos por alumno', '✅'],
        ['cuenta_corriente.php', 'Cuenta corriente', '💳'],
    ];
    $comprobantes = [
        ['registrar_cobro.php', 'Recibos / Cobros', '💵'],
        ['factura_electronica.php', 'Factura electrónica', '🧾'],
        ['caja.php', 'Caja del día', '🏧'],
        ['caja_cierres.php', 'Cierres de caja', '📒'],
        ['ajuste_debe.php', 'Cargar debe manual', '📝'],
        ['generar_cuotas.php', 'Generación de abonos', '🧾'],
    ];
    $informes = [
        ['cuenta_corriente.php', 'Resumen de saldos', '📊'],
        ['alumnos.php', 'Listado de clientes', '📋'],
    ];
    $utilitarios = [
        ['parametros_cobranza.php', 'Porcentajes / cobranza', '⚙️'],
        ['parametros_factura_electronica.php', 'Factura electrónica (parámetros)', '🧾'],
        ['formas_pago.php', 'Formas de pago', '💳'],
        ['tarjetas.php', 'Tarjetas y cuotas', '🏦'],
        ['feriados.php', 'Calendario de feriados', '📅'],
    ];

    $isActiveGroup = static function (array $items, string $currentPath): bool {
        foreach ($items as $item) {
            if (($item[0] ?? '') === $currentPath) {
                return true;
            }
        }
        return false;
    };
    $btnClass = static function (bool $isActive): string {
        return 'nav-item nav-main-btn' . ($isActive ? ' is-active' : '');
    };

    $isInicio = $current === 'index.php';
    echo '<a class="' . h($btnClass($isInicio)) . '" href="index.php" title="Inicio"><span class="nav-item-icon">🏠</span><span class="nav-item-label">Inicio</span></a>';
    echo '<button type="button" class="' . h($btnClass($isActiveGroup($archivos, $current))) . '" data-open-modal="menu-archivos"><span class="nav-item-icon">🗂️</span><span class="nav-item-label">Archivos</span></button>';
    echo '<button type="button" class="' . h($btnClass($isActiveGroup($comprobantes, $current))) . '" data-open-modal="menu-comprobantes"><span class="nav-item-icon">🧾</span><span class="nav-item-label">Comprobantes</span></button>';
    echo '<button type="button" class="' . h($btnClass($isActiveGroup($informes, $current))) . '" data-open-modal="menu-informes"><span class="nav-item-icon">📊</span><span class="nav-item-label">Informes</span></button>';
    echo '<button type="button" class="' . h($btnClass($isActiveGroup($utilitarios, $current))) . '" data-open-modal="menu-utilitarios"><span class="nav-item-icon">⚙️</span><span class="nav-item-label">Utilitarios</span></button>';
    echo '</nav></header>';

    $renderModal = static function (string $id, string $title, array $items): void {
        echo '<dialog id="' . h($id) . '" class="app-modal app-menu-modal"><div class="app-modal-content">';
        echo '<div class="app-modal-head"><h3>' . h($title) . '</h3>';
        echo '<button type="button" class="app-modal-close" data-close-modal="' . h($id) . '">Cerrar</button></div>';
        echo '<div class="menu-modal-grid">';
        foreach ($items as [$href, $label, $icon]) {
            echo '<a class="menu-modal-item" href="' . h($href) . '">';
            echo '<span class="menu-modal-icon">' . h($icon) . '</span>';
            echo '<span class="menu-modal-label">' . h($label) . '</span>';
            echo '</a>';
        }
        echo '</div></div></dialog>';
    };

    $renderModal('menu-archivos', 'Archivos', $archivos);
    $renderModal('menu-comprobantes', 'Comprobantes', $comprobantes);
    $renderModal('menu-informes', 'Informes', $informes);
    $renderModal('menu-utilitarios', 'Utilitarios', $utilitarios);
}

function flash_ok(string $msg): void
{
    echo '<p class="ok flash">' . h($msg) . '</p>';
}

function flash_err(string $msg): void
{
    echo '<p class="err flash">' . h($msg) . '</p>';
}
