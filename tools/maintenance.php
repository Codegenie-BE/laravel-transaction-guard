<?php

declare(strict_types=1);

$path = __DIR__.'/../src/Analysis/SourceScanner.php';
$source = file_get_contents($path);
if ($source === false) {
    throw new RuntimeException('Unable to read SourceScanner.php');
}

$source = str_replace(
    '    /** @return list<array{offset:int,matches:array<int|string,array{0:string,1:int}|string>}> */'."\n".
    '    private function matches(string $pattern, array $allowNonCodeCaptures = []): array',
    "    /**\n     * @param  list<string>  \$allowNonCodeCaptures\n     * @return list<array{offset:int,matches:array<int|string,array{0:string,1:int}|string>}>\n     */\n".
    '    private function matches(string $pattern, array $allowNonCodeCaptures = []): array',
    $source,
    $matchesCount,
);

$source = str_replace(
    '    /** @param array<int|string, mixed> $match */'."\n".
    '    private function semanticCaptureIsNonCode(array $match, array $allowNonCodeCaptures = []): bool',
    "    /**\n     * @param  array<int|string, mixed>  \$match\n     * @param  list<string>  \$allowNonCodeCaptures\n     */\n".
    '    private function semanticCaptureIsNonCode(array $match, array $allowNonCodeCaptures = []): bool',
    $source,
    $semanticCount,
);

if ($matchesCount !== 1 || $semanticCount !== 1) {
    throw new RuntimeException("Expected one PHPStan annotation replacement per helper; matches={$matchesCount}, semantic={$semanticCount}");
}

file_put_contents($path, $source);
