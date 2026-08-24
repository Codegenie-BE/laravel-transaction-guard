<?php

declare(strict_types=1);

use Codegenie\TransactionGuard\Analysis\AnalysisConfig;
use Codegenie\TransactionGuard\Analysis\ClassMetadataIndex;
use Codegenie\TransactionGuard\Analysis\SourceScanner;
use Codegenie\TransactionGuard\Tests\Support\CatalogScanner;
use Codegenie\TransactionGuard\TransactionGuard;

it('reports TG900 when a requested source file cannot be read', function (): void {
    $missing = sys_get_temp_dir().DIRECTORY_SEPARATOR.'transaction-guard-missing-'.bin2hex(random_bytes(4)).'.php';
    $scanner = new SourceScanner(ClassMetadataIndex::fromFiles([]));
    $findings = $scanner->scan($missing);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->rule)->toBe('TG900');
});

it('reports TG901 for invalid PHP syntax', function (): void {
    $findings = CatalogScanner::scan('<?php function broken( {');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->rule)->toBe('TG901');
});

it('reports TG902 when a valid analyzer regex fails at runtime', function (): void {
    $originalLimit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '1');
    $tail = str_repeat('a', 128).'Y';
    $source = "<?php\nuse Illuminate\\Support\\Facades\\DB;\nDB::transaction(function () { CustomGateway::noop(); });\n/* {$tail} */\n";

    try {
        $findings = CatalogScanner::scan($source, new AnalysisConfig(
            customSideEffectPatterns: ['/(a+)+X/'],
        ));
    } finally {
        if (is_string($originalLimit)) {
            ini_set('pcre.backtrack_limit', $originalLimit);
        }
    }

    $rules = array_map(static fn ($finding): string => $finding->rule, $findings);
    expect($rules)->toContain('TG902');
});

it('reports TG903 when a requested source subtree cannot be traversed', function (): void {
    if (DIRECTORY_SEPARATOR === '\\') {
        $this->markTestSkipped('POSIX permission semantics are required for deterministic unreadable-directory coverage.');
    }

    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'transaction-guard-unreadable-'.bin2hex(random_bytes(4));
    $blocked = $root.DIRECTORY_SEPARATOR.'blocked';
    $hidden = $blocked.DIRECTORY_SEPARATOR.'Hidden.php';
    mkdir($blocked, 0777, true);
    file_put_contents($hidden, '<?php');
    chmod($blocked, 0000);

    if (is_readable($blocked)) {
        @chmod($blocked, 0777);
        @unlink($hidden);
        @rmdir($blocked);
        @rmdir($root);
        $this->markTestSkipped('The current process can bypass directory permission restrictions.');
    }

    try {
        $result = (new TransactionGuard(new AnalysisConfig))->analyze([$root]);
        $rules = array_map(static fn ($finding): string => $finding->rule, $result->diagnostics);

        expect($rules)->toContain('TG903');
    } finally {
        @chmod($blocked, 0777);
        @unlink($hidden);
        @rmdir($blocked);
        @rmdir($root);
    }
});
