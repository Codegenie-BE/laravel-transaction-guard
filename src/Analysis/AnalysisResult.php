<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final readonly class AnalysisResult
{
    /**
     * @param  list<Finding>  $findings
     * @param  list<Finding>  $diagnostics
     */
    public function __construct(
        public array $findings,
        public int $filesAnalyzed,
        public array $diagnostics = [],
    ) {}

    /** @return list<Finding> */
    public function all(): array
    {
        return array_merge($this->diagnostics, $this->findings);
    }

    public function hasDiagnostics(): bool
    {
        return $this->diagnostics !== [];
    }

    public function highestSeverity(): ?Severity
    {
        $highest = null;
        foreach ($this->all() as $finding) {
            if ($highest === null || $finding->severity->value > $highest->value) {
                $highest = $finding->severity;
            }
        }

        return $highest;
    }
}
