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

## Changelog

Keep exactly one `## [Unreleased]` section as the first release heading in `CHANGELOG.md` while changes are being accumulated.

For a release, move the relevant entries into exactly one new dated semver heading, for example:

```text
## [v0.5.0] - 2026-08-24
```

The publish workflow derives the tag from the version heading introduced by the validated `main` commit itself. A release commit must therefore introduce exactly one new dated `vX.Y.Z` heading. Ordinary commits that only update `[Unreleased]` do not create tags.

## GitHub

1. Publish the repository publicly as `Codegenie-BE/laravel-transaction-guard`.
2. Keep `main` as the default branch.
3. Require `Tests / Required` before merging changes when repository settings permit it.
4. Keep the MIT license, contribution guide, security policy, support policy, and changelog in the repository root.
5. Let the successful `Tests` workflow on the release commit publish the annotated version tag; do not create a competing tag manually.

## Packagist

The Composer package name is:

```text
codegenie-be/laravel-transaction-guard
```

Submit the public GitHub repository to Packagist and enable the GitHub/Packagist update integration so future tags are synchronized automatically.

Do not publish a stable tag before the GitHub Actions matrix, security audit, static analysis, formatting check, Pest suite, coverage gate, and distribution archive validation have passed in the public repository.
