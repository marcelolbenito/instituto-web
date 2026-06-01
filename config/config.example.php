<?php
/**
 * Copiar a config.php y completar (config.php no versionar si contiene secretos).
 */
declare(strict_types=1);

// Sin Docker: host 127.0.0.1. Con Docker Compose: usar variables DB_* o copiar valores.
$host = getenv('DB_HOST') ?: '127.0.0.1';
$name = getenv('DB_NAME') ?: 'instituto';
$user = getenv('DB_USER') ?: 'instituto';
$pass = getenv('DB_PASSWORD') ?: '';

return [
    'app' => [
        'name' => 'Instituto — gestión de cuotas',
        'base_url' => '/',
    ],
    'db' => [
        'dsn' => sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name),
        'user' => $user,
        'pass' => $pass,
    ],
    // Factura electrónica: preferir pantalla parametros_factura_electronica.php (tabla BD).
    // 'gesis' => [ 'email' => '', 'password' => '', ... ],
];
