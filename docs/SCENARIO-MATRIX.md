# Scenario and regression matrix

The executable source of truth is [`tests/Support/ScenarioMatrix.php`](../tests/Support/ScenarioMatrix.php). Every case contains syntactically valid PHP that is parsed but never executed.

The initial release matrix covers more than one hundred transaction-safety scenarios across these groups:

## Transaction boundaries

- `DB::transaction()` callback syntax;
- arrow functions;
- `DB::connection(...)->transaction()`;
- facade aliases;
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
- queued closures, pending chains, raw queue pushes and explicit connection precedence over route configuration.

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
- Redis read-only calls that should remain clean;
- external processes/shell execution;
- Laravel concurrency/deferred execution;
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
- `DB::afterCommit()` callbacks;
- inline rule suppression;
- next-line rule suppression;
- suppression isolation;
- disabled rules;
- baseline filtering;
- unreadable files and parse errors.

The dependency-free smoke runner executes the same matrix as the Pest suite:

```bash
php tools/smoke.php
```

Pest/Testbench additionally tests command registration, exit codes, output modes, recursive discovery, excludes, baseline persistence, and cross-file metadata.
