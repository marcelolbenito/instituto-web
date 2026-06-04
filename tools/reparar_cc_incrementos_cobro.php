<?php
declare(strict_types=1);

/**
 * Crea contramovimientos RECIBO_INC / RECIBO_DEC faltantes (mora, beca, descuentos) y recalcula saldos.
 * Uso: php tools/reparar_cc_incrementos_cobro.php [alumno_id]
 * Web (producción, solo admin): /reparar_cc_incrementos.php
 */
$config = require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/util.php';
require_once dirname(__DIR__) . '/src/Cobranza.php';
require_once dirname(__DIR__) . '/src/Saldos.php';

$pdo = Db::pdo($config);
$alumnoId = isset($argv[1]) ? (int) $argv[1] : null;
if ($alumnoId !== null && $alumnoId <= 0) {
    $alumnoId = null;
}

$res = cobranza_backfill_incrementos_cc_desde_pagos($pdo, $alumnoId);
$n = recalcular_saldo_alumnos($pdo, $alumnoId);
echo 'Incrementos CC creados: ' . (int) $res['creados'] . PHP_EOL;
echo 'Ya existían (omitidos): ' . (int) $res['omitidos'] . PHP_EOL;
echo 'Saldos recalculados (filas): ' . $n . PHP_EOL;
