# Plan #16 — Framework-agnostic core + Laravel adapter

Issue: https://github.com/korozcolt/payments/issues/16
Progress tracker: [16-progress.md](16-progress.md)

## Golden rule
The existing suite (`tests/Feature/*`, `tests/Unit/*`) must pass **unmodified** at the end of every phase.
Editing an existing test means a breaking change: stop and discuss first.

## Design decisions

| # | Decision | Choice |
|---|---|---|
| D1 | Repo layout | New repo `korozcolt/payments-core`. Local dev via composer `path` repository. `korozcolt/payments` stays the Laravel package (Packagist/stars unchanged). |
| D2 | Webhook request | Own value object `WebhookRequest` (headers, raw body, payload, query) + `fromPsr7()`. Avoids requiring psr-http-message-bridge in Laravel. |
| D3 | HTTP | Core depends on `psr/http-client` + `psr/http-factory`. Laravel ships `LaravelHttpClient implements ClientInterface` over the `Http` facade so `Http::fake()` keeps working and existing tests are unchanged. |
| D4 | Models in DTOs | DTOs type `TransactionRecord`, `SubscriptionRecord`, `PayoutRecord` interfaces. Eloquent models implement them; `$result->transaction` is still the Eloquent model at runtime. |
| D5 | Events | Core dispatches neutral events via PSR-14. A Laravel bridge re-dispatches the current `Korbytes\Payments\Events\*` classes (`Dispatchable` + `SerializesModels`). Existing listeners are untouched. |

## Phases

### Phase 0 — Safety net (in `payments`, no functional change)
- Tag baseline `v2.0.x`.
- Characterization tests that are missing: `WebhookController` (401 bad signature, 200 known failure, 500), event payloads, `ProcessDueSubscriptionsCommand` for ePayco/MercadoPago.
- Add `phpstan` to `require-dev` (the `analyse` script exists but the dependency does not).

### Phase 1 — Core skeleton
- Repo `payments-core`: `composer.json` (PHP ^8.2, `psr/*`, `mercadopago/dx-php`, NO `illuminate/*`), Pest, CI.
- Ports: `TransactionRepositoryInterface`, `SubscriptionRepositoryInterface`, `PayoutRepositoryInterface`, `GatewayConfigProviderInterface`, `TransactionRunnerInterface` (replaces `DB::transaction`), `ClockInterface`.
- `InMemory*` implementations for core tests.

### Phase 2 — Move what is already portable
- DTOs, Enums, Exceptions move to core, **same namespace**.
- Replace model types with the `*Record` interfaces (D4); Eloquent models implement them.

### Phase 3 — `AbstractDriver`
- `Http::` -> PSR-18 (D3), `Log::` -> PSR-3, `config()` -> array / config provider.
- Remove `Illuminate\Support\Str` (8 uses) and `Collection`.

### Phase 4 — Extract persistence per driver
Order: **Wompi -> ePayco -> MercadoPago** (Wompi has most test coverage, used as the mold).
- `Model::create/find`, `DB::transaction`, `->fresh()` -> repositories.
- `PaymentDriverInterface` / `PayoutDriverInterface` use `WebhookRequest` (D2) and `*Record` (D4).
- Events via PSR-14 (D5).
- One PR per driver, suite green on each.

### Phase 5 — Manager and orchestration
- Core `PaymentManager` takes config/providers by constructor.
- Laravel `PaymentManager` + Facade stay as a wrapper reading `config('payments')` and `PaymentGateway` (`use_database`, `AsEncryptedArrayObject`).
- Core `WebhookHandler`: verify + process + map to HTTP status/body (401/200/500) so every adapter shares the same semantics. `WebhookController` becomes thin.
- Core `SubscriptionScheduler::processDue()`; artisan command only calls it.

### Phase 6 — Laravel adapter (`payments`)
- Require `payments-core`; Eloquent repositories, `LaravelHttpClient`, event bridge, updated ServiceProvider.
- Old interfaces kept as `@deprecated` wrappers.
- **Gate:** full suite unmodified and green on Laravel 10/11/12/13.

### Phase 7 — Standalone proof
- Core CI job WITHOUT Laravel/Testbench, with a rule (grep or deptrac) that fails on `Illuminate\`.
- `examples/standalone/`: plain PHP + Guzzle + PDO repository + schema SQL.
- Standalone guide in README.

### Phase 8 — Adapters (independent releases)
1. CodeIgniter 4: `Config\Payments`, `service('payments')`, Spark command, webhook route.
2. Slim: PSR-15 middleware/handler.
3. Symfony: bundle.

### Phase 9 — Release
- `payments-core` v1.0.0, `payments` v2.1.0 (v3 only if Phase 6 forces an intentional break).
- CHANGELOG, migration note (what to do if you extended `AbstractDriver`), announcement.
- Then unblock #12–#15.

## Risks

| Risk | Mitigation |
|---|---|
| Queued listeners lose `SerializesModels` | D5: Laravel events kept as-is. |
| `Http::fake` stops working | D3. |
| Third-party custom drivers break | Migration note and/or deprecated compatibility layer. The only real break. |
| `*Record` interfaces change parameter variance of `refund(...)` | Verify with phpstan in Phase 4; model must still be accepted at runtime. |

## Acceptance criteria
1. Current tests pass unedited on Laravel 10/11/12/13.
2. Core installs and tests without `illuminate/*`.
3. A plain PHP example charges and processes a Wompi webhook in sandbox.
4. At least one non-Laravel adapter (CI4) works end to end.
