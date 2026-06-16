<?php
declare(strict_types=1);

require_once __DIR__ . '/Auth.php';

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

function layout_render_nav_user_menu(): void
{
    $navUser = auth_user();
    if ($navUser === null) {
        return;
    }
    $nom = auth_display_name();
    $rol = auth_rol_label((string) ($navUser['rol'] ?? ''));

    echo '<details class="nav-user-menu">';
    echo '<summary class="nav-user-trigger">';
    echo '<span class="nav-user-name">' . h($nom) . '</span>';
    echo '<span class="nav-user-chevron" aria-hidden="true"></span>';
    echo '</summary>';
    echo '<div class="nav-user-dropdown">';
    echo '<p class="nav-user-dropdown-role">' . h($rol) . '</p>';
    echo '<a class="nav-user-dropdown-item nav-user-dropdown-logout" href="logout.php">Salir</a>';
    echo '</div>';
    echo '</details>';
}

function nav_main(): void
{
    $current = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    echo '<header class="site-header"><div class="brand"><a href="' . h(auth_is_alumno() ? 'portal_alumno.php' : 'index.php') . '">Instituto</a></div><nav class="nav nav-main nav-main-short">';

    if (auth_is_alumno()) {
        $navUser = auth_user();
        $isPortal = $current === 'portal_alumno.php';
        $isCc = $current === 'cuenta_corriente.php';
        $btnClass = static function (bool $isActive): string {
            return 'nav-item nav-main-btn' . ($isActive ? ' is-active' : '');
        };
        echo '<a class="' . h($btnClass($isPortal)) . '" href="portal_alumno.php"><span class="nav-item-icon">🏠</span><span class="nav-item-label">Mi cuenta</span></a>';
        echo '<a class="' . h($btnClass($isCc)) . '" href="cuenta_corriente.php?alumno_id=' . auth_alumno_id() . '"><span class="nav-item-icon">💳</span><span class="nav-item-label">Cta. cte.</span></a>';
        if ($navUser !== null) {
            layout_render_nav_user_menu();
        }
        echo '</nav></header>';

        return;
    }

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
        ['informes_recibos.php', 'Resumen de recibos', '📋'],
        ['anular_recibo.php', 'Anular recibo', '↩️'],
        ['factura_electronica.php', 'Factura electrónica por recibo', '🧾'],
        ['caja.php', 'Caja del día', '🏧'],
        ['caja_cierres.php', 'Historial de cierres', '📒'],
        ['ajuste_debe.php', 'Cargar debe manual', '📝'],
        ['generar_cuotas.php', 'Generación de abonos', '🧾'],
    ];
    $informes = [
        ['informes_saldos.php', 'Saldo general', '📊'],
        ['informes_morosos.php', 'Morosos', '⚠️'],
        ['informes_recibos.php', 'Resumen de recibos', '🧾'],
        ['alumnos.php', 'Listado de clientes', '📋'],
        ['cuenta_corriente.php', 'Cuenta corriente', '💳'],
    ];
    $utilitarios = [
        ['parametros_cobranza.php', 'Porcentajes / cobranza', '⚙️'],
        ['parametros_factura_electronica.php', 'Factura electrónica (parámetros)', '🧾'],
        ['formas_pago.php', 'Formas de pago', '💳'],
        ['tarjetas.php', 'Tarjetas y cuotas', '🏦'],
        ['feriados.php', 'Calendario de feriados', '📅'],
    ];
    $navUser = auth_user();
    $isAdmin = $navUser !== null && ($navUser['rol'] ?? '') === 'admin';
    if ($isAdmin) {
        $utilitarios[] = ['reparar_cc_incrementos.php', 'Reparar CC (contramovimientos)', '🔧'];
        $utilitarios[] = ['activar_postitulo.php', 'Activar postítulo (Excel)', '🎓'];
    }

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
    if ($isAdmin) {
        $isUsuarios = $current === 'usuarios.php';
        echo '<a class="' . h($btnClass($isUsuarios)) . '" href="usuarios.php" title="Usuarios del sistema">';
        echo '<span class="nav-item-icon">👤</span><span class="nav-item-label">Usuarios</span></a>';
    }

    if ($navUser !== null) {
        layout_render_nav_user_menu();
    }

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
