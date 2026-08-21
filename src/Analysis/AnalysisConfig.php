<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final readonly class AnalysisConfig
{
    /**
     * @param array<string, bool> $queueAfterCommitByConnection
     * @param list<string> $customSideEffectPatterns
     * @param list<string> $disabledRules
     */
    public function __construct(
        public string $defaultQueueConnection = 'sync',
        public array $queueAfterCommitByConnection = [],
        public array $customSideEffectPatterns = [],
        public array $disabledRules = [],
        public bool $detectReadHttpCalls = false,
    ) {
        foreach ($this->customSideEffectPatterns as $pattern) {
            if (@preg_match($pattern, '') === false) {
                throw new \InvalidArgumentException("Invalid custom side-effect regular expression [{$pattern}].");
            }
        }
    }

    public function ruleEnabled(string $rule): bool
    {
        return ! in_array($rule, $this->disabledRules, true);
    }

    public function queueDispatchesAfterCommit(?string $connection = null): bool
    {
        $connection ??= $this->defaultQueueConnection;

        return $this->queueAfterCommitByConnection[$connection] ?? false;
    }
}
