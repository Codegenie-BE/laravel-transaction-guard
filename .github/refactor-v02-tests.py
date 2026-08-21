import json
from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    file = Path(path)
    text = file.read_text()
    if old not in text:
        raise SystemExit(f'Expected fragment not found in {path}: {old[:140]!r}')
    file.write_text(text.replace(old, new, 1))


# New analyzer config dimension used by cross-connection atomicity checks.
for path in ['tests/Unit/SourceScannerTest.php', 'tools/smoke.php']:
    replace_once(
        path,
        "        detectReadHttpCalls: (bool) ($cfg['detect_read_http_calls'] ?? false),\n",
        "        detectReadHttpCalls: (bool) ($cfg['detect_read_http_calls'] ?? false),\n        defaultDatabaseConnection: (string) ($cfg['database_default'] ?? '@default'),\n",
    )

replace_once(
    'tools/smoke.php',
    "    'src/Analysis/ClassMetadataIndex.php',\n    'src/Analysis/SourceScanner.php',",
    "    'src/Analysis/ClassMetadataIndex.php',\n    'src/Analysis/SourceIndex.php',\n    'src/Analysis/SourceScanner.php',",
)

matrix_path = Path('tests/Support/ScenarioMatrix.php')
matrix = matrix_path.read_text()
matrix = matrix.replace(
    """    'broadcast after commit contract is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\\Events;
use Illuminate\\Contracts\\Broadcasting\\ShouldBroadcast;
use Illuminate\\Contracts\\Events\\ShouldDispatchAfterCommit;
use Illuminate\\Support\\Facades\\DB;
class OrderUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit {}
DB::transaction(function () { broadcast(new OrderUpdated()); });
PHP,
        'rules' => [],
        'absent' => ['TG005'],
    ],""",
    """    'ShouldDispatchAfterCommit alone does not defer direct broadcast helper' => [
        'code' => <<<'PHP'
<?php
namespace App\\Events;
use Illuminate\\Contracts\\Broadcasting\\ShouldBroadcast;
use Illuminate\\Contracts\\Events\\ShouldDispatchAfterCommit;
use Illuminate\\Support\\Facades\\DB;
class OrderUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit {}
DB::transaction(function () { broadcast(new OrderUpdated()); });
PHP,
        'rules' => ['TG005'],
    ],""",
)
matrix = matrix.replace(
    """    'Laravel 13 Queue route array connection is treated conservatively' => [
        'code' => <<<'PHP'
<?php
namespace App\\Jobs;
use Illuminate\\Contracts\\Queue\\ShouldQueue;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Queue;
class ProcessOrder implements ShouldQueue {}
Queue::route([ProcessOrder::class => ['redis', 'orders']]);
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['redis' => true, 'database' => false]],
    ],""",
    """    'Laravel 13 Queue route array connection follows runtime connection-first semantics' => [
        'code' => <<<'PHP'
<?php
namespace App\\Jobs;
use Illuminate\\Contracts\\Queue\\ShouldQueue;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Queue;
class ProcessOrder implements ShouldQueue {}
Queue::route([ProcessOrder::class => ['redis', 'orders']]);
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['redis' => true, 'database' => false]],
    ],""",
)

extra = r'''
    'fully qualified DB and Http facades are detected' => [
        'code' => <<<'PHP'
<?php
\Illuminate\Support\Facades\DB::transaction(function () {
    \Illuminate\Support\Facades\Http::post('https://example.test/capture');
});
PHP,
        'rules' => ['TG006'],
    ],
    'queued closure inside transaction is flagged' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { dispatch(function () { metrics_flush(); }); });
PHP,
        'rules' => ['TG001'],
    ],
    'queued closure chained afterCommit is safe' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { dispatch(function () { Http::post('https://example.test/capture'); })->afterCommit(); });
PHP,
        'rules' => [],
        'absent' => ['TG001', 'TG006'],
    ],
    'queued closure uses safe global queue after_commit' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { dispatch(fn () => metrics_flush()); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
    'queued closure afterResponse is lifecycle risk' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { dispatch(fn () => metrics_flush())->afterResponse(); });
PHP,
        'rules' => ['TG017'],
        'absent' => ['TG001'],
    ],
    'dispatch_sync closure is synchronous risk' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { dispatch_sync(fn () => metrics_flush()); });
PHP,
        'rules' => ['TG016'],
    ],
    'job withChain dispatch is flagged' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::withChain([new ProcessOrder()])->dispatch(); });
PHP,
        'rules' => ['TG001'],
    ],
    'job withChain dispatch afterCommit is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::withChain([new ProcessOrder()])->dispatch()->afterCommit(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'Queue pushRaw is unsafe even on after_commit connection' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
DB::transaction(function () { Queue::connection('redis')->pushRaw('{"id":"1"}'); });
PHP,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
    'Queue laterRaw is unsafe even on after_commit connection' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
DB::transaction(function () { Queue::connection('redis')->laterRaw(10, '{"id":"1"}'); });
PHP,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
    'event static dispatchIf is flagged' => [
        'code' => <<<'PHP'
<?php
namespace App\Events;
use Illuminate\Support\Facades\DB;
class OrderUpdated {}
DB::transaction(function () { OrderUpdated::dispatchIf(true); });
PHP,
        'rules' => ['TG002'],
    ],
    'event static dispatchUnless honors ShouldDispatchAfterCommit' => [
        'code' => <<<'PHP'
<?php
namespace App\Events;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
class OrderUpdated implements ShouldDispatchAfterCommit {}
DB::transaction(function () { OrderUpdated::dispatchUnless(false); });
PHP,
        'rules' => [],
        'absent' => ['TG002'],
    ],
    'named event helper is conservatively reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { event('order.updated'); });
PHP,
        'rules' => ['TG002'],
    ],
    'event static broadcast is flagged' => [
        'code' => <<<'PHP'
<?php
namespace App\Events;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\DB;
class OrderUpdated implements ShouldBroadcast {}
DB::transaction(function () { OrderUpdated::broadcast(); });
PHP,
        'rules' => ['TG005'],
    ],
    'direct broadcast explicit afterCommit property is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Events;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\DB;
class OrderUpdated implements ShouldBroadcast { public bool $afterCommit = true; }
DB::transaction(function () { broadcast(new OrderUpdated()); });
PHP,
        'rules' => [],
        'absent' => ['TG005'],
    ],
    'custom interface extending ShouldQueueAfterCommit is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
interface AtomicJob extends ShouldQueueAfterCommit {}
class ProcessOrder implements AtomicJob {}
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'transitive custom interface inheritance is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
interface AtomicJob extends ShouldQueueAfterCommit {}
interface CriticalAtomicJob extends AtomicJob {}
class ProcessOrder implements CriticalAtomicJob {}
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'ShouldQueueAfterCommit can be explicitly overridden false by property' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueueAfterCommit { public bool $afterCommit = false; }
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
    'ShouldQueue job explicit afterCommit property is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue { public bool $afterCommit = true; }
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'constructor last afterCommit call wins over earlier beforeCommit' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue { public function __construct() { $this->beforeCommit(); $this->afterCommit(); } }
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'constructor last beforeCommit call overrides safe queue config' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue { public function __construct() { $this->afterCommit(); $this->beforeCommit(); } }
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
    'Laravel 13 Queue route on direct trait is respected' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
trait RoutedToRedis {}
class ProcessOrder implements ShouldQueue { use RoutedToRedis; }
Queue::route(RoutedToRedis::class, connection: 'redis');
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['database' => false, 'redis' => true]],
    ],
    'Laravel 13 Queue route on recursively used trait is respected' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
trait RoutedToRedis {}
trait FastJob { use RoutedToRedis; }
class ProcessOrder implements ShouldQueue { use FastJob; }
Queue::route(RoutedToRedis::class, connection: 'redis');
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['database' => false, 'redis' => true]],
    ],
    'Laravel 13 Queue forward resolves Queue attribute connection' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Queue as QueueName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
#[QueueName('emails')]
class ProcessOrder implements ShouldQueue {}
Queue::forward('emails', 'critical-emails', connection: 'redis');
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['database' => false, 'redis' => true]],
    ],
    'Laravel 13 Queue forward resolves constructor onQueue' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
class ProcessOrder implements ShouldQueue { public function __construct() { $this->onQueue('emails'); } }
Queue::forward('emails', 'critical-emails', connection: 'redis');
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['database' => false, 'redis' => true]],
    ],
    'manual transactions are balanced per database connection' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::connection('mysql')->beginTransaction();
DB::connection('pgsql')->commit();
PHP,
        'rules' => ['TG013'],
    ],
    'cross connection database write is reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::connection('mysql')->transaction(function () { DB::connection('pgsql')->table('audit')->insert(['ok' => 1]); });
PHP,
        'rules' => ['TG021'],
        'config' => ['database_default' => 'mysql'],
    ],
    'same connection database write stays atomic' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::connection('mysql')->transaction(function () { DB::connection('mysql')->table('audit')->insert(['ok' => 1]); });
PHP,
        'rules' => [],
        'absent' => ['TG021'],
        'config' => ['database_default' => 'mysql'],
    ],
    'named transaction detects write through different default connection' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::connection('mysql')->transaction(function () { DB::table('audit')->insert(['ok' => 1]); });
PHP,
        'rules' => ['TG021'],
        'config' => ['database_default' => 'pgsql'],
    ],
    'dynamic database connection is not guessed as cross connection' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::connection($tenant)->transaction(function () { DB::connection('pgsql')->table('audit')->insert(['ok' => 1]); });
PHP,
        'rules' => [],
        'absent' => ['TG021'],
        'config' => ['database_default' => 'mysql'],
    ],
    'unrelated beforeCommit method is not treated as Laravel dispatch override' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () use ($service) { $service->beforeCommit(); });
PHP,
        'rules' => [],
        'absent' => ['TG010'],
    ],
    'Http pool inside transaction is reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { Http::pool(fn ($pool) => [$pool->post('https://example.test')]); });
PHP,
        'rules' => ['TG006'],
    ],
    'Http batch inside transaction is reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { Http::batch(fn ($batch) => $batch->post('https://example.test')); });
PHP,
        'rules' => ['TG006'],
    ],
    'Process pool inside transaction is reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
DB::transaction(function () { Process::pool(fn ($pool) => [$pool->command('sync-orders')]); });
PHP,
        'rules' => ['TG009'],
    ],
    'Cache putMany is reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { Cache::putMany(['order:1' => 'paid']); });
PHP,
        'rules' => ['TG008'],
    ],
    'Cache remember is reported because it can mutate cache on miss' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { Cache::remember('order:1', 60, fn () => 'paid'); });
PHP,
        'rules' => ['TG008'],
    ],
    'Storage writeStream is reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
DB::transaction(function () use ($stream) { Storage::writeStream('receipt.txt', $stream); });
PHP,
        'rules' => ['TG007'],
    ],
    'File replaceInFile is reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
DB::transaction(function () { File::replaceInFile('a', 'b', '/tmp/a'); });
PHP,
        'rules' => ['TG007'],
    ],
'''

if not matrix.rstrip().endswith('];'):
    raise SystemExit('Unexpected scenario matrix ending')
matrix = matrix.rstrip()[:-2].rstrip() + '\n' + extra + '\n];\n'
matrix_path.write_text(matrix)

# Discovery regression: `vendor` should match a path segment, not `vendorized`.
transaction_test = Path('tests/Unit/TransactionGuardTest.php')
text = transaction_test.read_text()
text = text.replace(
    """    mkdir($root.'/vendor', 0777, true);
    file_put_contents($root.'/app/A.php', '<?php');""",
    """    mkdir($root.'/vendor', 0777, true);
    mkdir($root.'/app/vendorized', 0777, true);
    file_put_contents($root.'/app/A.php', '<?php');""",
    1,
)
text = text.replace(
    """    file_put_contents($root.'/vendor/C.php', '<?php');

    try {""",
    """    file_put_contents($root.'/vendor/C.php', '<?php');
    file_put_contents($root.'/app/vendorized/Keep.php', '<?php');

    try {""",
    1,
)
text = text.replace(
    """        expect($files)->toHaveCount(2)
            ->and(implode('|', $files))->toContain('A.php')
            ->and(implode('|', $files))->toContain('B.php')
            ->not->toContain('C.php');""",
    """        expect($files)->toHaveCount(3)
            ->and(implode('|', $files))->toContain('A.php')
            ->and(implode('|', $files))->toContain('B.php')
            ->and(implode('|', $files))->toContain('Keep.php')
            ->not->toContain('C.php');""",
    1,
)
text = text.replace(
    """        @unlink($root.'/app/A.php');
        @unlink($root.'/app/readme.txt');""",
    """        @unlink($root.'/app/A.php');
        @unlink($root.'/app/readme.txt');
        @unlink($root.'/app/vendorized/Keep.php');
        @rmdir($root.'/app/vendorized');""",
    1,
)
transaction_test.write_text(text)

# Optional local benchmark: deterministic workload, informational only (no flaky wall-clock gate).
Path('tools/benchmark.php').write_text(r'''<?php

declare(strict_types=1);

$root = dirname(__DIR__);
foreach ([
    'src/Analysis/Severity.php',
    'src/Analysis/Finding.php',
    'src/Analysis/AnalysisConfig.php',
    'src/Analysis/ClassMetadata.php',
    'src/Analysis/FileContext.php',
    'src/Analysis/ClassMetadataIndex.php',
    'src/Analysis/SourceIndex.php',
    'src/Analysis/SourceScanner.php',
    'src/Analysis/AnalysisResult.php',
    'src/Analysis/Baseline.php',
    'src/TransactionGuard.php',
] as $file) {
    require_once $root.'/'.$file;
}

use Codegenie\TransactionGuard\TransactionGuard;

$directory = sys_get_temp_dir().'/transaction-guard-benchmark-'.bin2hex(random_bytes(4));
mkdir($directory, 0777, true);

try {
    for ($file = 0; $file < 50; $file++) {
        $methods = '';
        for ($method = 0; $method < 20; $method++) {
            $methods .= "    public function run{$method}(): void { DB::transaction(function () { DB::table('orders')->update(['paid' => true]); }); }\n";
        }
        file_put_contents($directory."/Service{$file}.php", "<?php\nnamespace App\\Services;\nuse Illuminate\\Support\\Facades\\DB;\nfinal class Service{$file} {\n{$methods}}\n");
    }

    $startMemory = memory_get_usage(true);
    $start = hrtime(true);
    $result = (new TransactionGuard)->analyze([$directory]);
    $elapsedMs = (hrtime(true) - $start) / 1_000_000;
    $memoryMb = (memory_get_peak_usage(true) - $startMemory) / 1024 / 1024;

    printf("Analyzed %d files in %.2f ms; peak delta %.2f MiB; %d findings.\n", $result->filesAnalyzed, $elapsedMs, $memoryMb, count($result->findings));
} finally {
    foreach (glob($directory.'/*.php') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($directory);
}
''')

composer_path = Path('composer.json')
composer = json.loads(composer_path.read_text())
composer['scripts']['benchmark'] = '@php tools/benchmark.php'
composer_path.write_text(json.dumps(composer, indent=4, ensure_ascii=False) + '\n')

# Rule reference: direct broadcast semantics and connection atomicity.
rules = Path('docs/RULES.md').read_text()
rules = rules.replace(
    "Queued broadcasts can race the commit. `ShouldBroadcastNow` is synchronous and therefore remains unsafe even when the default queue connection is configured with `after_commit => true`.",
    "Queued broadcasts can race the commit. `ShouldBroadcastNow` is synchronous and therefore remains unsafe even when the default queue connection is configured with `after_commit => true`. A direct `broadcast(...)` / `Event::broadcast()` call does not become commit-safe merely because the event implements `ShouldDispatchAfterCommit`; Laravel's broadcast manager queues a `BroadcastEvent` directly. An explicit event `afterCommit` value or a queue connection with `after_commit => true` is recognized.",
)
rules += r'''

## TG021 — database write on another connection

Laravel database transactions are connection-scoped. Transaction Guard reports statically known writes that use a different database connection from the surrounding `DB::transaction()` / manual transaction. A rollback on one connection cannot roll back the other connection.

Dynamic connection expressions are intentionally not guessed. When a multi-database workflow is intentional, coordinate it explicitly (for example with an outbox/saga/compensation strategy) rather than assuming cross-connection atomicity.
'''
Path('docs/RULES.md').write_text(rules)

scenario_doc = Path('docs/SCENARIO-MATRIX.md').read_text()
scenario_doc = scenario_doc.replace(
    '- route array syntax is treated conservatively when a safe connection cannot be proven;\n- explicit connection precedence over route configuration.',
    '- current Laravel 13 route-array connection-first runtime semantics;\n- parent/interface and recursive trait routes;\n- `Queue::forward()` with queue attributes / constructor queue names;\n- queued closures, pending chains, raw queue pushes and explicit connection precedence over route configuration.',
)
scenario_doc = scenario_doc.replace(
    '- manual begin/commit/rollback;\n',
    '- manual begin/commit/rollback, balanced per database connection;\n- statically known cross-connection writes (`TG021`);\n',
)
scenario_doc = scenario_doc.replace(
    '- Laravel filesystem mutations;\n',
    '- pooled/batched HTTP work;\n- Laravel filesystem mutations including streams/directory operations;\n',
)
scenario_doc = scenario_doc.replace(
    '- cache writes/invalidation;\n',
    '- modern cache writes/invalidation (`putMany`, `remember*`, `flexible`, etc.);\n',
)
Path('docs/SCENARIO-MATRIX.md').write_text(scenario_doc)

analysis = Path('docs/ANALYSIS.md').read_text()
analysis += r'''

## v0.2 analyzer hardening

The analyzer hot path now pre-indexes source lines and non-code token ranges, caches statement/facade lookups, uses binary-search token/line lookup, and avoids temporary filter/sort allocations when selecting transaction/callable regions. Directory discovery prunes excluded directories before descending into them.

Laravel 13 queue metadata follows the runtime resolver more closely: exact classes, parents, expanded interfaces, recursive traits, route arrays, queue forwarding and `#[Queue]`/constructor queue names are modeled when statically resolvable. Raw queue pushes are treated separately because driver `pushRaw()` paths bypass Laravel's job-aware `enqueueUsing()` after-commit decision.

Manual transaction state carries its database connection. This both prevents a commit on one connection from lexically closing a transaction opened on another and enables high-confidence `TG021` cross-connection write findings.
'''
Path('docs/ANALYSIS.md').write_text(analysis)

design = Path('docs/DESIGN.md').read_text()
design += r'''

## Static-analysis boundary

Transaction Guard deliberately does not pretend to resolve arbitrary macros, reflection, container bindings, dynamic database/queue connection expressions, or user-defined higher-order callbacks. It reports high-confidence framework semantics and leaves dynamic behavior to custom patterns or human review. This is preferable to converting uncertain regex guesses into release-blocking findings.
'''
Path('docs/DESIGN.md').write_text(design)

readme = Path('README.md').read_text()
readme += r'''

### Analyzer efficiency

Transaction Guard performs no runtime instrumentation. Its tokenizer scanner pre-indexes each source file once, prunes excluded directories before traversal, and caches hot-path source lookups. For local profiling of the analyzer itself, maintainers can run `composer benchmark`; the benchmark is informational and intentionally not a timing-based CI gate.
'''
Path('README.md').write_text(readme)

changelog = Path('CHANGELOG.md').read_text()
changelog = changelog.replace(
    '## [Unreleased]\n',
    '''## [Unreleased]\n\n### Added\n\n- Connection-aware manual transaction analysis and `TG021` cross-database-connection write detection.\n- Queued closure, pending chain, raw queue, conditional event, static broadcast, HTTP pool/batch and Process pool coverage.\n- Laravel 13 recursive trait routing, queue forwarding, queue attributes and current route-array connection semantics.\n- Additional cache/filesystem mutation coverage and fully qualified facade support.\n- Informational `composer benchmark` workload for analyzer profiling.\n\n### Changed\n\n- Source scanning pre-indexes lines/non-code ranges, caches repeated lookups and avoids repeated sort/filter work on transaction regions.\n- Recursive file discovery prunes excluded directories before descending and matches exclude path segments precisely.\n- After-commit metadata honors interface inheritance and explicit `afterCommit` property/constructor overrides.\n- Direct broadcasts no longer treat `ShouldDispatchAfterCommit` alone as proof of safety because Laravel queues them through the broadcast manager directly.\n- PHPStan level 8 runs without the previous analyzer-specific ignore baseline.\n\n''',
    1,
)
Path('CHANGELOG.md').write_text(changelog)
