<?php

declare(strict_types=1);

use Codegenie\TransactionGuard\Analysis\Baseline;
use Codegenie\TransactionGuard\Analysis\Finding;
use Codegenie\TransactionGuard\Analysis\Severity;
use Codegenie\TransactionGuard\TransactionGuard;

it('keeps baseline fingerprints stable across Windows and Unix path separators', function (): void {
    $windows = new Finding(
        rule: 'TG006',
        severity: Severity::Error,
        message: 'Outbound HTTP',
        file: 'C:\\workspace\\project\\app\\Services\\OrderService.php',
        line: 10,
        snippet: 'Http::post($url);',
        remediation: 'Move after commit.',
        projectRoot: 'C:\\workspace\\project',
    );
    $unix = new Finding(
        rule: 'TG006',
        severity: Severity::Error,
        message: 'Outbound HTTP',
        file: '/workspace/project/app/Services/OrderService.php',
        line: 999,
        snippet: '  Http::post($url);  ',
        remediation: 'Move after commit.',
        projectRoot: '/workspace/project',
    );

    expect($windows->fingerprint())->toBe($unix->fingerprint());
});

it('discovers native paths with spaces and honors segment and wildcard excludes', function (): void {
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'transaction guard platform '.bin2hex(random_bytes(4));
    $app = $root.DIRECTORY_SEPARATOR.'app';
    $nested = $app.DIRECTORY_SEPARATOR.'Nested Folder';
    $generated = $app.DIRECTORY_SEPARATOR.'generated'.DIRECTORY_SEPARATOR.'cache-one';
    $vendor = $root.DIRECTORY_SEPARATOR.'vendor';

    mkdir($nested, 0777, true);
    mkdir($generated, 0777, true);
    mkdir($vendor, 0777, true);

    $keep = $nested.DIRECTORY_SEPARATOR.'Keep.php';
    $skipGenerated = $generated.DIRECTORY_SEPARATOR.'Generated.php';
    $skipVendor = $vendor.DIRECTORY_SEPARATOR.'Vendor.php';
    file_put_contents($keep, '<?php');
    file_put_contents($skipGenerated, '<?php');
    file_put_contents($skipVendor, '<?php');

    try {
        $files = (new TransactionGuard)->discoverPhpFiles([$root], ['vendor', 'generated/*']);
        $normalized = array_map(static fn (string $path): string => str_replace('\\', '/', $path), $files);

        expect($files)->toHaveCount(1)
            ->and($normalized[0])->toEndWith('/app/Nested Folder/Keep.php');
    } finally {
        @unlink($keep);
        @unlink($skipGenerated);
        @unlink($skipVendor);
        @rmdir($nested);
        @rmdir($generated);
        @rmdir($app.DIRECTORY_SEPARATOR.'generated');
        @rmdir($app);
        @rmdir($vendor);
        @rmdir($root);
    }
});

it('atomically replaces an existing baseline on the native filesystem', function (): void {
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'transaction-guard-baseline-platform-'.bin2hex(random_bytes(4));
    $path = $root.DIRECTORY_SEPARATOR.'baseline.json';
    $first = new Finding('TG006', Severity::Error, 'HTTP', '/app/A.php', 1, 'Http::post();', 'Move after commit.');
    $second = new Finding('TG007', Severity::Warning, 'Filesystem', '/app/B.php', 1, 'Storage::put();', 'Move after commit.');

    try {
        Baseline::write($path, [$first]);
        Baseline::write($path, [$second]);
        $baseline = Baseline::load($path);

        expect($baseline->contains($first))->toBeFalse()
            ->and($baseline->contains($second))->toBeTrue();
    } finally {
        @unlink($path);
        @rmdir($root);
    }
});

it('accepts an absolute Artisan scan path containing spaces on the native platform', function (): void {
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'transaction guard command '.bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    $file = $root.DIRECTORY_SEPARATOR.'Unsafe Service.php';
    file_put_contents($file, "<?php\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Http;\nDB::transaction(fn () => Http::post('https://example.test'));\n");

    try {
        $this->artisan('transaction:guard', [
            'paths' => [$file],
            '--format' => 'json',
            '--fail-on' => 'never',
        ])->expectsOutputToContain('TG006')
            ->assertSuccessful();
    } finally {
        @unlink($file);
        @rmdir($root);
    }
});
