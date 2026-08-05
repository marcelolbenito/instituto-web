<?php
declare(strict_types=1);

/**
 * Provisiona usuarios portal (rol alumno) para alumnos activos con DNI.
 * Uso: php tools/crear_usuarios_alumnos.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Solo CLI.\n");
    exit(1);
}

$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/UsuarioAlumno.php';

$pdo = Db::pdo($config);
$res = usuario_alumno_provisionar_activos($pdo);

echo "=== Usuarios portal (alumnos activos) ===\n";
echo 'Creados:      ' . $res['creados'] . "\n";
echo 'Actualizados: ' . $res['actualizados'] . "\n";
echo 'Omitidos/err: ' . $res['omitidos'] . "\n";
if ($res['errores'] !== []) {
    echo "\nDetalle (hasta 30):\n";
    foreach (array_slice($res['errores'], 0, 30) as $e) {
        echo '  - ' . $e . "\n";
    }
}
echo "\nLogin portal: usuario = DNI (solo números), contraseña inicial = mismo DNI.\n";
