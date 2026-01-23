# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
