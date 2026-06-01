<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$configPath = $root . '/config/config.php';

if (is_readable($configPath)) {
    return require $configPath;
}

$host = getenv('DB_HOST');
if ($host !== false && $host !== '') {
    $dbName = getenv('DB_NAME') ?: 'instituto';
    $dbUser = getenv('DB_USER') ?: 'instituto';
    $dbPass = getenv('DB_PASSWORD') ?: '';

    return [
        'app' => [
            'name' => getenv('APP_NAME') ?: 'Instituto — gestión de cuotas',
            'base_url' => getenv('APP_BASE_URL') ?: '/',
        ],
        'db' => [
            'dsn' => sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $dbName),
            'user' => $dbUser,
            'pass' => $dbPass,
        ],
        'gesis' => [
            'base_url' => getenv('GESIS_FE_URL') ?: 'https://servicios.gesis2.com',
            'email' => getenv('GESIS_FE_EMAIL') ?: '',
            'password' => getenv('GESIS_FE_PASSWORD') ?: '',
            'production' => getenv('GESIS_FE_PRODUCTION') === 'true',
            'punto_venta' => (int) (getenv('GESIS_FE_PTO_VTA') ?: 1),
            'cbte_tipo' => (int) (getenv('GESIS_FE_CBTE_TIPO') ?: 11),
            'concepto' => 2,
        ],
    ];
}

$example = $root . '/config/config.example.php';
if (is_readable($example)) {
    return require $example;
}

http_response_code(503);
header('Content-Type: text/plain; charset=UTF-8');
echo 'Configuración no encontrada: cree config/config.php o defina DB_HOST (p. ej. en Docker).';
exit;
