<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/src/Analysis/RuleCatalog.php';

use Codegenie\TransactionGuard\Analysis\RuleCatalog;

$root = dirname(__DIR__);
$rules = file_get_contents($root.'/docs/RULES.md');
$readme = file_get_contents($root.'/README.md');
$changelog = file_get_contents($root.'/CHANGELOG.md');
if ($rules === false || $readme === false || $changelog === false) {
    fwrite(STDERR, "Unable to read documentation.\n");
    exit(1);
}

$failed = [];
foreach (RuleCatalog::ids() as $id) {
    $definition = RuleCatalog::definition($id);
    if (! str_contains($rules, $id) && ! RuleCatalog::isDiagnostic($id)) {
        $failed[] = "docs/RULES.md is missing {$id}";
    }
    if (! str_contains($readme, $id)) {
        $failed[] = "README.md is missing {$id}";
    }
    $severity = preg_quote($definition['severity'], '/');
    if (preg_match('/\\| `'.preg_quote($id, '/').'` \\| '.$severity.' \\|/i', $readme) !== 1) {
        $failed[] = "README.md severity is out of sync for {$id}: expected {$definition['severity']}";
    }
}

preg_match_all('/^## \[([^]]+)](?: - ([0-9]{4}-[0-9]{2}-[0-9]{2}))?$/m', $changelog, $headings, PREG_SET_ORDER);
if ($headings === [] || ($headings[0][1] ?? null) !== 'Unreleased') {
    $failed[] = 'CHANGELOG.md must keep [Unreleased] as the first release heading.';
}
$unreleased = array_values(array_filter(
    $headings,
    static fn (array $heading): bool => ($heading[1] ?? null) === 'Unreleased',
));
if (count($unreleased) !== 1) {
    $failed[] = 'CHANGELOG.md must contain exactly one [Unreleased] heading.';
}
foreach ($headings as $heading) {
    $name = $heading[1] ?? '';
    if ($name === 'Unreleased') {
        continue;
    }
    if (preg_match('/^v\d+\.\d+\.\d+$/', $name) !== 1 || ($heading[2] ?? '') === '') {
        $failed[] = "CHANGELOG.md release heading [{$name}] must be a dated vX.Y.Z entry.";
    }
}

if ($failed !== []) {
    fwrite(STDERR, implode("\n", $failed)."\n");
    exit(1);
}

fwrite(STDOUT, "Rule documentation and changelog structure are synchronized.\n");
