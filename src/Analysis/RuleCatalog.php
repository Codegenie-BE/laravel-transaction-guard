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
        'TG100' => ['title' => 'Configured custom side effect', 'description' => 'A configured project-specific side effect runs inside a transaction.'],
        'TG900' => ['title' => 'Unreadable source file', 'description' => 'The analyzer could not read a requested PHP source file.'],
        'TG901' => ['title' => 'PHP parse failure', 'description' => 'The analyzer could not parse a requested PHP source file.'],
        'TG902' => ['title' => 'Analyzer regular-expression failure', 'description' => 'A scanner regular expression failed at analysis time and results may be incomplete.'],
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
        return in_array(strtoupper($rule), ['TG900', 'TG901', 'TG902'], true);
    }

    /** @return array{title:string,description:string} */
    public static function definition(string $rule): array
    {
        $rule = strtoupper($rule);
        if (! isset(self::RULES[$rule])) {
            throw new \InvalidArgumentException("Unknown Transaction Guard rule [{$rule}].");
        }

        return self::RULES[$rule];
    }

    public static function helpUri(string $rule): string
    {
        return 'https://github.com/Codegenie-BE/laravel-transaction-guard/blob/main/docs/RULES.md#'.strtolower($rule);
    }
}
