<?php

declare(strict_types=1);

use Codegenie\TransactionGuard\Analysis\Baseline;
use Codegenie\TransactionGuard\Analysis\Finding;
use Codegenie\TransactionGuard\Analysis\Severity;

function sampleFinding(string $file = '/app/Order.php', string $snippet = 'Http::post($url);'): Finding
{
    return new Finding(
        rule: 'TG006',
        severity: Severity::Error,
        message: 'Outbound HTTP',
        file: $file,
        line: 10,
        snippet: $snippet,
        remediation: 'Move after commit.',
    );
}

it('creates deterministic fingerprints independent of line number', function (): void {
    $a = sampleFinding();
    $b = new Finding('TG006', Severity::Error, 'Changed wording', '/app/Order.php', 999, '  Http::post($url); ', 'Changed remediation');

    expect($a->fingerprint())->toBe($b->fingerprint());
});

it('changes fingerprints when the rule, file or normalized snippet changes', function (): void {
    $base = sampleFinding()->fingerprint();

    expect(sampleFinding('/app/Other.php')->fingerprint())->not->toBe($base)
        ->and(sampleFinding('/app/Order.php', 'Mail::send($m);')->fingerprint())->not->toBe($base);
});

it('writes v2 occurrence counts and loads them', function (): void {
    $dir = sys_get_temp_dir().'/transaction-guard-baseline-'.bin2hex(random_bytes(4));
    $path = $dir.'/baseline.json';
    $finding = sampleFinding();

    try {
        Baseline::write($path, [$finding, $finding]);
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $baseline = Baseline::load($path);

        expect($decoded['version'])->toBe(2)
            ->and($decoded['fingerprints'][$finding->fingerprint()])->toBe(2)
            ->and($baseline->contains($finding))->toBeTrue()
            ->and($baseline->contains($finding, 2))->toBeTrue()
            ->and($baseline->contains($finding, 3))->toBeFalse();
    } finally {
        @unlink($path);
        @rmdir($dir);
    }
});

it('loads legacy v1 baselines', function (): void {
    $dir = sys_get_temp_dir().'/transaction-guard-baseline-v1-'.bin2hex(random_bytes(4));
    $path = $dir.'/baseline.json';
    $finding = sampleFinding();
    mkdir($dir, 0777, true);

    try {
        file_put_contents($path, json_encode([
            'version' => 1,
            'fingerprints' => [$finding->fingerprint()],
        ], JSON_THROW_ON_ERROR));

        $baseline = Baseline::load($path);

        expect($baseline->contains($finding))->toBeTrue()
            ->and($baseline->contains($finding, 2))->toBeFalse();
    } finally {
        @unlink($path);
        @rmdir($dir);
    }
});

it('rejects unsupported baseline versions', function (): void {
    $dir = sys_get_temp_dir().'/transaction-guard-baseline-version-'.bin2hex(random_bytes(4));
    $path = $dir.'/baseline.json';
    mkdir($dir, 0777, true);

    try {
        file_put_contents($path, json_encode([
            'version' => 99,
            'fingerprints' => [],
        ], JSON_THROW_ON_ERROR));

        expect(fn (): Baseline => Baseline::load($path))
            ->toThrow(RuntimeException::class, 'unsupported version');
    } finally {
        @unlink($path);
        @rmdir($dir);
    }
});

it('rejects invalid occurrence indexes', function (): void {
    expect(fn (): bool => (new Baseline)->contains(sampleFinding(), 0))
        ->toThrow(InvalidArgumentException::class);
});

it('treats a missing baseline as empty', function (): void {
    $baseline = Baseline::load(sys_get_temp_dir().'/missing-'.bin2hex(random_bytes(8)).'.json');

    expect($baseline->contains(sampleFinding()))->toBeFalse();
});
