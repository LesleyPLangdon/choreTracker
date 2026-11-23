<?php
declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    public static function connect(Config $config): PDO
    {
        $host = (string) $config->get('db.host', 'localhost');
        $name = (string) $config->get('db.name', '');
        $dsn = "mysql:host=$host;dbname=$name;charset=utf8mb4";
        $port = $config->get('db.port');
        if ($port) {
            $dsn .= ";port=$port";
        }
        $pdo = new PDO($dsn, (string) $config->get('db.user', ''), (string) $config->get('db.password', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Everything is stored in UTC.
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET NAMES utf8mb4");
        return $pdo;
    }
}
