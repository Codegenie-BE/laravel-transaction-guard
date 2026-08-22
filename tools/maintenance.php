<?php

declare(strict_types=1);

$path = __DIR__.'/../src/Analysis/SourceScanner.php';
$source = file_get_contents($path);
if ($source === false) {
    throw new RuntimeException('Unable to read SourceScanner.php');
}

$pattern = '~^    private function callArgumentContainsPreference\([^\n]*\)[^\n]*\n    \{.*?^    \}\n~ms';
$replacement = <<<'PHP'
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
                if (ord($char) === 92) {
                    $escaped = true;

                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === "'" || $char === '"') {
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
PHP;
$count = 0;
$updated = preg_replace($pattern, $replacement, $source, 1, $count);
if ($updated === null || $count !== 1) {
    throw new RuntimeException('Unable to replace callArgumentContainsPreference() exactly once');
}

file_put_contents($path, $updated);
