<?php

declare(strict_types=1);

namespace Korbytes\Payments\Contracts\Records;

/**
 * Minimal persisted-record contract used by drivers.
 *
 * Deliberately shaped like Eloquent's own method signatures (and without
 * native return types) so Eloquent models satisfy it unchanged on every
 * supported Laravel version. This is what keeps `$result->transaction` an
 * Eloquent model at runtime in the Laravel adapter.
 */
interface Record
{
    /**
     * @return int|string|null
     */
    public function getKey();

    /**
     * @param  string  $key
     * @return mixed
     */
    public function getAttribute($key);

    /**
     * Persist the given attributes.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     * @return bool
     */
    public function update(array $attributes = [], array $options = []);

    /**
     * Reload the record from storage.
     *
     * @param  array<int, string>|string  $with
     * @return static|null
     */
    public function fresh($with = []);
}
