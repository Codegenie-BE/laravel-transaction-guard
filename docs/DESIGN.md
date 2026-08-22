# Design notes

## Goals

- Detect transaction-boundary mistakes before production.
- Stay read-only: analysis must never execute application code.
- Add no runtime hooks or infrastructure.
- Work in local development, shared hosting deployments and CI.
- Produce stable output suitable for baselines and GitHub Actions annotations.
- Prefer high-signal Laravel-specific checks over a generic PHP linter.

## Analyzer strategy

The package uses `token_get_all(..., TOKEN_PARSE)` plus bounded source-pattern recognition.

Why this design:

- no extra parser runtime dependency;
- PHP validates source syntax during tokenization;
- source offsets can be mapped to transaction/callback regions;
- Laravel idioms such as facade aliases and interfaces can be indexed without executing code;
- install footprint stays small.

The analyzer builds a lightweight class metadata index for imports, inheritance, implemented interfaces, constructor `afterCommit()` / `beforeCommit()` intent, literal job queue connections, and literal Laravel 13 `Queue::route()` connection rules. It then maps transaction regions and examines only effects that lexically execute inside those regions.

## False-positive controls

- queue connection `after_commit` awareness;
- `afterCommit()` / `beforeCommit()` awareness;
- `ShouldQueueAfterCommit` / `ShouldDispatchAfterCommit` recognition;
- `DB::afterCommit()` region recognition;
- nested closure suppression unless immediately invoked;
- baselines;
- rule-specific inline suppressions;
- configurable disabled rules;
- medium-confidence classification where class metadata cannot be proven.

## Non-goals

- Full interprocedural data-flow analysis.
- Executing or reflecting user code.
- Automatically rewriting business logic.
- Replacing PHPStan/Larastan.
- Replacing database migration safety tooling.
- Guaranteeing exactly-once delivery to third-party systems.

## Release boundaries

The analyzer intentionally stops short of a full PHP call-graph engine. It supports bounded local inference for closure variables, payload variables and common Laravel facade-derived handles, plus statically resolvable Laravel 13 queue routing/forwarding/attributes. Arbitrary observer/listener indirection, container bindings and runtime configuration mutation remain documented limitations rather than guessed behavior. Critical cross-system atomic delivery remains an application architecture concern; use a transactional outbox/idempotency where required.


## Static-analysis boundary

Transaction Guard deliberately does not pretend to resolve arbitrary macros, reflection, container bindings, dynamic database/queue connection expressions, or user-defined higher-order callbacks. It reports high-confidence framework semantics and leaves dynamic behavior to custom patterns or human review. This is preferable to converting uncertain regex guesses into release-blocking findings.
