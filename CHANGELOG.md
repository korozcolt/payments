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
