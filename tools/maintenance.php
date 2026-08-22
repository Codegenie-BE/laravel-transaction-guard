<?php

declare(strict_types=1);

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

$source = replacePrivateFunction($source, 'queueConnectionFromStatement', <<<'PHP'
    private function queueConnectionFromStatement(string $statement, ?ClassMetadata $metadata = null): ?string
    {
        $code = $this->codeOnlyFragment($statement);
        if (preg_match('/->\s*onConnection\s*\(/i', $code, $call, PREG_OFFSET_CAPTURE) === 1) {
            $literal = $this->literalStringArgumentFromCall(substr($statement, $call[0][1]));

            return $literal ?? '@dynamic';
        }

        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Queue', 'Queue') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*connection\s*\(/i';
            if (preg_match($pattern, $code, $call, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $literal = $this->literalStringArgumentFromCall(substr($statement, $call[0][1]));

            return $literal ?? '@dynamic';
        }

        if ($metadata?->constructorQueueConnection !== null) {
            return $metadata->constructorQueueConnection;
        }

        return $metadata === null ? null : $this->classIndex->queueRouteConnection($metadata->name);
    }
PHP);

$marker = "    private function statementContainsAfterCommit(string \$statement): bool\n";
if (! str_contains($source, 'private function literalStringArgumentFromCall')) {
    $helper = <<<'PHP'
    private function literalStringArgumentFromCall(string $call): ?string
    {
        $tokens = token_get_all('<?php '.$call);
        $insideArguments = false;

        foreach ($tokens as $token) {
            if (! $insideArguments) {
                if ($token === '(') {
                    $insideArguments = true;
                }

                continue;
            }

            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                return null;
            }

            $literal = $token[1];
            if (strlen($literal) < 2) {
                return null;
            }

            return stripcslashes(substr($literal, 1, -1));
        }

        return null;
    }

PHP;
    if (! str_contains($source, $marker)) {
        throw new RuntimeException('Unable to find queue helper insertion marker');
    }
    $source = str_replace($marker, $helper.$marker, $source, $count);
    if ($count !== 1) {
        throw new RuntimeException('Queue helper insertion was not unique');
    }
}

$source = replacePrivateFunction($source, 'newClassFromStatement', <<<'PHP'
    private function newClassFromStatement(string $statement): ?string
    {
        return $this->newClassesFromStatement($statement)[0] ?? null;
    }
PHP);
$source = replacePrivateFunction($source, 'newClassesFromStatement', <<<'PHP'
    private function newClassesFromStatement(string $statement): array
    {
        $tokens = token_get_all('<?php '.$statement);
        $classes = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_NEW) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                $token = $tokens[$j];
                if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                    $classes[] = $token[1];
                }
                break;
            }
        }

        return array_values(array_unique($classes));
    }
PHP);

$source = str_replace("    }    private function", "    }\n\n    private function", $source);
$source = str_replace("    }\n    private function", "    }\n\n    private function", $source);
$source = str_replace("    }\n    /**", "    }\n\n    /**", $source);
$source = str_replace("private function facadeAliases(string \$fqcn, string \$fallback): array    {", "private function facadeAliases(string \$fqcn, string \$fallback): array\n    {", $source);

file_put_contents($path, $source);
