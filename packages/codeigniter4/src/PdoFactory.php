<?php

declare(strict_types=1);

namespace Korbytes\Payments\CodeIgniter;

use InvalidArgumentException;
use PDO;

/**
 * Builds a PDO connection from a CodeIgniter database group
 * (the array in app/Config/Database.php), so the payment tables can live in
 * the same database as the rest of the application.
 */
final class PdoFactory
{
    /**
     * @param  array<string, mixed>  $group  a CodeIgniter database group
     */
    public static function fromDatabaseGroup(array $group, ?string $writePath = null): PDO
    {
        $pdo = new PDO(
            self::dsn($group, $writePath),
            self::string($group, 'username') ?: null,
            self::string($group, 'password') ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        return $pdo;
    }

    /**
     * @param  array<string, mixed>  $group
     */
    public static function dsn(array $group, ?string $writePath = null): string
    {
        $driver = strtolower(self::string($group, 'DBDriver'));
        $host = self::string($group, 'hostname', 'localhost');
        $database = self::string($group, 'database');
        $port = self::string($group, 'port');

        return match ($driver) {
            'mysqli' => sprintf(
                'mysql:host=%s;%sdbname=%s;charset=%s',
                $host,
                $port !== '' ? "port={$port};" : '',
                $database,
                self::string($group, 'charset', 'utf8mb4'),
            ),
            'postgre' => sprintf('pgsql:host=%s;%sdbname=%s', $host, $port !== '' ? "port={$port};" : '', $database),
            'sqlite3' => 'sqlite:'.self::sqlitePath($database, $writePath),
            default => throw new InvalidArgumentException(
                "Database driver [{$group['DBDriver']}] is not supported by korozcolt/payments-codeigniter4; use MySQLi, Postgre or SQLite3."
            ),
        };
    }

    private static function sqlitePath(string $database, ?string $writePath): string
    {
        if ($database === ':memory:' || str_starts_with($database, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $database)) {
            return $database;
        }

        // CodeIgniter resolves relative SQLite files against WRITEPATH.
        $base = $writePath ?? (defined('WRITEPATH') ? WRITEPATH : getcwd().'/writable/');

        return rtrim($base, '/\\').DIRECTORY_SEPARATOR.$database;
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private static function string(array $group, string $key, string $default = ''): string
    {
        $value = $group[$key] ?? null;

        return $value === null || $value === '' ? $default : (string) $value;
    }
}
