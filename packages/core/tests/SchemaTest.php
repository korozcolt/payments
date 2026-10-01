<?php

declare(strict_types=1);

use Korbytes\Payments\Pdo\Schema;

it('maps driver names to bundled dialects', function () {
    expect(Schema::dialectFor('MySQLi'))->toBe('mysql')
        ->and(Schema::dialectFor('Postgre'))->toBe('pgsql')
        ->and(Schema::dialectFor('SQLite3'))->toBe('sqlite');

    Schema::dialectFor('SQLSRV');
})->throws(InvalidArgumentException::class);

it('splits every bundled schema into executable statements covering all tables', function (string $dialect) {
    $statements = Schema::statements($dialect);
    $created = [];
    foreach ($statements as $statement) {
        if (preg_match('/^CREATE TABLE (\w+)/', $statement, $m)) {
            $created[] = $m[1];
        }
    }

    expect($created)->toBe(Schema::TABLES);
})->with(['sqlite', 'mysql', 'pgsql']);

it('creates a usable database from the split statements', function () {
    if (! extension_loaded('pdo_sqlite')) {
        test()->markTestSkipped('pdo_sqlite is not available');
    }

    $pdo = new PDO('sqlite::memory:');
    foreach (Schema::statements('sqlite') as $statement) {
        $pdo->exec($statement);
    }

    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

    $expected = Schema::TABLES;
    sort($expected);

    expect($tables)->toBe($expected);
});
