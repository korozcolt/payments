<?php

declare(strict_types=1);

namespace Korbytes\Payments\Pdo;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Korbytes\Payments\Enums\BillingInterval;
use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Enums\PaymentStatus;
use Korbytes\Payments\Enums\PayoutStatus;
use Korbytes\Payments\Enums\SubscriptionStatus;
use PDO;
use Psr\Clock\ClockInterface;

/**
 * Shared PDO plumbing (insert/update/hydrate/casting) behind PdoTransactionRepository,
 * PdoSubscriptionRepository and PdoPayoutRepository, for projects without an ORM
 * (it only needs a PDO connection). Create the tables with resources/schema.{sqlite,mysql,pgsql}.sql.
 *
 * Table and column names match the Laravel package's migrations, so a project
 * can move between the two without touching its data.
 */
final class PdoStore
{
    public const TRANSACTIONS = 'payment_transactions';

    public const PLANS = 'subscription_plans';

    public const SUBSCRIPTIONS = 'subscriptions';

    public const BENEFICIARIES = 'payout_beneficiaries';

    public const PAYOUTS = 'payouts';

    private const JSON_COLUMNS = ['provider_request', 'provider_response', 'webhook_payload', 'metadata'];

    private const DATE_COLUMNS = [
        'webhook_received_at', 'initiated_at', 'completed_at', 'refunded_at', 'trial_ends_at',
        'next_billing_date', 'started_at', 'cancelled_at', 'last_charged_at', 'processed_at', 'created_at', 'updated_at',
    ];

    private const INT_COLUMNS = [
        'id', 'payable_id', 'subscription_id', 'amount', 'refunded_amount', 'webhook_attempts', 'interval_count',
        'trial_days', 'subscription_plan_id', 'failed_charge_attempts', 'payout_beneficiary_id',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function findPlan(int $id): ?PdoRecord
    {
        return $this->hydrate(self::PLANS, $id);
    }

    /**
     * Active/trialing subscriptions whose next billing date has passed.
     *
     * @param  array<int, string>  $providers
     * @return array<int, PdoRecord>
     */
    public function dueSubscriptions(array $providers): array
    {
        if ($providers === []) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($providers), '?'));
        $statement = $this->pdo->prepare(
            'SELECT * FROM '.self::SUBSCRIPTIONS." WHERE status IN (?, ?) AND provider IN ({$marks}) AND next_billing_date <= ? ORDER BY id"
        );
        $statement->execute([
            SubscriptionStatus::Active->value,
            SubscriptionStatus::Trialing->value,
            ...array_values($providers),
            $this->clock->now()->format('Y-m-d H:i:s'),
        ]);

        return array_map(
            fn (array $row) => new PdoRecord($this, self::SUBSCRIPTIONS, $this->cast(self::SUBSCRIPTIONS, $row)),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    // ---- shared internals (also used by PdoRecord) ----

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function insert(string $table, array $attributes): PdoRecord
    {
        $now = $this->clock->now();
        $attributes += ['ulid' => $this->ulid(), 'created_at' => $now, 'updated_at' => $now];

        $columns = array_keys($attributes);
        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', array_map($this->quote(...), $columns)),
            implode(', ', array_fill(0, count($columns), '?')),
        ));
        $statement->execute(array_map(fn ($v) => $this->encode($v), array_values($attributes)));

        return $this->hydrate($table, (int) $this->pdo->lastInsertId());
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed> the refreshed, cast attributes
     */
    public function updateRow(string $table, int $id, array $attributes): array
    {
        $attributes['updated_at'] = $this->clock->now();

        $sets = implode(', ', array_map(fn ($c) => $this->quote($c).' = ?', array_keys($attributes)));
        $statement = $this->pdo->prepare("UPDATE {$table} SET {$sets} WHERE id = ?");
        $statement->execute([...array_map(fn ($v) => $this->encode($v), array_values($attributes)), $id]);

        return $this->hydrate($table, $id)?->toArray() ?? [];
    }

    public function hydrate(string $table, int $id): ?PdoRecord
    {
        return $this->firstWhere($table, 'id', $id);
    }

    public function firstWhere(string $table, string $column, int|string $value): ?PdoRecord
    {
        $statement = $this->pdo->prepare("SELECT * FROM {$table} WHERE {$column} = ? ORDER BY id LIMIT 1");
        $statement->execute([$value]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ? new PdoRecord($this, $table, $this->cast($table, $row)) : null;
    }

    /**
     * Quote a column name (`interval` is reserved in MySQL).
     */
    private function quote(string $identifier): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? '`'.$identifier.'`'
            : '"'.$identifier.'"';
    }

    private function encode(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_array($value) => json_encode($value, JSON_THROW_ON_ERROR),
            is_bool($value) => (int) $value,
            default => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function cast(string $table, array $row): array
    {
        foreach ($row as $column => $value) {
            if ($value === null) {
                continue;
            }

            $row[$column] = match (true) {
                in_array($column, self::INT_COLUMNS, true) => (int) $value,
                in_array($column, self::JSON_COLUMNS, true) => json_decode((string) $value, true),
                in_array($column, self::DATE_COLUMNS, true) => CarbonImmutable::parse((string) $value),
                $column === 'provider' => PaymentProvider::tryFrom((string) $value) ?? $value,
                $column === 'interval' => BillingInterval::tryFrom((string) $value) ?? $value,
                $column === 'status' => $this->statusEnum($table)::tryFrom((string) $value) ?? $value,
                default => $value,
            };
        }

        return $row;
    }

    /**
     * @return class-string<\BackedEnum>
     */
    private function statusEnum(string $table): string
    {
        return match ($table) {
            self::SUBSCRIPTIONS => SubscriptionStatus::class,
            self::PAYOUTS => PayoutStatus::class,
            default => PaymentStatus::class,
        };
    }

    public function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * 26-char time-sortable id (ULID layout, Crockford base32).
     */
    private function ulid(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $time = (int) floor(microtime(true) * 1000);

        $timePart = '';
        for ($i = 0; $i < 10; $i++) {
            $timePart = $alphabet[$time % 32].$timePart;
            $time = intdiv($time, 32);
        }

        $randomPart = '';
        for ($i = 0; $i < 16; $i++) {
            $randomPart .= $alphabet[random_int(0, 31)];
        }

        return $timePart.$randomPart;
    }
}
