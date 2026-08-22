<?php

declare(strict_types=1);

$path = __DIR__.'/../src/Analysis/SourceScanner.php';
$source = file_get_contents($path);
if ($source === false) {
    throw new RuntimeException('Unable to read SourceScanner.php');
}

$source = str_replace(
    "        try {\n            \$this->tokens = \$this->tokenize(\$source);\n        \$this->suppressionComments = \$this->indexSuppressionComments();\n",
    "        try {\n            \$this->tokens = \$this->tokenize(\$source);\n            \$this->suppressionComments = \$this->indexSuppressionComments();\n",
    $source,
    $indentCount,
);

$source = str_replace(
    "        return \$comments;\n    }\n    private function suppressionDirectiveMatches",
    "        return \$comments;\n    }\n\n    private function suppressionDirectiveMatches",
    $source,
    $separationCount,
);

if ($indentCount !== 1 || $separationCount !== 1) {
    throw new RuntimeException("Expected one formatting replacement each; indent={$indentCount}, separation={$separationCount}");
}

file_put_contents($path, $source);
