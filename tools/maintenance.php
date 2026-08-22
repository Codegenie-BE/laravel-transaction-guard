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

$source = replacePrivateFunction($source, 'matches', <<<'PHP'
    private function matches(string $pattern, array $allowNonCodeCaptures = []): array
    {
        $result = [];
        $ok = @preg_match_all($pattern, $this->source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($ok === false || $ok === 0) {
            return [];
        }

        foreach ($matches as $match) {
            $offset = $match[0][1];
            if ($this->offsetIsNonCode($offset) || $this->semanticCaptureIsNonCode($match, $allowNonCodeCaptures)) {
                continue;
            }
            $result[] = ['offset' => $offset, 'matches' => $match];
        }

        return $result;
    }
PHP);
$source = replacePrivateFunction($source, 'semanticCaptureIsNonCode', <<<'PHP'
    private function semanticCaptureIsNonCode(array $match, array $allowNonCodeCaptures = []): bool
    {
        $allowed = array_fill_keys($allowNonCodeCaptures, true);

        foreach (['method', 'class', 'fn', 'command'] as $name) {
            if (isset($allowed[$name])) {
                continue;
            }

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
PHP);

$oldRequest = "foreach (\$this->matches('/->\\s*request\\s*\\(\\s*[\\'\\\"](?P<method>POST|PUT|PATCH|DELETE)[\\'\\\"]/i') as \$match)";
$newRequest = "foreach (\$this->matches('/->\\s*request\\s*\\(\\s*[\\'\\\"](?P<method>POST|PUT|PATCH|DELETE)[\\'\\\"]/i', ['method']) as \$match)";
if (! str_contains($source, $oldRequest)) {
    throw new RuntimeException('Generic HTTP request matcher not found');
}
$source = str_replace($oldRequest, $newRequest, $source, $requestCount);
if ($requestCount !== 1) {
    throw new RuntimeException('Generic HTTP request matcher replacement was not unique');
}

$oldRedis = "foreach (\$this->matches(\$commandPattern) as \$match)";
$newRedis = "foreach (\$this->matches(\$commandPattern, ['command']) as \$match)";
if (! str_contains($source, $oldRedis)) {
    throw new RuntimeException('Redis command matcher not found');
}
$source = str_replace($oldRedis, $newRedis, $source, $redisCount);
if ($redisCount !== 1) {
    throw new RuntimeException('Redis command matcher replacement was not unique');
}

file_put_contents($path, $source);
