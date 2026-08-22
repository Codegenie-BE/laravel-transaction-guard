# Laravel Transaction Guard

`codegenie-be/laravel-transaction-guard` statically detects side effects that can escape a Laravel database transaction before the transaction successfully commits.

A database rollback can undo database writes. It cannot unsend an email, undo an HTTP request, put a deleted file back, reverse an already-started process, or stop a queue worker that saw data before commit. Transaction Guard is designed to catch those boundaries in development and CI.

Built and maintained by [Codegenie](https://www.codegenie.be). Open source under the MIT license.

## What it detects

Transaction Guard understands both `DB::transaction(...)` and manual `beginTransaction()` / `commit()` / `rollBack()` flows. It reports:

- queued jobs without a proven after-commit strategy;
- synchronous jobs and `dispatch_sync()` inside a transaction;
- events without `ShouldDispatchAfterCommit` and `Event::defer()` used as if it were a commit boundary;
- mail and notifications that may escape a rollback;
- broadcasts that are not commit-safe;
- mutating outbound HTTP requests;
- filesystem mutations;
- cache mutations;
- direct Redis mutations / publishes;
- external processes;
- Laravel `Concurrency::run()`, `Concurrency::defer()` and `defer()` inside a transaction;
- `beforeCommit()` overrides;
- retryable transactions that may duplicate irreversible side effects after deadlock retries;
- schema / DDL operations that may implicitly commit;
- obviously unclosed manual transactions;
- project-specific side effects through configurable regex patterns.

It also recognizes common safe patterns such as:

- `->afterCommit()`;
- jobs implementing `ShouldQueueAfterCommit`;
- events implementing `ShouldDispatchAfterCommit`;
- queued mailables / notifications configured with `afterCommit()`;
- queue connections with `after_commit => true`;
- Laravel 13 `Queue::route()` class/parent/interface/trait routing, `Queue::forward()` and statically resolvable queue attributes;
- `DB::afterCommit(...)` callbacks;
- moving the side effect after a manual `commit()` or `rollBack()`.

## Installation

```bash
composer require --dev codegenie-be/laravel-transaction-guard
```

The service provider is auto-discovered by Laravel.

## Usage

```bash
php artisan transaction:guard
```

By default the package scans `app/` and `routes/`.

Useful CI modes:

```bash
php artisan transaction:guard --format=github
php artisan transaction:guard --format=json
php artisan transaction:guard --format=sarif
php artisan transaction:guard --fail-on=error
```

Scan explicit paths:

```bash
php artisan transaction:guard app/Domain app/Http routes/api.php
```

Exit codes are non-zero when findings at or above `fail_on` exist. The default threshold is `warning`.

## Baseline for existing projects

Adopt the guard without fixing every legacy finding immediately:

```bash
php artisan transaction:guard --generate-baseline
```

This creates `.transaction-guard-baseline.json`. Existing fingerprints are suppressed; newly introduced findings still fail CI.

Ignore the baseline temporarily:

```bash
php artisan transaction:guard --no-baseline
```

## Local suppressions

Prefer fixing the transaction boundary. If a specific finding is intentionally safe and reviewed, suppress only the exact rule:

```php
// transaction-guard-ignore-next-line TG006
Http::post($url, $payload);
```

or:

```php
Http::post($url, $payload); // transaction-guard-ignore TG006
```

A suppression without rule IDs suppresses all Transaction Guard findings on that line and should therefore be used sparingly.

## Custom side effects

Publish the config:

```bash
php artisan vendor:publish --tag=transaction-guard-config
```

Then add project-specific patterns:

```php
'custom_side_effect_patterns' => [
    '/StripeGateway::capture\\s*\\(/',
    '/SmsGateway::send\\s*\\(/',
],
```

They are reported as `TG100` while executed inside a detected transaction.

## Rules

| Rule | Default severity | Purpose |
| --- | --- | --- |
| `TG001` | error / warning | Job, Bus, queue push before commit |
| `TG002` | warning | Event before commit |
| `TG003` | error | Mail before commit |
| `TG004` | error | Notification before commit |
| `TG005` | error | Broadcast before commit |
| `TG006` | error / warning | Outbound HTTP inside transaction |
| `TG007` | warning | Filesystem mutation inside transaction |
| `TG008` | warning | Cache mutation inside transaction |
| `TG009` | error | External process inside transaction |
| `TG010` | error | Explicit `beforeCommit()` override |
| `TG011` | warning / critical | Side effect can repeat during transaction deadlock retries |
| `TG012` | critical | DDL / implicit commit risk |
| `TG013` | critical | Unclosed manual transaction |
| `TG014` | info | Transaction callback could not be resolved statically |
| `TG016` | warning | Synchronous job dispatch inside transaction |
| `TG017` | warning | After-response dispatch mistaken for commit safety |
| `TG018` | warning | Concurrent/deferred execution inside transaction |
| `TG020` | warning / error | Redis mutation or publish inside transaction |
| `TG021` | error | Database/Eloquent write on another connection |
| `TG100` | warning | Configured custom side effect |
| `TG900` | error | Unreadable source file |
| `TG901` | error | PHP parse failure |

See [`docs/RULES.md`](docs/RULES.md) for rule details and remediation guidance. The deeper failure model and remediation decision table are in [`docs/ANALYSIS.md`](docs/ANALYSIS.md); regression coverage is documented in [`docs/SCENARIO-MATRIX.md`](docs/SCENARIO-MATRIX.md).

## Why no runtime hooks?

Transaction Guard does not monkey-patch `DB`, queues, mail, events, or HTTP. It does not change production behavior. The analyzer uses PHP's native tokenizer, so it adds no parser dependency to production or development runtime beyond `ext-tokenizer`.

This is deliberate: transaction safety is a design decision. Automatically delaying an arbitrary side effect can change semantics or hide an architectural bug.

## Static-analysis limits

The analyzer is intentionally conservative and Laravel-focused. It does not execute PHP and therefore cannot prove every dynamic call graph. In particular:

- side effects hidden behind arbitrary application service methods require a custom pattern;
- dynamically chosen queue/database connection names cannot always be resolved;
- runtime queue reconfiguration, dynamic attributes/enums and arbitrary container bindings remain conservative;
- side effects hidden in arbitrary event listeners or Eloquent observers require explicit post-commit contracts or project-specific patterns;
- local closure variables and simple local Laravel handles are resolved, but the package intentionally does not build a general PHP call graph;
- highly branch-dependent manual transaction flows may require review;
- third-party SDK calls are not guessed automatically;
- nested closures that are merely defined inside a transaction are ignored unless immediately invoked.

When the analyzer cannot prove a queued job's metadata, it prefers a medium-confidence warning over pretending certainty.

## Compatibility target

- PHP 8.2+
- Laravel 12 and 13

Laravel 13 requires a PHP version supported by Laravel itself. CI is designed to test supported Laravel/PHP combinations rather than force unsupported pairs.

## Quality checks

```bash
composer check:all
composer test:coverage
```

A dependency-free regression suite is also included:

```bash
php tools/smoke.php
```

## Security

Please do not disclose security issues in a public GitHub issue. See [`SECURITY.md`](SECURITY.md).

## License

MIT. See [`LICENSE.md`](LICENSE.md).


### Analyzer efficiency

Transaction Guard performs no runtime instrumentation. Its tokenizer scanner pre-indexes each source file once, prunes excluded directories before traversal, and caches hot-path source lookups. For local profiling of the analyzer itself, maintainers can run `composer benchmark`; the benchmark is informational and intentionally not a timing-based CI gate.
