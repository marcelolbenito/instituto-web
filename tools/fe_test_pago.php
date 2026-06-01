<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$config = require $root . '/src/bootstrap.php';
require_once $root . '/src/Db.php';
require_once $root . '/src/FacturaElectronica.php';
require_once $root . '/src/ParametrosFe.php';

$pagoId = (int) ($argv[1] ?? 41192);
$pdo = Db::pdo($config);
echo "fe_schema_ok: " . (fe_schema_ok($pdo) ? 'yes' : 'no') . "\n";
$prev = recibo_cargar_por_pago($pdo, $pagoId);
echo "recibo: " . ($prev ? 'ok alumno=' . ($prev['pago']['alumno_id'] ?? '?') : 'NULL') . "\n";
echo "estado FE: ";
print_r(fe_estado_pago($pdo, $pagoId));
echo "\nemitir:\n";
print_r(fe_emitir_desde_pago($pdo, $config, $pagoId));
