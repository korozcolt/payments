# Releasing the core and adapters

The monorepo holds one Laravel package (repo root) and four sub-packages. They are published
as separate Composer packages; development uses `path` repositories so everything is tested together.

| Directory | Package | Depends on |
|---|---|---|
| `packages/core` | `korozcolt/payments-core` | PSR interfaces only |
| `packages/codeigniter4` | `korozcolt/payments-codeigniter4` | core, codeigniter4/framework, guzzle |
| `packages/slim` | `korozcolt/payments-slim` | core |
| `packages/symfony` | `korozcolt/payments-symfony` | core, symfony/* |
| `.` (root) | `korozcolt/payments` | core, illuminate/* |

## One-time setup

1. Create read-only split repositories on GitHub: `payments-core`, `payments-codeigniter4`, `payments-slim`, `payments-symfony`.
2. Register each on Packagist.
3. Add a subtree-split workflow (e.g. `symplify/monorepo-split-github-action`) that pushes `packages/<name>` to its repository on every tag, with a token secret that can write to those repositories.

## Per release

1. Make sure CI is green (`.github/workflows/tests.yml`: Laravel 10-13 matrix, core without Laravel, adapters, static analysis).
2. **Release order matters** — the core first, because everything else requires it:
   1. Tag `payments-core` `v1.0.0`.
   2. In every package that has `"korozcolt/payments-core": "@dev"`, replace it with `"^1.0"` **and delete the `repositories` path entry** (root `composer.json`, `packages/codeigniter4`, `packages/slim`, `packages/symfony`, `examples/standalone`). Run `composer update` and the test suites against the published core.
   3. Tag the adapters and the root package (`v2.1.0`).
3. Update `CHANGELOG.md` (move `[Unreleased]` to the new version).
4. Do not publish with `@dev` constraints or path repositories still in a `composer.json`.
