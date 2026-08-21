import re
from pathlib import Path

PATH = Path('src/Analysis/SourceScanner.php')


def replace_once(old: str, new: str) -> None:
    text = PATH.read_text()
    if old not in text:
        raise SystemExit(f'Expected SourceScanner fragment not found: {old[:140]!r}')
    PATH.write_text(text.replace(old, new, 1))


def replace_method(name: str, next_name: str, replacement: str) -> None:
    text = PATH.read_text()
    marker = f'    private function {name}('
    next_marker = f'    private function {next_name}('
    method_pos = text.find(marker)
    next_pos = text.find(next_marker, method_pos + 1)
    if method_pos < 0 or next_pos < 0:
        raise SystemExit(f'Unable to locate method boundary {name} -> {next_name}')

    previous_method = max(
        text.rfind('\n    private function ', 0, method_pos),
        text.rfind('\n    public function ', 0, method_pos),
    )
    doc_pos = text.rfind('\n    /**', 0, method_pos)
    start = doc_pos + 1 if doc_pos > previous_method else method_pos

    next_doc = text.rfind('\n    /**', method_pos, next_pos)
    end = next_doc + 1 if next_doc > method_pos else next_pos
    PATH.write_text(text[:start] + replacement.rstrip() + '\n\n' + text[end:])


Path('src/Analysis/SourceIndex.php').write_text(r'''<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

/**
 * Immutable per-file source lookup tables used by the analyzer hot path.
 *
 * @phpstan-type ScannerToken array{id:int|null,text:string,line:int,offset:int,end:int}
 */
final class SourceIndex
{
    /** @var list<int> */
    private array $lineStarts = [0];

    /** @var list<string> */
    private array $lines = [];

    /** @var list<array{start:int,end:int}> */
    private array $nonCodeRanges = [];

    /**
     * @param  list<ScannerToken>  $tokens
     */
    public function __construct(string $source, array $tokens)
    {
        $this->lines = preg_split('/\R/', $source) ?: [];

        $length = strlen($source);
        for ($offset = 0; $offset < $length; $offset++) {
            if ($source[$offset] === "\n") {
                $this->lineStarts[] = $offset + 1;
            }
        }

        $ignored = [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];
        foreach ($tokens as $token) {
            if ($token['id'] !== null && in_array($token['id'], $ignored, true)) {
                $this->nonCodeRanges[] = ['start' => $token['offset'], 'end' => $token['end']];
            }
        }
    }

    public function lineAt(int $offset): int
    {
        $offset = max(0, $offset);
        $low = 0;
        $high = count($this->lineStarts) - 1;
        $best = 0;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            if ($this->lineStarts[$mid] <= $offset) {
                $best = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return $best + 1;
    }

    public function line(int $number): string
    {
        return $this->lines[$number - 1] ?? '';
    }

    public function isNonCode(int $offset): bool
    {
        $low = 0;
        $high = count($this->nonCodeRanges) - 1;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $range = $this->nonCodeRanges[$mid];

            if ($offset < $range['start']) {
                $high = $mid - 1;
            } elseif ($offset >= $range['end']) {
                $low = $mid + 1;
            } else {
                return true;
            }
        }

        return false;
    }
}
''')

replace_once(
    'final class SourceScanner\n{',
    '''/**
 * @phpstan-type TransactionRegion array{start:int,end:int,line:int,type:string,attempts:int,connection:string,callableStart:int,callableEnd:int}
 * @phpstan-type DatabaseControlCall array{type:string,offset:int,end:int,scope:string,connection:string}
 */
final class SourceScanner
{''',
)

replace_once(
    "    /** @var list<array{start:int,end:int,line:int,type:string,attempts:int,callableStart:int,callableEnd:int}> */\n    private array $transactions = [];",
    "    /** @var list<TransactionRegion> */\n    private array $transactions = [];",
)

replace_once(
    "    private array $afterCommitCallbacks = [];\n\n    private string $source = '';",
    """    private array $afterCommitCallbacks = [];

    private SourceIndex $sourceIndex;

    private string $sourceLower = '';

    /** @var array<int, string> */
    private array $statementCache = [];

    /** @var array<string, list<string>> */
    private array $facadeAliasCache = [];

    private string $source = '';""",
)

replace_once(
    """        }

        $this->callables = $this->findCallableRegions();""",
    """        }

        $this->sourceIndex = new SourceIndex($source, $this->tokens);
        $this->sourceLower = strtolower($source);
        $this->statementCache = [];
        $this->facadeAliasCache = [];

        $this->callables = $this->findCallableRegions();""",
)

text = PATH.read_text()
text, count = re.subn(
    r"\n        foreach \(\$this->findExplicitBeforeCommitCalls\(\) as \$match\) \{.*?\n        \}\n\n",
    '\n',
    text,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit('Unable to remove broad beforeCommit pass')
PATH.write_text(text)

replace_once(
    "        $this->scanConcurrency($findings);\n        $this->scanImplicitCommits($findings);",
    "        $this->scanConcurrency($findings);\n        $this->scanCrossConnectionDatabaseWrites($findings);\n        $this->scanImplicitCommits($findings);",
)

replace_method('scanJobDispatches', 'scanBusAndQueue', r'''    /** @param  list<Finding>  $findings */
    private function scanJobDispatches(array &$findings): void
    {
        if (! $this->sourceContainsAny(['dispatch'])) {
            return;
        }

        $patterns = [
            '/(?<![A-Za-z0-9_\\\\])(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)\s*::\s*(?P<method>dispatchSync|dispatchAfterResponse|dispatchIf|dispatchUnless|dispatch)\s*\(/',
            '/(?<![A-Za-z0-9_>])(?<!->)\\\\?dispatch\s*\(\s*new\s+(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)/i',
            '/(?<![A-Za-z0-9_>])(?<!->)\\\\?(?P<method>dispatch_sync)\s*\(\s*new\s+(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)/i',
        ];

        foreach ($patterns as $pattern) {
            foreach ($this->matches($pattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->transactionAt($offset);
                if ($tx === null || $this->isDeferredNestedCallable($offset, $tx) || $this->isInsideAfterCommitCallback($offset)) {
                    continue;
                }

                $class = $this->captured($match, 'class');
                if ($class === '') {
                    continue;
                }

                $resolved = $this->context->resolve($class);
                $base = strtolower($this->basename($resolved));
                if (in_array($base, ['event', 'bus', 'queue', 'mail', 'notification'], true)) {
                    continue;
                }

                $metadata = $this->classIndex->metadata($resolved);
                $method = $this->captured($match, 'method');
                $statement = $this->statementAt($offset);
                $looksLikeJob = $metadata?->queued() === true
                    || str_contains(strtolower($resolved), '\\jobs\\')
                    || preg_match('/\\\\Jobs\\\\/', $resolved) === 1;

                if (! $looksLikeJob && ! in_array($method, ['dispatchSync', 'dispatch_sync', 'dispatchAfterResponse'], true)) {
                    continue;
                }

                if (in_array($method, ['dispatchSync', 'dispatch_sync'], true)) {
                    $this->appendFinding($findings, $offset, 'TG016', Severity::Warning,
                        "Synchronous job dispatch [{$this->basename($resolved)}] executes while the database transaction is still open.",
                        'Move the dispatch outside the transaction or make any irreversible work explicitly post-commit.',
                        'high', ['transaction_type' => $tx['type'], 'database_connection' => $tx['connection']]);
                    $this->appendRetryFinding($findings, $offset, $tx, 'synchronous job dispatch');
                    continue;
                }

                if ($method === 'dispatchAfterResponse' || $this->statementContainsAfterResponse($statement)) {
                    $this->appendFinding($findings, $offset, 'TG017', Severity::Warning,
                        "After-response dispatch is not a transaction boundary for [{$this->basename($resolved)}].",
                        'Prefer afterCommit() when correctness depends on a successful database commit.',
                        'medium', ['transaction_type' => $tx['type']]);
                    continue;
                }

                if ($this->statementContainsBeforeCommit($statement)) {
                    $this->appendExplicitBeforeCommitFinding($findings, $offset);
                    $this->appendRetryFinding($findings, $offset, $tx, 'job dispatch');
                    continue;
                }

                if ($this->jobDispatchIsAfterCommitSafe($statement, $metadata)) {
                    continue;
                }

                $severity = $metadata === null ? Severity::Warning : Severity::Error;
                $confidence = $metadata === null ? 'medium' : 'high';
                $this->appendFinding($findings, $offset, 'TG001', $severity,
                    "Job [{$this->basename($resolved)}] may be dispatched before the surrounding database transaction commits.",
                    'Chain afterCommit(), implement ShouldQueueAfterCommit, enable after_commit on the selected queue connection, or dispatch after the transaction.',
                    $confidence, ['transaction_type' => $tx['type'], 'queue_connection' => $this->queueConnectionFromStatement($statement, $metadata)]);
                $this->appendRetryFinding($findings, $offset, $tx, 'job dispatch');
            }
        }

        $this->scanQueuedClosureDispatches($findings);
        $this->scanPendingChains($findings);
    }

    /** @param  list<Finding>  $findings */
    private function scanQueuedClosureDispatches(array &$findings): void
    {
        foreach ($this->matches('/(?<![A-Za-z0-9_>])(?<!->)\\\\?dispatch\s*\(\s*(?:static\s+)?(?:function|fn)\b/i') as $match) {
            $offset = $match['offset'];
            $tx = $this->eligibleTransaction($offset);
            if ($tx === null) {
                continue;
            }
            $statement = $this->statementAt($offset);

            if ($this->statementContainsBeforeCommit($statement)) {
                $this->appendExplicitBeforeCommitFinding($findings, $offset);
                $this->appendRetryFinding($findings, $offset, $tx, 'queued closure dispatch');
                continue;
            }

            if ($this->statementContainsAfterResponse($statement)) {
                $this->appendFinding($findings, $offset, 'TG017', Severity::Warning,
                    'A queued closure is deferred until after the response, which is not a database commit boundary.',
                    'Use afterCommit() when the closure depends on committed state.', 'high');
                continue;
            }

            if ($this->statementContainsAfterCommit($statement) || $this->queueConnectionDispatchesAfterCommit($statement)) {
                continue;
            }

            $this->appendFinding($findings, $offset, 'TG001', Severity::Error,
                'A queued closure may execute before the surrounding database transaction commits.',
                'Chain afterCommit(), enable after_commit on the selected queue connection, or dispatch the closure after the transaction.', 'high');
            $this->appendRetryFinding($findings, $offset, $tx, 'queued closure dispatch');
        }

        foreach ($this->matches('/(?<![A-Za-z0-9_>])(?<!->)\\\\?dispatch_sync\s*\(\s*(?:static\s+)?(?:function|fn)\b/i') as $match) {
            $offset = $match['offset'];
            $tx = $this->eligibleTransaction($offset);
            if ($tx === null) {
                continue;
            }
            $this->appendFinding($findings, $offset, 'TG016', Severity::Warning,
                'A synchronously dispatched closure executes while the database transaction is open.',
                'Move synchronous work outside the transaction when it can produce irreversible effects.', 'high');
            $this->appendRetryFinding($findings, $offset, $tx, 'synchronous closure dispatch');
        }
    }

    /** @param  list<Finding>  $findings */
    private function scanPendingChains(array &$findings): void
    {
        foreach ($this->matches('/(?<![A-Za-z0-9_\\\\])(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)\s*::\s*withChain\s*\(/i') as $match) {
            $offset = $match['offset'];
            $tx = $this->eligibleTransaction($offset);
            if ($tx === null) {
                continue;
            }

            $statement = $this->statementAt($offset);
            if (preg_match('/->\s*dispatch(?:If|Unless)?\s*\(/i', $statement) !== 1) {
                continue;
            }

            $resolved = $this->context->resolve($this->captured($match, 'class'));
            $metadata = $this->classIndex->metadata($resolved);

            if ($this->statementContainsBeforeCommit($statement)) {
                $this->appendExplicitBeforeCommitFinding($findings, $offset);
                $this->appendRetryFinding($findings, $offset, $tx, 'job chain dispatch');
                continue;
            }
            if ($this->statementContainsAfterResponse($statement)) {
                $this->appendFinding($findings, $offset, 'TG017', Severity::Warning,
                    'A job chain is deferred until after the response, not after a successful database commit.',
                    'Use afterCommit() on the returned pending dispatch.', 'high');
                continue;
            }
            if ($this->jobDispatchIsAfterCommitSafe($statement, $metadata)) {
                continue;
            }

            $this->appendFinding($findings, $offset, 'TG001', Severity::Error,
                "Job chain [{$this->basename($resolved)}] may be queued before the surrounding transaction commits.",
                'Call afterCommit() on the returned pending dispatch, use a safe queue after_commit policy, or dispatch the chain after commit.', 'high');
            $this->appendRetryFinding($findings, $offset, $tx, 'job chain dispatch');
        }
    }''')

replace_method('scanBusAndQueue', 'scanEvents', r'''    /** @param  list<Finding>  $findings */
    private function scanBusAndQueue(array &$findings): void
    {
        if (! $this->sourceContainsAny(['bus', 'queue'])) {
            return;
        }

        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Bus', 'Bus') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*(?P<method>dispatchSync|dispatchAfterResponse|dispatch|chain|batch)\s*\(/';
            foreach ($this->matches($pattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $statement = $this->statementAt($offset);
                $method = $this->captured($match, 'method');

                if ($method === 'dispatchAfterResponse' || $this->statementContainsAfterResponse($statement)) {
                    $this->appendFinding($findings, $offset, 'TG017', Severity::Warning,
                        'Bus after-response dispatch does not guarantee that the surrounding transaction committed successfully.',
                        'Use an after-commit dispatch when the work depends on committed state.', 'medium');
                    continue;
                }

                if ($method === 'dispatchSync') {
                    $this->appendFinding($findings, $offset, 'TG016', Severity::Warning,
                        'Bus::dispatchSync() executes while the database transaction is still open.',
                        'Move synchronous work outside the transaction when it can cause irreversible side effects.', 'high');
                    $this->appendRetryFinding($findings, $offset, $tx, 'synchronous bus dispatch');
                    continue;
                }

                if ($this->statementContainsBeforeCommit($statement)) {
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

                $this->appendFinding($findings, $offset, 'TG001', Severity::Error,
                    'Bus::dispatch() may execute or enqueue work before the surrounding database transaction commits.',
                    'Chain afterCommit(), enable after_commit on the selected queue connection, or dispatch after the transaction.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'bus dispatch');
            }
        }

        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Queue', 'Queue') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::(?:(?!;).)*?\b(?P<method>pushRaw|laterRaw|push|later|bulk|pushOn|laterOn)\s*\(/s';
            foreach ($this->matches($pattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $statement = $this->statementAt($offset);
                $method = strtolower($this->captured($match, 'method'));

                if (in_array($method, ['pushraw', 'laterraw'], true)) {
                    $this->appendFinding($findings, $offset, 'TG001', Severity::Error,
                        "Queue::{$this->captured($match, 'method')}() bypasses Laravel's job-aware after-commit enqueue path.",
                        'Push raw payloads from DB::afterCommit() or after the transaction; queue after_commit cannot make a raw payload job-aware.', 'high');
                    $this->appendRetryFinding($findings, $offset, $tx, 'raw queue push');
                    continue;
                }

                if ($this->queueConnectionDispatchesAfterCommit($statement)) {
                    continue;
                }

                $severity = $method === 'bulk' ? Severity::Warning : Severity::Error;
                $confidence = $method === 'bulk' ? 'medium' : 'high';
                $this->appendFinding($findings, $offset, 'TG001', $severity,
                    'A job is pushed directly to a queue while the surrounding database transaction is still open.',
                    'Enable after_commit for that queue connection or push the job from DB::afterCommit()/after the transaction.', $confidence);
                $this->appendRetryFinding($findings, $offset, $tx, 'queue push');
            }
        }
    }''')

replace_method('scanEvents', 'scanMail', r'''    /** @param  list<Finding>  $findings */
    private function scanEvents(array &$findings): void
    {
        if (! $this->sourceContainsAny(['event', 'dispatch'])) {
            return;
        }

        $patterns = ['/\bevent\s*\(\s*new\s+(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)/i'];
        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Event', 'Event') as $alias) {
            $patterns[] = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*dispatch\s*\(\s*new\s+(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)/i';

            foreach ($this->matches('/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*defer\s*\(/i') as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG002', Severity::Warning,
                    'Event::defer() waits for its closure to finish, not for the surrounding database transaction to commit.',
                    'Move Event::defer() outside the transaction, wrap it in DB::afterCommit(), or use explicit post-commit event/observer contracts.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'deferred event dispatch');
            }

            foreach ($this->matches('/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*dispatch\s*\(\s*([\'\"])/i') as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG002', Severity::Warning,
                    'A named event is dispatched while the database transaction is still open; synchronous listeners run immediately.',
                    'Dispatch the named event after commit when its listeners may observe or externalize transactional state.', 'medium');
                $this->appendRetryFinding($findings, $offset, $tx, 'named event dispatch');
            }
        }

        foreach ($this->matches('/(?<![A-Za-z0-9_>])(?<!->)\\\\?event\s*\(\s*([\'\"])/i') as $match) {
            $offset = $match['offset'];
            $tx = $this->eligibleTransaction($offset);
            if ($tx === null) {
                continue;
            }
            $this->appendFinding($findings, $offset, 'TG002', Severity::Warning,
                'A named event is dispatched while the database transaction is still open; synchronous listeners run immediately.',
                'Dispatch the named event after commit when its listeners may observe or externalize transactional state.', 'medium');
            $this->appendRetryFinding($findings, $offset, $tx, 'named event dispatch');
        }

        foreach ($patterns as $pattern) {
            foreach ($this->matches($pattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $class = $this->context->resolve($this->captured($match, 'class'));
                $metadata = $this->classIndex->metadata($class);
                if ($metadata?->eventAfterCommit() === true) {
                    continue;
                }

                $this->appendFinding($findings, $offset, 'TG002', Severity::Warning,
                    "Event [{$this->basename($class)}] is dispatched before the surrounding transaction commits; synchronous listeners execute immediately.",
                    'Implement ShouldDispatchAfterCommit on the event, use DB::afterCommit(), or dispatch after the transaction.',
                    $metadata === null ? 'medium' : 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'event dispatch');
            }
        }

        foreach ($this->matches('/(?<![A-Za-z0-9_\\\\])(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)\s*::\s*(?P<method>dispatchIf|dispatchUnless|dispatch)\s*\(/') as $match) {
            $offset = $match['offset'];
            $tx = $this->eligibleTransaction($offset);
            if ($tx === null) {
                continue;
            }
            $class = $this->context->resolve($this->captured($match, 'class'));
            $metadata = $this->classIndex->metadata($class);
            $looksLikeEvent = $metadata?->eventAfterCommit() === true || str_contains(strtolower($class), '\\events\\');
            if (! $looksLikeEvent || $metadata?->eventAfterCommit() === true) {
                continue;
            }
            $this->appendFinding($findings, $offset, 'TG002', Severity::Warning,
                "Event [{$this->basename($class)}] may dispatch before the surrounding transaction commits; synchronous listeners execute immediately.",
                'Implement ShouldDispatchAfterCommit on the event, use DB::afterCommit(), or dispatch after the transaction.',
                $metadata === null ? 'medium' : 'high');
            $this->appendRetryFinding($findings, $offset, $tx, 'event dispatch');
        }
    }''')

# Add lightweight prefilters to unchanged scanners and honor explicit job-object before-commit overrides.
replace_once(
    "    private function scanMail(array &$findings): void\n    {",
    "    private function scanMail(array &$findings): void\n    {\n        if (! $this->sourceContainsAny(['mail'])) {\n            return;\n        }",
)
replace_once(
    """                if ($queued && ! $this->statementContainsBeforeCommit($statement)
                    && ($this->statementContainsAfterCommit($statement) || $metadata?->queueAfterCommit() === true || $this->queueConnectionDispatchesAfterCommit($statement, $metadata))) {""",
    """                $explicitlyBeforeCommit = $this->statementContainsBeforeCommit($statement) || $metadata?->explicitlyBeforeCommit() === true;
                if ($queued && ! $explicitlyBeforeCommit
                    && ($this->statementContainsAfterCommit($statement) || $metadata?->queueAfterCommit() === true || $this->queueConnectionDispatchesAfterCommit($statement, $metadata))) {""",
)
replace_once(
    "    private function scanNotifications(array &$findings): void\n    {",
    "    private function scanNotifications(array &$findings): void\n    {\n        if (! $this->sourceContainsAny(['notify', 'notification'])) {\n            return;\n        }",
)
replace_once(
    """                if ($queued && ! $this->statementContainsBeforeCommit($statement)
                    && ($this->statementContainsAfterCommit($statement) || $metadata?->queueAfterCommit() === true || $this->queueConnectionDispatchesAfterCommit($statement, $metadata))) {""",
    """                $explicitlyBeforeCommit = $this->statementContainsBeforeCommit($statement) || $metadata?->explicitlyBeforeCommit() === true;
                if ($queued && ! $explicitlyBeforeCommit
                    && ($this->statementContainsAfterCommit($statement) || $metadata?->queueAfterCommit() === true || $this->queueConnectionDispatchesAfterCommit($statement, $metadata))) {""",
)

replace_method('scanBroadcasts', 'scanHttp', r'''    /** @param  list<Finding>  $findings */
    private function scanBroadcasts(array &$findings): void
    {
        if (! $this->sourceContainsAny(['broadcast'])) {
            return;
        }

        $patterns = ['/\bbroadcast\s*\(\s*new\s+(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)/i'];
        $patterns[] = '/(?<![A-Za-z0-9_\\\\])(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)\s*::\s*broadcast\s*\(/i';

        foreach ($patterns as $pattern) {
            foreach ($this->matches($pattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $class = $this->context->resolve($this->captured($match, 'class'));
                $metadata = $this->classIndex->metadata($class);
                $statement = $this->statementAt($offset);
                $broadcastNow = $metadata?->implements('Illuminate\\Contracts\\Broadcasting\\ShouldBroadcastNow') === true;

                // Direct broadcast() bypasses event-dispatch deferral. ShouldDispatchAfterCommit
                // alone is therefore not proof of safety; BroadcastEvent copies the event's
                // explicit afterCommit property and otherwise relies on queue configuration.
                if (! $broadcastNow
                    && ($metadata?->queueAfterCommit() === true || $this->queueConnectionDispatchesAfterCommit($statement, $metadata))) {
                    continue;
                }

                $this->appendFinding($findings, $offset, 'TG005', Severity::Error,
                    "Broadcast [{$this->basename($class)}] may run before the surrounding database transaction commits.",
                    'Set the broadcast event afterCommit property, configure its queue connection for after-commit dispatch, or broadcast from DB::afterCommit().',
                    $metadata === null ? 'medium' : 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'broadcast');
            }
        }
    }''')

replace_method('scanHttp', 'scanFilesystem', r'''    /** @param  list<Finding>  $findings */
    private function scanHttp(array &$findings): void
    {
        if (! $this->sourceContainsAny(['http', '->request'])) {
            return;
        }

        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Http', 'Http') as $alias) {
            foreach ($this->matches('/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*(?P<method>pool|batch)\s*\(/i') as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $method = strtolower($this->captured($match, 'method'));
                $this->appendFinding($findings, $offset, 'TG006', Severity::Error,
                    "Http::{$method}() starts outbound network work while a database transaction is open.",
                    'Move pooled/batched HTTP work after commit or use an outbox/idempotent integration pattern.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, "HTTP {$method}");
            }

            $methods = $this->config->detectReadHttpCalls ? 'get|head|query|post|put|patch|delete|send' : 'post|put|patch|delete|send';
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::(?:(?!;).)*?(?P<method>'.$methods.')\s*\(/is';
            foreach ($this->matches($pattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $method = strtoupper($this->captured($match, 'method'));
                $severity = in_array(strtolower($method), ['get', 'head', 'query'], true) ? Severity::Warning : Severity::Error;
                $this->appendFinding($findings, $offset, 'TG006', $severity,
                    "Outbound HTTP {$method} is executed while a database transaction is open.",
                    'Perform non-transactional external I/O after commit, or use an outbox/idempotent integration pattern when atomic delivery matters.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, "HTTP {$method}");
            }
        }

        foreach ($this->matches('/->\s*request\s*\(\s*[\'\"](?P<method>POST|PUT|PATCH|DELETE)[\'\"]/i') as $match) {
            $offset = $match['offset'];
            $tx = $this->eligibleTransaction($offset);
            if ($tx === null) {
                continue;
            }
            $method = strtoupper($this->captured($match, 'method'));
            $this->appendFinding($findings, $offset, 'TG006', Severity::Warning,
                "A client request using HTTP {$method} is executed while a database transaction is open.",
                'Move the external request after commit or make the integration explicitly idempotent.', 'medium');
            $this->appendRetryFinding($findings, $offset, $tx, "HTTP {$method}");
        }
    }''')

replace_method('scanFilesystem', 'scanCache', r'''    /** @param  list<Finding>  $findings */
    private function scanFilesystem(array &$findings): void
    {
        if (! $this->sourceContainsAny(['storage', 'file', 'unlink', 'rename', 'mkdir', 'rmdir', 'symlink', 'chmod', 'touch'])) {
            return;
        }

        $facades = [
            'Illuminate\\Support\\Facades\\Storage' => ['Storage', 'put|putFile|putFileAs|writeStream|write|delete|move|copy|append|prepend|setVisibility|makeDirectory|createDirectory|deleteDirectory'],
            'Illuminate\\Support\\Facades\\File' => ['File', 'put|replace|replaceInFile|delete|move|copy|append|prepend|chmod|link|relativeLink|ensureDirectoryExists|makeDirectory|moveDirectory|copyDirectory|deleteDirectory|deleteDirectories|cleanDirectory'],
        ];

        foreach ($facades as $fqcn => [$fallback, $methods]) {
            foreach ($this->facadeAliases($fqcn, $fallback) as $alias) {
                $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::(?:(?!;).)*?\b(?P<method>'.$methods.')\s*\(/is';
                foreach ($this->matches($pattern) as $match) {
                    $offset = $match['offset'];
                    $tx = $this->eligibleTransaction($offset);
                    if ($tx === null) {
                        continue;
                    }
                    $this->appendFinding($findings, $offset, 'TG007', Severity::Warning,
                        'Filesystem mutation occurs while a database transaction is open and will not be rolled back with the database.',
                        'Move the file mutation after commit or design explicit compensation/cleanup.', 'high');
                    $this->appendRetryFinding($findings, $offset, $tx, 'filesystem mutation');
                }
            }
        }

        foreach ($this->matches('/\b(?P<fn>file_put_contents|unlink|rename|mkdir|rmdir|copy|touch|chmod|symlink|link)\s*\(/i') as $match) {
            $offset = $match['offset'];
            $tx = $this->eligibleTransaction($offset);
            if ($tx === null) {
                continue;
            }
            $this->appendFinding($findings, $offset, 'TG007', Severity::Warning,
                $this->captured($match, 'fn').'() mutates the filesystem while a database transaction is open.',
                'Move the filesystem mutation after commit or add explicit compensation.', 'high');
            $this->appendRetryFinding($findings, $offset, $tx, 'filesystem mutation');
        }
    }''')

replace_method('scanCache', 'scanRedis', r'''    /** @param  list<Finding>  $findings */
    private function scanCache(array &$findings): void
    {
        if (! $this->sourceContainsAny(['cache'])) {
            return;
        }

        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Cache', 'Cache') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::(?:(?!;).)*?\b(?P<method>put|set|putMany|setMultiple|add|forever|remember|rememberWithWarmth|rememberForever|sear|flexible|touch|forget|delete|deleteMultiple|clear|flush|flushLocks|increment|decrement|pull)\s*\(/is';
            foreach ($this->matches($pattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG008', Severity::Warning,
                    'Cache state is mutated before the database transaction commits.',
                    'Invalidate or mutate cache after commit so rollback cannot leave cache and database state inconsistent.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'cache mutation');
            }
        }
    }''')

replace_once(
    "    private function scanRedis(array &$findings): void\n    {",
    "    private function scanRedis(array &$findings): void\n    {\n        if (! $this->sourceContainsAny(['redis'])) {\n            return;\n        }",
)

replace_method('scanProcesses', 'scanConcurrency', r'''    /** @param  list<Finding>  $findings */
    private function scanProcesses(array &$findings): void
    {
        if (! $this->sourceContainsAny(['process', 'exec(', 'shell_exec(', 'system(', 'passthru(', 'proc_open('])) {
            return;
        }

        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Process', 'Process') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::(?:(?!;).)*?\b(?P<method>run|start|pipe|pool)\s*\(/is';
            foreach ($this->matches($pattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG009', Severity::Error,
                    'An external process is started or pooled while a database transaction is open.',
                    'Run external processes after commit or make the operation explicitly idempotent and compensatable.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'external process');
            }
        }

        foreach ($this->matches('/\b(?P<fn>exec|shell_exec|system|passthru|proc_open)\s*\(/i') as $match) {
            $offset = $match['offset'];
            $tx = $this->eligibleTransaction($offset);
            if ($tx === null) {
                continue;
            }
            $this->appendFinding($findings, $offset, 'TG009', Severity::Error,
                $this->captured($match, 'fn').'() executes an external process while a database transaction is open.',
                'Run external commands after commit.', 'high');
            $this->appendRetryFinding($findings, $offset, $tx, 'external process');
        }
    }''')

replace_once(
    "    private function scanConcurrency(array &$findings): void\n    {",
    "    private function scanConcurrency(array &$findings): void\n    {\n        if (! $this->sourceContainsAny(['concurrency', 'defer('])) {\n            return;\n        }",
)

cross_connection = r'''    /** @param  list<Finding>  $findings */
    private function scanCrossConnectionDatabaseWrites(array &$findings): void
    {
        if (! $this->sourceContainsAny(['db', 'database'])) {
            return;
        }

        $mutations = 'insert|insertGetId|insertOrIgnore|insertUsing|update|updateOrInsert|upsert|delete|truncate|increment|decrement|statement|unprepared|affectingStatement';

        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\DB', 'DB') as $alias) {
            $connectionPattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*connection\s*\((?P<connection>[^;]*?)\)\s*->(?:(?![;{}]).)*?\b(?P<method>'.$mutations.')\s*\(/is';
            foreach ($this->matches($connectionPattern) as $match) {
                $this->reportCrossConnectionWrite($findings, $match['offset'], $this->connectionFromExpression($this->captured($match, 'connection')));
            }

            $builderPattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*table\s*\((?:(?![;{}]).)*?\)(?:(?![;{}]).)*?\b(?P<method>'.$mutations.')\s*\(/is';
            foreach ($this->matches($builderPattern) as $match) {
                $this->reportCrossConnectionWrite($findings, $match['offset'], $this->config->defaultDatabaseConnection);
            }

            $directPattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*(?P<method>insert|update|delete|statement|unprepared|affectingStatement)\s*\(/i';
            foreach ($this->matches($directPattern) as $match) {
                $this->reportCrossConnectionWrite($findings, $match['offset'], $this->config->defaultDatabaseConnection);
            }
        }
    }

    /** @param  list<Finding>  $findings */
    private function reportCrossConnectionWrite(array &$findings, int $offset, string $writeConnection): void
    {
        $tx = $this->eligibleTransaction($offset);
        if ($tx === null || $writeConnection === '@dynamic' || $tx['connection'] === '@dynamic' || $writeConnection === $tx['connection']) {
            return;
        }

        $this->appendFinding($findings, $offset, 'TG021', Severity::Error,
            "Database write uses connection [{$writeConnection}] while the active transaction uses [{$tx['connection']}].",
            'Use the transaction connection for all atomic writes, or coordinate separate connections explicitly; Laravel cannot roll back another connection as part of this transaction.',
            'high', ['transaction_connection' => $tx['connection'], 'write_connection' => $writeConnection]);
    }

'''
replace_once(
    "    /** @param list<Finding> $findings */\n    private function scanImplicitCommits",
    cross_connection + "    /** @param list<Finding> $findings */\n    private function scanImplicitCommits",
)
replace_once(
    "    private function scanImplicitCommits(array &$findings): void\n    {",
    "    private function scanImplicitCommits(array &$findings): void\n    {\n        if (! $this->sourceContainsAny(['schema', 'statement(', 'unprepared('])) {\n            return;\n        }",
)

replace_method('scanManualTransactionBalance', 'findClosureTransactions', r'''    /** @param  list<Finding>  $findings */
    private function scanManualTransactionBalance(array &$findings): void
    {
        /** @var array<string, list<DatabaseControlCall>> $stacks */
        $stacks = [];

        foreach ($this->manualControlCalls() as $call) {
            $key = $call['scope'].'|'.$call['connection'];
            if ($call['type'] === 'begin') {
                $stacks[$key][] = $call;
                continue;
            }

            if (in_array($call['type'], ['commit', 'rollback'], true) && ($stacks[$key] ?? []) !== []) {
                array_pop($stacks[$key]);
            }
        }

        foreach ($stacks as $stack) {
            foreach ($stack as $call) {
                $this->appendFinding($findings, $call['offset'], 'TG013', Severity::Critical,
                    "A manually started database transaction on [{$call['connection']}] has no matching commit() or rollBack() on the same connection in this source flow.",
                    'Prefer DB::transaction() or guarantee a same-connection commit/rollback with a try/catch/finally structure.', 'medium',
                    ['database_connection' => $call['connection']]);
            }
        }
    }''')

replace_method('findClosureTransactions', 'findManualTransactions', r'''    /** @return list<TransactionRegion> */
    private function findClosureTransactions(): array
    {
        $regions = [];
        foreach ($this->dbTransactionCalls('transaction') as $call) {
            $offset = $call['offset'];
            $open = $this->tokenIndexAtOrAfter($offset, '(');
            if ($open === null) {
                continue;
            }
            $callClose = $this->matchingToken($open, '(', ')');
            if ($callClose === null) {
                continue;
            }

            $closure = $this->closureWithin($open + 1, $callClose - 1);
            if ($closure === null) {
                continue;
            }

            $attempts = $this->transactionAttempts($closure['endToken'], $callClose);
            $regions[] = [
                'start' => $closure['start'],
                'end' => $closure['end'],
                'line' => $this->lineAtOffset($offset),
                'type' => 'closure',
                'attempts' => $attempts,
                'connection' => $call['connection'],
                'callableStart' => $closure['start'],
                'callableEnd' => $closure['end'],
            ];
        }

        return $regions;
    }''')

replace_method('findManualTransactions', 'manualControlCalls', r'''    /** @return list<TransactionRegion> */
    private function findManualTransactions(): array
    {
        /** @var array<string, list<DatabaseControlCall>> $groups */
        $groups = [];
        foreach ($this->manualControlCalls() as $call) {
            $groups[$call['scope'].'|'.$call['connection']][] = $call;
        }

        $regions = [];
        foreach ($groups as $calls) {
            $groupStart = null;
            $groupEnd = null;
            $depth = 0;

            $flush = function () use (&$regions, &$groupStart, &$groupEnd, &$depth): void {
                if ($groupStart === null) {
                    return;
                }

                $end = $groupEnd ?? strlen($this->source);
                $regions[] = [
                    'start' => $groupStart['end'],
                    'end' => $end,
                    'line' => $this->lineAtOffset($groupStart['offset']),
                    'type' => 'manual',
                    'attempts' => 1,
                    'connection' => $groupStart['connection'],
                    'callableStart' => $groupStart['end'],
                    'callableEnd' => $end,
                ];

                $groupStart = null;
                $groupEnd = null;
                $depth = 0;
            };

            foreach ($calls as $call) {
                if ($call['type'] === 'begin') {
                    if ($groupStart !== null && $depth === 0) {
                        $flush();
                    }
                    if ($groupStart === null) {
                        $groupStart = $call;
                    }
                    $depth++;
                    continue;
                }

                if ($groupStart === null) {
                    continue;
                }

                $groupEnd = $call['offset'];
                if ($depth > 0) {
                    $depth--;
                }
            }

            $flush();
        }

        return $regions;
    }''')

replace_method('manualControlCalls', 'callableScopeAt', r'''    /** @return list<DatabaseControlCall> */
    private function manualControlCalls(): array
    {
        $calls = [];
        foreach (['beginTransaction' => 'begin', 'commit' => 'commit', 'rollBack' => 'rollback'] as $method => $type) {
            foreach ($this->dbTransactionCalls($method) as $dbCall) {
                $offset = $dbCall['offset'];
                $calls[] = [
                    'type' => $type,
                    'offset' => $offset,
                    'end' => $this->statementEnd($offset),
                    'scope' => $this->callableScopeAt($offset),
                    'connection' => $dbCall['connection'],
                ];
            }
        }

        usort($calls, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        return $calls;
    }''')

replace_method('callableScopeAt', 'dbTransactionCallOffsets', r'''    private function callableScopeAt(int $offset): string
    {
        $best = null;
        $bestSpan = PHP_INT_MAX;

        foreach ($this->callables as $callable) {
            if ($offset < $callable['start'] || $offset > $callable['end']) {
                continue;
            }
            $span = $callable['end'] - $callable['start'];
            if ($span < $bestSpan) {
                $best = $callable;
                $bestSpan = $span;
            }
        }

        return $best === null ? 'global' : $best['start'].':'.$best['end'];
    }''')

replace_method('dbTransactionCallOffsets', 'findAfterCommitCallbacks', r'''    /** @return list<int> */
    private function dbTransactionCallOffsets(string $method): array
    {
        return array_values(array_unique(array_map(
            static fn (array $call): int => $call['offset'],
            $this->dbTransactionCalls($method),
        )));
    }

    /** @return list<array{offset:int,connection:string}> */
    private function dbTransactionCalls(string $method): array
    {
        $calls = [];

        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\DB', 'DB') as $alias) {
            $connectionPattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*connection\s*\((?P<connection>[^;]*?)\)\s*->\s*'.preg_quote($method, '/').'\s*\(/is';
            foreach ($this->matches($connectionPattern) as $match) {
                $full = $match['matches'][0][0] ?? '';
                $relative = strripos((string) $full, $method);
                $calls[] = [
                    'offset' => $match['offset'] + ($relative === false ? 0 : $relative),
                    'connection' => $this->connectionFromExpression($this->captured($match, 'connection')),
                ];
            }

            $directPattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*'.preg_quote($method, '/').'\s*\(/i';
            foreach ($this->matches($directPattern) as $match) {
                $full = $match['matches'][0][0] ?? '';
                $relative = strripos((string) $full, $method);
                $calls[] = [
                    'offset' => $match['offset'] + ($relative === false ? 0 : $relative),
                    'connection' => $this->config->defaultDatabaseConnection,
                ];
            }
        }

        usort($calls, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);
        $unique = [];
        foreach ($calls as $call) {
            $unique[$call['offset'].'|'.$call['connection']] = $call;
        }

        return array_values($unique);
    }

    private function connectionFromExpression(string $expression): string
    {
        $expression = trim($expression);
        if ($expression === '' || strcasecmp($expression, 'null') === 0) {
            return $this->config->defaultDatabaseConnection;
        }

        if (preg_match('/^([\'\"])(.*)\1$/s', $expression, $match) === 1) {
            return stripcslashes($match[2]);
        }

        return '@dynamic';
    }''')

# Remove the old generic beforeCommit finder completely.
text = PATH.read_text()
method_pos = text.find('    private function findExplicitBeforeCommitCalls(')
next_pos = text.find('    private function eligibleTransaction(', method_pos)
if method_pos < 0 or next_pos < 0:
    raise SystemExit('Unable to locate findExplicitBeforeCommitCalls')
start_doc = text.rfind('\n    /**', 0, method_pos)
start = start_doc + 1 if start_doc >= 0 else method_pos
next_doc = text.rfind('\n    /**', method_pos, next_pos)
end = next_doc + 1 if next_doc > method_pos else next_pos
PATH.write_text(text[:start] + text[end:])

replace_method('transactionAt', 'isDeferredNestedCallable', r'''    /** @return TransactionRegion|null */
    private function transactionAt(int $offset): ?array
    {
        $best = null;
        $bestSpan = PHP_INT_MAX;

        foreach ($this->transactions as $tx) {
            if ($offset < $tx['start'] || $offset > $tx['end']) {
                continue;
            }
            $span = $tx['end'] - $tx['start'];
            if ($span < $bestSpan) {
                $best = $tx;
                $bestSpan = $span;
            }
        }

        return $best;
    }''')

replace_once(
    "    /** @param array{start:int,end:int,line:int,type:string,attempts:int,callableStart:int,callableEnd:int} $tx */\n    private function isDeferredNestedCallable",
    "    /** @param  TransactionRegion  $tx */\n    private function isDeferredNestedCallable",
)

replace_method('jobDispatchIsAfterCommitSafe', 'queueConnectionDispatchesAfterCommit', r'''    private function jobDispatchIsAfterCommitSafe(string $statement, ?ClassMetadata $metadata): bool
    {
        if ($this->statementContainsBeforeCommit($statement) || $metadata?->explicitlyBeforeCommit() === true) {
            return false;
        }

        return $this->statementContainsAfterCommit($statement)
            || $metadata?->queueAfterCommit() === true
            || ($metadata?->queued() !== false && $this->queueConnectionDispatchesAfterCommit($statement, $metadata));
    }''')

replace_method('facadeAliases', 'appendFinding', r'''    /** @return list<string> */
    private function facadeAliases(string $fqcn, string $fallback): array
    {
        $cacheKey = strtolower(ltrim($fqcn, '\\')).'|'.$fallback;
        if (isset($this->facadeAliasCache[$cacheKey])) {
            return $this->facadeAliasCache[$cacheKey];
        }

        $normalized = ltrim($fqcn, '\\');
        $aliases = ['\\'.$normalized];
        $fallbackImport = $this->context->imports[$fallback] ?? null;
        if ($fallbackImport === null || strcasecmp(ltrim($fallbackImport, '\\'), $normalized) === 0) {
            $aliases[] = $fallback;
        }

        foreach ($this->context->imports as $alias => $import) {
            if (strcasecmp(ltrim($import, '\\'), $normalized) === 0) {
                $aliases[] = $alias;
            }
        }

        return $this->facadeAliasCache[$cacheKey] = array_values(array_unique($aliases));
    }

    /** @param  list<Finding>  $findings */
    private function appendExplicitBeforeCommitFinding(array &$findings, int $offset): void
    {
        $this->appendFinding($findings, $offset, 'TG010', Severity::Error,
            'beforeCommit() explicitly forces dispatch before the surrounding database transaction commits.',
            'Remove beforeCommit(), use afterCommit(), implement an after-commit contract, or move the dispatch outside the transaction.');
    }''')

replace_method('retryableTransactionAt', 'suppressed', r'''    /** @return TransactionRegion|null */
    private function retryableTransactionAt(int $offset): ?array
    {
        $best = null;

        foreach ($this->transactions as $candidate) {
            if ($offset < $candidate['start'] || $offset > $candidate['end'] || $candidate['attempts'] === 1) {
                continue;
            }

            if ($best === null) {
                $best = $candidate;
                continue;
            }

            $candidateKnown = $candidate['attempts'] > 1;
            $bestKnown = $best['attempts'] > 1;
            if ($candidateKnown && ! $bestKnown) {
                $best = $candidate;
                continue;
            }
            if ($candidateKnown === $bestKnown && $candidate['attempts'] > $best['attempts']) {
                $best = $candidate;
            }
        }

        return $best;
    }''')

replace_method('suppressed', 'suppressionDirectiveMatches', r'''    private function suppressed(int $offset, string $rule): bool
    {
        $line = $this->lineAtOffset($offset);
        $current = $this->sourceIndex->line($line);
        if ($this->suppressionDirectiveMatches($current, 'transaction-guard-ignore', $rule, rejectNextLine: true)) {
            return true;
        }

        return $this->suppressionDirectiveMatches(
            $this->sourceIndex->line($line - 1),
            'transaction-guard-ignore-next-line',
            $rule,
        );
    }''')

replace_method('statementAt', 'statementEnd', r'''    private function statementAt(int $offset): string
    {
        if (array_key_exists($offset, $this->statementCache)) {
            return $this->statementCache[$offset];
        }

        $start = $offset;
        while ($start > 0 && ! in_array($this->source[$start - 1], [';', '{', '}'], true)) {
            $start--;
        }

        $end = $this->statementEnd($offset);

        return $this->statementCache[$offset] = substr($this->source, $start, max(0, $end - $start));
    }''')

replace_method('statementEnd', 'basename', r'''    private function statementEnd(int $offset): int
    {
        $index = $this->tokenIndexContainingOrAfterOffset($offset);
        if ($index === null) {
            return min(strlen($this->source), $offset + 1);
        }

        $paren = $bracket = $brace = 0;
        $count = count($this->tokens);

        for ($i = $index; $i < $count; $i++) {
            $text = $this->tokens[$i]['text'];
            if ($text === '(') {
                $paren++;
            } elseif ($text === ')') {
                $paren = max(0, $paren - 1);
            } elseif ($text === '[') {
                $bracket++;
            } elseif ($text === ']') {
                $bracket = max(0, $bracket - 1);
            } elseif ($text === '{') {
                $brace++;
            } elseif ($text === '}') {
                $brace = max(0, $brace - 1);
            } elseif ($text === ';' && $paren === 0 && $bracket === 0 && $brace === 0) {
                return $this->tokens[$i]['end'];
            }
        }

        return min(strlen($this->source), $offset + 1000);
    }''')

replace_method('lineAtOffset', 'tokenize', r'''    private function lineAtOffset(int $offset): int
    {
        return $this->sourceIndex->lineAt($offset);
    }''')

replace_method('tokenIndexAtOrAfter', 'tokenIndexBeforeOffset', r'''    private function tokenIndexAtOrAfter(int $offset, string $text): ?int
    {
        $start = $this->tokenIndexContainingOrAfterOffset($offset);
        if ($start === null) {
            return null;
        }

        $count = count($this->tokens);
        for ($i = $start; $i < $count; $i++) {
            if ($this->tokens[$i]['text'] === $text) {
                return $i;
            }
        }

        return null;
    }

    private function tokenIndexContainingOrAfterOffset(int $offset): ?int
    {
        $low = 0;
        $high = count($this->tokens) - 1;
        $best = null;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $token = $this->tokens[$mid];
            if ($token['end'] <= $offset) {
                $low = $mid + 1;
            } else {
                $best = $mid;
                $high = $mid - 1;
            }
        }

        return $best;
    }''')

replace_method('tokenIndexBeforeOffset', 'nextTokenText', r'''    private function tokenIndexBeforeOffset(int $offset): ?int
    {
        $low = 0;
        $high = count($this->tokens) - 1;
        $best = null;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            if ($this->tokens[$mid]['offset'] < $offset) {
                $best = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return $best;
    }''')

# offsetIsNonCode is between matches() and captured().
replace_method('offsetIsNonCode', 'captured', r'''    private function offsetIsNonCode(int $offset): bool
    {
        return $this->sourceIndex->isNonCode($offset);
    }''')

# Insert a cheap per-file prefilter helper immediately before matches().
replace_once(
    "    /** @return list<array{offset:int,matches:array<int|string,array{0:string,1:int}|string>}> */\n    private function matches",
    """    /** @param  list<string>  $needles */
    private function sourceContainsAny(array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($this->sourceLower, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{offset:int,matches:array<int|string,array{0:string,1:int}|string>}> */
    private function matches""",
)

# Update transaction shape annotations and appendFinding/retry docs.
scanner = PATH.read_text()
scanner = scanner.replace(
    '/** @return array{start:int,end:int,line:int,type:string,attempts:int,callableStart:int,callableEnd:int}|null */',
    '/** @return TransactionRegion|null */',
)
scanner = scanner.replace(
    '/** @param list<Finding> $findings @param array{attempts:int,type:string} $tx */',
    "/**\n     * @param  list<Finding>  $findings\n     * @param  TransactionRegion  $tx\n     */",
)
scanner = scanner.replace(
    '/** @param list<Finding> $findings */\n    private function appendFinding(',
    "/**\n     * @param  list<Finding>  $findings\n     * @param  array<string, scalar|null>  $context\n     */\n    private function appendFinding(",
)
PATH.write_text(scanner)
