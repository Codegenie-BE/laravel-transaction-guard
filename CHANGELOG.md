# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added

- Connection-aware manual transaction analysis and `TG021` cross-database-connection write detection.
- Queued closure, pending chain, raw queue, conditional event, static broadcast, HTTP pool/batch and Process pool coverage.
- Laravel 13 recursive trait routing, queue forwarding, queue attributes and current route-array connection semantics.
- Additional cache/filesystem mutation coverage and fully qualified facade support.
- Informational `composer benchmark` workload for analyzer profiling.

### Changed

- Source scanning pre-indexes lines/non-code ranges, caches repeated lookups and avoids repeated sort/filter work on transaction regions.
- Files without detected database transactions exit before side-effect rule scans, and baseline generation reuses the initial analysis result instead of scanning twice.
- Recursive file discovery prunes excluded directories before descending and matches exclude path segments precisely.
- After-commit metadata honors interface inheritance and explicit `afterCommit` property/constructor overrides.
- Direct broadcasts no longer treat `ShouldDispatchAfterCommit` alone as proof of safety because Laravel queues them through the broadcast manager directly.
- PHPStan level 8 runs without the previous analyzer-specific ignore baseline.


## [v0.1.0] - 2026-08-21

### Added

- Initial static transaction-side-effect analyzer.
- Detection for jobs, Bus/Queue, events, mail, notifications and broadcasts.
- Detection for outbound HTTP, filesystem/cache mutations and external processes.
- Laravel 13 concurrency/deferred-work checks.
- Deadlock-retry duplicate-side-effect detection.
- DDL / implicit-commit detection.
- Manual transaction balance checks.
- Queue `after_commit`, `afterCommit()`, `beforeCommit()`, `ShouldQueueAfterCommit` and `ShouldDispatchAfterCommit` awareness.
- Laravel 13 literal single-target `Queue::route()` connection analysis for exact classes, parents and interfaces; array routes remain conservative while upstream positional semantics are inconsistent.
- Baseline, inline suppression and custom-pattern support.
- Console, JSON and GitHub Actions output.
- Dependency-free smoke regression matrix with 100+ transaction-safety scenarios plus Pest/Testbench integration tests.

### Fixed

- GitHub Actions annotation assertions are compatible across supported Laravel/Testbench combinations.
- Invalid custom side-effect regular expressions are rejected without leaking runtime warnings into the test suite.
- Command option handling and path normalization are statically type-safe.

### Quality

- Validated on PHP 8.2–8.5 across Laravel 12 and 13.
- Composer validation, dependency audit, Pint, PHPStan level 8, Pest and the 80% coverage gate are enforced in CI.
