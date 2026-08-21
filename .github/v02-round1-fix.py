from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    file = Path(path)
    text = file.read_text()
    if old not in text:
        raise SystemExit(f'Expected fragment not found in {path}: {old[:160]!r}')
    file.write_text(text.replace(old, new, 1))


# Restore the escape level required by PHP single-quoted PCRE patterns.
path = Path('src/Analysis/ClassMetadataIndex.php')
text = path.read_text()
text = text.replace(
    r"(?P<class>\\?[A-Za-z_][A-Za-z0-9_\\]*)",
    r"(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)",
)
text = text.replace(
    r"(\\?[A-Za-z_][A-Za-z0-9_\\]*)\s*::\s*class",
    r"(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)\s*::\s*class",
)
path.write_text(text)

# Bus pending objects only cause an effect once dispatch()/dispatchIf()/dispatchUnless() is called.
scanner = Path('src/Analysis/SourceScanner.php')
text = scanner.read_text()
text = text.replace(
"""                if ($this->statementContainsBeforeCommit($statement)) {
                    $this->appendExplicitBeforeCommitFinding($findings, $offset);
                    $this->appendRetryFinding($findings, $offset, $tx, 'bus dispatch');

                    continue;
                }

                if ($method === 'batch') {
                    $this->appendFinding($findings, $offset, 'TG001', Severity::Warning,
                        'Bus::batch() is dispatched from inside a database transaction and has no general afterCommit pending-dispatch contract.',
                        'Dispatch the batch from DB::afterCommit() or after the transaction.', 'high');
                    $this->appendRetryFinding($findings, $offset, $tx, 'bus batch dispatch');

                    continue;
                }

                if ($method === 'chain') {
                    if ($this->statementContainsAfterCommit($statement) || $this->queueConnectionDispatchesAfterCommit($statement)) {
                        continue;
                    }
                    $this->appendFinding($findings, $offset, 'TG001', Severity::Warning,
                        'Bus::chain() is dispatched from inside a database transaction and cannot be proven commit-safe.',
                        'Call afterCommit() on the returned pending dispatch or dispatch the chain after commit.', 'medium');
                    $this->appendRetryFinding($findings, $offset, $tx, 'bus chain dispatch');

                    continue;
                }

                if ($this->statementContainsAfterCommit($statement) || $this->queueConnectionDispatchesAfterCommit($statement)) {
                    continue;
                }
""",
"""                if (in_array($method, ['chain', 'batch'], true)
                    && preg_match('/->\\s*dispatch(?:If|Unless)?\\s*\\(/i', $statement) !== 1) {
                    continue;
                }

                if ($method === 'dispatch' && $this->callArgumentContainsPreference($statement, 'dispatch', 'beforeCommit')) {
                    $this->appendExplicitBeforeCommitFinding($findings, $offset);
                    $this->appendRetryFinding($findings, $offset, $tx, 'bus dispatch');

                    continue;
                }

                if ($method === 'batch') {
                    $this->appendFinding($findings, $offset, 'TG001', Severity::Warning,
                        'Bus::batch() is dispatched from inside a database transaction and has no general afterCommit pending-dispatch contract.',
                        'Dispatch the batch from DB::afterCommit() or after the transaction.', 'high');
                    $this->appendRetryFinding($findings, $offset, $tx, 'bus batch dispatch');

                    continue;
                }

                if ($method === 'chain') {
                    if ($this->statementContainsBeforeCommit($statement)) {
                        $this->appendExplicitBeforeCommitFinding($findings, $offset);
                        $this->appendRetryFinding($findings, $offset, $tx, 'bus chain dispatch');

                        continue;
                    }
                    if ($this->statementContainsAfterCommit($statement) || $this->queueConnectionDispatchesAfterCommit($statement)) {
                        continue;
                    }
                    $this->appendFinding($findings, $offset, 'TG001', Severity::Warning,
                        'Bus::chain() is dispatched from inside a database transaction and cannot be proven commit-safe.',
                        'Call afterCommit() on the returned pending dispatch or dispatch the chain after commit.', 'medium');
                    $this->appendRetryFinding($findings, $offset, $tx, 'bus chain dispatch');

                    continue;
                }

                if ($this->callArgumentContainsPreference($statement, 'dispatch', 'afterCommit')
                    || $this->queueConnectionDispatchesAfterCommit($statement)) {
                    continue;
                }
""",
1,
)

# Direct queue push/later methods go through Queue::enqueueUsing and therefore inherit
# job-level afterCommit / ShouldQueueAfterCommit semantics (raw pushes do not).
text = text.replace(
"""                if ($this->queueConnectionDispatchesAfterCommit($statement)) {
                    continue;
                }

                $severity = $method === 'bulk' ? Severity::Warning : Severity::Error;
""",
"""                $jobClass = $this->newClassFromStatement($statement);
                $jobMetadata = $jobClass !== null ? $this->classIndex->metadata($this->context->resolve($jobClass)) : null;
                $jobExplicitlyBeforeCommit = $jobMetadata?->explicitlyBeforeCommit() === true;
                $callMethod = $this->captured($match, 'method');

                if (! $jobExplicitlyBeforeCommit
                    && ($jobMetadata?->queueAfterCommit() === true
                        || $this->callArgumentContainsPreference($statement, $callMethod, 'afterCommit')
                        || $this->queueConnectionDispatchesAfterCommit($statement, $jobMetadata))) {
                    continue;
                }

                $severity = $method === 'bulk' ? Severity::Warning : Severity::Error;
""",
1,
)

# Direct broadcasts must respect an explicit afterCommit=false override before consulting
# the queue connection's after_commit setting.
text = text.replace(
"""                if (! $broadcastNow
                    && ($metadata?->queueAfterCommit() === true || $this->queueConnectionDispatchesAfterCommit($statement, $metadata))) {
                    continue;
                }
""",
"""                if (! $broadcastNow && $metadata?->explicitlyBeforeCommit() !== true
                    && ($metadata?->queueAfterCommit() === true || $this->queueConnectionDispatchesAfterCommit($statement, $metadata))) {
                    continue;
                }
""",
1,
)

# Parse a call's first-level argument range so an invalid `Bus::dispatch(...)->afterCommit()`
# does not masquerade as a commit-safe job argument.
needle = """    private function statementContainsAfterCommit(string $statement): bool
    {
        return preg_match('/->\\s*afterCommit\\s*\\(/i', $statement) === 1;
    }
"""
helper = needle + """
    private function callArgumentContainsPreference(string $statement, string $callMethod, string $preference): bool
    {
        if (preg_match('/::\\s*'.preg_quote($callMethod, '/').'\\s*\\(/i', $statement, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return false;
        }

        $matched = $match[0][0];
        $matchedOffset = $match[0][1];
        $open = $matchedOffset + strlen($matched) - 1;
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($statement);

        for ($i = $open; $i < $length; $i++) {
            $char = $statement[$i];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\\\') {
                    $escaped = true;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '\\'' || $char === '\"') {
                $quote = $char;
                continue;
            }
            if ($char === '(') {
                $depth++;
                continue;
            }
            if ($char !== ')') {
                continue;
            }

            $depth--;
            if ($depth === 0) {
                $arguments = substr($statement, $open + 1, $i - $open - 1);

                return preg_match('/->\\s*'.preg_quote($preference, '/').'\\s*\\(/i', $arguments) === 1;
            }
        }

        return false;
    }
"""
if needle not in text:
    raise SystemExit('Could not find statementContainsAfterCommit helper')
text = text.replace(needle, helper, 1)
scanner.write_text(text)

# Correct the old Bus test to Laravel's valid API: configure the job before Bus::dispatch().
matrix_path = Path('tests/Support/ScenarioMatrix.php')
matrix = matrix_path.read_text()
matrix = matrix.replace(
    "DB::transaction(function () { Bus::dispatch(new \\App\\Jobs\\ProcessOrder())->afterCommit(); });",
    "DB::transaction(function () { Bus::dispatch((new \\App\\Jobs\\ProcessOrder())->afterCommit()); });",
    1,
)

extra = r'''
    'Bus chain creation without dispatch has no side effect' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { Bus::chain([new \App\Jobs\A(), new \App\Jobs\B()]); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'Bus batch creation without dispatch has no side effect' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { Bus::batch([new \App\Jobs\A()]); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'Bus invalid post-dispatch afterCommit chain is not accepted as safety proof' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { Bus::dispatch(new \App\Jobs\ProcessOrder())->afterCommit(); });
PHP,
        'rules' => ['TG001'],
    ],
    'Queue push honors ShouldQueueAfterCommit job contract' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
class ProcessOrder implements ShouldQueueAfterCommit {}
DB::transaction(function () { Queue::push(new ProcessOrder()); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['database' => false]],
    ],
    'Queue push honors explicit job afterCommit preference' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { Queue::push((new ProcessOrder())->afterCommit()); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['database' => false]],
    ],
    'Queue push explicit afterCommit false overrides safe queue config' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
class ProcessOrder implements ShouldQueue { public bool $afterCommit = false; }
DB::transaction(function () { Queue::push(new ProcessOrder()); });
PHP,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
    'direct broadcast explicit afterCommit false overrides queue after_commit' => [
        'code' => <<<'PHP'
<?php
namespace App\Events;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\DB;
class OrderUpdated implements ShouldBroadcast { public bool $afterCommit = false; }
DB::transaction(function () { broadcast(new OrderUpdated()); });
PHP,
        'rules' => ['TG005'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
'''
if not matrix.rstrip().endswith('];'):
    raise SystemExit('Unexpected scenario matrix ending')
matrix = matrix.rstrip()[:-2].rstrip() + '\n' + extra + '\n];\n'
matrix_path.write_text(matrix)
