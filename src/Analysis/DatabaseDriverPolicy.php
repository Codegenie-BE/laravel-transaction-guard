<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final class DatabaseDriverPolicy
{
    /** @return array{severity:Severity,semantics:string} */
    public static function ddl(?string $driver): array
    {
        if ($driver === null || $driver === '') {
            return ['severity' => Severity::Critical, 'semantics' => 'unknown'];
        }

        return match (strtolower($driver)) {
            'mysql', 'mariadb' => ['severity' => Severity::Critical, 'semantics' => 'implicit-commit'],
            'pgsql' => ['severity' => Severity::Warning, 'semantics' => 'transactional-ddl'],
            'sqlite' => ['severity' => Severity::Warning, 'semantics' => 'transactional-ddl-with-limitations'],
            'sqlsrv' => ['severity' => Severity::Warning, 'semantics' => 'mostly-transactional-ddl'],
            default => ['severity' => Severity::Warning, 'semantics' => 'driver-specific'],
        };
    }
}
