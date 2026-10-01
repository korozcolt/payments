<?php

declare(strict_types=1);

namespace Korbytes\Payments\Contracts\Records;

/**
 * A persisted payment transaction.
 */
interface TransactionRecord extends Record
{
    /** @return bool */
    public function isSuccessful();

    /** @return bool */
    public function isFinal();

    /** @return bool */
    public function canProcess();
}
