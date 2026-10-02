# Releasing the core and adapters

The monorepo holds one Laravel package (repo root) and four sub-packages. Each sub-package is
published as its own read-only repository + Packagist package; the root is `korozcolt/payments`.

| Directory | Repository / Packagist package | Depends on |
|---|---|---|
| `packages/core` | [`korozcolt/payments-core`](https://github.com/korozcolt/payments-core) | PSR interfaces only |
| `packages/codeigniter4` | [`korozcolt/payments-codeigniter4`](https://github.com/korozcolt/payments-codeigniter4) | core ^1.0, codeigniter4/framework, guzzle |
| `packages/slim` | [`korozcolt/payments-slim`](https://github.com/korozcolt/payments-slim) | core ^1.0 |
| `packages/symfony` | [`korozcolt/payments-symfony`](https://github.com/korozcolt/payments-symfony) | core ^1.0, symfony/* |
| `.` (root) | [`korozcolt/payments`](https://github.com/korozcolt/payments) | core ^1.0, illuminate/* |

Everything resolves the core from Packagist (`^1.0`); there are no path repositories left in any `composer.json`.
To hack on the core and an adapter together, use a temporary path repository locally and do not commit it.

## Publishing a change to a sub-package

The split repositories are produced with `git subtree split` from this monorepo (history is preserved
and the split is deterministic, so later splits are descendants of earlier ones):

```bash
# example for the core; same for codeigniter4 / slim / symfony
git subtree split --prefix=packages/core -b split/core
git push https://github.com/korozcolt/payments-core.git split/core:main
gh release create vX.Y.Z -R korozcolt/payments-core --target main
```

Packagist picks up new tags automatically (GitHub hook enabled).

## Order matters

1. Release `payments-core` first (everything requires it).
2. If the core's public API changed, bump the `^x.y` constraint in the adapters and the root, run all suites, then release the adapters.
3. Release the root (`korozcolt/payments`) last and update `CHANGELOG.md`.

## Checks before any release

CI (`.github/workflows/tests.yml`) must be green: Laravel 10-13 matrix, core without Laravel, the three adapters on PHP 8.2 and 8.4, and static analysis.
