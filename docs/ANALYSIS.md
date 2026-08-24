# Transaction-safety analysis

## Analyzer diagnostics are never suppressible by baselines

TG900/TG901/TG902 represent analyzer integrity failures, not accepted application debt. They are always reported, are never written to baselines, and keep a non-zero exit status even when `fail_on=never`.

## Problem model

A SQL transaction only provides atomicity for state managed by that database transaction. Laravel code can still cross a non-transactional boundary while the SQL transaction is open. That creates four distinct failure classes.

### 1. Pre-commit visibility race

A queued job, queued listener, queued mailable, notification, or broadcast may be consumed before the transaction commits. The consumer can observe a missing model, stale relationships, old status values, or an incomplete aggregate.

Commit-aware queue behavior (`after_commit`, `afterCommit()`, `ShouldQueueAfterCommit`) solves the ordering problem for supported queued work. It does not make an unrelated external system part of the SQL transaction.

### 2. Rollback leakage

Synchronous side effects cannot be rolled back with the database. Examples include:

- sending mail or a synchronous notification;
- firing an event whose synchronous listener performs I/O;
- making an HTTP write;
- writing/deleting files;
- mutating Redis or publishing a Redis message;
- mutating cache state;
- launching an external process.

If the database transaction later rolls back, the outside world can retain an effect for state that never committed.

### 3. Duplicate effects during transaction retries

Laravel can retry a transaction after a deadlock. The transaction callback can therefore execute more than once. An irreversible effect in that callback can also execute more than once even though only one SQL transaction finally commits.

Examples include duplicate payment capture, duplicate webhook delivery, duplicate email, repeated Redis increments, repeated file writes, or repeated shell commands.

### 4. Lock amplification and latency coupling

Network calls, processes, filesystem I/O, and other slow operations keep the transaction open longer. That increases lock duration, contention, deadlock probability, and request latency. Even a read-only remote call can therefore be architecturally undesirable while a transaction is open.

## Correctness patterns

| Situation | Preferred pattern |
| --- | --- |
| Queued work needs committed state | `afterCommit()`, `ShouldQueueAfterCommit`, or a deliberately configured `after_commit` queue connection |
| Event only semantically exists after commit | `ShouldDispatchAfterCommit` |
| Synchronous irreversible effect | Move after `DB::transaction(...)` or use `DB::afterCommit()` |
| Critical cross-system write | Transactional outbox + idempotent consumer/provider operation |
| External provider supports idempotency keys | Persist the operation intent in the SQL transaction and perform the provider call after commit with an idempotency key |
| Cache invalidation / Redis projection | Apply after commit |
| DDL/schema mutation | Migration or explicit schema-management flow, not normal application transaction logic |
| Deadlock-retryable transaction | Keep the callback limited to retry-safe work; never place unprotected irreversible effects inside it |

## Why `afterCommit()` is not a delivery guarantee

Post-commit execution fixes ordering and rollback leakage, but it does not make two systems atomic. A process can still crash after the database commits but before a job, webhook, or provider request is durably recorded elsewhere.

For business-critical delivery, use a transactional outbox or another durable intent record written in the same SQL transaction. Process that record idempotently after commit. Transaction Guard intentionally does not claim exactly-once delivery.

## Laravel lifecycle distinctions

These concepts must not be conflated:

- **after commit**: work waits for successful database commit;
- **after response**: work is deferred until the HTTP response lifecycle ends;
- **sync dispatch**: work runs immediately in the current process;
- **concurrency/defer**: execution timing/process boundaries change, but SQL commit ordering is not automatically guaranteed.

`afterResponse()` is therefore not accepted as proof of transaction safety.

## Manual and nested transactions

Manual `beginTransaction()` / `commit()` / `rollBack()` flows are harder to prove statically because branches and exception paths can differ. Transaction Guard scopes manual transaction state per function/closure and database connection and reports obvious unclosed transactions, while remaining conservative for branch-heavy control flow.

Nested `DB::transaction()` calls also inherit retry risk from a retryable outer transaction: if the outer callback is retried, a nested non-transactional side effect can repeat too.

## Laravel 13 queue routing

Laravel 13 can route jobs by exact class, parent class, interface, or trait. The guard resolves statically known `Queue::route()` targets across those relationships, `Queue::forward()` mappings, and queue metadata that can be proven from attributes or constructors. An explicit job connection continues to take precedence over a route.

There is an upstream documentation/runtime discrepancy for multi-class route arrays: the Laravel 13 documentation describes array values as `[queue, connection]`, while the current `QueueRoutes` implementation stores the provided array directly and reads element `0` as connection and element `1` as queue. Transaction Guard models the current framework runtime semantics, not the contradictory prose example, and keeps unresolved or enum/dynamic values conservative instead of inventing safety.

## Observer/event indirection

An Eloquent observer or arbitrary synchronous event listener can hide side effects outside the lexical transaction body. Full interprocedural call-graph analysis is outside the current scope. Laravel observers that require post-commit handling should use Laravel's post-commit observer contract; project-specific hidden gateways can be covered with `custom_side_effect_patterns` until deeper call-graph support exists.

## Analyzer safety properties

Transaction Guard itself:

- never executes analyzed application code;
- never opens a database connection;
- never modifies transaction behavior;
- never sends mail, HTTP requests, jobs, or events;
- performs read-only token/source analysis;
- uses stable finding fingerprints for baselines;
- fails conservatively when queue routing/connection selection cannot be proven.

This makes the analyzer suitable for local development, testing, staging, CI, production deployments, and custom Laravel environments. When installed on a production server, it remains an explicitly invoked CLI analysis tool and does not participate in normal HTTP request handling or modify application transaction semantics.

## v0.2 analyzer hardening

The analyzer hot path pre-indexes source lines and non-code token ranges, caches statement/facade lookups, uses binary-search token/line lookup, avoids temporary filter/sort allocations when selecting transaction/callable regions, and skips rule families early when their relevant API keywords are absent. Directory discovery prunes excluded directories before descending into them.

Laravel 13 queue metadata follows the runtime resolver more closely: exact classes, parents, expanded interfaces, recursive traits, route arrays, queue forwarding and `#[Queue]`/constructor queue names are modeled when statically resolvable. Raw queue pushes are treated separately because driver `pushRaw()` paths bypass Laravel's job-aware `enqueueUsing()` after-commit decision.

Manual transaction state carries its database connection. This both prevents a commit on one connection from lexically closing a transaction opened on another and enables high-confidence `TG021` cross-connection write findings.


## v0.2 bounded local data flow

The analyzer resolves simple local closure variables passed to `DB::transaction()`, simple local job/event/notification/broadcast payload assignments, and locally assigned Laravel HTTP/filesystem/cache/Redis/process/database handles. Any later unknown reassignment invalidates the inference. This deliberately increases signal without turning Transaction Guard into a general PHP call-graph engine.

Statically known Eloquent model connections, including Laravel 13 `#[Connection]`, participate in `TG021` cross-connection analysis. `TG012` also uses the configured database driver: MySQL/MariaDB implicit-commit hazards remain critical, while drivers with broadly transactional DDL remain visible as warnings rather than being mislabeled as identical MySQL semantics.


## Pre-dispatch lifecycle

Queue `after_commit` governs queue enqueue timing; it does not retroactively defer arbitrary work performed while constructing a `PendingDispatch`. Laravel 13 can call `prepareForDispatch()`, acquire `ShouldBeUnique` locks and acquire debounce locks before the dispatcher reaches queue after-commit handling. Transaction Guard therefore reports these pre-dispatch lifecycle effects independently from TG001.

Cache locks and `RateLimiter` operations are also external cache state. Redis pipeline/transaction callbacks are inspected when inline; known read-only callbacks are ignored, mutating callbacks are reported, and unresolved callbacks remain conservative.
