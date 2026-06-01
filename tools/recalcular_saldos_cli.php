<?php
declare(strict_types=1);

/**
 * Recalcula saldo_cc de todos los alumnos (CLI).
 * Uso: php tools/recalcular_saldos_cli.php
 */
$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/Saldos.php';

$pdo = Db::pdo($config);
$n = recalcular_saldo_alumnos($pdo);
echo "Saldos recalculados (filas afectadas): {$n}\n";
