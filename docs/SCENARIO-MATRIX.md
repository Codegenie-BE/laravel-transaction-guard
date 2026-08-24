# Scenario and regression matrix

The executable source of truth is [`tests/Support/ScenarioMatrix.php`](../tests/Support/ScenarioMatrix.php). Every case contains syntactically valid PHP that is parsed but never executed.

The executable matrix is the source of truth for the exact scenario count. It is split into a core matrix plus focused hardening modules and covers these groups:

## Platform compatibility

The required CI gate validates Linux, Windows and macOS rather than assuming Linux filesystem semantics are portable.

- Linux runs the complete supported PHP 8.2-8.5 and Laravel 12/13 compatibility matrix;
- Windows runs both boundary stacks: PHP 8.2 / Laravel 12 and PHP 8.5 / Laravel 13;
- macOS runs both boundary stacks: PHP 8.2 / Laravel 12 and PHP 8.5 / Laravel 13;
- the Windows/macOS jobs run the complete `composer check:all` gate, including Composer validation/audit, optimized autoloading, Pint, PHPStan, documentation checks, the dependency-free scenario matrix, benchmark bootstrap smoke and Pest;
- native-filesystem regressions cover paths containing spaces, Windows/Unix separator-stable fingerprints, segment and wildcard excludes, replacement of an existing baseline and absolute Artisan scan paths.

All platform jobs are dependencies of `Tests / Required`, so a platform regression blocks merging.

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
- Redis mutations, commands, increments, publishes, pipelines/transactions;
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
- unreadable files and parse errors.

The dependency-free smoke runner executes the same scanner pipeline as the Pest suite:

```bash
php tools/smoke.php
```

The fast benchmark bootstrap smoke is part of `composer check`, while the full informational benchmark remains available as:

```bash
composer benchmark
```

Pest/Testbench additionally tests command registration, exit codes, output modes, recursive discovery, excludes, baseline persistence, cross-file metadata, namespace-context isolation and Redis classification refinements.
