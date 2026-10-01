<?php

declare(strict_types=1);

namespace Korbytes\Payments\Pdo;

/**
 * Access to the bundled SQL schema, for adapters that create tables from a
 * migration system (CodeIgniter migrations, Doctrine migrations, ...).
 */
final class Schema
{
    /** Tables in dependency order (create top to bottom, drop bottom to top). */
    public const TABLES = [
        PdoStore::TRANSACTIONS,
        PdoStore::PLANS,
        PdoStore::SUBSCRIPTIONS,
        PdoStore::BENEFICIARIES,
        PdoStore::PAYOUTS,
    ];

    public const DIALECTS = ['sqlite', 'mysql', 'pgsql'];

    public static function path(string $dialect): string
    {
        if (! in_array($dialect, self::DIALECTS, true)) {
            throw new \InvalidArgumentException("Unsupported SQL dialect [{$dialect}]; use one of: ".implode(', ', self::DIALECTS));
        }

        return dirname(__DIR__, 2)."/resources/schema.{$dialect}.sql";
    }

    /**
     * Map a PDO driver name or a CodeIgniter-style driver name to a schema dialect.
     */
    public static function dialectFor(string $driver): string
    {
        return match (strtolower($driver)) {
            'mysql', 'mysqli', 'mariadb' => 'mysql',
            'pgsql', 'postgre', 'postgres', 'postgresql' => 'pgsql',
            'sqlite', 'sqlite3' => 'sqlite',
            default => throw new \InvalidArgumentException("No bundled schema for database driver [{$driver}]"),
        };
    }

    /**
     * The schema as individual SQL statements (comments removed), ready to execute one by one.
     *
     * @return array<int, string>
     */
    public static function statements(string $dialect): array
    {
        $sql = (string) file_get_contents(self::path($dialect));
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';

        return array_values(array_filter(
            array_map('trim', explode(';', $sql)),
            fn (string $statement) => $statement !== '',
        ));
    }
}
