<?php

declare(strict_types=1);

use Codegenie\TransactionGuard\Analysis\AnalysisConfig;
use Codegenie\TransactionGuard\Analysis\ClassMetadataIndex;
use Codegenie\TransactionGuard\Analysis\SourceScanner;

function scanRedisPipelineFixture(string $body): array
{
    $temporary = tempnam(sys_get_temp_dir(), 'tg-redis-pipeline-');
    expect($temporary)->not->toBeFalse();
    $file = $temporary.'.php';
    rename($temporary, $file);
    file_put_contents($file, "<?php\n"
        ."use Illuminate\\Support\\Facades\\DB;\n"
        ."use Illuminate\\Support\\Facades\\Redis;\n"
        ."DB::transaction(function () { {$body} });\n");

    try {
        return (new SourceScanner(ClassMetadataIndex::fromFiles([$file]), new AnalysisConfig))->scan($file);
    } finally {
        @unlink($file);
    }
}

it('applies GETEX refinement when SourceScanner is used directly', function (): void {
    $findings = scanRedisPipelineFixture("Redis::getex('key');");

    expect(array_column(array_map(static fn ($finding): array => $finding->toArray(), $findings), 'rule'))
        ->not->toContain('TG020');
});

it('preserves medium-confidence unknown GETEX through the direct scanner pipeline', function (): void {
    $findings = scanRedisPipelineFixture("Redis::getex('key', config('cache.getex')); ");
    $redis = collect($findings)->firstWhere('rule', 'TG020');

    expect($redis)->not->toBeNull()
        ->and($redis->confidence)->toBe('medium');
});
