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

function run(string $command): void
{
    passthru($command, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException("Command failed ({$exitCode}): {$command}");
    }
}

replaceOnce(
    'src/Analysis/SourceScanner.php',
    "            \$pattern = '/(?<![A-Za-z0-9_])'.preg_quote(\$alias, '/').'\\s*::\\s*(?P<method>dispatchSync|dispatchAfterResponse|dispatch|chain|batch)\\s*\\(/';\n",
    "            \$pattern = '/(?<![A-Za-z0-9_])'.preg_quote(\$alias, '/').'\\s*::\\s*(?P<method>dispatchSync|dispatchAfterResponse|dispatch|bulk|chain|batch)\\s*\\(/';\n",
);

replaceOnce(
    'src/Analysis/SourceScanner.php',
    <<<'OLD'
                if (in_array($method, ['chain', 'batch'], true)
                    && preg_match('/->\s*dispatch(?:If|Unless)?\s*\(/i', $statement) !== 1) {
                    continue;
                }

                if ($method === 'dispatch') {
OLD,
    <<<'NEW'
                if ($method === 'bulk') {
                    $jobClasses = $this->newClassesFromStatement($statement);
                    if ($jobClasses === []) {
                        $this->appendFinding($findings, $offset, 'TG001', Severity::Warning,
                            'Bus::bulk() contains jobs that cannot be resolved statically and may execute synchronously or enqueue before commit.',
                            'Make bulk job classes statically visible or dispatch the bulk after commit.', 'medium');
                        $this->appendRetryFinding($findings, $offset, $tx, 'bus bulk dispatch');

                        continue;
                    }

                    $hasSynchronous = false;
                    $hasUnsafeQueued = false;
                    $hasUnknown = false;
                    $singleInlineAfterCommit = count($jobClasses) === 1 && $this->statementContainsAfterCommit($statement);

                    foreach ($jobClasses as $jobClass) {
                        $metadata = $this->classIndex->metadata($this->context->resolve($jobClass));
                        if ($metadata === null) {
                            $hasUnknown = true;

                            continue;
                        }
                        if (! $metadata->queued()) {
                            $hasSynchronous = true;

                            continue;
                        }

                        $safe = ! $metadata->explicitlyBeforeCommit()
                            && ($singleInlineAfterCommit
                                || $metadata->queueAfterCommit()
                                || $this->queueConnectionDispatchesAfterCommit($statement, $metadata));
                        if (! $safe) {
                            $hasUnsafeQueued = true;
                        }
                    }

                    if ($hasSynchronous) {
                        $this->appendFinding($findings, $offset, 'TG016', Severity::Warning,
                            'Bus::bulk() includes a non-queueable command that Laravel executes synchronously while the database transaction is open.',
                            'Move the bulk after the transaction or keep synchronous commands outside transactional orchestration.', 'high');
                        $this->appendRetryFinding($findings, $offset, $tx, 'synchronous bus bulk dispatch');
                    }

                    if ($hasUnsafeQueued || $hasUnknown) {
                        $this->appendFinding($findings, $offset, 'TG001', $hasUnsafeQueued ? Severity::Error : Severity::Warning,
                            'Bus::bulk() may enqueue one or more jobs before the surrounding database transaction commits.',
                            'Use commit-aware jobs/queue connections or dispatch the bulk after commit.',
                            $hasUnsafeQueued ? 'high' : 'medium');
                        $this->appendRetryFinding($findings, $offset, $tx, 'bus bulk dispatch');
                    }

                    continue;
                }

                if (in_array($method, ['chain', 'batch'], true)
                    && preg_match('/->\s*dispatch(?:If|Unless)?\s*\(/i', $statement) !== 1) {
                    continue;
                }

                if ($method === 'dispatch') {
NEW,
);

replaceOnce(
    'src/Analysis/SourceScanner.php',
    <<<'OLD'
    private function newClassFromStatement(string $statement): ?string
    {
        if (preg_match('/\bnew\s+(\\?[A-Za-z_][A-Za-z0-9_\\]*)/', $statement, $m) === 1) {
            return $m[1];
        }

        return null;
    }
OLD,
    <<<'NEW'
    private function newClassFromStatement(string $statement): ?string
    {
        return $this->newClassesFromStatement($statement)[0] ?? null;
    }

    /** @return list<string> */
    private function newClassesFromStatement(string $statement): array
    {
        if (preg_match_all('/\bnew\s+(\\?[A-Za-z_][A-Za-z0-9_\\]*)/', $statement, $matches) === false) {
            return [];
        }

        return array_values(array_unique($matches[1] ?? []));
    }
NEW,
);

replaceOnce(
    'tests/Support/ScenarioMatrix.php',
    <<<'OLD'
    'Bus dispatchSync is reported' => [
OLD,
    <<<'NEW'
    'Bus bulk queued job without after commit is flagged' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
class BulkJob implements ShouldQueue {}
DB::transaction(function () { Bus::bulk([new BulkJob()]); });
PHP,
        'rules' => ['TG001'],
        'absent' => ['TG016'],
    ],
    'Bus bulk ShouldQueueAfterCommit job is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
class BulkJob implements ShouldQueueAfterCommit {}
DB::transaction(function () { Bus::bulk([new BulkJob()]); });
PHP,
        'rules' => [],
        'absent' => ['TG001', 'TG016'],
    ],
    'Bus bulk non queueable command is synchronous' => [
        'code' => <<<'PHP'
<?php
namespace App\Commands;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
class BulkCommand {}
DB::transaction(function () { Bus::bulk([new BulkCommand()]); });
PHP,
        'rules' => ['TG016'],
        'absent' => ['TG001'],
    ],
    'Bus bulk mixed commands reports synchronous and queued risk' => [
        'code' => <<<'PHP'
<?php
namespace App\Bulk;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
class QueuedWork implements ShouldQueue {}
class ImmediateWork {}
DB::transaction(function () { Bus::bulk([new QueuedWork(), new ImmediateWork()]); });
PHP,
        'rules' => ['TG001', 'TG016'],
    ],
    'Bus dispatchSync is reported' => [
NEW,
);

replaceOnce(
    'CHANGELOG.md',
    "### Added\n\n",
    "### Added\n\n- Laravel 13 `Bus::bulk()` analysis distinguishes synchronous commands from commit-sensitive queued jobs, including mixed bulk payloads.\n",
);

run('composer update --prefer-stable --prefer-dist --with-all-dependencies --no-interaction --no-progress');
run('vendor/bin/pint src/Analysis/SourceScanner.php tests/Support/ScenarioMatrix.php');
if (is_file('composer.lock')) {
    unlink('composer.lock');
}
