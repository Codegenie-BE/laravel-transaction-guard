<?php

declare(strict_types=1);

use Codegenie\TransactionGuard\Analysis\AnalysisConfig;
use Codegenie\TransactionGuard\Analysis\Baseline;
use Codegenie\TransactionGuard\TransactionGuard;

it('discovers php files recursively and honors excludes', function (): void {
    $root = sys_get_temp_dir().'/transaction-guard-discovery-'.bin2hex(random_bytes(4));
    mkdir($root.'/app/Nested', 0777, true);
    mkdir($root.'/vendor', 0777, true);
    file_put_contents($root.'/app/A.php', '<?php');
    file_put_contents($root.'/app/Nested/B.php', '<?php');
    file_put_contents($root.'/app/readme.txt', 'x');
    file_put_contents($root.'/vendor/C.php', '<?php');

    try {
        $guard = new TransactionGuard();
        $files = $guard->discoverPhpFiles([$root], ['vendor']);

        expect($files)->toHaveCount(2)
            ->and(implode('|', $files))->toContain('A.php')
            ->and(implode('|', $files))->toContain('B.php')
            ->not->toContain('C.php');
    } finally {
        @unlink($root.'/app/Nested/B.php');
        @rmdir($root.'/app/Nested');
        @unlink($root.'/app/A.php');
        @unlink($root.'/app/readme.txt');
        @rmdir($root.'/app');
        @unlink($root.'/vendor/C.php');
        @rmdir($root.'/vendor');
        @rmdir($root);
    }
});

it('filters baseline findings while preserving file count', function (): void {
    $root = sys_get_temp_dir().'/transaction-guard-analysis-'.bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    $file = $root.'/Unsafe.php';
    file_put_contents($file, <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { Http::post('https://example.test'); });
PHP);

    try {
        $guard = new TransactionGuard(new AnalysisConfig());
        $raw = $guard->analyze([$root]);
        expect($raw->filesAnalyzed)->toBe(1)
            ->and($raw->findings)->not->toBeEmpty();

        $baseline = new Baseline(array_map(static fn ($finding): string => $finding->fingerprint(), $raw->findings));
        $filtered = $guard->analyze([$root], [], $baseline);

        expect($filtered->filesAnalyzed)->toBe(1)
            ->and($filtered->findings)->toBeEmpty();
    } finally {
        @unlink($file);
        @rmdir($root);
    }
});
