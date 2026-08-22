<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final readonly class AnalysisConfig
{
    /** @var array<string, true> */
    private array $disabledRuleLookup;

    /** @var list<string> */
    private array $compiledCustomSideEffectPatterns;

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
        public bool $allowEmptyScan = false,
        public bool $failOnUnresolvedTransaction = false,
        public string $projectRoot = '',
    ) {
        $normalizedDisabled = [];
        foreach ($this->disabledRules as $rule) {
            $rule = strtoupper(trim($rule));
            if (! RuleCatalog::exists($rule)) {
                throw new \InvalidArgumentException("Unknown Transaction Guard rule [{$rule}] in disabled_rules.");
            }
            if (RuleCatalog::isDiagnostic($rule)) {
                throw new \InvalidArgumentException("Analyzer diagnostic [{$rule}] cannot be disabled.");
            }
            $normalizedDisabled[$rule] = true;
        }
        $this->disabledRuleLookup = $normalizedDisabled;
        $compiledCustomSideEffectPatterns = [];

        foreach ($this->customSideEffectPatterns as $pattern) {
            $regex = $this->compileCustomRegex($pattern);
            set_error_handler(static fn (): bool => true);
            try {
                $valid = preg_match($regex, '') !== false;
            } finally {
                restore_error_handler();
            }

            if (! $valid) {
                throw new \InvalidArgumentException("Invalid custom side-effect regular expression [{$pattern}].");
            }
            $compiledCustomSideEffectPatterns[] = $regex;
        }

        $this->compiledCustomSideEffectPatterns = $compiledCustomSideEffectPatterns;
    }

    private function compileCustomRegex(string $pattern): string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            throw new \InvalidArgumentException('Custom side-effect regular expressions cannot be empty.');
        }

        $first = $pattern[0];
        if (! ctype_alnum($first) && ! ctype_space($first) && $first !== '\\') {
            $last = strrpos($pattern, $first);
            if ($last !== false && $last > 0 && preg_match('/^[imsxuADUJu]*$/', substr($pattern, $last + 1)) === 1) {
                return $pattern;
            }
        }

        foreach (['~', '#', '%', '!', '@', ';'] as $delimiter) {
            if (! str_contains($pattern, $delimiter)) {
                return $delimiter.$pattern.$delimiter;
            }
        }

        return '/'.str_replace('/', '\/', $pattern).'/';
    }

    /** @return list<string> */
    public function customRegexes(): array
    {
        return $this->compiledCustomSideEffectPatterns;
    }

    public function ruleEnabled(string $rule): bool
    {
        return RuleCatalog::isDiagnostic($rule) || ! isset($this->disabledRuleLookup[strtoupper($rule)]);
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
