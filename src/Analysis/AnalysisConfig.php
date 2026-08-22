<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final readonly class AnalysisConfig
{
    /** @var array<string, true> */
    private array $disabledRuleLookup;

    /**
     * @param  array<string, bool>  $queueAfterCommitByConnection
     * @param  list<string>  $customSideEffectPatterns
     * @param  list<string>  $disabledRules
     * @param  array<string, string>  $databaseDriverByConnection
     */
    public function __construct(
        public string $defaultQueueConnection = 'sync',
        public array $queueAfterCommitByConnection = [],
        public array $customSideEffectPatterns = [],
        public array $disabledRules = [],
        public bool $detectReadHttpCalls = false,
        public string $defaultDatabaseConnection = '@default',
        public array $databaseDriverByConnection = [],
    ) {
        $this->disabledRuleLookup = array_fill_keys($this->disabledRules, true);

        foreach ($this->customSideEffectPatterns as $pattern) {
            $regex = str_starts_with($pattern, '/') ? $pattern : '/'.str_replace('/', '\\/', $pattern).'/';
            set_error_handler(static fn (): bool => true);
            try {
                $valid = preg_match($regex, '') !== false;
            } finally {
                restore_error_handler();
            }

            if (! $valid) {
                throw new \InvalidArgumentException("Invalid custom side-effect regular expression [{$pattern}].");
            }
        }
    }

    public function ruleEnabled(string $rule): bool
    {
        return ! isset($this->disabledRuleLookup[$rule]);
    }

    public function queueDispatchesAfterCommit(?string $connection = null): bool
    {
        $connection ??= $this->defaultQueueConnection;

        return $this->queueAfterCommitByConnection[$connection] ?? false;
    }

    public function databaseDriver(?string $connection = null): ?string
    {
        $connection ??= $this->defaultDatabaseConnection;

        return $this->databaseDriverByConnection[$connection] ?? null;
    }
}
