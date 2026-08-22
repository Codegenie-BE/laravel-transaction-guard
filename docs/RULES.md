# Rule reference

Transaction Guard models one invariant:

> A database transaction should not cause an irreversible or externally visible effect before the database state it depends on has successfully committed.

## TG001 — queued work before commit

Detects Laravel job dispatch, Bus dispatch, chains/batches and direct queue pushes that cannot be proven commit-safe.

Preferred fixes, in order of local clarity:

1. move dispatch after `DB::transaction(...)`;
2. chain `->afterCommit()`;
3. implement `ShouldQueueAfterCommit` when the job should always behave this way;
4. enable `after_commit` for the queue connection when that is the application-wide policy;
5. use `DB::afterCommit()` for explicit orchestration.

`->beforeCommit()` is an intentional unsafe override and is reported separately by `TG010`.

## TG002 — event before commit

Synchronous event listeners execute immediately. If they observe data mutated in the current transaction or produce side effects, rollback cannot undo them.

Use `ShouldDispatchAfterCommit` on the event when the event itself semantically only exists after a successful commit. `Event::defer()` only delays events until its closure finishes; when called inside a transaction callback, that can still be before the database commit.

## TG003 — mail before commit

A sent email is irreversible. Queued mail may also be consumed before commit unless Laravel is instructed to wait.

For queued mailables use `afterCommit()` or an `after_commit` queue connection. For synchronous mail, move the send after commit.

## TG004 — notification before commit

Notifications may be synchronous or queued. A synchronous channel escapes rollback immediately; a queued notification needs an after-commit policy.

## TG005 — broadcast before commit

Queued broadcasts can race the commit. `ShouldBroadcastNow` is synchronous and therefore remains unsafe even when the default queue connection is configured with `after_commit => true`. A direct `broadcast(...)` / `Event::broadcast()` call does not become commit-safe merely because the event implements `ShouldDispatchAfterCommit`; Laravel's broadcast manager queues a `BroadcastEvent` directly. An explicit event `afterCommit` value or a queue connection with `after_commit => true` is recognized.

## TG006 — outbound HTTP inside transaction

POST, PUT, PATCH, DELETE and generic mutating client requests are considered non-transactional external effects. GET and HEAD are ignored by default because they are read-only by convention; enable `detect_read_http_calls` for strict I/O isolation.

For business-critical remote writes, consider an idempotency key and/or transactional outbox rather than keeping a database transaction open across network I/O.

## TG007 — filesystem mutation

Database rollback cannot restore a deleted/moved file or automatically remove a newly written object. Prefer post-commit writes or explicit compensation.

## TG008 — cache mutation

Changing cache before commit can expose state the database later rolls back. Cache invalidation is normally safest after commit.

## TG009 — external process

`Process::run()`, `exec()` and related APIs can create irreversible effects and also extend transaction lock duration.

## TG010 — explicit beforeCommit

`beforeCommit()` overrides Laravel's after-commit behavior. Transaction Guard reports the override even if the queue connection is globally configured to dispatch after commit.

## TG011 — retry duplicate risk

`DB::transaction($callback, attempts: N)` may re-run the callback after a deadlock. A non-transactional effect inside the callback can therefore execute more than once even if the database write ultimately commits only once.

This is critical because it can mean duplicate charges, duplicate messages, duplicate files, or repeated external commands.

## TG012 — implicit commit / DDL

Some database statements may implicitly commit. Schema/DDL inside application transactions can therefore break the assumption that Laravel still controls the transaction boundary.

Schema changes belong in migrations or an explicit schema-management flow, not inside normal application transactions.

## TG013 — unclosed manual transaction

Reports a lexical `beginTransaction()` without a matching `commit()` / `rollBack()` in the scanned source flow. This is medium-confidence because complex control flow may require human review.

Prefer `DB::transaction()` where possible.

## TG014 — unresolved transaction callback

Reports an informational, low-confidence diagnostic when a `DB::transaction(...)` callback cannot be resolved as an inline closure or a simple local closure variable. The guard does not pretend that an unanalyzed callback is proven safe.

Prefer an inline closure or simple local closure variable when practical; otherwise review the callback manually. The default `warning` failure threshold does not fail CI on this informational diagnostic.

## TG016 — synchronous job dispatch

Synchronous jobs run immediately inside the current process while the transaction is open. If that job performs external effects or expects committed state, it is unsafe.

## TG017 — after-response is not after-commit

Deferring work until after the HTTP response is a lifecycle boundary, not a database-commit guarantee. Use an explicit after-commit mechanism when correctness depends on committed data.

## TG018 — concurrency/deferred work

Laravel concurrency starts child work or defers it outside the current transaction semantics. Child processes cannot be assumed to observe the parent connection's uncommitted state.

## TG100 — project custom side effect

Use custom patterns for domain integrations Transaction Guard cannot infer safely, such as payment capture or SMS gateways.

## TG020 — Redis mutation inside transaction

Direct Redis writes, publishes, pipelines and Redis transactions are not part of the SQL database transaction. A later SQL rollback cannot undo Redis state, and Laravel deadlock retries may execute the Redis mutation more than once.

Move the mutation to `DB::afterCommit()`, perform it after `DB::transaction(...)`, or use an idempotent/outbox strategy when cross-system delivery matters. Read-only Redis commands are intentionally ignored.


## TG021 — database write on another connection

Laravel database transactions are connection-scoped. Transaction Guard reports statically known writes that use a different database connection from the surrounding `DB::transaction()` / manual transaction. A rollback on one connection cannot roll back the other connection.

Dynamic connection expressions are intentionally not guessed. When a multi-database workflow is intentional, coordinate it explicitly (for example with an outbox/saga/compensation strategy) rather than assuming cross-connection atomicity.
