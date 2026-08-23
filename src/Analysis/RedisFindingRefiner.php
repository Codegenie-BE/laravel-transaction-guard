<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final class RedisFindingRefiner
{
    public static function refine(Finding $finding): ?Finding
    {
        if ($finding->rule !== 'TG020' || stripos($finding->snippet, 'getex') === false) {
            return $finding;
        }

        if (self::containsOtherRedisMutation($finding->snippet)) {
            return $finding;
        }

        [$mutates, $unknown] = RedisOperationClassifier::getexMutationState($finding->snippet);
        if ($mutates) {
            return $finding;
        }
        if (! $unknown) {
            return null;
        }

        return new Finding(
            rule: $finding->rule,
            severity: Severity::Warning,
            message: 'Redis GETEX cannot be proven read-only while a database transaction is open.',
            file: $finding->file,
            line: $finding->line,
            snippet: $finding->snippet,
            remediation: 'Make the GETEX expiry modifier statically visible, or perform expiry-changing Redis work after commit.',
            confidence: 'medium',
            context: $finding->context,
            column: $finding->column,
            endColumn: $finding->endColumn,
            projectRoot: $finding->projectRoot,
        );
    }

    private static function containsOtherRedisMutation(string $snippet): bool
    {
        foreach (OperationCatalog::REDIS_MUTATIONS as $method) {
            if (strcasecmp($method, 'getex') === 0) {
                continue;
            }

            if (preg_match('/(?:->|::)\s*'.preg_quote($method, '/').'\s*\(/i', $snippet) === 1) {
                return true;
            }
        }

        if (preg_match_all('/\bcommand\s*\(\s*[\'\"](?<command>[A-Za-z0-9_]+)[\'\"]/i', $snippet, $commands, PREG_SET_ORDER) > 0) {
            foreach ($commands as $command) {
                $name = strtoupper($command['command']);
                if ($name !== 'GETEX' && OperationCatalog::redisCommandKind($name) !== 'read') {
                    return true;
                }
            }
        }

        return false;
    }
}
