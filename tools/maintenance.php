<?php

declare(strict_types=1);

$path = __DIR__.'/../src/Analysis/SourceScanner.php';
$source = file_get_contents($path);
if ($source === false) {
    throw new RuntimeException('Unable to read SourceScanner.php');
}

$source = str_replace("\n\n\n    /**", "\n\n    /**", $source);
$source = str_replace("\n\n\n    private function", "\n\n    private function", $source);
$source = str_replace(
    '    private function offsetIsNonCode(int $offset): bool    {',
    "    private function offsetIsNonCode(int \$offset): bool\n    {",
    $source,
    $offsetCount,
);

if ($offsetCount !== 1) {
    throw new RuntimeException("Expected one offsetIsNonCode formatting correction, got {$offsetCount}");
}

file_put_contents($path, $source);
