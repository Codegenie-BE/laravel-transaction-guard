# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

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
