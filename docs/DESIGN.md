# Design notes

## Goals

- Detect transaction-boundary mistakes before release and directly against deployed source when operational verification is useful.
- Stay read-only: analysis must never execute application code.
- Add no runtime hooks or infrastructure.
- Work in local development, testing, staging, production, shared hosting deployments, CI, and custom Laravel environments.
- Produce stable output suitable for baselines, deployment checks, and GitHub Actions annotations.
- Prefer high-signal Laravel-specific checks over a generic PHP linter.

## Environment model

Transaction Guard is environment-agnostic. Analyzer behavior is not conditioned on `APP_ENV` and the Artisan command may be invoked anywhere the package is installed and Laravel can boot its console application.

The package remains command-driven rather than request-driven. Its service provider registers Transaction Guard configuration, commands, and publishing only while Laravel is running in the console. Installing the package as a normal production Composer dependency therefore keeps regular HTTP request handling free from Transaction Guard analysis and package configuration loading.

A production scan has the same safety model as a local or CI scan: source files are tokenized read-only and analyzed application code is never executed. Large scans can still consume CPU and filesystem I/O, so operators should choose an appropriate deployment or maintenance window when server contention matters.

## Analyzer strategy

The package uses `token_get_all(..., TOKEN_PARSE)` plus bounded source-pattern recognition.

Why this design:

- no extra parser runtime dependency;
- PHP validates source syntax during tokenization;
- source offsets can be mapped to transaction/callback regions;
- Laravel idioms such as facade aliases and interfaces can be indexed without executing code;
- install footprint stays small.

The analyzer builds a lightweight class metadata index for imports, inheritance, implemented interfaces, constructor `afterCommit()` / `beforeCommit()` intent, literal job queue connections, Laravel 13 queue routing, pre-dispatch attributes and simple model-relation targets. `FileContextMap` keeps namespace/import resolution offset-aware for multi-namespace and bracketed-namespace files, while `SourceIndex` owns source-location/non-code lookups. The scanner then maps transaction regions and examines only effects that lexically execute inside those regions.

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

- Full interprocedural data-flow analysis. Local inference is deliberately conservative when multiple/conditional reaching assignments are visible.
- Executing or reflecting user code.
- Automatically rewriting business logic.
- Replacing PHPStan/Larastan.
- Replacing database migration safety tooling.
- Guaranteeing exactly-once delivery to third-party systems.

## Release boundaries

The analyzer intentionally stops short of a full PHP call-graph engine. It supports bounded local inference for closure variables, payload variables and common Laravel facade-derived handles, plus statically resolvable Laravel 13 queue routing/forwarding/attributes. Arbitrary observer/listener indirection, container bindings and runtime configuration mutation remain documented limitations rather than guessed behavior. Critical cross-system atomic delivery remains an application architecture concern; use a transactional outbox/idempotency where required.


## Static-analysis boundary

Transaction Guard deliberately does not pretend to resolve arbitrary macros, reflection, container bindings, dynamic database/queue connection expressions, or user-defined higher-order callbacks. It reports high-confidence framework semantics and leaves dynamic behavior to custom patterns or human review. This is preferable to converting uncertain regex guesses into release-blocking findings.


## Focused internal components

The tokenizer architecture remains intentionally dependency-light. `OperationCatalog` centralizes Laravel mutation APIs, `DatabaseDriverPolicy` owns driver-specific DDL classification, `StaticExpressionResolver` reduces bounded literal expressions, `MetadataAttributeResolver` resolves class attributes, and `ModelRelationExtractor` indexes simple Eloquent relation targets. These extractions keep framework-semantic tables out of the scanner hot path without introducing a general PHP AST/call-graph engine.
