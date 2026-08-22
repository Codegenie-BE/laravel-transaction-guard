from __future__ import annotations

import subprocess
from pathlib import Path

source = Path('src/Analysis/SourceScanner.php')
text = source.read_text()
old = "        $this->scanBroadcasts($findings);\n        $this->scanHttp($findings);\n"
new = "        $this->scanBroadcasts($findings);\n        $this->scanVariablePayloadEffects($findings);\n        $this->scanHttp($findings);\n"
if old not in text:
    raise SystemExit('scan call marker missing')
text = text.replace(old, new, 1)

marker = "    /** @param  list<Finding>  $findings */\n    private function scanHttp(array &$findings): void\n"
method = r'''    /** @param list<Finding> $findings */
    private function scanVariablePayloadEffects(array &$findings): void
    {
        foreach ($this->matches('/(?<![A-Za-z0-9_>:])dispatch\s*\(\s*(?P<var>\$[A-Za-z_][A-Za-z0-9_]*)/i') as $match) {
            $offset = $match['offset'];
            $tx = $this->eligibleTransaction($offset);
            if ($tx === null) {
                continue;
            }

            $class = $this->localNewClassForVariable($offset, $this->captured($match, 'var'));
            $metadata = $class !== null ? $this->classIndex->metadata($class) : null;
            $statement = $this->statementAt($offset);

            if ($metadata !== null && ! $metadata->queued()) {
                $this->appendFinding($findings, $offset, 'TG016', Severity::Warning,
                    "Dispatch of non-queueable [{$this->basename($class)}] executes synchronously while the database transaction is open.",
                    'Move synchronous work outside the transaction when it can observe committed state or produce irreversible effects.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'synchronous job dispatch');

                continue;
            }

            if ($this->jobDispatchIsAfterCommitSafe($statement, $metadata)) {
                continue;
            }

            $this->appendFinding($findings, $offset, 'TG001', $metadata === null ? Severity::Warning : Severity::Error,
                $class === null
                    ? 'A variable job payload is dispatched inside a transaction but its runtime type cannot be proven commit-safe.'
                    : "Job [{$this->basename($class)}] may be dispatched before the surrounding database transaction commits.",
                'Make the payload type statically visible, use afterCommit()/ShouldQueueAfterCommit, or dispatch after the transaction.',
                $metadata === null ? 'medium' : 'high');
            $this->appendRetryFinding($findings, $offset, $tx, 'job dispatch');
        }

        foreach ($this->matches('/(?<![A-Za-z0-9_>:])event\s*\(\s*(?P<var>\$[A-Za-z_][A-Za-z0-9_]*)/i') as $match) {
            $offset = $match['offset'];
            $tx = $this->eligibleTransaction($offset);
            if ($tx === null) {
                continue;
            }

            $class = $this->localNewClassForVariable($offset, $this->captured($match, 'var'));
            $metadata = $class !== null ? $this->classIndex->metadata($class) : null;
            if ($metadata?->eventAfterCommit() === true) {
                continue;
            }

            $this->appendFinding($findings, $offset, 'TG002', Severity::Warning,
                $class === null
                    ? 'A variable event payload is dispatched while the database transaction is still open.'
                    : "Event [{$this->basename($class)}] is dispatched before the surrounding transaction commits.",
                'Implement ShouldDispatchAfterCommit, use DB::afterCommit(), or dispatch after the transaction.',
                $metadata === null ? 'medium' : 'high');
            $this->appendRetryFinding($findings, $offset, $tx, 'event dispatch');
        }

        foreach ($this->matches('/->\s*notify\s*\(\s*(?P<var>\$[A-Za-z_][A-Za-z0-9_]*)/i') as $match) {
            $offset = $match['offset'];
            $tx = $this->eligibleTransaction($offset);
            if ($tx === null) {
                continue;
            }

            $class = $this->localNewClassForVariable($offset, $this->captured($match, 'var'));
            $metadata = $class !== null ? $this->classIndex->metadata($class) : null;
            $statement = $this->statementAt($offset);
            if ($metadata?->queued() === true && $this->jobDispatchIsAfterCommitSafe($statement, $metadata)) {
                continue;
            }

            $this->appendFinding($findings, $offset, 'TG004', Severity::Error,
                $class === null
                    ? 'A variable notification payload may be delivered before the surrounding transaction commits.'
                    : "Notification [{$this->basename($class)}] may be delivered before the surrounding database transaction commits.",
                'Make queued delivery commit-aware or send the notification from DB::afterCommit()/after the transaction.',
                $metadata === null ? 'medium' : 'high');
            $this->appendRetryFinding($findings, $offset, $tx, 'notification delivery');
        }

        foreach ($this->matches('/(?<![A-Za-z0-9_>:])broadcast\s*\(\s*(?P<var>\$[A-Za-z_][A-Za-z0-9_]*)/i') as $match) {
            $offset = $match['offset'];
            $tx = $this->eligibleTransaction($offset);
            if ($tx === null) {
                continue;
            }

            $class = $this->localNewClassForVariable($offset, $this->captured($match, 'var'));
            $metadata = $class !== null ? $this->classIndex->metadata($class) : null;
            $statement = $this->statementAt($offset);
            $broadcastNow = $metadata?->implements('Illuminate\\Contracts\\Broadcasting\\ShouldBroadcastNow') === true;
            if (! $broadcastNow && $metadata?->explicitlyBeforeCommit() !== true
                && ($metadata?->queueAfterCommit() === true || $this->queueConnectionDispatchesAfterCommit($statement, $metadata))) {
                continue;
            }

            $this->appendFinding($findings, $offset, 'TG005', Severity::Error,
                $class === null
                    ? 'A variable broadcast payload may run before the surrounding database transaction commits.'
                    : "Broadcast [{$this->basename($class)}] may run before the surrounding database transaction commits.",
                'Use an explicit after-commit broadcast strategy or broadcast from DB::afterCommit().',
                $metadata === null ? 'medium' : 'high');
            $this->appendRetryFinding($findings, $offset, $tx, 'broadcast');
        }
    }

    private function localNewClassForVariable(int $offset, string $variable): ?string
    {
        $scopeStart = 0;
        $scopeSpan = PHP_INT_MAX;
        foreach ($this->callables as $callable) {
            if ($offset < $callable['start'] || $offset > $callable['end']) {
                continue;
            }
            $span = $callable['end'] - $callable['start'];
            if ($span < $scopeSpan) {
                $scopeStart = $callable['start'];
                $scopeSpan = $span;
            }
        }

        $resolved = null;
        $count = count($this->tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $this->tokens[$i];
            if ($token['offset'] < $scopeStart || $token['offset'] >= $offset || $token['id'] !== T_VARIABLE || $token['text'] !== $variable) {
                continue;
            }

            $assign = $this->nextSignificantToken($i + 1);
            if ($assign === null || $this->tokens[$assign]['text'] !== '=') {
                continue;
            }

            $value = $this->nextSignificantToken($assign + 1);
            if ($value === null || $this->tokens[$value]['id'] !== T_NEW) {
                $resolved = null;
                continue;
            }

            $name = $this->nextSignificantToken($value + 1);
            if ($name === null || ! in_array($this->tokens[$name]['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $resolved = null;
                continue;
            }

            $resolved = $this->context->resolve($this->tokens[$name]['text']);
        }

        return $resolved;
    }

    private function nextSignificantToken(int $start): ?int
    {
        $count = count($this->tokens);
        for ($i = $start; $i < $count; $i++) {
            $id = $this->tokens[$i]['id'];
            if ($id !== null && in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

'''
if marker not in text:
    raise SystemExit('scanHttp marker missing')
source.write_text(text.replace(marker, method + marker, 1))

matrix = Path('tests/Support/ScenarioMatrix.php')
text = matrix.read_text()
marker = "    'global dispatch helper unsafe queued job is flagged' => [\n"
scenarios = r'''    'locally assigned queued job variable is detected' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { $job = new ProcessOrder(); dispatch($job); });
PHP,
        'rules' => ['TG001'],
    ],
    'locally assigned non queueable job variable is synchronous' => [
        'code' => <<<'PHP'
<?php
namespace App\Actions;
use Illuminate\Support\Facades\DB;
class RecalculateOrder {}
DB::transaction(function () { $job = new RecalculateOrder(); dispatch($job); });
PHP,
        'rules' => ['TG016'],
        'absent' => ['TG001'],
    ],
    'locally reassigned job variable is not trusted as original type' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
class SafeJob implements ShouldQueueAfterCommit {}
DB::transaction(function () { $job = new SafeJob(); $job = make_job(); dispatch($job); });
PHP,
        'rules' => ['TG001'],
    ],
    'locally assigned event variable is detected' => [
        'code' => <<<'PHP'
<?php
namespace App\Events;
use Illuminate\Support\Facades\DB;
class OrderCreated {}
DB::transaction(function () { $event = new OrderCreated(); event($event); });
PHP,
        'rules' => ['TG002'],
    ],
    'locally assigned notification variable is detected' => [
        'code' => <<<'PHP'
<?php
namespace App\Notifications;
use Illuminate\Support\Facades\DB;
class ReceiptReady {}
DB::transaction(function () { $notification = new ReceiptReady(); $user->notify($notification); });
PHP,
        'rules' => ['TG004'],
    ],
    'locally assigned broadcast variable is detected' => [
        'code' => <<<'PHP'
<?php
namespace App\Events;
use Illuminate\Support\Facades\DB;
class OrderChanged {}
DB::transaction(function () { $event = new OrderChanged(); broadcast($event); });
PHP,
        'rules' => ['TG005'],
    ],
'''
if marker not in text:
    raise SystemExit('scenario marker missing')
matrix.write_text(text.replace(marker, scenarios + marker, 1))

Path('.audit-request').unlink(missing_ok=True)
Path('tools/point9_patch.py').unlink(missing_ok=True)
base = subprocess.run(['git', 'show', 'origin/main:.github/audit_writer.py'], check=True, capture_output=True, text=True).stdout
Path('.github/audit_writer.py').write_text(base)
