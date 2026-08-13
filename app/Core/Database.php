<?php
namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(bool $withoutDatabase = false): PDO
    {
        if (!$withoutDatabase && self::$pdo) return self::$pdo;
        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '3306');
        $db = $withoutDatabase ? '' : ';dbname=' . Env::get('DB_DATABASE', 'monitoring_air');
        $dsn = "mysql:host={$host};port={$port}{$db};charset=utf8mb4";
        $pdo = new PDO($dsn, Env::get('DB_USERNAME', 'root'), Env::get('DB_PASSWORD', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if (!$withoutDatabase) self::$pdo = $pdo;
        return $pdo;
    }

    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}

