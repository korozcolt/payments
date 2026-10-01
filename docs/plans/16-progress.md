# Progress — #16 framework-agnostic core

Update this file in every commit. If work is interrupted, resume from the first unchecked item.
Branch: `feat/framework-agnostic-core`. Plan: [16-framework-agnostic-core.md](16-framework-agnostic-core.md)

- [x] Phase 0 — Safety net (#17) — baseline tag is existing `v2.0.1`; 138 tests green (129 original + 9 WebhookController/event characterization); larastan level 5 + baseline (24 known errors)
- [x] Phase 1 — Core skeleton (#18) — `packages/core` (monorepo dir, split to `korozcolt/payments-core` in Phase 9); ports, Record interfaces (Eloquent-shaped, docblock returns), WebhookRequest, ArrayConfig, InMemory repos, token-based no-Illuminate arch test; core: 8 tests green
- [ ] Phase 2 — Move DTOs/Enums/Exceptions (#19) — Enums + Exceptions DONE (moved to core, same namespace); remaining: DTOs (still reference Eloquent models) + models implement Record interfaces
- [ ] Phase 3 — AbstractDriver (PSR-18/3) (#20)
- [ ] Phase 4 — Extract persistence (Wompi, ePayco, MercadoPago) (#21)
- [ ] Phase 5 — Manager, WebhookHandler, SubscriptionScheduler (#22)
- [ ] Phase 6 — Laravel adapter (#23)
- [ ] Phase 7 — Standalone proof (#24)
- [ ] Phase 8 — CI4 / Slim / Symfony adapters (#25)
- [ ] Phase 9 — Release (#26)
