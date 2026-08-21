# Release procedure

## Pre-release gate

Run on every supported matrix combination through GitHub Actions:

```bash
composer validate --strict
composer audit --no-interaction
composer test:smoke
composer test
composer format:test
composer analyse
composer test:coverage
```

Coverage must remain at or above the configured 80% baseline, but scenario coverage is the primary correctness gate for transaction rules.

## GitHub

1. Publish the repository publicly as `Codegenie-BE/laravel-transaction-guard`.
2. Keep `main` as the default branch.
3. Require the test workflow before merging changes when repository settings permit it.
4. Keep the MIT license, contribution guide, security policy, support policy, and changelog in the repository root.
5. Create an annotated/release tag such as `v0.1.0` only after CI is green.

## Packagist

The Composer package name is:

```text
codegenie-be/laravel-transaction-guard
```

Submit the public GitHub repository to Packagist and enable the GitHub/Packagist update integration so future tags are synchronized automatically.

Do not publish a stable tag before the GitHub Actions matrix, security audit, static analysis, formatting check, Pest suite, and coverage gate have passed in the public repository.
