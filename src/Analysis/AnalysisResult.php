<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final readonly class AnalysisResult
{
    /** @param list<Finding> $findings */
    public function __construct(
        public array $findings,
        public int $filesAnalyzed,
    ) {}

    public function highestSeverity(): ?Severity
    {
        $highest = null;
        foreach ($this->findings as $finding) {
            if ($highest === null || $finding->severity->value > $highest->value) {
                $highest = $finding->severity;
            }
        }

        return $highest;
    }
}
