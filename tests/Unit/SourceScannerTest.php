<?php

declare(strict_types=1);

use Codegenie\TransactionGuard\Analysis\AnalysisConfig;
use Codegenie\TransactionGuard\Analysis\ClassMetadataIndex;
use Codegenie\TransactionGuard\Analysis\SourceScanner;

/** @var array<string, array{code:string,rules:list<string>,absent?:list<string>,config?:array<string,mixed>}> $scenarioMatrix */
$scenarioMatrix = require __DIR__.'/../Support/ScenarioMatrix.php';

function scanTransactionGuardScenario(array $case): array
{
    $file = tempnam(sys_get_temp_dir(), 'transaction-guard-test-');
    expect($file)->not->toBeFalse();
    $phpFile = $file.'.php';
    rename($file, $phpFile);
    file_put_contents($phpFile, $case['code']);

    $cfg = $case['config'] ?? [];
    $config = new AnalysisConfig(
        defaultQueueConnection: (string) ($cfg['queue_default'] ?? 'sync'),
        queueAfterCommitByConnection: (array) ($cfg['queue_after_commit'] ?? []),
        customSideEffectPatterns: (array) ($cfg['custom_side_effect_patterns'] ?? []),
        disabledRules: (array) ($cfg['disabled_rules'] ?? []),
        detectReadHttpCalls: (bool) ($cfg['detect_read_http_calls'] ?? false),
        defaultDatabaseConnection: (string) ($cfg['database_default'] ?? '@default'),
    );

    try {
        $index = ClassMetadataIndex::fromFiles([$phpFile]);
        $scanner = new SourceScanner($index, $config);

        return $scanner->scan($phpFile);
    } finally {
        @unlink($phpFile);
    }
}

it('matches the complete transaction safety scenario matrix', function (string $name, array $case): void {
    $findings = scanTransactionGuardScenario($case);
    $rules = array_values(array_unique(array_map(static fn ($finding): string => $finding->rule, $findings)));

    foreach ($case['rules'] as $rule) {
        expect($rules)->toContain($rule);
    }
    foreach ($case['absent'] ?? [] as $rule) {
        expect($rules)->not->toContain($rule);
    }
})->with(array_map(
    static fn (string $name, array $case): array => [$name, $case],
    array_keys($scenarioMatrix),
    array_values($scenarioMatrix),
));

it('reports a parse error as TG901', function (): void {
    $findings = scanTransactionGuardScenario([
        'code' => '<?php function broken( {',
        'rules' => ['TG901'],
    ]);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->rule)->toBe('TG901');
});

it('rejects invalid custom side effect patterns', function (): void {
    expect(fn () => new AnalysisConfig(customSideEffectPatterns: ['/[invalid/']))
        ->toThrow(InvalidArgumentException::class);
});

it('classifies literal retry duplicate risk as critical', function (): void {
    $findings = scanTransactionGuardScenario([
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(fn () => Http::post('https://example.test'), attempts: 3);
PHP,
        'rules' => ['TG006', 'TG011'],
    ]);

    $retry = collect($findings)->firstWhere('rule', 'TG011');
    expect($retry)->not->toBeNull()
        ->and($retry->severity->label())->toBe('critical');
});

it('classifies dynamic transaction retry count conservatively as warning', function (): void {
    $findings = scanTransactionGuardScenario([
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(fn () => Http::post('https://example.test'), config('database.attempts'));
PHP,
        'rules' => ['TG006', 'TG011'],
    ]);

    $retry = collect($findings)->firstWhere('rule', 'TG011');
    expect($retry)->not->toBeNull()
        ->and($retry->severity->label())->toBe('warning');
});

it('classifies DDL and unclosed manual transactions as critical', function (): void {
    $findings = scanTransactionGuardScenario([
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::beginTransaction();
DB::statement('CREATE TABLE example (id INT)');
PHP,
        'rules' => ['TG012', 'TG013'],
    ]);

    $byRule = collect($findings)->keyBy('rule');
    expect($byRule['TG012']->severity->label())->toBe('critical')
        ->and($byRule['TG013']->severity->label())->toBe('critical');
});
