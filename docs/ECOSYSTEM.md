# The `korozcolt/payments` ecosystem

One payment-gateway codebase, five Composer packages, one rule: **the gateway logic is written once**, in a framework-agnostic core, and every framework gets a thin adapter on top of it.

This page is the map. Every package README links here.

## The packages

| Package | Repository | Role | Install it when |
|---|---|---|---|
| [`korozcolt/payments`](https://packagist.org/packages/korozcolt/payments) | [payments](https://github.com/korozcolt/payments) | **Laravel adapter** (10–13). Facade, config, Eloquent models, migrations, webhook route, artisan command. Also the **monorepo**: the other four live in `packages/` here and are mirrored to their own repositories. | You use Laravel. This is the original package and the entry point of the project. |
| [`korozcolt/payments-core`](https://packagist.org/packages/korozcolt/payments-core) | [payments-core](https://github.com/korozcolt/payments-core) | **The core.** Drivers (Wompi, MercadoPago, ePayco), DTOs, enums, manager, `WebhookHandler`, `SubscriptionScheduler`, PDO storage. Depends only on PSR interfaces. **No framework.** | Plain PHP, or a framework that has no adapter yet. Every other package depends on it. |
| [`korozcolt/payments-codeigniter4`](https://packagist.org/packages/korozcolt/payments-codeigniter4) | [payments-codeigniter4](https://github.com/korozcolt/payments-codeigniter4) | **CodeIgniter 4 adapter.** `service('payments')`, route, Spark command, migration, `Events::on()`. | You use CodeIgniter 4. |
| [`korozcolt/payments-slim`](https://packagist.org/packages/korozcolt/payments-slim) | [payments-slim](https://github.com/korozcolt/payments-slim) | **Slim 4 / PSR-15 adapter.** PSR-15 webhook request handler plus a Slim route helper. | You use Slim, or any PSR-15 stack. |
| [`korozcolt/payments-symfony`](https://packagist.org/packages/korozcolt/payments-symfony) | [payments-symfony](https://github.com/korozcolt/payments-symfony) | **Symfony bundle.** Configuration, controller, console command, PSR-14 via `event_dispatcher`. | You use Symfony. |

```
                      ┌───────────────────────────────┐
                      │  korozcolt/payments-core      │   gateway logic, written once
                      │  Wompi · MercadoPago · ePayco │   PSR-18 / 17 / 3 / 14 / 20
                      └───────────────┬───────────────┘
        ┌─────────────┬───────────────┼───────────────┬─────────────┐
        ▼             ▼               ▼               ▼             ▼
  payments        payments-       payments-       payments-     your own
  (Laravel)       codeigniter4    slim            symfony       adapter / plain PHP
```

Rule of thumb: **install the adapter for your framework**; Composer pulls the core in. Install the core directly only for plain PHP.

All adapters answer webhooks identically: `400` unknown or unavailable provider, `401` invalid signature, `200` processed, `500` unexpected error. That behaviour lives in the core's `WebhookHandler`, so it cannot drift between frameworks.

## Why it is built this way

**It started as a Laravel package.** `korozcolt/payments` unified Wompi, MercadoPago and ePayco behind one interface, because integrating payment gateways in Colombia and LATAM usually means outdated official libraries, different signature schemes and scattered APIs. It reached 260+ downloads on Packagist and is used in production.

**Then the community asked for more**, mostly in the comments of the project's LinkedIn post. Two requests shaped everything that followed:

1. **"Can I use this without Laravel?"** Two people independently asked for CodeIgniter 4 / Symfony / Slim / plain PHP. A large share of production PHP in Colombia and LATAM runs on those stacks. That is [#16](https://github.com/korozcolt/payments/issues/16): extract the gateway logic from Laravel into a core, and keep Laravel as the first-class adapter.
2. **"Can you add gateway X?"** The roadmap named Sistecrédito, Addi and Bold; the comments added PlaceToPay (enterprise and institutional use in Colombia and Ecuador) and Transbank Webpay Plus (Chile). Those are [#12](https://github.com/korozcolt/payments/issues/12), [#13](https://github.com/korozcolt/payments/issues/13), [#14](https://github.com/korozcolt/payments/issues/14) and [#15](https://github.com/korozcolt/payments/issues/15).

**Why the core came first.** New gateways written against the old Laravel-coupled drivers would have had to be written again for every other framework. So #12–#15 were deliberately *blocked by #16*: build the core, then add each gateway once and every adapter gets it for free.

The Laravel public API did not change: the original test suite passes unmodified on Laravel 10, 11, 12 and 13. Only custom drivers registered with `Payments::extend()` need a small change; see [UPGRADING-2.1.md](UPGRADING-2.1.md).

## Where things are

| You want to… | Go to |
|---|---|
| Use it in Laravel | [`README.md`](../README.md), then [`USAGE.md`](../USAGE.md) |
| Use it in plain PHP | [`packages/core/README.md`](../packages/core/README.md) and [`examples/standalone`](../examples/standalone) |
| Use it in CodeIgniter 4 / Slim / Symfony | the adapter's README (links above) |
| Upgrade from 2.0.x | [`UPGRADING-2.1.md`](UPGRADING-2.1.md) |
| See how the core was extracted | [`plans/16-framework-agnostic-core.md`](plans/16-framework-agnostic-core.md) and [`plans/16-progress.md`](plans/16-progress.md) |
| Release a new version | [`RELEASING.md`](RELEASING.md) |
| Add a gateway | implement the core's driver contracts in `packages/core/src/Drivers`; every adapter then supports it |

## Status and roadmap

| | Status |
|---|---|
| Wompi, MercadoPago, ePayco | Available in the core and every adapter |
| Core + Laravel, CodeIgniter 4, Slim, Symfony | Released: `payments` 2.1.0, the other four 1.0.0 |
| [#12](https://github.com/korozcolt/payments/issues/12) Sistecrédito | Open, unblocked |
| [#13](https://github.com/korozcolt/payments/issues/13) Addi and Bold | Open, unblocked |
| [#14](https://github.com/korozcolt/payments/issues/14) PlaceToPay (Evertec) | Open, unblocked |
| [#15](https://github.com/korozcolt/payments/issues/15) Transbank Webpay Plus (Chile) | Open, unblocked |

New gateways are developed in `packages/core` of the monorepo and released with the core; adapters pick them up through the `korozcolt/payments-core` version constraint.

## Development layout

`korozcolt/payments` is a monorepo. `packages/core`, `packages/codeigniter4`, `packages/slim` and `packages/symfony` are mirrored to their own repositories, which is what Packagist indexes. Open issues and pull requests in [`korozcolt/payments`](https://github.com/korozcolt/payments/issues) for any of the five. See [RELEASING.md](RELEASING.md) for how the mirrors are cut.

---

Maintained by [KOR Bytes S.A.S.](https://kor-bytes.com)
