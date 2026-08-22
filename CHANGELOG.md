# Changelog

All notable changes to this project will be documented in this file.

## [v0.3.0] - 2026-08-22

### Added

- Canonical rule catalog with rule explanation/help links and documentation consistency checks.
- Non-baselineable analyzer diagnostics, including TG902 for PCRE execution failures.
- Lazy Composer class-file metadata resolution for framework/vendor parent classes.
- Backed-enum resolution for statically known Laravel queue/database connection metadata.
- Source columns in JSON, GitHub annotations and SARIF output.
- Focused v0.3 hardening scenario module and broader Eloquent mutation coverage.

### Changed

- Parse/read/analyzer failures always fail CI, including with `--fail-on=never`, and prevent baseline generation.
- Local variable inference is conservative across multiple or conditional assignments.
- Global function detection no longer confuses object methods such as `->exec()`, `->touch()` and `->defer()`.
- Dynamic notification `viaConnections()` results no longer count as proof of commit safety.
- Manual transaction balance accounts for statically visible early exits.
- Empty/missing scan paths fail explicitly by default.
- Baseline output is deterministic and fingerprints are rooted at the configured project path.
- Benchmarks now cover larger transaction-free, safe, side-effect-heavy and metadata-heavy workloads.

## [Unreleased]

## [v0.2.0] - 2026-08-22

### Added

- Local closure-variable transaction callback analysis with explicit low-confidence `TG014` diagnostics when a callback cannot be resolved.
- Conservative local Laravel handle inference for HTTP, filesystem, cache, Redis, process and database connection objects.
- Eloquent cross-connection write analysis for statically known model connections, including Laravel 13 `#[Connection]`.
- Notification `viaConnections()` queue-connection analysis.
- SARIF 2.1.0 output for code-scanning integrations.
- Database-driver-aware `TG012` severity and a lowest-supported-dependency CI job.

- Laravel 13 `Bus::bulk()` analysis distinguishes synchronous commands from commit-sensitive queued jobs, including mixed bulk payloads.
- Connection-aware manual transaction analysis and `TG021` cross-database-connection write detection.
- Queued closure, pending chain, raw queue, conditional event, static broadcast, HTTP pool/batch and Process pool coverage.
- Laravel 13 recursive trait routing, queue forwarding, queue attributes and current route-array connection semantics.
- Additional cache/filesystem mutation coverage and fully qualified facade support.
- Informational `composer benchmark` workload for analyzer profiling.

### Changed

- Fixed Laravel concurrency/defer detection by centralizing facade static-method regex construction.
- Transaction-free files now stop after transaction discovery instead of running every rule family.
- Custom side-effect patterns are normalized consistently whether or not callers provide regex delimiters.
- JSON and SARIF rendering substitute invalid UTF-8 source bytes instead of crashing output generation.
- Release archives are validated before a tag is created, and analyzer benchmarks cover transaction-free, safe-transaction and side-effect-heavy workloads.
- Temporary self-mutating audit/maintenance workflows are removed after the v0.2 hardening cycle.

- `Bus::dispatch()` now follows Laravel's actual queued-vs-synchronous command semantics and honors indexed after-commit job metadata.
- Source scanning pre-indexes lines/non-code ranges, caches repeated lookups, uses API-keyword fast paths and avoids repeated sort/filter work on transaction regions.
- Baseline generation reuses the initial analysis result instead of scanning twice.
- Disabled-rule checks use a precomputed lookup map instead of repeated linear scans on every finding.
- Recursive file discovery prunes excluded directories before descending and matches exclude path segments precisely.
- After-commit metadata honors interface inheritance and explicit `afterCommit` property/constructor overrides.
- Direct broadcasts no longer treat `ShouldDispatchAfterCommit` alone as proof of safety because Laravel queues them through the broadcast manager directly.
- PHPStan / Larastan now runs at `level: max` across the supported Laravel/PHP CI matrix.
- Console configuration and baseline JSON handling use explicit runtime type narrowing so mixed framework/config input is safe at PHPStan max.
- Six tokenizer/control-flow inference diagnostics that PHPStan cannot prove from dynamic token/PREG shapes remain narrowly pinned by identifier and exact analyzer file; `reportUnmatchedIgnoredErrors` stays enabled so stale exceptions and unrelated regressions fail CI.

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
- Composer validation, dependency audit, Pint, PHPStan max, Pest and the 80% coverage gate are enforced in CI.
