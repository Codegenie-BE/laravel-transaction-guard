<?php

declare(strict_types=1);

function replaceOnce(string $text, string $old, string $new, string $label): string
{
    if (! str_contains($text, $old)) {
        throw new RuntimeException("{$label}: expected source block not found");
    }

    $count = 0;
    $result = str_replace($old, $new, $text, $count);
    if ($count !== 1) {
        throw new RuntimeException("{$label}: expected one replacement, got {$count}");
    }

    return $result;
}

function replacePrivateFunction(string $text, string $name, string $replacement): string
{
    $pattern = '~^    private function '.preg_quote($name, '~').'\([^\n]*\)[^\n]*\n    \{.*?^    \}\n~ms';
    $count = 0;
    $result = preg_replace($pattern, rtrim($replacement)."\n\n", $text, 1, $count);
    if ($result === null || $count !== 1) {
        throw new RuntimeException("{$name}: private function boundary not found exactly once");
    }

    return $result;
}

$path = __DIR__.'/../src/Analysis/SourceScanner.php';
$source = file_get_contents($path);
if ($source === false) {
    throw new RuntimeException('Unable to read SourceScanner.php');
}

$source = replaceOnce(
    $source,
    ' * @phpstan-type DatabaseControlCall array{type:string,offset:int,end:int,scope:string,connection:string}',
    ' * @phpstan-type DatabaseControlCall array{type:string,offset:int,end:int,scope:string,connection:string,conditionalScope:string|null}',
    'DatabaseControlCall type',
);

$source = replacePrivateFunction($source, 'scanManualTransactionBalance', <<<'PHP'
    private function scanManualTransactionBalance(array &$findings): void
    {
        /** @var array<string, list<DatabaseControlCall>> $stacks */
        $stacks = [];

        foreach ($this->manualControlCalls() as $call) {
            $key = $call['scope'].'|'.$call['connection'];
            if ($call['type'] === 'begin') {
                $stacks[$key][] = $call;

                continue;
            }

            if (! in_array($call['type'], ['commit', 'rollback'], true) || ($stacks[$key] ?? []) === []) {
                continue;
            }

            $start = end($stacks[$key]);
            if ($start === false || ! $this->manualTerminalCloses($start, $call)) {
                continue;
            }

            array_pop($stacks[$key]);
        }

        foreach ($stacks as $stack) {
            foreach ($stack as $call) {
                $this->appendFinding($findings, $call['offset'], 'TG013', Severity::Critical,
                    "A manually started database transaction on [{$call['connection']}] has no matching commit() or rollBack() on every statically visible branch.",
                    'Prefer DB::transaction() or guarantee a same-connection commit/rollback on every branch and exception path.', 'medium',
                    ['database_connection' => $call['connection']]);
            }
        }
    }
PHP);

$source = replacePrivateFunction($source, 'findManualTransactions', <<<'PHP'
    private function findManualTransactions(): array
    {
        /** @var array<string, list<DatabaseControlCall>> $groups */
        $groups = [];
        foreach ($this->manualControlCalls() as $call) {
            $groups[$call['scope'].'|'.$call['connection']][] = $call;
        }

        $regions = [];
        foreach ($groups as $calls) {
            $groupStart = null;
            $groupEnd = null;
            $depth = 0;

            /** @param DatabaseControlCall|null $start */
            $flush = function (?array $start, ?int $endOffset) use (&$regions): void {
                if ($start === null) {
                    return;
                }

                $end = $endOffset ?? strlen($this->source);
                $regions[] = [
                    'start' => $start['end'],
                    'end' => $end,
                    'line' => $this->lineAtOffset($start['offset']),
                    'type' => 'manual',
                    'attempts' => 1,
                    'connection' => $start['connection'],
                    'callableStart' => $start['end'],
                    'callableEnd' => $end,
                ];
            };

            foreach ($calls as $call) {
                if ($call['type'] === 'begin') {
                    if ($groupStart !== null && $depth === 0) {
                        $flush($groupStart, $groupEnd);
                        $groupStart = null;
                        $groupEnd = null;
                        $depth = 0;
                    }
                    if ($groupStart === null) {
                        $groupStart = $call;
                    }
                    $depth++;

                    continue;
                }

                if ($groupStart === null || ! $this->manualTerminalCloses($groupStart, $call)) {
                    continue;
                }

                $groupEnd = $call['offset'];
                if ($depth > 0) {
                    $depth--;
                }
            }

            $flush($groupStart, $groupEnd);
        }

        return $regions;
    }
PHP);

$source = replacePrivateFunction($source, 'manualControlCalls', <<<'PHP'
    private function manualControlCalls(): array
    {
        $calls = [];
        foreach (['beginTransaction' => 'begin', 'commit' => 'commit', 'rollBack' => 'rollback'] as $method => $type) {
            foreach ($this->dbTransactionCalls($method) as $dbCall) {
                $offset = $dbCall['offset'];
                $calls[] = [
                    'type' => $type,
                    'offset' => $offset,
                    'end' => $this->statementEnd($offset),
                    'scope' => $this->callableScopeAt($offset),
                    'connection' => $dbCall['connection'],
                    'conditionalScope' => $this->conditionalControlScopeAt($offset),
                ];
            }
        }

        usort($calls, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        return $calls;
    }
PHP);

$marker = "    private function callableScopeAt(int \$offset): string\n";
$helpers = <<<'PHP'
    /**
     * @param  DatabaseControlCall  $start
     * @param  DatabaseControlCall  $terminal
     */
    private function manualTerminalCloses(array $start, array $terminal): bool
    {
        return $terminal['conditionalScope'] === null
            || $terminal['conditionalScope'] === $start['conditionalScope'];
    }

    private function conditionalControlScopeAt(int $offset): ?string
    {
        $target = $this->tokenIndexContainingOrAfterOffset($offset);
        if ($target === null) {
            return null;
        }

        /** @var list<string|null> $blocks */
        $blocks = [];
        for ($i = 0; $i < $target; $i++) {
            $text = $this->tokens[$i]['text'];
            if ($text === '{') {
                $blocks[] = $this->openingBraceStartsConditionalBlock($i)
                    ? 'block:'.$this->tokens[$i]['offset']
                    : null;

                continue;
            }
            if ($text === '}') {
                array_pop($blocks);
            }
        }

        for ($i = count($blocks) - 1; $i >= 0; $i--) {
            if ($blocks[$i] !== null) {
                return $blocks[$i];
            }
        }

        for ($i = $target - 1; $i >= 0; $i--) {
            $token = $this->tokens[$i];
            if (in_array($token['text'], [';', '{', '}'], true)) {
                break;
            }
            if (in_array($token['id'], [T_IF, T_ELSEIF, T_FOR, T_FOREACH, T_WHILE, T_DO], true)) {
                return 'statement:'.$token['offset'];
            }
        }

        return null;
    }

    private function openingBraceStartsConditionalBlock(int $braceIndex): bool
    {
        $previous = $this->previousSignificantToken($braceIndex - 1);
        if ($previous === null) {
            return false;
        }

        $id = $this->tokens[$previous]['id'];
        if (in_array($id, [T_ELSE, T_DO], true)) {
            return true;
        }
        if ($this->tokens[$previous]['text'] !== ')') {
            return false;
        }

        $open = $this->matchingOpeningToken($previous, '(', ')');
        if ($open === null) {
            return false;
        }
        $control = $this->previousSignificantToken($open - 1);
        if ($control === null) {
            return false;
        }

        return in_array($this->tokens[$control]['id'], [T_IF, T_ELSEIF, T_FOR, T_FOREACH, T_WHILE, T_SWITCH], true);
    }

    private function previousSignificantToken(int $start): ?int
    {
        for ($i = $start; $i >= 0; $i--) {
            $id = $this->tokens[$i]['id'];
            if ($id !== null && in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    private function matchingOpeningToken(int $close, string $openText, string $closeText): ?int
    {
        $depth = 0;
        for ($i = $close; $i >= 0; $i--) {
            $text = $this->tokens[$i]['text'];
            if ($text === $closeText) {
                $depth++;
            } elseif ($text === $openText) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

PHP;
$source = replaceOnce($source, $marker, $helpers.$marker, 'manual branch helpers');
file_put_contents($path, $source);

$matrixPath = __DIR__.'/../tests/Support/ScenarioMatrix.php';
$matrix = file_get_contents($matrixPath);
if ($matrix === false) {
    throw new RuntimeException('Unable to read ScenarioMatrix.php');
}

$scenarioMarker = "    'manual transaction side effect before commit is flagged' => [\n";
$scenarios = <<<'PHP'
    'conditional manual commit does not prove the transaction closed' => [
        'code' => <<<'CODE'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::beginTransaction();
if ($shouldCommit) {
    DB::commit();
}
Http::post('https://example.test/capture');
CODE,
        'rules' => ['TG006', 'TG013'],
    ],
    'unbraced conditional manual commit does not prove the transaction closed' => [
        'code' => <<<'CODE'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::beginTransaction();
if ($shouldCommit) DB::commit();
Http::post('https://example.test/capture');
CODE,
        'rules' => ['TG006', 'TG013'],
    ],
    'manual transaction opened and closed in the same branch is bounded there' => [
        'code' => <<<'CODE'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
if ($enabled) {
    DB::beginTransaction();
    DB::commit();
}
Http::post('https://example.test/outside');
CODE,
        'rules' => [],
        'absent' => ['TG006', 'TG013'],
    ],
    'side effect inside branch-contained manual transaction is detected' => [
        'code' => <<<'CODE'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
if ($enabled) {
    DB::beginTransaction();
    Http::post('https://example.test/capture');
    DB::commit();
}
CODE,
        'rules' => ['TG006'],
        'absent' => ['TG013'],
    ],
PHP;
$matrix = replaceOnce($matrix, $scenarioMarker, $scenarios.$scenarioMarker, 'branch-aware manual transaction regressions');
file_put_contents($matrixPath, $matrix);
