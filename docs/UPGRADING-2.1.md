# Upgrading from 2.0.x to 2.1.0

The Laravel package is now a thin adapter over the framework-agnostic core
([`korozcolt/payments-core`](../packages/core/README.md)).

## Nothing changes if you only use the built-in drivers

`Payments::driver('wompi')->charge(...)`, `Payments::extend()`, `config/payments.php`,
the models and migrations, the events (`PaymentApproved`, ...), the webhook route and
`payments:process-subscriptions` all behave as before. The existing test suite passes
unmodified on Laravel 10, 11, 12 and 13.

Composer pulls in `korozcolt/payments-core` automatically.

## Custom drivers (registered with `Payments::extend()`)

This is the only breaking area. A custom driver that implements `PaymentDriverInterface`
or extends `AbstractDriver` must adapt to the core contracts:

| 2.0.x | 2.1.0 |
|---|---|
| `verifyWebhookSignature(Illuminate\Http\Request $request)` | `verifyWebhookSignature(object $request)` |
| `processWebhook(Illuminate\Http\Request $request)` | `processWebhook(object $request)` |
| `refund(PaymentTransaction $transaction, ...)` | `refund(TransactionRecord $transaction, ...)` |
| `cancelSubscription(Subscription $subscription)` | `cancelSubscription(SubscriptionRecord $subscription)` |
| `chargeSubscriptionCycle(Subscription $subscription)` | `chargeSubscriptionCycle(SubscriptionRecord $subscription)` |
| `queryPayoutStatus(Payout $payout)` | `queryPayoutStatus(PayoutRecord $payout)` |

- `object $request` receives an `Illuminate\Http\Request` under Laravel. Call
  `Korbytes\Payments\Http\WebhookRequest::from($request)` first to get a neutral object with
  `all()`, `input()` (dot notation), `header()`, `query()` and `getContent()`.
- `TransactionRecord`, `SubscriptionRecord`, `PayoutRecord` are interfaces in `Korbytes\Payments\Contracts\Records`
  that the Eloquent models implement, so passing/receiving models still works. Read attributes as
  properties (`$transaction->reference_id`) or with `getAttribute()`.
- If you extend `AbstractDriver`, its constructor now receives a `Korbytes\Payments\Drivers\DriverContext`.
  The container injects it when the driver is resolved through `Payments::extend()`, so do not
  override the constructor (or call `parent::__construct($context)`). Inside a driver use:

  | instead of | use |
  |---|---|
  | `Http::withHeaders(...)` | `$this->ctx->http->withHeaders(...)` (same fluent API: `get/post/put/patch/delete`, `json()`, `status()`, `failed()`) |
  | `Log::...` / `config('payments.logging')` | `$this->log($level, $message, $context)` |
  | `config('payments.x')` | `$this->setting('x')` |
  | `now()` | `$this->now()` |
  | `Str::uuid()` | `$this->uuid()` |
  | `PaymentTransaction::create()/find()` | `$this->ctx->transactions->create()/find()` |
  | `DB::transaction(fn)` | `$this->ctx->runner->run(fn)` |
  | `PaymentApproved::dispatch(...)` | `$this->emit(\Korbytes\Payments\Core\Events\PaymentApproved::class, ...)` |

  Events emitted through `emit()` use the core event classes; the Laravel adapter re-dispatches
  them as the existing `Korbytes\Payments\Events\*` classes your listeners already handle.

## Namespaces

Enums, exceptions, DTOs, contracts and drivers moved into the core package **under the same
`Korbytes\Payments\...` namespaces**, so `use` statements keep working.

## Dependencies

`guzzlehttp/guzzle` (^7.5) is now a declared requirement. Laravel 10 does not install it by default.
