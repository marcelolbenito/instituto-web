<?php
declare(strict_types=1);

/**
 * Activa postgrado/postítulo desde Excel (PHP, sin Python).
 *
 * Uso:
 *   php tools/activar_postitulo_excel.php --dry-run
 *   php tools/activar_postitulo_excel.php --yes
 *   php tools/activar_postitulo_excel.php --excel="/ruta/LISTADO COMPLETO POSTITULO.xlsx" --dry-run
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Solo CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
$config = require $root . '/src/bootstrap.php';
require_once $root . '/src/Db.php';
require_once $root . '/src/ActivarPostituloExcel.php';

$excel = $root . '/LISTADO COMPLETO POSTITULO.xlsx';
$dryRun = false;
$yes = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--yes') {
        $yes = true;
    } elseif (strpos($arg, '--excel=') === 0) {
        $excel = substr($arg, 8);
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Uso: php tools/activar_postitulo_excel.php [--excel=ruta.xlsx] [--dry-run] [--yes]\n";
        exit(0);
    } else {
        fwrite(STDERR, "Opción desconocida: {$arg}\n");
        exit(1);
    }
}

if (!is_readable($excel)) {
    fwrite(STDERR, "No existe o no se puede leer: {$excel}\n");
    exit(1);
}

try {
    $rows = ActivarPostituloExcel::readExcel($excel);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error leyendo Excel: ' . $e->getMessage() . "\n");
    exit(1);
}

echo 'Filas leídas del Excel: ' . count($rows) . "\n";
if ($rows === []) {
    fwrite(STDERR, "Sin filas válidas (A=articulo_id, D=DNI, datos desde fila 4).\n");
    exit(1);
}

$pdo = Db::pdo($config);
$enriched = ActivarPostituloExcel::enrich($pdo, $rows);
$nErr = ActivarPostituloExcel::printReport($enriched, $dryRun);

if ($dryRun) {
    echo "\nEjecutá sin --dry-run para aplicar.\n";
    exit($nErr > 0 ? 2 : 0);
}

if ($nErr > 0 && !$yes) {
    echo "\nHay errores. Usá --yes para aplicar las filas OK.\n";
    exit(2);
}

try {
    $res = ActivarPostituloExcel::apply($pdo, $enriched);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error al aplicar: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\nAplicado: {$res['activados']} alumnos pasaron a activo; {$res['conceptos']} vínculos alumno_articulo nuevos.\n";
echo "Listo. Verificá en Alumnos (filtro Activos).\n";
