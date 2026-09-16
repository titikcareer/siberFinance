<?php

declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            $driver   = $_ENV['DB_DRIVER'] ?? 'pgsql';
            $host     = $_ENV['DB_HOST'] ?? '127.0.0.1';
            $port     = $_ENV['DB_PORT'] ?? ($driver === 'pgsql' ? '5432' : '3306');
            $database = $_ENV['DB_DATABASE'] ?? 'postgres';
            $username = $_ENV['DB_USERNAME'] ?? 'postgres';
            $password = $_ENV['DB_PASSWORD'] ?? '';
            $sslMode  = $_ENV['DB_SSLMODE'] ?? 'require';

            $dsn = $driver === 'pgsql'
                ? "pgsql:host={$host};port={$port};dbname={$database};sslmode={$sslMode}"
                : "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

            try {
                self::$instance = new PDO($dsn, $username, $password, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES    => false,
                ]);
            } catch (PDOException $e) {
                throw new RuntimeException('Database connection failed: ' . $e->getMessage());
            }
        }

        return self::$instance;
    }
}
