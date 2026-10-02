<?php

declare(strict_types=1);

namespace Korbytes\Payments\Pdo;

use Korbytes\Payments\Contracts\TransactionRunnerInterface;
use PDO;

/**
 * Runs callbacks inside a PDO transaction (joins an already-open one instead of nesting).
 */
final class PdoTransactionRunner implements TransactionRunnerInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function run(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $callback();
        }

        $this->pdo->beginTransaction();

        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }
}
