<?php
declare(strict_types=1);

final class Db
{
    private static ?\PDO $pdo = null;

    public static function pdo(array $config): \PDO
    {
        if (self::$pdo === null) {
            $db = $config['db'];
            self::$pdo = new \PDO($db['dsn'], $db['user'], $db['pass'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        }
        return self::$pdo;
    }
}
