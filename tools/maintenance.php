<?php

declare(strict_types=1);

function replaceOnce(string $text, string $old, string $new, string $label): string
{
    if (! str_contains($text, $old)) {
        throw new RuntimeException("{$label}: expected source marker not found");
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
    $result = preg_replace($pattern, $replacement, $text, 1, $count);
    if ($result === null || $count !== 1) {
        throw new RuntimeException("{$name}: private function boundary not found exactly once");
    }

    return $result;
}

$sourcePath = __DIR__.'/../src/Analysis/SourceScanner.php';
$source = file_get_contents($sourcePath);
if ($source === false) {
    throw new RuntimeException('Unable to read SourceScanner.php');
}

$source = replaceOnce(
    $source,
    "    /** @var array<int, string> */\n    private array \$statementCache = [];\n\n",
    "    /** @var array<int, string> */\n    private array \$statementCache = [];\n\n    /** @var array<string, string> */\n    private array \$statementCodeCache = [];\n\n",
    'statement-code cache property',
);
$source = replaceOnce(
    $source,
    "        \$this->statementCache = [];\n        \$this->facadeAliasCache = [];\n",
    "        \$this->statementCache = [];\n        \$this->statementCodeCache = [];\n        \$this->facadeAliasCache = [];\n",
    'statement-code cache reset',
);

$source = replacePrivateFunction($source, 'matches', <<<'PHP'
    private function matches(string $pattern): array
    {
        $result = [];
        $ok = @preg_match_all($pattern, $this->source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($ok === false || $ok === 0) {
            return [];
        }

        foreach ($matches as $match) {
            $offset = $match[0][1];
            if ($this->offsetIsNonCode($offset) || $this->semanticCaptureIsNonCode($match)) {
                continue;
            }
            $result[] = ['offset' => $offset, 'matches' => $match];
        }

        return $result;
    }
PHP);
$source = replaceOnce(
    $source,
    "    private function offsetIsNonCode(int \$offset): bool\n",
    <<<'PHP'
    /** @param array<int|string, mixed> $match */
    private function semanticCaptureIsNonCode(array $match): bool
    {
        foreach (['method', 'class', 'fn', 'command'] as $name) {
            $capture = $match[$name] ?? null;
            if (! is_array($capture)) {
                continue;
            }

            $offset = $capture[1] ?? null;
            if (is_int($offset) && $offset >= 0 && $this->offsetIsNonCode($offset)) {
                return true;
            }
        }

        return false;
    }

    private function offsetIsNonCode(int $offset): bool
PHP,
    'semantic capture helper',
);

$source = replacePrivateFunction($source, 'queueConnectionFromStatement', <<<'PHP'
    private function queueConnectionFromStatement(string $statement, ?ClassMetadata $metadata = null): ?string
    {
        $code = $this->codeOnlyFragment($statement);
        if (preg_match('/->\s*onConnection\s*\(/i', $code, $call, PREG_OFFSET_CAPTURE) === 1) {
            $offset = $call[0][1];
            $tail = substr($statement, $offset);
            if (preg_match('/^->\s*onConnection\s*\(\s*([\'\"])(.*?)\1\s*\)/is', $tail, $literal) === 1) {
                return stripcslashes($literal[2]);
            }

            return '@dynamic';
        }

        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Queue', 'Queue') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*connection\s*\(/i';
            if (preg_match($pattern, $code, $call, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $offset = $call[0][1];
            $tail = substr($statement, $offset);
            $literalPattern = '/^(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*connection\s*\(\s*([\'\"])(.*?)\1\s*\)/is';
            if (preg_match($literalPattern, $tail, $literal) === 1) {
                return stripcslashes($literal[2]);
            }

            return '@dynamic';
        }

        if ($metadata?->constructorQueueConnection !== null) {
            return $metadata->constructorQueueConnection;
        }

        return $metadata === null ? null : $this->classIndex->queueRouteConnection($metadata->name);
    }
PHP);

$source = replacePrivateFunction($source, 'statementContainsAfterCommit', <<<'PHP'
    private function statementContainsAfterCommit(string $statement): bool
    {
        return preg_match('/->\s*afterCommit\s*\(/i', $this->codeOnlyFragment($statement)) === 1;
    }
PHP);
$source = replacePrivateFunction($source, 'callArgumentContainsPreference', <<<'PHP'
    private function callArgumentContainsPreference(string $statement, string $callMethod, string $preference): bool
    {
        $code = $this->codeOnlyFragment($statement);
        if (preg_match('/::\s*'.preg_quote($callMethod, '/').'\s*\(/i', $code, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return false;
        }

        $matched = $match[0][0];
        $matchedOffset = $match[0][1];
        $open = $matchedOffset + strlen($matched) - 1;
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($statement);

        for ($i = $open; $i < $length; $i++) {
            $char = $statement[$i];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;

                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;

                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === '\'' || $char === '"') {
                $quote = $char;

                continue;
            }
            if ($char === '(') {
                $depth++;

                continue;
            }
            if ($char !== ')') {
                continue;
            }

            $depth--;
            if ($depth === 0) {
                $arguments = substr($statement, $open + 1, $i - $open - 1);

                return preg_match('/->\s*'.preg_quote($preference, '/').'\s*\(/i', $this->codeOnlyFragment($arguments)) === 1;
            }
        }

        return false;
    }
PHP);
$source = replacePrivateFunction($source, 'statementContainsBeforeCommit', <<<'PHP'
    private function statementContainsBeforeCommit(string $statement): bool
    {
        return preg_match('/->\s*beforeCommit\s*\(/i', $this->codeOnlyFragment($statement)) === 1;
    }
PHP);
$source = replacePrivateFunction($source, 'statementContainsAfterResponse', <<<'PHP'
    private function statementContainsAfterResponse(string $statement): bool
    {
        return preg_match('/->\s*afterResponse\s*\(/i', $this->codeOnlyFragment($statement)) === 1;
    }
PHP);
$source = replacePrivateFunction($source, 'newClassFromStatement', <<<'PHP'
    private function newClassFromStatement(string $statement): ?string
    {
        if (preg_match('/\bnew\s+(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)/', $this->codeOnlyFragment($statement), $m) === 1) {
            return $m[1];
        }

        return null;
    }
PHP);
$source = replacePrivateFunction($source, 'newClassesFromStatement', <<<'PHP'
    private function newClassesFromStatement(string $statement): array
    {
        $result = preg_match_all('/\bnew\s+(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)/', $this->codeOnlyFragment($statement), $matches);
        if ($result === false || $result === 0) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }
PHP);
$source = replaceOnce(
    $source,
    "    /** @return list<string> */\n    private function facadeAliases(string \$fqcn, string \$fallback): array\n",
    <<<'PHP'
    private function codeOnlyFragment(string $fragment): string
    {
        if (array_key_exists($fragment, $this->statementCodeCache)) {
            return $this->statementCodeCache[$fragment];
        }

        $prefix = '<?php ';
        $masked = '';
        foreach (token_get_all($prefix.$fragment) as $token) {
            if (! is_array($token)) {
                $masked .= $token;

                continue;
            }

            [$id, $text] = $token;
            if (in_array($id, [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $text = preg_replace('/[^\r\n]/', ' ', $text) ?? $text;
            }
            $masked .= $text;
        }

        return $this->statementCodeCache[$fragment] = substr($masked, strlen($prefix));
    }

    /** @return list<string> */
    private function facadeAliases(string $fqcn, string $fallback): array
PHP,
    'code-only fragment helper',
);

file_put_contents($sourcePath, $source);

$matrixPath = __DIR__.'/../tests/Support/ScenarioMatrix.php';
$matrix = file_get_contents($matrixPath);
if ($matrix === false) {
    throw new RuntimeException('Unable to read ScenarioMatrix.php');
}
$marker = "    'commented side effect inside transaction is ignored' => [\n";
$scenarios = <<<'PHP'
    'afterCommit text inside string does not make dispatch safe' => [
        'code' => <<<'CODE'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class StringAfterCommitJob implements ShouldQueue {}
DB::transaction(function () { StringAfterCommitJob::dispatch('->afterCommit('); });
CODE,
        'rules' => ['TG001'],
    ],
    'beforeCommit text inside string is not an explicit override' => [
        'code' => <<<'CODE'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class StringBeforeCommitJob implements ShouldQueue {}
DB::transaction(function () { StringBeforeCommitJob::dispatch('->beforeCommit('); });
CODE,
        'rules' => ['TG001'],
        'absent' => ['TG010'],
    ],
    'HTTP mutating method text inside string is ignored' => [
        'code' => <<<'CODE'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { Http::withBody('post(')->get('https://example.test'); });
CODE,
        'rules' => [],
        'absent' => ['TG006'],
    ],
    'onConnection text inside string does not override queue connection' => [
        'code' => <<<'CODE'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class StringConnectionJob implements ShouldQueue {}
DB::transaction(function () { StringConnectionJob::dispatch("->onConnection('redis')"); });
CODE,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['database' => false, 'redis' => true]],
    ],
PHP;
if (str_contains($matrix, "'afterCommit text inside string does not make dispatch safe'")) {
    throw new RuntimeException('Point 1 scenarios already present');
}
$matrix = replaceOnce($matrix, $marker, $scenarios.$marker, 'scenario insertion');
file_put_contents($matrixPath, $matrix);
