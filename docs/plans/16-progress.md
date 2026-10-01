# Progress — #16 framework-agnostic core

Update this file in every commit. If work is interrupted, resume from the first unchecked item.
Branch: `feat/framework-agnostic-core`. Plan: [16-framework-agnostic-core.md](16-framework-agnostic-core.md)

- [x] Phase 0 — Safety net (#17) — baseline tag is existing `v2.0.1`; 138 tests green (129 original + 9 WebhookController/event characterization); larastan level 5 + baseline (24 known errors)
- [x] Phase 1 — Core skeleton (#18) — `packages/core` (monorepo dir, split to `korozcolt/payments-core` in Phase 9); ports, Record interfaces (Eloquent-shaped, docblock returns), WebhookRequest, ArrayConfig, InMemory repos, token-based no-Illuminate arch test; core: 8 tests green
- [x] Phase 2 — Move DTOs/Enums/Exceptions (#19) — Enums, Exceptions, DTOs now in `packages/core` (same namespace); DTOs type `*Record` interfaces; Eloquent models implement them. Root 138/138, core 8/8. phpstan baseline = 47 (27 are `property.notFound` on Record interfaces in drivers; they go away in Phase 4 when drivers use `getAttribute()`)
- [x] Phase 3 — AbstractDriver (PSR-18/3) (#20) — HttpClient/Response over PSR-18/17, PSR-3 logger, ConfigProviderInterface/ArrayConfig, injected PSR-20 clock; no Str/Collection/Laravel helpers left in core (enforced by the arch test)
- [x] Phase 4 — Extract persistence (Wompi, ePayco, MercadoPago) (#21) — drivers + driver contracts live in packages/core and use DriverContext (repositories, transaction runner, PSR-14 events in Korbytes\Payments\Core\Events, WebhookRequest::from() accepting Illuminate/PSR-7/neutral requests). Laravel side = src/Support/* adapters (LaravelHttpClient over the Http facade so Http::fake() keeps working, Eloquent repositories, LaravelEventBridge re-dispatching the legacy Laravel events). Root 138/138 UNMODIFIED; core 13/13 incl. Wompi end to end with no framework; phpstan baseline 23
- [ ] Phase 5 — Manager, WebhookHandler, SubscriptionScheduler (#22)
- [ ] Phase 6 — Laravel adapter (#23)
- [ ] Phase 7 — Standalone proof (#24)
- [ ] Phase 8 — CI4 / Slim / Symfony adapters (#25)
- [ ] Phase 9 — Release (#26)
