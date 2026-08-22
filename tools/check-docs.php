<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/src/Analysis/RuleCatalog.php';

use Codegenie\TransactionGuard\Analysis\RuleCatalog;

$rules = file_get_contents(dirname(__DIR__).'/docs/RULES.md');
$readme = file_get_contents(dirname(__DIR__).'/README.md');
if ($rules === false || $readme === false) {
    fwrite(STDERR, "Unable to read documentation.\n");
    exit(1);
}

$failed = [];
foreach (RuleCatalog::ids() as $id) {
    if (! str_contains($rules, $id) && ! RuleCatalog::isDiagnostic($id)) {
        $failed[] = "docs/RULES.md is missing {$id}";
    }
    if (! str_contains($readme, $id)) {
        $failed[] = "README.md is missing {$id}";
    }
}

if ($failed !== []) {
    fwrite(STDERR, implode("\n", $failed)."\n");
    exit(1);
}

fwrite(STDOUT, "Rule documentation is synchronized.\n");
