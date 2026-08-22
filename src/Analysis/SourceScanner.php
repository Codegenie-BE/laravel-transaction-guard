<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

use ParseError;

/**
 * @phpstan-type TransactionRegion array{start:int,end:int,line:int,type:string,attempts:int,connection:string,callableStart:int,callableEnd:int}
 * @phpstan-type DatabaseControlCall array{type:string,offset:int,end:int,scope:string,connection:string}
 */
final class SourceScanner
{
    /** @var list<array{id:int|null,text:string,line:int,offset:int,end:int}> */
    private array $tokens = [];

    /** @var list<TransactionRegion> */
    private array $transactions = [];

    /** @var list<array{start:int,end:int}> */
    private array $callables = [];

    /** @var list<array{start:int,end:int}> */
    private array $afterCommitCallbacks = [];

    private SourceIndex $sourceIndex;

    private string $sourceLower = '';

    /** @var array<int, string> */
    private array $statementCache = [];

    /** @var array<string, string> */
    private array $statementCodeCache = [];

    /** @var array<string, list<string>> */
    private array $facadeAliasCache = [];

    /** @var array<int, list<string>> */
    private array $suppressionComments = [];

    private string $source = '';

    private string $file = '';

    private FileContext $context;

    public function __construct(
        private readonly ClassMetadataIndex $classIndex,
        private readonly AnalysisConfig $config = new AnalysisConfig,
    ) {}

    /** @return list<Finding> */
    public function scan(string $file): array
    {
        $source = @file_get_contents($file);
        if ($source === false) {
            return [new Finding(
                rule: 'TG900',
                severity: Severity::Error,
                message: 'The PHP source file could not be read.',
                file: $file,
                line: 1,
                snippet: '',
                remediation: 'Check file permissions and retry the analysis.',
                confidence: 'high',
            )];
        }

        $this->source = $source;
        $this->file = $file;
        $this->context = $this->classIndex->contextFor($file);

        try {
            $this->tokens = $this->tokenize($source);
            $this->suppressionComments = $this->indexSuppressionComments();
        } catch (ParseError $e) {
            return [new Finding(
                rule: 'TG901',
                severity: Severity::Error,
                message: 'The PHP source file could not be parsed: '.$e->getMessage(),
                file: $file,
                line: max(1, $e->getLine()),
                snippet: '',
                remediation: 'Fix the PHP syntax error before running Transaction Guard.',
                confidence: 'high',
            )];
        }

        $this->sourceIndex = new SourceIndex($source, $this->tokens);
        $this->sourceLower = strtolower($source);
        $this->statementCache = [];
        $this->statementCodeCache = [];
        $this->facadeAliasCache = [];

        $this->callables = $this->findCallableRegions();
        $this->transactions = array_merge($this->findClosureTransactions(), $this->findManualTransactions());
        $this->afterCommitCallbacks = $this->findAfterCommitCallbacks();

        $findings = [];

        $this->scanJobDispatches($findings);
        $this->scanBusAndQueue($findings);
        $this->scanEvents($findings);
        $this->scanMail($findings);
        $this->scanNotifications($findings);
        $this->scanBroadcasts($findings);
        $this->scanHttp($findings);
        $this->scanFilesystem($findings);
        $this->scanCache($findings);
        $this->scanRedis($findings);
        $this->scanProcesses($findings);
        $this->scanConcurrency($findings);
        $this->scanCrossConnectionDatabaseWrites($findings);
        $this->scanImplicitCommits($findings);
        $this->scanCustomPatterns($findings);
        $this->scanManualTransactionBalance($findings);

        $unique = [];
        foreach ($findings as $finding) {
            if (! $this->config->ruleEnabled($finding->rule)) {
                continue;
            }
            $key = $finding->rule.'|'.$finding->line.'|'.$finding->snippet;
            $unique[$key] ??= $finding;
        }

        $result = array_values($unique);
        usort($result, static fn (Finding $a, Finding $b): int => [$a->line, -$a->severity->value, $a->rule] <=> [$b->line, -$b->severity->value, $b->rule]);

        return $result;
    }

    /** @param  list<Finding>  $findings */
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
                $globalDispatchHelper = $method === '';
                $standardDispatch = $globalDispatchHelper || in_array($method, ['dispatch', 'dispatchIf', 'dispatchUnless'], true);
                $jobNamespace = str_contains(strtolower($resolved), '\\jobs\\') || preg_match('/\\\\Jobs\\\\/', $resolved) === 1;
                if ($standardDispatch && $metadata !== null && ! $metadata->queued() && ($globalDispatchHelper || $jobNamespace)) {
                    $this->appendFinding($findings, $offset, 'TG016', Severity::Warning,
                        "Dispatch of non-queueable [{$this->basename($resolved)}] executes synchronously while the database transaction is open.",
                        'Move synchronous work outside the transaction when it can observe committed state or produce irreversible effects.',
                        'high', ['transaction_type' => $tx['type'], 'database_connection' => $tx['connection']]);
                    $this->appendRetryFinding($findings, $offset, $tx, 'synchronous job dispatch');

                    continue;
                }

                $looksLikeJob = ($globalDispatchHelper && $metadata === null)
                    || $metadata?->queued() === true
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
    }

    /** @param  list<Finding>  $findings */
    private function scanBusAndQueue(array &$findings): void
    {
        if (! $this->sourceContainsAny(['bus', 'queue'])) {
            return;
        }

        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Bus', 'Bus') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*(?P<method>dispatchSync|dispatchAfterResponse|dispatch|bulk|chain|batch)\s*\(/';
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

                $jobClass = $this->newClassFromStatement($statement);
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
                $confidence = $method === 'bulk' ? 'medium' : 'high';
                $this->appendFinding($findings, $offset, 'TG001', $severity,
                    'A job is pushed directly to a queue while the surrounding database transaction is still open.',
                    'Enable after_commit for that queue connection or push the job from DB::afterCommit()/after the transaction.', $confidence);
                $this->appendRetryFinding($findings, $offset, $tx, 'queue push');
            }
        }
    }

    /** @param  list<Finding>  $findings */
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
    }

    /** @param list<Finding> $findings */
    private function scanMail(array &$findings): void
    {
        if (! $this->sourceContainsAny(['mail'])) {
            return;
        }
        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Mail', 'Mail') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::(?:(?!;).)*?(?P<method>send|queue|later|raw|html|text)\s*\(/s';
            foreach ($this->matches($pattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $statement = $this->statementAt($offset);
                $method = strtolower($this->captured($match, 'method'));
                $class = $this->newClassFromStatement($statement);
                $metadata = $class !== null ? $this->classIndex->metadata($this->context->resolve($class)) : null;
                $queued = in_array($method, ['queue', 'later'], true) || $metadata?->queued() === true;

                $explicitlyBeforeCommit = $this->statementContainsBeforeCommit($statement) || $metadata?->explicitlyBeforeCommit() === true;
                if ($queued && ! $explicitlyBeforeCommit
                    && ($this->statementContainsAfterCommit($statement) || $metadata?->queueAfterCommit() === true || $this->queueConnectionDispatchesAfterCommit($statement, $metadata))) {
                    continue;
                }

                $this->appendFinding($findings, $offset, 'TG003', Severity::Error,
                    'Mail may be sent or queued before the surrounding database transaction commits.',
                    $queued
                        ? 'Call afterCommit() on the mailable, enable queue after_commit, or queue it after the transaction.'
                        : 'Send synchronous mail from DB::afterCommit() or after the transaction so a rollback cannot leave an irreversible email behind.',
                    $metadata === null && $class !== null ? 'medium' : 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'mail delivery');
            }
        }
    }

    /** @param list<Finding> $findings */
    private function scanNotifications(array &$findings): void
    {
        if (! $this->sourceContainsAny(['notify', 'notification'])) {
            return;
        }
        $patterns = [
            '/->\s*(?P<method>notifyNow|notify)\s*\(\s*(?:\(\s*)?new\s+(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)/i',
        ];
        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Notification', 'Notification') as $alias) {
            $patterns[] = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::(?:(?!;).)*?(?P<method>sendNow|send|notify)\s*\([^;]*?new\s+(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)/is';
        }

        foreach ($patterns as $pattern) {
            foreach ($this->matches($pattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $statement = $this->statementAt($offset);
                $method = strtolower($this->captured($match, 'method'));
                $class = $this->context->resolve($this->captured($match, 'class'));
                $metadata = $this->classIndex->metadata($class);
                $queued = ! in_array($method, ['notifynow', 'sendnow'], true) && $metadata?->queued() === true;

                $explicitlyBeforeCommit = $this->statementContainsBeforeCommit($statement) || $metadata?->explicitlyBeforeCommit() === true;
                if ($queued && ! $explicitlyBeforeCommit
                    && ($this->statementContainsAfterCommit($statement) || $metadata->queueAfterCommit() === true || $this->queueConnectionDispatchesAfterCommit($statement, $metadata))) {
                    continue;
                }

                $this->appendFinding($findings, $offset, 'TG004', Severity::Error,
                    "Notification [{$this->basename($class)}] may be delivered before the surrounding database transaction commits.",
                    $queued
                        ? 'Call afterCommit() on the notification, enable queue after_commit, or notify after the transaction.'
                        : 'Send the notification from DB::afterCommit() or after the transaction.',
                    $metadata === null ? 'medium' : 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'notification delivery');
            }
        }
    }

    /** @param  list<Finding>  $findings */
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
                if (! $broadcastNow && $metadata?->explicitlyBeforeCommit() !== true
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
    }

    /** @param  list<Finding>  $findings */
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

        foreach ($this->matches('/->\s*request\s*\(\s*[\'\"](?P<method>POST|PUT|PATCH|DELETE)[\'\"]/i', ['method']) as $match) {
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
    }

    /** @param  list<Finding>  $findings */
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
    }

    /** @param  list<Finding>  $findings */
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
    }

    /** @param list<Finding> $findings */
    private function scanRedis(array &$findings): void
    {
        if (! $this->sourceContainsAny(['redis'])) {
            return;
        }
        $mutating = 'set|setex|psetex|mset|del|unlink|incr|incrby|incrbyfloat|decr|decrby|hset|hmset|hdel|hincrby|lpush|rpush|lpop|rpop|ltrim|sadd|srem|smove|zadd|zincrby|zrem|expire|pexpire|persist|flushdb|flushall|publish|xadd|xdel|xtrim|pipeline|transaction';

        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Redis', 'Redis') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::(?:(?!;).)*?\b(?P<method>'.$mutating.')\s*\(/is';
            foreach ($this->matches($pattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }

                $method = strtolower($this->captured($match, 'method'));
                $severity = $method === 'publish' ? Severity::Error : Severity::Warning;
                $this->appendFinding($findings, $offset, 'TG020', $severity,
                    "Redis::{$method}() mutates non-transactional state while a database transaction is open.",
                    'Move the Redis mutation after commit, or use an idempotent/outbox strategy when both systems must remain consistent.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, "Redis {$method}");
            }

            $commandPattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*command\s*\(\s*[\'\"](?P<command>SET|SETEX|PSETEX|MSET|DEL|UNLINK|INCR|INCRBY|DECR|DECRBY|HSET|HMSET|HDEL|LPUSH|RPUSH|SADD|SREM|ZADD|ZREM|EXPIRE|PEXPIRE|PERSIST|FLUSHDB|FLUSHALL|PUBLISH|XADD|XDEL)[\'\"]/i';
            foreach ($this->matches($commandPattern, ['command']) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }

                $command = strtoupper($this->captured($match, 'command'));
                $severity = $command === 'PUBLISH' ? Severity::Error : Severity::Warning;
                $this->appendFinding($findings, $offset, 'TG020', $severity,
                    "Redis command {$command} mutates non-transactional state while a database transaction is open.",
                    'Move the Redis mutation after commit, or use an idempotent/outbox strategy when both systems must remain consistent.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, "Redis {$command}");
            }
        }
    }

    /** @param  list<Finding>  $findings */
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
    }

    /** @param list<Finding> $findings */
    private function scanConcurrency(array &$findings): void
    {
        if (! $this->sourceContainsAny(['concurrency', 'defer('])) {
            return;
        }
        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Concurrency', 'Concurrency') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\\s*::\\s*(?P<method>run|defer)\\s*\\(/i';
            foreach ($this->matches($pattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $method = strtolower($this->captured($match, 'method'));
                $severity = Severity::Warning;
                $this->appendFinding($findings, $offset, 'TG018', $severity,
                    "Concurrency::{$method}() is invoked while a database transaction is open; child/deferred work is not part of that transaction boundary.",
                    'Move concurrent/deferred work after commit or register it from DB::afterCommit().', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, "concurrency {$method}");
            }
        }

        foreach ($this->matches('/(?<![A-Za-z0-9_])defer\\s*\\(/i') as $match) {
            $offset = $match['offset'];
            $tx = $this->eligibleTransaction($offset);
            if ($tx === null) {
                continue;
            }
            $this->appendFinding($findings, $offset, 'TG018', Severity::Warning,
                'defer() schedules work after the response, not after a successful database commit.',
                'Register deferred work from DB::afterCommit() when it depends on committed state.', 'medium');
            $this->appendRetryFinding($findings, $offset, $tx, 'deferred callback');
        }
    }

    /** @param  list<Finding>  $findings */
    private function scanCrossConnectionDatabaseWrites(array &$findings): void
    {
        if (! $this->sourceContainsAny(['db', 'database'])) {
            return;
        }

        $mutations = 'insert|insertGetId|insertOrIgnore|insertUsing|update|updateOrInsert|upsert|delete|truncate|increment|decrement|statement|unprepared|affectingStatement';

        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\DB', 'DB') as $alias) {
            $connectionPattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*connection\s*\(\s*(?P<quote>[\'\"])(?P<connection>[^\'\"]+)\k<quote>\s*\)\s*->(?:(?![;{}]).)*?\b(?P<method>'.$mutations.')\s*\(/is';
            foreach ($this->matches($connectionPattern) as $match) {
                $this->reportCrossConnectionWrite($findings, $match['offset'], $this->captured($match, 'connection'));
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

    /** @param list<Finding> $findings */
    private function scanImplicitCommits(array &$findings): void
    {
        if (! $this->sourceContainsAny(['schema', 'statement(', 'unprepared('])) {
            return;
        }
        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Schema', 'Schema') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*(?P<method>create|table|drop|dropIfExists|rename|disableForeignKeyConstraints|enableForeignKeyConstraints)\s*\(/i';
            foreach ($this->matches($pattern) as $match) {
                $offset = $match['offset'];
                if ($this->eligibleTransaction($offset) === null) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG012', Severity::Critical,
                    'Schema/DDL work inside a transaction can trigger an implicit database commit and leave Laravel transaction state out of sync.',
                    'Perform schema changes outside application transactions. Never rely on a surrounding transaction to roll back DDL.', 'high');
            }
        }

        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\DB', 'DB') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*(?P<method>statement|unprepared)\s*\(\s*(?P<quote>[\'\"])(?P<sql>.*?)(?:\\k<quote>)/is';
            foreach ($this->matches($pattern) as $match) {
                $offset = $match['offset'];
                if ($this->eligibleTransaction($offset) === null) {
                    continue;
                }
                $sql = ltrim($this->captured($match, 'sql'));
                if (preg_match('/^(CREATE|ALTER|DROP|TRUNCATE|RENAME|LOCK\s+TABLES|UNLOCK\s+TABLES|SET\s+AUTOCOMMIT)\b/i', $sql) !== 1) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG012', Severity::Critical,
                    'A SQL statement that may implicitly commit is executed inside a Laravel transaction.',
                    'Move implicit-commit DDL outside the transaction and let migrations/schema tooling own schema changes.', 'high');
            }
        }
    }

    /** @param list<Finding> $findings */
    private function scanCustomPatterns(array &$findings): void
    {
        foreach ($this->config->customSideEffectPatterns as $pattern) {
            $regex = str_starts_with($pattern, '/') ? $pattern : '/'.str_replace('/', '\\/', $pattern).'/';
            foreach ($this->matches($regex) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG100', Severity::Warning,
                    'A configured custom side-effect pattern is executed while a database transaction is open.',
                    'Move the side effect after commit or suppress this finding only after verifying transactional safety.', 'medium');
                $this->appendRetryFinding($findings, $offset, $tx, 'custom side effect');
            }
        }
    }

    /** @param  list<Finding>  $findings */
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
    }

    /** @return list<TransactionRegion> */
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
    }

    /** @return list<TransactionRegion> */
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

            /** @param array{type:string,offset:int,end:int,scope:string,connection:string}|null $start */
            $flush = function (?array $start, ?int $endOffset) use (&$regions): void {
                if ($start === null) {
                    return;
                }
                if (! isset($start['offset'], $start['end'], $start['connection'])
                    || ! is_int($start['offset'])
                    || ! is_int($start['end'])
                    || ! is_string($start['connection'])) {
                    return;
                }

                $end = $endOffset ?? strlen($this->source);
                $regions[] = [
                    'start' => $start['end'],
                    'end' => $end,
                    'line' => $this->lineAtOffset($start['offset']),
                    'type' => 'manual',
                    'attempts' => 1,
                    'connection' => $start['connection'],
                    'callableStart' => $start['end'],
                    'callableEnd' => $end,
                ];
            };

            foreach ($calls as $call) {
                if ($call['type'] === 'begin') {
                    if ($groupStart !== null && $depth === 0) {
                        $flush($groupStart, $groupEnd);
                        $groupStart = null;
                        $groupEnd = null;
                        $depth = 0;
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

            $flush($groupStart, $groupEnd);
        }

        return $regions;
    }

    /** @return list<DatabaseControlCall> */
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
    }

    private function callableScopeAt(int $offset): string
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
    }

    /** @return list<int> */
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
    }

    /** @return list<array{start:int,end:int}> */
    private function findAfterCommitCallbacks(): array
    {
        $regions = [];
        foreach ($this->dbTransactionCallOffsets('afterCommit') as $offset) {
            $open = $this->tokenIndexAtOrAfter($offset, '(');
            if ($open === null) {
                continue;
            }
            $close = $this->matchingToken($open, '(', ')');
            if ($close === null) {
                continue;
            }
            $closure = $this->closureWithin($open + 1, $close - 1);
            if ($closure !== null) {
                $regions[] = ['start' => $closure['start'], 'end' => $closure['end']];
            }
        }

        return $regions;
    }

    /** @return list<array{start:int,end:int}> */
    private function findCallableRegions(): array
    {
        $regions = [];
        $count = count($this->tokens);
        for ($i = 0; $i < $count; $i++) {
            $id = $this->tokens[$i]['id'];
            if ($id === T_FUNCTION) {
                $open = $this->nextTokenText($i + 1, '{');
                if ($open === null) {
                    continue;
                }
                $close = $this->matchingToken($open, '{', '}');
                if ($close !== null) {
                    $regions[] = ['start' => $this->tokens[$open]['end'], 'end' => $this->tokens[$close]['offset']];
                }
            } elseif ($id === T_FN) {
                $arrow = $this->nextTokenText($i + 1, '=>');
                if ($arrow === null) {
                    continue;
                }
                $end = $this->arrowExpressionEnd($arrow + 1);
                $regions[] = ['start' => $this->tokens[$arrow]['end'], 'end' => $end];
            }
        }

        return $regions;
    }

    /** @return array{start:int,end:int,startToken:int,endToken:int}|null */
    private function closureWithin(int $startToken, int $endToken): ?array
    {
        for ($i = $startToken; $i <= $endToken; $i++) {
            $id = $this->tokens[$i]['id'];
            if ($id === T_FUNCTION) {
                $open = $this->nextTokenText($i + 1, '{', $endToken);
                if ($open === null) {
                    continue;
                }
                $close = $this->matchingToken($open, '{', '}', $endToken);
                if ($close === null) {
                    continue;
                }

                return [
                    'start' => $this->tokens[$open]['end'],
                    'end' => $this->tokens[$close]['offset'],
                    'startToken' => $open,
                    'endToken' => $close,
                ];
            }

            if ($id === T_FN) {
                $arrow = $this->nextTokenText($i + 1, '=>', $endToken);
                if ($arrow === null) {
                    continue;
                }
                $end = $this->arrowExpressionEnd($arrow + 1, $endToken);

                return [
                    'start' => $this->tokens[$arrow]['end'],
                    'end' => $end,
                    'startToken' => $arrow,
                    'endToken' => $this->tokenIndexBeforeOffset($end) ?? $arrow,
                ];
            }
        }

        return null;
    }

    private function transactionAttempts(int $closureEndToken, int $callCloseToken): int
    {
        $start = $this->tokens[$closureEndToken]['end'];
        $end = $this->tokens[$callCloseToken]['offset'];
        $tail = substr($this->source, $start, max(0, $end - $start));

        if (preg_match('/,\s*attempts\s*:\s*(\d+)/i', $tail, $m) === 1) {
            return max(1, (int) $m[1]);
        }
        if (preg_match('/,\s*(\d+)\s*$/s', $tail, $m) === 1) {
            return max(1, (int) $m[1]);
        }
        if (str_contains($tail, ',')) {
            return 0; // A dynamic attempts expression exists but cannot be resolved statically.
        }

        return 1;
    }

    /** @param  list<string>  $needles */
    private function sourceContainsAny(array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($this->sourceLower, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $allowNonCodeCaptures
     * @return list<array{offset:int,matches:array<int|string,array{0:string,1:int}|string>}>
     */
    private function matches(string $pattern, array $allowNonCodeCaptures = []): array
    {
        $result = [];
        $ok = @preg_match_all($pattern, $this->source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($ok === false || $ok === 0) {
            return [];
        }

        foreach ($matches as $match) {
            $offset = $match[0][1];
            if ($this->offsetIsNonCode($offset) || $this->semanticCaptureIsNonCode($match, $allowNonCodeCaptures)) {
                continue;
            }
            $result[] = ['offset' => $offset, 'matches' => $match];
        }

        return $result;
    }

    /**
     * @param  array<int|string, mixed>  $match
     * @param  list<string>  $allowNonCodeCaptures
     */
    private function semanticCaptureIsNonCode(array $match, array $allowNonCodeCaptures = []): bool
    {
        $allowed = array_fill_keys($allowNonCodeCaptures, true);

        foreach (['method', 'class', 'fn', 'command'] as $name) {
            if (isset($allowed[$name])) {
                continue;
            }

            $capture = $match[$name] ?? null;
            if (! is_array($capture)) {
                continue;
            }

            $offset = $capture[1] ?? null;
            if (is_int($offset) && $offset >= 0 && $this->offsetIsNonCode($offset)) {
                return true;
            }
        }

        return false;
    }

    private function offsetIsNonCode(int $offset): bool
    {
        return $this->sourceIndex->isNonCode($offset);
    }

    /** @param array{matches:array<int|string,mixed>} $match */
    private function captured(array $match, string $name): string
    {
        $value = $match['matches'][$name] ?? '';
        if (is_array($value)) {
            $captured = $value[0] ?? '';

            return is_string($captured) ? $captured : '';
        }

        return is_string($value) ? $value : '';
    }

    /** @return TransactionRegion|null */
    private function eligibleTransaction(int $offset): ?array
    {
        $tx = $this->transactionAt($offset);
        if ($tx === null || $this->isInsideAfterCommitCallback($offset) || $this->isDeferredNestedCallable($offset, $tx)) {
            return null;
        }

        return $tx;
    }

    /** @return TransactionRegion|null */
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
    }

    /** @param  TransactionRegion  $tx */
    private function isDeferredNestedCallable(int $offset, array $tx): bool
    {
        foreach ($this->callables as $callable) {
            if ($callable['start'] <= $tx['start'] || $callable['end'] >= $tx['end']) {
                continue;
            }
            if ($offset < $callable['start'] || $offset > $callable['end']) {
                continue;
            }

            $tail = ltrim(substr($this->source, $callable['end'], 24));
            if (str_starts_with($tail, '(') || preg_match('/^}\s*\)?\s*\(/', $tail) === 1) {
                return false; // Immediately invoked closure.
            }

            return true;
        }

        return false;
    }

    private function isInsideAfterCommitCallback(int $offset): bool
    {
        foreach ($this->afterCommitCallbacks as $region) {
            if ($offset >= $region['start'] && $offset <= $region['end']) {
                return true;
            }
        }

        return false;
    }

    private function jobDispatchIsAfterCommitSafe(string $statement, ?ClassMetadata $metadata): bool
    {
        if ($this->statementContainsBeforeCommit($statement) || $metadata?->explicitlyBeforeCommit() === true) {
            return false;
        }

        return $this->statementContainsAfterCommit($statement)
            || $metadata?->queueAfterCommit() === true
            || ($metadata?->queued() !== false && $this->queueConnectionDispatchesAfterCommit($statement, $metadata));
    }

    private function queueConnectionDispatchesAfterCommit(string $statement, ?ClassMetadata $metadata = null): bool
    {
        $connection = $this->queueConnectionFromStatement($statement, $metadata);

        return $this->config->queueDispatchesAfterCommit($connection);
    }

    private function queueConnectionFromStatement(string $statement, ?ClassMetadata $metadata = null): ?string
    {
        $code = $this->codeOnlyFragment($statement);
        if (preg_match('/->\s*onConnection\s*\(/i', $code, $call, PREG_OFFSET_CAPTURE) === 1) {
            $literal = $this->literalStringArgumentFromCall(substr($statement, $call[0][1]));

            return $literal ?? '@dynamic';
        }

        foreach ($this->facadeAliases('Illuminate\Support\Facades\Queue', 'Queue') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*connection\s*\(/i';
            if (preg_match($pattern, $code, $call, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $literal = $this->literalStringArgumentFromCall(substr($statement, $call[0][1]));

            return $literal ?? '@dynamic';
        }

        if ($metadata?->constructorQueueConnection !== null) {
            return $metadata->constructorQueueConnection;
        }

        return $metadata === null ? null : $this->classIndex->queueRouteConnection($metadata->name);
    }

    private function literalStringArgumentFromCall(string $call): ?string
    {
        $tokens = token_get_all('<?php '.$call);
        $insideArguments = false;

        foreach ($tokens as $token) {
            if (! $insideArguments) {
                if ($token === '(') {
                    $insideArguments = true;
                }

                continue;
            }

            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                return null;
            }

            $literal = $token[1];
            if (strlen($literal) < 2) {
                return null;
            }

            return stripcslashes(substr($literal, 1, -1));
        }

        return null;
    }

    private function statementContainsAfterCommit(string $statement): bool
    {
        return preg_match('/->\s*afterCommit\s*\(/i', $this->codeOnlyFragment($statement)) === 1;
    }

    private function callArgumentContainsPreference(string $statement, string $callMethod, string $preference): bool
    {
        $code = $this->codeOnlyFragment($statement);
        if (preg_match('/::\s*'.preg_quote($callMethod, '/').'\s*\(/i', $code, $match, PREG_OFFSET_CAPTURE) !== 1) {
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
                if (ord($char) === 92) {
                    $escaped = true;

                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === "'" || $char === '"') {
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

                return preg_match('/->\s*'.preg_quote($preference, '/').'\s*\(/i', $this->codeOnlyFragment($arguments)) === 1;
            }
        }

        return false;
    }

    private function statementContainsBeforeCommit(string $statement): bool
    {
        return preg_match('/->\s*beforeCommit\s*\(/i', $this->codeOnlyFragment($statement)) === 1;
    }

    private function statementContainsAfterResponse(string $statement): bool
    {
        return preg_match('/->\s*afterResponse\s*\(/i', $this->codeOnlyFragment($statement)) === 1;
    }

    private function newClassFromStatement(string $statement): ?string
    {
        return $this->newClassesFromStatement($statement)[0] ?? null;
    }

    /** @return list<string> */
    private function newClassesFromStatement(string $statement): array
    {
        $tokens = token_get_all('<?php '.$statement);
        $classes = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_NEW) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                $token = $tokens[$j];
                if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                    $classes[] = $token[1];
                }
                break;
            }
        }

        return array_values(array_unique($classes));
    }

    private function codeOnlyFragment(string $fragment): string
    {
        if (array_key_exists($fragment, $this->statementCodeCache)) {
            return $this->statementCodeCache[$fragment];
        }

        $prefix = '<?php ';
        $masked = '';
        foreach (token_get_all($prefix.$fragment) as $token) {
            if (! is_array($token)) {
                $masked .= $token;

                continue;
            }

            [$id, $text] = $token;
            if (in_array($id, [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $text = preg_replace('/[^\r\n]/', ' ', $text) ?? $text;
            }
            $masked .= $text;
        }

        return $this->statementCodeCache[$fragment] = substr($masked, strlen($prefix));
    }

    /** @return list<string> */
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
    }

    /**
     * @param  list<Finding>  $findings
     * @param  array<string, scalar|null>  $context
     */
    private function appendFinding(
        array &$findings,
        int $offset,
        string $rule,
        Severity $severity,
        string $message,
        string $remediation,
        string $confidence = 'high',
        array $context = [],
    ): void {
        if (! $this->config->ruleEnabled($rule) || $this->suppressed($offset, $rule)) {
            return;
        }

        $findings[] = new Finding(
            rule: $rule,
            severity: $severity,
            message: $message,
            file: $this->file,
            line: $this->lineAtOffset($offset),
            snippet: trim($this->statementAt($offset)),
            remediation: $remediation,
            confidence: $confidence,
            context: $context,
        );
    }

    /**
     * @param  list<Finding>  $findings
     * @param  TransactionRegion  $tx
     */
    private function appendRetryFinding(array &$findings, int $offset, array $tx, string $kind): void
    {
        $retry = $this->retryableTransactionAt($offset) ?? $tx;
        if ($retry['attempts'] === 1) {
            return;
        }

        if ($retry['attempts'] === 0) {
            $this->appendFinding($findings, $offset, 'TG011', Severity::Warning,
                "Non-transactional {$kind} is inside a transaction with a dynamic retry-attempt expression and may execute more than once if retries are enabled.",
                'Move the side effect after commit, make retries statically explicit, or make the effect idempotent.',
                'medium', ['attempts' => 'dynamic', 'transaction_type' => $retry['type']]);

            return;
        }

        $this->appendFinding($findings, $offset, 'TG011', Severity::Critical,
            "Non-transactional {$kind} is inside a transaction configured for {$retry['attempts']} attempts and may execute more than once when Laravel retries after a deadlock.",
            'Move the side effect after commit or make it idempotent and dispatch it only from a successful commit callback.',
            'high', ['attempts' => $retry['attempts'], 'transaction_type' => $retry['type']]);
    }

    /** @return TransactionRegion|null */
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
    }

    private function suppressed(int $offset, string $rule): bool
    {
        $line = $this->lineAtOffset($offset);

        foreach ($this->suppressionComments[$line] ?? [] as $comment) {
            if ($this->suppressionDirectiveMatches($comment, 'transaction-guard-ignore', $rule, rejectNextLine: true)) {
                return true;
            }
        }

        foreach ($this->suppressionComments[$line - 1] ?? [] as $comment) {
            if ($this->suppressionDirectiveMatches($comment, 'transaction-guard-ignore-next-line', $rule)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, list<string>> */
    private function indexSuppressionComments(): array
    {
        $comments = [];

        foreach ($this->tokens as $token) {
            if (! in_array($token['id'], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $lines = preg_split('/\R/', $token['text']) ?: [$token['text']];
            foreach ($lines as $offset => $text) {
                $comments[$token['line'] + $offset][] = $text;
            }
        }

        return $comments;
    }

    private function suppressionDirectiveMatches(string $line, string $directive, string $rule, bool $rejectNextLine = false): bool
    {
        $pattern = '/'.preg_quote($directive, '/').'(?:\s+([A-Z0-9, ]+))?/i';
        if (preg_match($pattern, $line, $match) !== 1) {
            return false;
        }
        if ($rejectNextLine && stripos($line, 'transaction-guard-ignore-next-line') !== false) {
            return false;
        }

        $rules = trim($match[1] ?? '');
        if ($rules === '') {
            return true;
        }

        return in_array(strtoupper($rule), preg_split('/[,\s]+/', strtoupper($rules)) ?: [], true);
    }

    private function statementAt(int $offset): string
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
    }

    private function statementEnd(int $offset): int
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
    }

    private function basename(string $class): string
    {
        $parts = explode('\\', trim($class, '\\'));

        return end($parts) ?: $class;
    }

    private function lineAtOffset(int $offset): int
    {
        return $this->sourceIndex->lineAt($offset);
    }

    /** @return list<array{id:int|null,text:string,line:int,offset:int,end:int}> */
    private function tokenize(string $source): array
    {
        $raw = token_get_all($source, TOKEN_PARSE);
        $tokens = [];
        $offset = 0;
        $line = 1;

        foreach ($raw as $rawToken) {
            if (is_array($rawToken)) {
                [$id, $text, $tokenLine] = $rawToken;
                $line = $tokenLine;
            } else {
                $id = null;
                $text = $rawToken;
            }
            $tokens[] = [
                'id' => $id,
                'text' => $text,
                'line' => $line,
                'offset' => $offset,
                'end' => $offset + strlen($text),
            ];
            $offset += strlen($text);
            $line += substr_count($text, "\n");
        }

        return $tokens;
    }

    private function tokenIndexAtOrAfter(int $offset, string $text): ?int
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
    }

    private function tokenIndexBeforeOffset(int $offset): ?int
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
    }

    private function nextTokenText(int $start, string $text, ?int $limit = null): ?int
    {
        $limit ??= count($this->tokens) - 1;
        for ($i = $start; $i <= $limit; $i++) {
            if ($this->tokens[$i]['text'] === $text) {
                return $i;
            }
        }

        return null;
    }

    private function matchingToken(int $open, string $openText, string $closeText, ?int $limit = null): ?int
    {
        $depth = 0;
        $limit ??= count($this->tokens) - 1;
        for ($i = $open; $i <= $limit; $i++) {
            if ($this->tokens[$i]['text'] === $openText) {
                $depth++;
            } elseif ($this->tokens[$i]['text'] === $closeText) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function arrowExpressionEnd(int $startToken, ?int $limit = null): int
    {
        $limit ??= count($this->tokens) - 1;
        $paren = $bracket = $brace = 0;
        for ($i = $startToken; $i <= $limit; $i++) {
            $text = $this->tokens[$i]['text'];
            if ($text === '(') {
                $paren++;
            } elseif ($text === ')') {
                if ($paren === 0 && $bracket === 0 && $brace === 0) {
                    return $this->tokens[$i]['offset'];
                }
                $paren = max(0, $paren - 1);
            } elseif ($text === '[') {
                $bracket++;
            } elseif ($text === ']') {
                $bracket = max(0, $bracket - 1);
            } elseif ($text === '{') {
                $brace++;
            } elseif ($text === '}') {
                $brace = max(0, $brace - 1);
            } elseif ($text === ',' && $paren === 0 && $bracket === 0 && $brace === 0) {
                return $this->tokens[$i]['offset'];
            } elseif ($text === ';' && $paren === 0 && $bracket === 0 && $brace === 0) {
                return $this->tokens[$i]['offset'];
            }
        }

        return $this->tokens[$limit]['end'] ?? strlen($this->source);
    }
}
