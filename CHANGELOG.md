# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Test coverage for `EpaycoDriver` and `MercadoPagoDriver` (previously only `WompiDriver` had tests): `charge()`, `verifyWebhookSignature()`, `processWebhook()`, `queryStatus()`.
- Webhook signature and processing test coverage for `WompiDriver` (previously only had `charge()` tests).
- `refund(PaymentTransaction $transaction, ?int $amountInCents = null): RefundResult` added to `PaymentDriverInterface` and implemented by all three drivers, plus `RefundResult` DTO and `PaymentRefunded` event.
- `refunded_amount`, `refunded_at`, and `provider_refund_id` columns on `payment_transactions`.

### Refund support — what's actually automated vs manual

Refund capability is **not equivalent** across providers. See the "Reembolsos" section in USAGE.md for full detail; summary:

- **MercadoPago**: fully automated, total or partial, via the official SDK's `PaymentRefundClient`. This is the only provider with real refund support in this release.
- **Wompi**: **no post-settlement refund API exists**. Only `POST /transactions/{id}/void` is available, which cancels a card transaction before it settles, and only in full (no partial amounts). Once a transaction settles, refunding it is only possible manually via the Wompi dashboard — `refund()` reports this as `MANUAL_REFUND_REQUIRED` rather than pretending to succeed.
- **ePayco**: **refund is not implemented in this package for any payment method**, including credit cards. ePayco's reversal API is documented to support credit card (TC) only — PSE and cash can never be reversed via API — but the endpoint's technical spec is gated behind an authenticated dashboard-only portal (`api.epayco.co`) that could not be verified while building this feature. `EpaycoDriver::refund()` always returns `RefundResult::notSupported()` and directs the merchant to ePayco's dashboard/support. Implementing real TC refund support is left for a follow-up once the endpoint contract is confirmed.

### Added — Subscriptions (recurring payments)

- `createPlan()`, `createSubscription()`, `cancelSubscription()`, `chargeSubscriptionCycle()` added to `PaymentDriverInterface`.
- New `PlanData`/`PlanResult`/`SubscriptionData`/`SubscriptionResult` DTOs, `SubscriptionCreated`/`SubscriptionCancelled`/`SubscriptionChargeSucceeded`/`SubscriptionChargeFailed` events, `SubscriptionStatus`/`BillingInterval` enums.
- New `subscription_plans` and `subscriptions` tables, plus a nullable `subscription_id` column on `payment_transactions` (recurring cycle charges are just tagged `PaymentTransaction` rows — no separate ledger).
- New `payments:process-subscriptions` Artisan command. It does nothing by itself — the host application must add it to its own scheduler (`Schedule::command('payments:process-subscriptions')->hourly()` in `routes/console.php`). Which providers it charges is controlled by `config('payments.subscriptions.scheduled_providers')` (default: `['wompi']` only).

### Subscription support — what's actually implemented vs deliberately skipped

Only **Wompi and MercadoPago** have real, tested subscription support in this release. **ePayco subscriptions are not implemented at all**, on purpose — see the "Suscripciones" section in USAGE.md for full detail:

- **MercadoPago**: full native support via the SDK's `PreApprovalPlanClient`/`PreApprovalClient` — MercadoPago bills each cycle automatically. `chargeSubscriptionCycle()` is a no-op for MercadoPago (`errorCode = 'NOT_APPLICABLE'`); recurring charge results arrive via `processWebhook()`'s new handling of the `subscription_authorized_payment` webhook type. That specific webhook path was not verified against a live MercadoPago sandbox this session — test it end-to-end before relying on it in production.
- **Wompi**: has no recurring-billing engine at all — only tokenized "payment sources" (`POST /v1/payment_sources`) for merchant-initiated charges. This package's own scheduler (the new Artisan command) is what actually bills each cycle for Wompi via `recurrent: true` transactions.
- **ePayco**: ePayco does have a full recurring-billing product (Plan + Customer + Subscription, via the official `epayco/epayco-php` SDK — inspected directly on GitHub), but **we could not confirm, from public documentation, the SDK's own source, or a shared Postman collection, whether ePayco bills each cycle automatically or requires the merchant's backend to call `subscriptions->charge()` manually**. Shipping either assumption wrong risks silently-uncollected revenue at best and double-charging a customer at worst. `createPlan()`, `createSubscription()`, `cancelSubscription()`, and `chargeSubscriptionCycle()` on `EpaycoDriver` all return `notSupported()` explaining this. No `epayco/epayco-php` dependency was added since nothing calls it. This is left for a follow-up once that behavior is confirmed against a real ePayco account.

## [1.0.0] - 2024-01-23

### Added
- Initial release
- Support for three payment providers:
  - **Wompi** (Colombia) - Credit/debit cards, PSE, Nequi, Efecty
  - **MercadoPago** (Latin America) - Credit/debit cards, bank transfers
  - **ePayco** (Colombia) - Credit/debit cards, PSE, Efecty
- Unified API through `Payments` facade
- `PaymentData` DTO for decoupled payment input
- `PaymentResult` DTO for payment creation results
- `WebhookResult` DTO for webhook processing results
- Event-driven architecture:
  - `PaymentCreated` - Fired when payment intent is created
  - `PaymentApproved` - Fired when payment is approved
  - `PaymentRejected` - Fired when payment is rejected
  - `WebhookReceived` - Fired when webhook is received
- Webhook controller with signature verification for all providers
- Database-driven or config-driven credential management
- Driver enable/disable support via configuration
- Extensible architecture for custom drivers
- Full webhook signature verification for all providers
- Idempotent webhook processing
- Comprehensive logging support
- Laravel 10, 11, and 12 compatibility
- PHP 8.2+ support

### Security
- Encrypted credential storage in database
- Webhook signature verification
- HMAC-based integrity checks

## [0.1.0] - 2024-01-20

### Added
- Initial development version
- Basic structure and contracts
