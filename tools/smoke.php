<?php

declare(strict_types=1);

$root = dirname(__DIR__);
foreach ([
    'src/Analysis/Severity.php',
    'src/Analysis/Finding.php',
    'src/Analysis/AnalysisConfig.php',
    'src/Analysis/ClassMetadata.php',
    'src/Analysis/FileContext.php',
    'src/Analysis/ClassMetadataIndex.php',
    'src/Analysis/SourceScanner.php',
    'src/Analysis/AnalysisResult.php',
    'src/Analysis/Baseline.php',
    'src/TransactionGuard.php',
] as $file) {
    require_once $root.'/'.$file;
}

use Codegenie\TransactionGuard\Analysis\AnalysisConfig;
use Codegenie\TransactionGuard\Analysis\Baseline;
use Codegenie\TransactionGuard\Analysis\ClassMetadataIndex;
use Codegenie\TransactionGuard\Analysis\SourceScanner;
use Codegenie\TransactionGuard\TransactionGuard;

/** @var array<string, array{code:string,rules:list<string>,absent?:list<string>,config?:array<string,mixed>}> $cases */
$cases = require $root.'/tests/Support/ScenarioMatrix.php';
$failed = 0;
$passed = 0;

foreach ($cases as $name => $case) {
    $file = tempnam(sys_get_temp_dir(), 'transaction-guard-');
    if ($file === false) {
        fwrite(STDERR, "Unable to create temporary file.\n");
        exit(2);
    }
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
    );

    $index = ClassMetadataIndex::fromFiles([$phpFile]);
    $scanner = new SourceScanner($index, $config);
    $findings = $scanner->scan($phpFile);
    $rules = array_values(array_unique(array_map(static fn ($finding): string => $finding->rule, $findings)));
    sort($rules);
    $expected = $case['rules'];
    sort($expected);

    $missing = array_values(array_diff($expected, $rules));
    $forbidden = array_values(array_intersect($case['absent'] ?? [], $rules));

    if ($missing !== [] || $forbidden !== []) {
        $failed++;
        fwrite(STDERR, "FAIL: {$name}\n");
        if ($missing !== []) {
            fwrite(STDERR, '  missing: '.implode(', ', $missing)."\n");
        }
        if ($forbidden !== []) {
            fwrite(STDERR, '  forbidden: '.implode(', ', $forbidden)."\n");
        }
        fwrite(STDERR, '  actual: '.($rules === [] ? '(none)' : implode(', ', $rules))."\n");
    } else {
        $passed++;
        fwrite(STDOUT, '.');
    }

    @unlink($phpFile);
}

fwrite(STDOUT, "\n{$passed} scenario(s) passed; {$failed} failed.\n");

// Cross-file metadata: the analyzer must understand job contracts declared in another file.
$integrationRoot = sys_get_temp_dir().'/transaction-guard-smoke-'.bin2hex(random_bytes(4));
mkdir($integrationRoot.'/app/Jobs', 0777, true);
mkdir($integrationRoot.'/app/Services', 0777, true);
file_put_contents($integrationRoot.'/app/Jobs/SafeJob.php', <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
class SafeJob implements ShouldQueueAfterCommit {}
PHP);
file_put_contents($integrationRoot.'/app/Services/OrderService.php', <<<'PHP'
<?php
namespace App\Services;
use App\Jobs\SafeJob;
use Illuminate\Support\Facades\DB;
class OrderService { public function run(): void { DB::transaction(fn () => SafeJob::dispatch()); } }
PHP);
file_put_contents($integrationRoot.'/app/Services/UnsafeService.php', <<<'PHP'
<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
class UnsafeService { public function run(): void { DB::transaction(fn () => Http::post('https://example.test')); } }
PHP);
file_put_contents($integrationRoot.'/app/Jobs/RoutedJob.php', <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
class RoutedJob implements ShouldQueue {}
PHP);
file_put_contents($integrationRoot.'/app/Services/QueueRoutes.php', <<<'PHP'
<?php
namespace App\Services;
use App\Jobs\RoutedJob;
use Illuminate\Support\Facades\Queue;
Queue::route(RoutedJob::class, connection: 'redis');
PHP);
file_put_contents($integrationRoot.'/app/Services/RoutedService.php', <<<'PHP'
<?php
namespace App\Services;
use App\Jobs\RoutedJob;
use Illuminate\Support\Facades\DB;
class RoutedService { public function run(): void { DB::transaction(fn () => RoutedJob::dispatch()); } }
PHP);

try {
    $guard = new TransactionGuard(new AnalysisConfig);
    $result = $guard->analyze([$integrationRoot.'/app']);
    $rulesByFile = [];
    foreach ($result->findings as $finding) {
        $rulesByFile[basename($finding->file)][] = $finding->rule;
    }

    if (($rulesByFile['SafeJob.php'] ?? []) !== [] || in_array('TG001', $rulesByFile['OrderService.php'] ?? [], true)) {
        fwrite(STDERR, "FAIL: cross-file ShouldQueueAfterCommit metadata\n");
        $failed++;
    } else {
        fwrite(STDOUT, '.');
        $passed++;
    }

    if (! in_array('TG006', $rulesByFile['UnsafeService.php'] ?? [], true)) {
        fwrite(STDERR, "FAIL: directory analysis integration\n");
        $failed++;
    } else {
        fwrite(STDOUT, '.');
        $passed++;
    }

    $routeGuard = new TransactionGuard(new AnalysisConfig(
        defaultQueueConnection: 'database',
        queueAfterCommitByConnection: ['database' => false, 'redis' => true],
    ));
    $routeResult = $routeGuard->analyze([$integrationRoot.'/app']);
    $routeRulesByFile = [];
    foreach ($routeResult->findings as $finding) {
        $routeRulesByFile[basename($finding->file)][] = $finding->rule;
    }
    if (in_array('TG001', $routeRulesByFile['RoutedService.php'] ?? [], true)) {
        fwrite(STDERR, "FAIL: cross-file Laravel 13 Queue::route metadata\n");
        $failed++;
    } else {
        fwrite(STDOUT, '.');
        $passed++;
    }

    $baseline = new Baseline(array_map(static fn ($finding): string => $finding->fingerprint(), $result->findings));
    $filtered = $guard->analyze([$integrationRoot.'/app'], [], $baseline);
    if ($filtered->findings !== []) {
        fwrite(STDERR, "FAIL: baseline filtering integration\n");
        $failed++;
    } else {
        fwrite(STDOUT, '.');
        $passed++;
    }

    $discovered = $guard->discoverPhpFiles([$integrationRoot], ['Jobs']);
    if (count($discovered) !== 4) {
        fwrite(STDERR, "FAIL: file exclusion integration\n");
        $failed++;
    } else {
        fwrite(STDOUT, '.');
        $passed++;
    }

    $missing = (new SourceScanner(ClassMetadataIndex::fromFiles([]), new AnalysisConfig))->scan($integrationRoot.'/missing.php');
    if (count($missing) !== 1 || $missing[0]->rule !== 'TG900') {
        fwrite(STDERR, "FAIL: unreadable source reporting\n");
        $failed++;
    } else {
        fwrite(STDOUT, '.');
        $passed++;
    }

    try {
        new AnalysisConfig(customSideEffectPatterns: ['/[invalid/']);
        fwrite(STDERR, "FAIL: invalid custom regex validation\n");
        $failed++;
    } catch (InvalidArgumentException) {
        fwrite(STDOUT, '.');
        $passed++;
    }
} finally {
    @unlink($integrationRoot.'/app/Jobs/SafeJob.php');
    @unlink($integrationRoot.'/app/Jobs/RoutedJob.php');
    @unlink($integrationRoot.'/app/Services/OrderService.php');
    @unlink($integrationRoot.'/app/Services/UnsafeService.php');
    @unlink($integrationRoot.'/app/Services/QueueRoutes.php');
    @unlink($integrationRoot.'/app/Services/RoutedService.php');
    @rmdir($integrationRoot.'/app/Jobs');
    @rmdir($integrationRoot.'/app/Services');
    @rmdir($integrationRoot.'/app');
    @rmdir($integrationRoot);
}

fwrite(STDOUT, "\n{$passed} total check(s) passed; {$failed} failed.\n");
exit($failed === 0 ? 0 : 1);
