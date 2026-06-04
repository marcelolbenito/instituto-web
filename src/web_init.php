<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Auth.php';

function web_init(array $config, bool $requireAuth = true): PDO
{
    auth_session_start();
    $pdo = Db::pdo($config);
    if ($requireAuth) {
        auth_require_login($pdo);
    }

    return $pdo;
}
