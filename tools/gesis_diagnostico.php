<?php
declare(strict_types=1);

/**
 * Diagnóstico Gesis: login + puntos-venta (homologación y producción).
 * Uso: php tools/gesis_diagnostico.php
 */

$root = dirname(__DIR__);
$config = require $root . '/src/bootstrap.php';
require_once $root . '/src/Db.php';
require_once $root . '/src/ParametrosFe.php';
require_once $root . '/src/GesisArcaClient.php';

$pdo = Db::pdo($config);
$cfg = fe_gesis_config($config, $pdo);

echo "URL: " . $cfg['base_url'] . "\n";
echo "Email: " . $cfg['email'] . "\n";
echo "CUIT (referencia local): " . ($cfg['cuit_emisor'] ?? '(vacío)') . "\n";
echo "PV parámetros: " . $cfg['punto_venta'] . "\n";
echo "Production en parámetros: " . ($cfg['production'] ? 'SÍ' : 'no (homologación)') . "\n\n";

$client = new GesisArcaClient($cfg);
$ref = new ReflectionClass($client);
$login = $ref->getMethod('getValidToken');
$login->setAccessible(true);

try {
    $login->invoke($client);
    echo "Login JWT: OK\n\n";
} catch (Throwable $e) {
    echo "Login JWT: FALLO — " . $e->getMessage() . "\n";
    exit(1);
}

$tokProp = $ref->getProperty('accessToken');
$tokProp->setAccessible(true);
$token = (string) $tokProp->getValue($client);
$base = rtrim((string) $cfg['base_url'], '/');

foreach ([false, true] as $prod) {
    $label = $prod ? 'producción' : 'homologación';
    $url = $base . '/api/v1/arca/puntos-venta?production=' . ($prod ? 'true' : 'false');
    $ctx = stream_context_create([
        'http' => [
            'header' => "Authorization: Bearer {$token}\r\nAccept: application/json",
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    $http = 0;
    if (isset($http_response_header[0]) && preg_match('/\d{3}/', (string) $http_response_header[0], $m)) {
        $http = (int) $m[0];
    }
    echo "--- puntos-venta ({$label}) HTTP {$http} ---\n";
    echo $raw !== false ? substr($raw, 0, 500) : '(sin respuesta)';
    echo "\n\n";
}
