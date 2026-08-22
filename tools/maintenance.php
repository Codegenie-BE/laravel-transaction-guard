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

$sourcePath = __DIR__.'/../src/Analysis/SourceScanner.php';
$source = file_get_contents($sourcePath);
if ($source === false) {
    throw new RuntimeException('Unable to read SourceScanner.php');
}

$source = replaceOnce(
    $source,
    "    /** @var array<string, list<string>> */\n    private array \$facadeAliasCache = [];\n\n",
    "    /** @var array<string, list<string>> */\n    private array \$facadeAliasCache = [];\n\n    /** @var array<int, list<string>> */\n    private array \$suppressionComments = [];\n\n",
    'suppression comment property',
);

$source = replaceOnce(
    $source,
    "        \$this->tokens = \$this->tokenize(\$source);\n",
    "        \$this->tokens = \$this->tokenize(\$source);\n        \$this->suppressionComments = \$this->indexSuppressionComments();\n",
    'suppression comment indexing',
);

$oldSuppressed = <<<'PHP'
    private function suppressed(int $offset, string $rule): bool
    {
        $line = $this->lineAtOffset($offset);
        $current = $this->sourceIndex->line($line);
        if ($this->suppressionDirectiveMatches($current, 'transaction-guard-ignore', $rule, rejectNextLine: true)) {
            return true;
        }

        return $this->suppressionDirectiveMatches(
            $this->sourceIndex->line($line - 1),
            'transaction-guard-ignore-next-line',
            $rule,
        );
    }
PHP;

$newSuppressed = <<<'PHP'
    private function suppressed(int $offset, string $rule): bool
    {
        $line = $this->lineAtOffset($offset);

        foreach ($this->suppressionComments[$line] ?? [] as $comment) {
            if ($this->suppressionDirectiveMatches($comment, 'transaction-guard-ignore', $rule, rejectNextLine: true)) {
                return true;
            }
        }

        foreach ($this->suppressionComments[$line - 1] ?? [] as $comment) {
            if ($this->suppressionDirectiveMatches($comment, 'transaction-guard-ignore-next-line', $rule)) {
                return true;
            }
        }

        return false;
    }
PHP;
$source = replaceOnce($source, $oldSuppressed, $newSuppressed, 'comment-only suppression lookup');

$marker = "    private function suppressionDirectiveMatches(string \$line, string \$directive, string \$rule, bool \$rejectNextLine = false): bool\n";
$helper = <<<'PHP'
    /** @return array<int, list<string>> */
    private function indexSuppressionComments(): array
    {
        $comments = [];

        foreach ($this->tokens as $token) {
            if (! in_array($token['id'], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $lines = preg_split('/\R/', $token['text']) ?: [$token['text']];
            foreach ($lines as $offset => $text) {
                $comments[$token['line'] + $offset][] = $text;
            }
        }

        return $comments;
    }

PHP;
$source = replaceOnce($source, $marker, $helper.$marker, 'suppression index helper');
file_put_contents($sourcePath, $source);

$matrixPath = __DIR__.'/../tests/Support/ScenarioMatrix.php';
$matrix = file_get_contents($matrixPath);
if ($matrix === false) {
    throw new RuntimeException('Unable to read ScenarioMatrix.php');
}
$scenarioMarker = "    'inline ignore current line suppresses finding' => [\n";
$scenarios = <<<'PHP'
    'suppression text inside same-line string does not suppress finding' => [
        'code' => <<<'CODE'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { $text = 'transaction-guard-ignore TG006'; Http::post('https://example.test/capture'); });
CODE,
        'rules' => ['TG006'],
    ],
    'next-line suppression text inside string does not suppress finding' => [
        'code' => <<<'CODE'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () {
    $text = 'transaction-guard-ignore-next-line TG006';
    Http::post('https://example.test/capture');
});
CODE,
        'rules' => ['TG006'],
    ],
PHP;
$matrix = replaceOnce($matrix, $scenarioMarker, $scenarios.$scenarioMarker, 'suppression regression scenarios');
file_put_contents($matrixPath, $matrix);
