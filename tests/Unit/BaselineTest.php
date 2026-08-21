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

it('writes and loads a baseline', function (): void {
    $dir = sys_get_temp_dir().'/transaction-guard-baseline-'.bin2hex(random_bytes(4));
    $path = $dir.'/baseline.json';
    $finding = sampleFinding();

    try {
        Baseline::write($path, [$finding]);
        $baseline = Baseline::load($path);

        expect($baseline->contains($finding))->toBeTrue();
    } finally {
        @unlink($path);
        @rmdir($dir);
    }
});

it('treats a missing baseline as empty', function (): void {
    $baseline = Baseline::load(sys_get_temp_dir().'/missing-'.bin2hex(random_bytes(8)).'.json');

    expect($baseline->contains(sampleFinding()))->toBeFalse();
});
