<?php

declare(strict_types=1);

function replaceOnce(string $path, string $old, string $new): void
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$path}.");
    }
    $updated = str_replace($old, $new, $contents, $count);
    if ($count !== 1) {
        throw new RuntimeException("Expected one marker in {$path}; got {$count}.");
    }
    file_put_contents($path, $updated);
}

function run(string $command): void
{
    passthru($command, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException("Command failed ({$exitCode}): {$command}");
    }
}

$scenarios = <<<'PHP'
    'Bus bulk queued job without after commit is flagged' => [
        'code' => <<<'CODE'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
class BulkJob implements ShouldQueue {}
DB::transaction(function () { Bus::bulk([new BulkJob()]); });
CODE,
        'rules' => ['TG001'],
        'absent' => ['TG016'],
    ],
    'Bus bulk ShouldQueueAfterCommit job is safe' => [
        'code' => <<<'CODE'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
class BulkJob implements ShouldQueueAfterCommit {}
DB::transaction(function () { Bus::bulk([new BulkJob()]); });
CODE,
        'rules' => [],
        'absent' => ['TG001', 'TG016'],
    ],
    'Bus bulk non queueable command is synchronous' => [
        'code' => <<<'CODE'
<?php
namespace App\Commands;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
class BulkCommand {}
DB::transaction(function () { Bus::bulk([new BulkCommand()]); });
CODE,
        'rules' => ['TG016'],
        'absent' => ['TG001'],
    ],
    'Bus bulk mixed commands reports synchronous and queued risk' => [
        'code' => <<<'CODE'
<?php
namespace App\Bulk;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
class QueuedWork implements ShouldQueue {}
class ImmediateWork {}
DB::transaction(function () { Bus::bulk([new QueuedWork(), new ImmediateWork()]); });
CODE,
        'rules' => ['TG001', 'TG016'],
    ],
PHP;
replaceOnce('tests/Support/ScenarioMatrix.php', "    'Bus dispatchSync is reported' => [\n", $scenarios."    'Bus dispatchSync is reported' => [\n");

replaceOnce(
    'CHANGELOG.md',
    "## [Unreleased]\n\n### Added\n\n",
    "## [Unreleased]\n\n### Added\n\n- Laravel 13 `Bus::bulk()` analysis distinguishes synchronous commands from commit-sensitive queued jobs, including mixed bulk payloads.\n",
);

run('composer update --prefer-stable --prefer-dist --with-all-dependencies --no-interaction --no-progress');
run('vendor/bin/pint src/Analysis/SourceScanner.php tests/Support/ScenarioMatrix.php');
if (is_file('composer.lock')) {
    unlink('composer.lock');
}
