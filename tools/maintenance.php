<?php

declare(strict_types=1);

$path = 'src/Analysis/SourceScanner.php';
$contents = file_get_contents($path);
if ($contents === false) {
    throw new RuntimeException('Unable to read SourceScanner.php.');
}

$old = <<<'OLD'
        $result = preg_match_all('/\bnew\s+(\\?[A-Za-z_][A-Za-z0-9_\\]*)/', $statement, $matches);
OLD;
$new = <<<'NEW'
        $result = preg_match_all('/\bnew\s+(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)/', $statement, $matches);
NEW;

$updated = str_replace($old, $new, $contents, $count);
if ($count !== 1) {
    throw new RuntimeException("Expected one bulk class regex, got {$count}.");
}
file_put_contents($path, $updated);
