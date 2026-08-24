# Scenario and regression matrix

The executable source of truth is [`tests/Support/ScenarioMatrix.php`](../tests/Support/ScenarioMatrix.php). Every case contains syntactically valid PHP that is parsed but never executed.

The executable matrix is the source of truth for the exact scenario count. It is split into a core matrix plus focused hardening modules and covers these groups:

## Completeness contract

The PHP input space is unbounded, so no finite test suite can truthfully enumerate every possible Laravel program. Transaction Guard instead makes the finite public analyzer contract exhaustive and keeps broad behavioral scenarios around that contract.

The required test suite enforces all of the following:

- every public non-diagnostic `TG` rule in `RuleCatalog` has at least one positive scenario that must report it;
- every public non-diagnostic `TG` rule has at least one negative/control scenario that must not report it;
- scenario expectations may only reference canonical rule IDs and may not require and forbid the same rule simultaneously;
- analyzer integrity diagnostics `TG900`, `TG901`, `TG902` and `TG903` each have dedicated executable regressions;
- every finite cache mutation, cache-lock terminal and RateLimiter mutation in `OperationCatalog` is exercised through the scanner;
- every finite Redis mutation and mutating command is exercised, every listed Redis read is required to remain clean, every script command is conservatively reported, and control wrappers are verified not to become mutations by themselves;
- Redis method/command mutation catalogs must remain equivalent, duplicate-free and disjoint from read/script classifications;
- every finite query-builder, Eloquent static, Eloquent instance and Eloquent relation mutation catalog entry is exercised through cross-connection `TG021` analysis;
- adding a new public finding rule or finite catalog entry without matching test coverage therefore fails CI instead of silently reducing coverage.

The broad scenario count remains useful as a regression signal, but the count itself is deliberately not the completeness guarantee.

## Platform compatibility

The required CI gate validates Linux, Windows and macOS rather than assuming Linux filesystem semantics are portable. The workflow deliberately separates checks that are sensitive to runtime/platform combinations from invariant checks that only need to run once.

- Linux covers every supported PHP/Laravel compatibility pair. PHP 8.5 / Laravel 13 is owned by the coverage job, so the normal compatibility matrix does not execute the same Pest suite twice for that exact pair;
- Windows runs both boundary stacks: PHP 8.2 / Laravel 12 and PHP 8.5 / Laravel 13;
- macOS runs both boundary stacks: PHP 8.2 / Laravel 12 and PHP 8.5 / Laravel 13;
- Windows and macOS execute the complete Pest suite, including native-filesystem regressions, instead of redundantly rerunning OS-independent Composer metadata, dependency audit, Pint, PHPStan and documentation checks on every platform job;
- native-filesystem regressions cover paths containing spaces, Windows/Unix separator-stable fingerprints, segment and wildcard excludes, replacement of an existing baseline and absolute Artisan scan paths;
- repository PHP sources are asserted to remain LF-normalized on the checked-out filesystem, so `.gitattributes` regressions are still caught on real Windows runners without rerunning Pint four times;
- dependency-free smoke analysis runs on the oldest and newest supported PHP boundaries;
- latest and lowest supported Laravel 12/13 dependency sets are audited, while static analysis runs against both latest and lowest framework-major boundaries;
- Composer metadata, optimized autoloading, formatting, documentation contracts and benchmark bootstrap checks run once on the canonical latest Laravel 12 quality job because their result does not change by operating system;
- all four platform jobs may run concurrently, while the Linux compatibility matrix may run all of its combinations concurrently.

All compatibility, platform, lowest-dependency, coverage and distribution jobs remain dependencies of `Tests / Required`, so removing duplicate work does not weaken the merge gate.

## Transaction boundaries

- `DB::transaction()` callback syntax;
- arrow functions;
- simple local closure variables passed to `DB::transaction()` plus unresolved-callback diagnostics;
- `DB::connection(...)->transaction()`;
- facade aliases;
- namespace-local facade aliases in files containing multiple or bracketed namespaces;
- case-insensitive PHP import aliases without leaking bindings between namespaces;
- manual begin/commit/rollback, balanced per database connection;
- statically known cross-connection writes (`TG021`);
- try/catch rollback paths;
- nested transactions;
- immediately invoked closures;
- deferred nested closures that must not be treated as executed;
- function-scope isolation for manual transaction state;
- unclosed manual transactions.

## Jobs and queues

- regular queued jobs;
- `ShouldQueueAfterCommit`;
- inherited `ShouldQueueAfterCommit`;
- same short job names in different namespaces with different queue contracts;
- constructor `afterCommit()` and `beforeCommit()`;
- statement-level `afterCommit()` / `beforeCommit()`;
- default queue `after_commit` configuration;
- explicit `onConnection()` safe/unsafe overrides;
- constructor `onConnection()` safe/unsafe overrides;
- dynamic connection names;
- `dispatchSync()` and `dispatch_sync()`;
- after-response dispatch;
- global `dispatch(new Job)`;
- Bus dispatch, sync dispatch, chains, and batches;
- direct Queue push/later variants;
- Laravel 13 `Queue::route()` exact class routes;
- parent/interface routes;
- current Laravel 13 route-array connection-first runtime semantics;
- parent/interface and recursive trait routes;
- `Queue::forward()` with queue attributes / constructor queue names;
- queued closures, pending chains, raw queue pushes and explicit connection precedence over route configuration;
- cyclic malformed metadata graphs terminate safely instead of recursing indefinitely.

## Events, mail, notifications, broadcasts

- event helper and Event facade;
- event class static dispatch;
- `ShouldDispatchAfterCommit`;
- synchronous and queued mail;
- queued mailable `afterCommit()`;
- queued notifications and synchronous `notifyNow` / `sendNow`;
- queued and immediate broadcasts.

## External/non-SQL side effects

- mutating HTTP methods;
- optional strict read-only HTTP detection;
- pooled/batched HTTP work;
- Laravel filesystem mutations including streams/directory operations;
- native filesystem mutations;
- modern cache writes/invalidation (`putMany`, `remember*`, `flexible`, etc.);
- all finite catalogued cache and RateLimiter mutations plus representative read-only controls;
- Redis mutations, commands, increments, publishes, pipelines/transactions;
- all finite catalogued Redis mutations/read/script classifications;
- modern Redis write commands such as `DELEX`, `HGETDEL`, `HSETEX`, `XDELEX` and `XACKDEL`;
- known Redis read-only methods that should remain clean;
- unknown methods on proven Redis receivers reported conservatively rather than silently ignored;
- Redis `GETEX` raw/Predis positional forms and Laravel-default PhpRedis options-array forms;
- read-only `GETEX` versus expiry-changing `EX`, `PX`, `EXAT`, `PXAT` and `PERSIST` behavior;
- external processes/shell execution;
- Laravel concurrency/deferred execution;
- locally assigned HTTP/filesystem/cache/Redis/process/database handles;
- Eloquent cross-connection writes with statically known model connections;
- configurable project-specific gateway patterns.

## Retried transactions

- literal retry attempts greater than one;
- named `attempts:` argument;
- dynamic retry counts;
- one-attempt control case;
- side effects in nested transactions under a retryable outer transaction;
- duplicate-risk pairing with HTTP/Redis/other effects.

## SQL/DDL boundary hazards

- schema operations;
- DDL in `statement()` / `unprepared()` paths;
- implicit-commit hazards;
- ordinary DML controls that should not be flagged.

## False-positive controls

- commented-out side effects;
- side-effect text inside strings;
- side effects outside transactions;
- facade-like application classes shadowing Laravel facade aliases in another namespace;
- `DB::afterCommit()` callbacks;
- inline rule suppression;
- next-line rule suppression;
- suppression isolation;
- disabled rules;
- baseline filtering;
- read-only cache and Redis controls;
- resolved transaction callbacks that must not emit `TG014`;
- normal after-commit jobs that must not emit `TG022`/`TG023`;
- non-matching custom side-effect patterns;
- unreadable files and parse errors.

The dependency-free smoke runner executes the same scanner pipeline as the Pest suite:

```bash
php tools/smoke.php
```

The fast benchmark bootstrap smoke is part of `composer check`, while the full informational benchmark remains available as:

```bash
composer benchmark
```

Pest/Testbench additionally tests command registration, exit codes, output modes, recursive discovery, excludes, baseline persistence, cross-file metadata, namespace-context isolation, operation-catalog completeness, analyzer diagnostics and Redis classification refinements.
