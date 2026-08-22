<?php

declare(strict_types=1);

function replaceOnce(string $path, string $old, string $new): void
{
    $contents = file_get_contents($path);
    if ($contents === false || ! str_contains($contents, $old)) {
        throw new RuntimeException("Maintenance marker not found in {$path}.");
    }

    $updated = str_replace($old, $new, $contents, $count);
    if ($count !== 1) {
        throw new RuntimeException("Expected exactly one replacement in {$path}; got {$count}.");
    }

    file_put_contents($path, $updated);
}

replaceOnce(
    'src/Analysis/SourceScanner.php',
    <<<'OLD'
                if ($method === 'dispatch' && $this->callArgumentContainsPreference($statement, 'dispatch', 'beforeCommit')) {
                    $this->appendExplicitBeforeCommitFinding($findings, $offset);
                    $this->appendRetryFinding($findings, $offset, $tx, 'bus dispatch');

                    continue;
                }

                if ($method === 'batch') {
OLD,
    <<<'NEW'
                if ($method === 'dispatch') {
                    $jobClass = $this->newClassFromStatement($statement);
                    $jobMetadata = $jobClass !== null
                        ? $this->classIndex->metadata($this->context->resolve($jobClass))
                        : null;

                    if ($jobMetadata !== null && ! $jobMetadata->queued()) {
                        $this->appendFinding($findings, $offset, 'TG016', Severity::Warning,
                            'Bus::dispatch() executes a non-queueable command synchronously while the database transaction is open.',
                            'Move the command outside the transaction when its handler can observe committed state or cause irreversible effects.', 'high');
                        $this->appendRetryFinding($findings, $offset, $tx, 'synchronous bus dispatch');

                        continue;
                    }

                    if ($this->callArgumentContainsPreference($statement, 'dispatch', 'beforeCommit')
                        || $jobMetadata?->explicitlyBeforeCommit() === true) {
                        $this->appendExplicitBeforeCommitFinding($findings, $offset);
                        $this->appendRetryFinding($findings, $offset, $tx, 'bus dispatch');

                        continue;
                    }

                    if ($jobMetadata !== null) {
                        if ($this->callArgumentContainsPreference($statement, 'dispatch', 'afterCommit')
                            || $jobMetadata->queueAfterCommit()
                            || $this->queueConnectionDispatchesAfterCommit($statement, $jobMetadata)) {
                            continue;
                        }

                        $this->appendFinding($findings, $offset, 'TG001', Severity::Error,
                            "Bus::dispatch() may enqueue [{$this->basename($jobMetadata->name)}] before the surrounding database transaction commits.",
                            'Use afterCommit(), ShouldQueueAfterCommit, a safe queue after_commit policy, or dispatch after the transaction.', 'high',
                            ['queue_connection' => $this->queueConnectionFromStatement($statement, $jobMetadata)]);
                        $this->appendRetryFinding($findings, $offset, $tx, 'bus dispatch');

                        continue;
                    }

                    $this->appendFinding($findings, $offset, 'TG001', Severity::Warning,
                        'Bus::dispatch() cannot be proven queued; the unresolved command may execute synchronously or enqueue before commit.',
                        'Make the command class analyzable, or move dispatch after commit so either Laravel execution path is transaction-safe.', 'medium');
                    $this->appendRetryFinding($findings, $offset, $tx, 'bus dispatch');

                    continue;
                }

                if ($method === 'batch') {
NEW,
);

replaceOnce(
    'tests/Support/ScenarioMatrix.php',
    <<<'OLD'
    'Bus dispatch afterCommit is safe' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { Bus::dispatch((new \App\Jobs\ProcessOrder())->afterCommit()); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
OLD,
    <<<'NEW'
    'Bus dispatch afterCommit is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue { public function afterCommit(): static { return $this; } }
DB::transaction(function () { Bus::dispatch((new ProcessOrder())->afterCommit()); });
PHP,
        'rules' => [],
        'absent' => ['TG001', 'TG016'],
    ],
    'Bus dispatch ShouldQueueAfterCommit command is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueueAfterCommit {}
DB::transaction(function () { Bus::dispatch(new ProcessOrder()); });
PHP,
        'rules' => [],
        'absent' => ['TG001', 'TG016'],
    ],
    'Bus dispatch known queued command is flagged before commit' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { Bus::dispatch(new ProcessOrder()); });
PHP,
        'rules' => ['TG001'],
        'absent' => ['TG016'],
    ],
    'Bus dispatch known non queueable command is synchronous' => [
        'code' => <<<'PHP'
<?php
namespace App\Commands;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
class RecalculateOrder {}
DB::transaction(function () { Bus::dispatch(new RecalculateOrder()); });
PHP,
        'rules' => ['TG016'],
        'absent' => ['TG001'],
    ],
NEW,
);

replaceOnce(
    'CHANGELOG.md',
    "### Changed\n\n",
    "### Changed\n\n- `Bus::dispatch()` now follows Laravel's actual queued-vs-synchronous command semantics and honors indexed after-commit job metadata.\n",
);
