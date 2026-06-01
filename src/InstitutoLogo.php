<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/ParametrosFe.php';

function instituto_logo_tabla_ok(PDO $pdo): bool
{
    return fe_parametros_tabla_ok($pdo)
        && db_has_column($pdo, 'parametros_factura_electronica', 'logo_path');
}

function instituto_logo_directorio_abs(): string
{
    return dirname(__DIR__) . '/public/uploads/instituto';
}

/**
 * Ruta relativa bajo public/ guardada en BD, o null si no hay logo.
 */
function instituto_logo_path_desde_bd(PDO $pdo): ?string
{
    if (!instituto_logo_tabla_ok($pdo)) {
        return null;
    }
    $row = fe_parametros_cargar($pdo);
    if ($row === null) {
        return null;
    }
    $rel = trim((string) ($row['logo_path'] ?? ''));
    if ($rel === '' || str_contains($rel, '..')) {
        return null;
    }

    $abs = dirname(__DIR__) . '/public/' . str_replace('\\', '/', $rel);
    if (!is_file($abs)) {
        return null;
    }

    return str_replace('\\', '/', $rel);
}

/**
 * URL web del logo (desde la raíz del sitio), o null.
 */
function instituto_logo_url(PDO $pdo): ?string
{
    $rel = instituto_logo_path_desde_bd($pdo);

    return $rel !== null ? '/' . ltrim($rel, '/') : null;
}

/**
 * @return array{ok:bool,msg:string}
 */
function instituto_logo_eliminar(PDO $pdo): array
{
    if (!instituto_logo_tabla_ok($pdo)) {
        return ['ok' => false, 'msg' => 'Ejecute sql/migracion/33_parametros_logo_instituto_compat.sql'];
    }

    $rel = instituto_logo_path_desde_bd($pdo);
    if ($rel !== null) {
        $abs = dirname(__DIR__) . '/public/' . $rel;
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    instituto_logo_limpiar_archivos_sueltos();
    $pdo->prepare('UPDATE parametros_factura_electronica SET logo_path = NULL WHERE id = 1')->execute();

    return ['ok' => true, 'msg' => 'Logo eliminado.'];
}

function instituto_logo_limpiar_archivos_sueltos(): void
{
    $dir = instituto_logo_directorio_abs();
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/logo.*') ?: [] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
}

/**
 * @param array<string,mixed> $file Entrada $_FILES['logo']
 * @return array{ok:bool,msg:string}
 */
function instituto_logo_subir(PDO $pdo, array $file): array
{
    if (!instituto_logo_tabla_ok($pdo)) {
        return ['ok' => false, 'msg' => 'Ejecute sql/migracion/33_parametros_logo_instituto_compat.sql'];
    }

    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'msg' => 'Sin archivo nuevo.'];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'msg' => 'Error al subir el archivo (código ' . $err . ').'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'msg' => 'Archivo de logo inválido.'];
    }

    $maxBytes = 2 * 1024 * 1024;
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        return ['ok' => false, 'msg' => 'El logo debe pesar entre 1 byte y 2 MB.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp) ?: '';
    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    if (!isset($map[$mime])) {
        return ['ok' => false, 'msg' => 'Formato no permitido. Use JPG, PNG, GIF o WebP.'];
    }

    $imgInfo = @getimagesize($tmp);
    if ($imgInfo === false) {
        return ['ok' => false, 'msg' => 'El archivo no es una imagen válida.'];
    }

    $dir = instituto_logo_directorio_abs();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'msg' => 'No se pudo crear la carpeta de uploads.'];
    }

    instituto_logo_limpiar_archivos_sueltos();

    $ext = $map[$mime];
    $rel = 'uploads/instituto/logo.' . $ext;
    $dest = dirname(__DIR__) . '/public/' . $rel;

    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'msg' => 'No se pudo guardar el logo en el servidor.'];
    }

    $pdo->prepare('UPDATE parametros_factura_electronica SET logo_path = ? WHERE id = 1')
        ->execute([$rel]);

    return ['ok' => true, 'msg' => 'Logo guardado.'];
}

/**
 * Imprime <img> del logo si existe; si no, no imprime nada.
 */
function instituto_logo_render_html(PDO $pdo, string $class = 'instituto-logo-print'): void
{
    $url = instituto_logo_url($pdo);
    if ($url === null) {
        return;
    }

    echo '<div class="instituto-logo-wrap">';
    echo '<img src="' . h($url) . '" alt="Logo" class="' . h($class) . '">';
    echo '</div>';
}
