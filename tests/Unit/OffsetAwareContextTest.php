<?php

declare(strict_types=1);

use Codegenie\TransactionGuard\Analysis\AnalysisConfig;
use Codegenie\TransactionGuard\TransactionGuard;

function analyzeOffsetAwareFixture(string $source): array
{
    $temporary = tempnam(sys_get_temp_dir(), 'tg-context-offset-');
    expect($temporary)->not->toBeFalse();
    $file = $temporary.'.php';
    rename($temporary, $file);
    file_put_contents($file, $source);

    try {
        return (new TransactionGuard(new AnalysisConfig))->analyze([$file])->findings;
    } finally {
        @unlink($file);
    }
}

it('resolves class metadata in the namespace active at each match offset', function (): void {
    $findings = analyzeOffsetAwareFixture(<<<'PHP'
<?php
namespace App\First;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class FirstJob implements ShouldQueue {}
DB::transaction(fn () => FirstJob::dispatch());

namespace App\Second;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class SecondJob implements ShouldQueue {}
DB::transaction(fn () => SecondJob::dispatch());
PHP);

    $jobFindings = array_values(array_filter(
        $findings,
        static fn ($finding): bool => $finding->rule === 'TG001',
    ));

    expect($jobFindings)->toHaveCount(2)
        ->and($jobFindings[0]->message)->toContain('FirstJob')
        ->and($jobFindings[1]->message)->toContain('SecondJob');
});

it('does not reuse short class metadata across namespaces', function (): void {
    $findings = analyzeOffsetAwareFixture(<<<'PHP'
<?php
namespace App\First;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
class SharedJob implements ShouldQueueAfterCommit {}
DB::transaction(fn () => SharedJob::dispatch());

namespace App\Second;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class SharedJob implements ShouldQueue {}
DB::transaction(fn () => SharedJob::dispatch());
PHP);

    $jobFindings = array_values(array_filter(
        $findings,
        static fn ($finding): bool => $finding->rule === 'TG001',
    ));

    expect($jobFindings)->toHaveCount(1);
});

it('keeps facade aliases scoped to the namespace where they are imported', function (): void {
    $findings = analyzeOffsetAwareFixture(<<<'PHP'
<?php
namespace App\First;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http as Client;
DB::transaction(fn () => Client::post('https://example.test/first'));

namespace App\Second;
use Illuminate\Support\Facades\DB;
use App\Infrastructure\Client;
DB::transaction(fn () => Client::post('https://example.test/second'));
PHP);

    $httpFindings = array_values(array_filter(
        $findings,
        static fn ($finding): bool => $finding->rule === 'TG006',
    ));

    expect($httpFindings)->toHaveCount(1);
});

it('respects case-insensitive conflicting facade imports', function (): void {
    $findings = analyzeOffsetAwareFixture(<<<'PHP'
<?php
namespace App\First;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(fn () => Http::post('https://example.test/first'));

namespace App\Second;
use Illuminate\Support\Facades\DB;
use App\Infrastructure\Http as hTtP;
DB::transaction(fn () => HTTP::post('https://example.test/second'));
PHP);

    $httpFindings = array_values(array_filter(
        $findings,
        static fn ($finding): bool => $finding->rule === 'TG006',
    ));

    expect($httpFindings)->toHaveCount(1);
});

it('does not create transaction regions from aliases imported in another namespace', function (): void {
    $findings = analyzeOffsetAwareFixture(<<<'PHP'
<?php
namespace App\First;
use Illuminate\Support\Facades\DB as Tx;
Tx::transaction(fn () => 1);

namespace App\Second;
use App\Infrastructure\Transaction as Tx;
use Illuminate\Support\Facades\Http;
Tx::transaction(fn () => Http::post('https://example.test/not-a-db-transaction'));
PHP);

    $httpFindings = array_values(array_filter(
        $findings,
        static fn ($finding): bool => $finding->rule === 'TG006',
    ));

    expect($httpFindings)->toBe([]);
});

it('does not suppress generic static job analysis because another namespace uses the same facade alias', function (): void {
    $findings = analyzeOffsetAwareFixture(<<<'PHP'
<?php
namespace App\First;
use Illuminate\Support\Facades\Event as Task;

namespace App\Second;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class Task implements ShouldQueue {}
DB::transaction(fn () => Task::dispatch());
PHP);

    $jobs = array_values(array_filter(
        $findings,
        static fn ($finding): bool => $finding->rule === 'TG001',
    ));

    expect($jobs)->toHaveCount(1);
});
