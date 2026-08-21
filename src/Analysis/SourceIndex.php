<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

/**
 * Immutable per-file source lookup tables used by the analyzer hot path.
 *
 * @phpstan-type ScannerToken array{id:int|null,text:string,line:int,offset:int,end:int}
 */
final class SourceIndex
{
    /** @var list<int> */
    private array $lineStarts = [0];

    /** @var list<string> */
    private array $lines = [];

    /** @var list<array{start:int,end:int}> */
    private array $nonCodeRanges = [];

    /**
     * @param  list<ScannerToken>  $tokens
     */
    public function __construct(string $source, array $tokens)
    {
        $this->lines = preg_split('/\R/', $source) ?: [];

        $length = strlen($source);
        for ($offset = 0; $offset < $length; $offset++) {
            if ($source[$offset] === "\n") {
                $this->lineStarts[] = $offset + 1;
            }
        }

        $ignored = [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];
        foreach ($tokens as $token) {
            if ($token['id'] !== null && in_array($token['id'], $ignored, true)) {
                $this->nonCodeRanges[] = ['start' => $token['offset'], 'end' => $token['end']];
            }
        }
    }

    public function lineAt(int $offset): int
    {
        $offset = max(0, $offset);
        $low = 0;
        $high = count($this->lineStarts) - 1;
        $best = 0;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            if ($this->lineStarts[$mid] <= $offset) {
                $best = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return $best + 1;
    }

    public function line(int $number): string
    {
        return $this->lines[$number - 1] ?? '';
    }

    public function isNonCode(int $offset): bool
    {
        $low = 0;
        $high = count($this->nonCodeRanges) - 1;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $range = $this->nonCodeRanges[$mid];

            if ($offset < $range['start']) {
                $high = $mid - 1;
            } elseif ($offset >= $range['end']) {
                $low = $mid + 1;
            } else {
                return true;
            }
        }

        return false;
    }
}
