<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final class RuleCatalog
{
    /** @var array<string, array{title:string,description:string}> */
    private const RULES = [
        'TG001' => ['title' => 'Queued work before commit', 'description' => 'Queued work may escape the surrounding database transaction before commit.'],
        'TG002' => ['title' => 'Event before commit', 'description' => 'An event may execute listeners before the surrounding transaction commits.'],
        'TG003' => ['title' => 'Mail before commit', 'description' => 'Mail may be sent or queued before the surrounding transaction commits.'],
        'TG004' => ['title' => 'Notification before commit', 'description' => 'A notification may be delivered before the surrounding transaction commits.'],
        'TG005' => ['title' => 'Broadcast before commit', 'description' => 'A broadcast may run before the surrounding transaction commits.'],
        'TG006' => ['title' => 'Outbound HTTP inside transaction', 'description' => 'External HTTP I/O is executed while a database transaction is open.'],
        'TG007' => ['title' => 'Filesystem mutation inside transaction', 'description' => 'Filesystem state is mutated while a database transaction is open.'],
        'TG008' => ['title' => 'Cache mutation inside transaction', 'description' => 'Cache state is mutated while a database transaction is open.'],
        'TG009' => ['title' => 'External process inside transaction', 'description' => 'An external process is started while a database transaction is open.'],
        'TG010' => ['title' => 'Explicit beforeCommit override', 'description' => 'beforeCommit() explicitly opts out of commit-aware dispatch.'],
        'TG011' => ['title' => 'Retry duplicate risk', 'description' => 'A non-transactional effect may run more than once when a transaction retries.'],
        'TG012' => ['title' => 'Implicit commit / DDL risk', 'description' => 'DDL or driver-specific statements may break transaction semantics.'],
        'TG013' => ['title' => 'Unclosed manual transaction', 'description' => 'A manually started transaction is not closed on every statically visible path.'],
        'TG014' => ['title' => 'Unresolved transaction callback', 'description' => 'The transaction callback could not be resolved statically.'],
        'TG016' => ['title' => 'Synchronous job dispatch', 'description' => 'A job executes synchronously while the transaction is still open.'],
        'TG017' => ['title' => 'After-response is not after-commit', 'description' => 'Response deferral is not proof of a successful database commit.'],
        'TG018' => ['title' => 'Concurrency/deferred work', 'description' => 'Concurrent or deferred work is outside the current transaction boundary.'],
        'TG020' => ['title' => 'Redis mutation inside transaction', 'description' => 'Redis state is mutated while the SQL database transaction is open.'],
        'TG021' => ['title' => 'Cross-connection database write', 'description' => 'A database write uses a different connection from the active transaction.'],
        'TG022' => ['title' => 'Pre-dispatch hook before commit', 'description' => 'A PreparesForDispatch hook executes synchronously before commit-aware queue dispatch can defer the job.'],
        'TG023' => ['title' => 'Queue cache lock before commit', 'description' => 'PendingDispatch may acquire unique/debounce cache state before the surrounding database transaction commits.'],
        'TG100' => ['title' => 'Configured custom side effect', 'description' => 'A configured project-specific side effect runs inside a transaction.'],
        'TG900' => ['title' => 'Unreadable source file', 'description' => 'The analyzer could not read a requested PHP source file.'],
        'TG901' => ['title' => 'PHP parse failure', 'description' => 'The analyzer could not parse a requested PHP source file.'],
        'TG902' => ['title' => 'Analyzer regular-expression failure', 'description' => 'A scanner regular expression failed at analysis time and results may be incomplete.'],
        'TG903' => ['title' => 'Source traversal failure', 'description' => 'The analyzer could not traverse part of a requested source tree.'],
    ];

    /** @var array<string, array{severity:string,category:string,remediation:string}> */
    private const DEFAULTS = [
        'TG001' => ['severity' => 'error / warning', 'category' => 'queue', 'remediation' => 'Use after-commit dispatch or move the dispatch after the transaction.'],
        'TG002' => ['severity' => 'warning', 'category' => 'events', 'remediation' => 'Dispatch after commit or implement ShouldDispatchAfterCommit.'],
        'TG003' => ['severity' => 'error', 'category' => 'mail', 'remediation' => 'Send or queue mail after commit.'],
        'TG004' => ['severity' => 'error', 'category' => 'notifications', 'remediation' => 'Deliver notifications after commit.'],
        'TG005' => ['severity' => 'error', 'category' => 'broadcasting', 'remediation' => 'Broadcast after commit.'],
        'TG006' => ['severity' => 'error / warning', 'category' => 'external-io', 'remediation' => 'Perform outbound HTTP after commit.'],
        'TG007' => ['severity' => 'warning', 'category' => 'filesystem', 'remediation' => 'Move filesystem mutations after commit or compensate them.'],
        'TG008' => ['severity' => 'warning', 'category' => 'cache', 'remediation' => 'Mutate cache and cache locks after commit.'],
        'TG009' => ['severity' => 'error', 'category' => 'process', 'remediation' => 'Run external processes after commit.'],
        'TG010' => ['severity' => 'error', 'category' => 'queue', 'remediation' => 'Remove beforeCommit() unless pre-commit dispatch is intentional.'],
        'TG011' => ['severity' => 'warning / critical', 'category' => 'retries', 'remediation' => 'Keep retryable transaction callbacks free of irreversible effects.'],
        'TG012' => ['severity' => 'critical / warning', 'category' => 'database', 'remediation' => 'Keep DDL and implicit-commit statements outside application transactions.'],
        'TG013' => ['severity' => 'critical', 'category' => 'database', 'remediation' => 'Close manual transactions on every path or use DB::transaction().'],
        'TG014' => ['severity' => 'info', 'category' => 'analysis', 'remediation' => 'Use an analyzable transaction callback or enable strict unresolved-callback CI.'],
        'TG016' => ['severity' => 'warning', 'category' => 'queue', 'remediation' => 'Move synchronous dispatch outside the transaction.'],
        'TG017' => ['severity' => 'warning', 'category' => 'queue', 'remediation' => 'Use afterCommit() instead of after-response timing.'],
        'TG018' => ['severity' => 'warning', 'category' => 'concurrency', 'remediation' => 'Start concurrent/deferred work after commit.'],
        'TG020' => ['severity' => 'warning / error', 'category' => 'redis', 'remediation' => 'Move Redis mutations after commit.'],
        'TG021' => ['severity' => 'error', 'category' => 'database', 'remediation' => 'Use the transaction connection for all atomic writes.'],
        'TG022' => ['severity' => 'warning', 'category' => 'queue', 'remediation' => 'Keep prepareForDispatch() side-effect free or dispatch after commit.'],
        'TG023' => ['severity' => 'warning', 'category' => 'queue', 'remediation' => 'Create unique/debounce PendingDispatch jobs after commit when pre-commit cache state is unacceptable.'],
        'TG100' => ['severity' => 'warning', 'category' => 'custom', 'remediation' => 'Move the configured side effect after commit.'],
        'TG900' => ['severity' => 'error', 'category' => 'diagnostic', 'remediation' => 'Fix file readability.'],
        'TG901' => ['severity' => 'error', 'category' => 'diagnostic', 'remediation' => 'Fix PHP syntax before analysis.'],
        'TG902' => ['severity' => 'error', 'category' => 'diagnostic', 'remediation' => 'Report the analyzer regex failure.'],
        'TG903' => ['severity' => 'error', 'category' => 'diagnostic', 'remediation' => 'Fix source-tree traversal permissions or exclusions.'],
    ];

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::RULES);
    }

    public static function exists(string $rule): bool
    {
        return isset(self::RULES[strtoupper($rule)]);
    }

    public static function isDiagnostic(string $rule): bool
    {
        return in_array(strtoupper($rule), ['TG900', 'TG901', 'TG902', 'TG903'], true);
    }

    /** @return array{title:string,description:string,severity:string,category:string,remediation:string} */
    public static function definition(string $rule): array
    {
        $rule = strtoupper($rule);
        if (! isset(self::RULES[$rule])) {
            throw new \InvalidArgumentException("Unknown Transaction Guard rule [{$rule}].");
        }

        return [...self::RULES[$rule], ...self::DEFAULTS[$rule]];
    }

    public static function helpUri(string $rule): string
    {
        return 'https://github.com/Codegenie-BE/laravel-transaction-guard/blob/main/docs/RULES.md#'.strtolower($rule);
    }
}
