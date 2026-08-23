<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

use ParseError;

/**
 * @phpstan-type TransactionRegion array{start:int,end:int,line:int,type:string,attempts:int,connection:string,callableStart:int,callableEnd:int}
 * @phpstan-type DatabaseControlCall array{type:string,offset:int,end:int,scope:string,connection:string,conditionalScope:string|null}
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

    /** @var array<string, array{fqcn:string,fallback:string}> */
    private array $activeFacadeAliasTargets = [];

    /** @var array<int, list<string>> */
    private array $suppressionComments = [];

    /** @var list<Finding> */
    private array $preScanFindings = [];

    /** @var array<string, true> */
    private array $regexErrors = [];

    private string $source = '';

    private string $file = '';

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
                projectRoot: $this->config->projectRoot,
            )];
        }

        $this->source = $source;
        $this->file = $file;

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
                projectRoot: $this->config->projectRoot,
            )];
        }

        $this->sourceIndex = new SourceIndex($source, $this->tokens);
        $this->sourceLower = strtolower($source);
        $this->statementCache = [];
        $this->statementCodeCache = [];
        $this->facadeAliasCache = [];
        $this->activeFacadeAliasTargets = [];
        $this->preScanFindings = [];
        $this->regexErrors = [];

        $this->callables = $this->findCallableRegions();
        $this->transactions = array_merge($this->findClosureTransactions(), $this->findManualTransactions());
        if ($this->transactions === []) {
            return $this->preScanFindings;
        }
        $this->afterCommitCallbacks = $this->findAfterCommitCallbacks();

        $findings = $this->preScanFindings;

        $this->scanJobDispatches($findings);
        $this->scanBusAndQueue($findings);
        $this->scanEvents($findings);
        $this->scanMail($findings);
        $this->scanNotifications($findings);
        $this->scanBroadcasts($findings);
        $this->scanVariablePayloadEffects($findings);
        $this->scanVariableFrameworkPayloads($findings);
        $this->scanVariableFacadeHandles($findings);
        $this->scanHttp($findings);
        $this->scanFilesystem($findings);
        $this->scanCache($findings);
        $this->scanRateLimiter($findings);
        $this->scanRedis($findings);
        $this->scanProcesses($findings);
        $this->scanConcurrency($findings);
        $this->scanCrossConnectionDatabaseWrites($findings);
        $this->scanEloquentCrossConnectionWrites($findings);
        $this->scanImplicitCommits($findings);
        $this->scanCustomPatterns($findings);
        $this->scanManualTransactionBalance($findings);

        $unique = [];
        foreach ($findings as $finding) {
            $finding = RedisFindingRefiner::refine($finding);
            if ($finding === null) {
                continue;
            }
            if (! $this->config->ruleEnabled($finding->rule)) {
                continue;
            }
            $key = $finding->rule.'|'.$finding->line.'|'.($finding->column ?? 0).'|'.$finding->snippet;
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

                $resolved = $this->resolveClassAt($class, $offset);
                $base = strtolower($this->basename($resolved));
                if (in_array($base, ['event', 'bus', 'queue', 'mail', 'notification'], true)) {
                    continue;
                }

                $metadata = $this->classIndex->metadata($resolved);
                $method = $this->captured($match, 'method');
                $statement = $this->statementAt($offset);
                if ($this->conditionalDispatchIsSkipped($statement, $method)) {
                    continue;
                }
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

                if ($globalDispatchHelper || in_array($method, ['dispatch', 'dispatchIf', 'dispatchUnless', 'dispatchAfterResponse'], true)) {
                    $this->appendPendingDispatchLifecycleFindings($findings, $offset, $tx, $metadata);
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
            if ($this->conditionalDispatchIsSkipped($statement, 'dispatchIf')
                || $this->conditionalDispatchIsSkipped($statement, 'dispatchUnless')) {
                continue;
            }

            $resolved = $this->classIndex->contextFor($this->file, $match['offset'])->resolve($this->captured($match, 'class'));
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
                        $metadata = $this->classIndex->metadata($this->resolveClassAt($jobClass, $offset));
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
                        ? $this->classIndex->metadata($this->resolveClassAt($jobClass, $offset))
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
                $jobMetadata = $jobClass !== null ? $this->classIndex->metadata($this->resolveClassAt($jobClass, $offset)) : null;
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
                $class = $this->classIndex->contextFor($this->file, $match['offset'])->resolve($this->captured($match, 'class'));
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
            $class = $this->classIndex->contextFor($this->file, $match['offset'])->resolve($this->captured($match, 'class'));
            $metadata = $this->classIndex->metadata($class);
            $method = $this->captured($match, 'method');
            if ($this->conditionalDispatchIsSkipped($this->statementAt($offset), $method)) {
                continue;
            }
            $looksLikeEvent = $this->classIndex->isDispatchableEvent($class)
                || str_contains(strtolower($class), '\\events\\');
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
                $metadata = $class !== null ? $this->classIndex->metadata($this->resolveClassAt($class, $offset)) : null;
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
                $class = $this->classIndex->contextFor($this->file, $match['offset'])->resolve($this->captured($match, 'class'));
                $metadata = $this->classIndex->metadata($class);
                $queued = ! in_array($method, ['notifynow', 'sendnow'], true) && $metadata?->queued() === true;

                $explicitlyBeforeCommit = $this->statementContainsBeforeCommit($statement) || $metadata?->explicitlyBeforeCommit() === true;
                if ($queued && ! $explicitlyBeforeCommit && $this->notificationDispatchIsAfterCommitSafe($statement, $metadata)) {
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
                $class = $this->classIndex->contextFor($this->file, $match['offset'])->resolve($this->captured($match, 'class'));
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

    /** @param list<Finding> $findings */
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
            $this->appendPendingDispatchLifecycleFindings($findings, $offset, $tx, $metadata);

            if ($metadata !== null && ! $metadata->queued()) {
                $this->appendFinding($findings, $offset, 'TG016', Severity::Warning,
                    "Dispatch of non-queueable [{$this->basename($metadata->name)}] executes synchronously while the database transaction is open.",
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
            if ($metadata?->queued() === true && $this->notificationDispatchIsAfterCommitSafe($statement, $metadata)) {
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

    /** @param list<Finding> $findings */
    private function scanVariableFrameworkPayloads(array &$findings): void
    {
        foreach ($this->facadeAliases('Illuminate\Support\Facades\Bus', 'Bus') as $alias) {
            foreach ($this->matches('/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*dispatch\s*\(\s*(?P<var>\$[A-Za-z_][A-Za-z0-9_]*)/i') as $match) {
                $this->reportVariableJobPayload($findings, $match['offset'], $this->captured($match, 'var'), false);
            }
        }
        foreach ($this->facadeAliases('Illuminate\Support\Facades\Queue', 'Queue') as $alias) {
            foreach ($this->matches('/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*(?:push|later|pushOn|laterOn)\s*\([^;]*?(?P<var>\$[A-Za-z_][A-Za-z0-9_]*)/i') as $match) {
                $this->reportVariableJobPayload($findings, $match['offset'], $this->captured($match, 'var'), false);
            }
        }
        foreach ($this->facadeAliases('Illuminate\Support\Facades\Event', 'Event') as $alias) {
            foreach ($this->matches('/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*dispatch\s*\(\s*(?P<var>\$[A-Za-z_][A-Za-z0-9_]*)/i') as $match) {
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
                    $class === null ? 'A variable Event::dispatch payload is not provably commit-safe.' : "Event [{$this->basename($class)}] is dispatched before commit.",
                    'Implement ShouldDispatchAfterCommit or dispatch after commit.', $metadata === null ? 'medium' : 'high');
            }
        }
        foreach ($this->facadeAliases('Illuminate\Support\Facades\Notification', 'Notification') as $alias) {
            foreach ($this->matches('/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*send\s*\([^;]*?,\s*(?P<var>\$[A-Za-z_][A-Za-z0-9_]*)/is') as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $class = $this->localNewClassForVariable($offset, $this->captured($match, 'var'));
                $metadata = $class !== null ? $this->classIndex->metadata($class) : null;
                if ($metadata?->queued() === true && $this->notificationDispatchIsAfterCommitSafe($this->statementAt($offset), $metadata)) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG004', Severity::Error,
                    $class === null ? 'A variable Notification::send payload is not provably commit-safe.' : "Notification [{$this->basename($class)}] may be delivered before commit.",
                    'Make queued delivery commit-aware or send after commit.', $metadata === null ? 'medium' : 'high');
            }
        }
    }

    /** @param list<Finding> $findings */
    private function reportVariableJobPayload(array &$findings, int $offset, string $variable, bool $pendingDispatch): void
    {
        $tx = $this->eligibleTransaction($offset);
        if ($tx === null) {
            return;
        }
        $class = $this->localNewClassForVariable($offset, $variable);
        $metadata = $class !== null ? $this->classIndex->metadata($class) : null;
        if ($pendingDispatch) {
            $this->appendPendingDispatchLifecycleFindings($findings, $offset, $tx, $metadata);
        }
        if ($metadata !== null && ! $metadata->queued()) {
            $this->appendFinding($findings, $offset, 'TG016', Severity::Warning,
                "Dispatch of non-queueable [{$this->basename($metadata->name)}] executes synchronously inside the transaction.",
                'Move synchronous work outside the transaction.', 'high');

            return;
        }
        if ($this->jobDispatchIsAfterCommitSafe($this->statementAt($offset), $metadata)) {
            return;
        }
        $this->appendFinding($findings, $offset, 'TG001', $metadata === null ? Severity::Warning : Severity::Error,
            $class === null ? 'A variable job payload cannot be proven commit-safe.' : "Job [{$this->basename($class)}] may dispatch before commit.",
            'Make the payload type statically visible and commit-aware, or dispatch after commit.', $metadata === null ? 'medium' : 'high');
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
        $assignments = 0;
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
            $assignments++;
            if ($assignments > 1 || $this->conditionalControlScopeAt($token['offset']) !== null) {
                return null;
            }

            $value = $this->nextSignificantToken($assign + 1);
            if ($value === null || $this->tokens[$value]['id'] !== T_NEW) {
                $resolved = null;

                continue;
            }

            $name = $this->nextSignificantToken($value + 1);
            if ($name === null || ! in_array($this->tokens[$name]['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                $resolved = null;

                continue;
            }

            $resolved = $this->resolveClassAt($this->tokens[$name]['text'], $this->tokens[$name]['offset']);
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

    /** @param list<Finding> $findings */
    private function scanVariableFacadeHandles(array &$findings): void
    {
        foreach ($this->matches('/(?P<var>\$[A-Za-z_][A-Za-z0-9_]*)\s*->\s*(?P<method>[A-Za-z_][A-Za-z0-9_]*)\s*\(/i') as $match) {
            $offset = $match['offset'];
            $tx = $this->eligibleTransaction($offset);
            if ($tx === null) {
                continue;
            }

            $handle = $this->localFacadeHandleForVariable($offset, $this->captured($match, 'var'));
            if ($handle === null) {
                continue;
            }
            $method = strtolower($this->captured($match, 'method'));

            if ($handle['kind'] === 'http') {
                $read = in_array($method, ['get', 'head', 'query'], true);
                if (! $read && ! in_array($method, ['post', 'put', 'patch', 'delete', 'send'], true)) {
                    continue;
                }
                if ($read && ! $this->config->detectReadHttpCalls) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG006', $read ? Severity::Warning : Severity::Error,
                    'Outbound HTTP is executed through a locally assigned Laravel HTTP client while a database transaction is open.',
                    'Perform external I/O after commit or use an idempotent/outbox strategy when atomic delivery matters.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'HTTP request');

                continue;
            }

            if ($handle['kind'] === 'storage' && in_array($method, [
                'put', 'putfile', 'putfileas', 'writestream', 'write', 'delete', 'move', 'copy', 'append', 'prepend',
                'setvisibility', 'makedirectory', 'createdirectory', 'deletedirectory',
            ], true)) {
                $this->appendFinding($findings, $offset, 'TG007', Severity::Warning,
                    'Filesystem mutation occurs through a locally assigned Laravel filesystem handle while a database transaction is open.',
                    'Move the filesystem mutation after commit or add explicit compensation.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'filesystem mutation');

                continue;
            }

            if ($handle['kind'] === 'cache' && in_array($method, array_map('strtolower', OperationCatalog::CACHE_MUTATIONS), true)) {
                $this->appendFinding($findings, $offset, 'TG008', Severity::Warning,
                    'Cache state is mutated through a locally assigned cache repository before the database transaction commits.',
                    'Mutate or invalidate cache after commit.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'cache mutation');

                continue;
            }

            if ($handle['kind'] === 'cache_lock' && in_array($method, array_map('strtolower', OperationCatalog::CACHE_LOCK_TERMINALS), true)) {
                $this->appendFinding($findings, $offset, 'TG008', Severity::Warning,
                    'A Laravel cache lock is acquired or released while a database transaction is open.',
                    'Acquire/release cache locks after commit unless the lock lifecycle is explicitly compensatable.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'cache lock mutation');

                continue;
            }

            if ($handle['kind'] === 'redis' && in_array($method, array_map('strtolower', OperationCatalog::REDIS_MUTATIONS), true)) {
                $this->appendFinding($findings, $offset, 'TG020', $method === 'publish' ? Severity::Error : Severity::Warning,
                    'Redis state is mutated through a locally assigned Redis connection while a database transaction is open.',
                    'Move the Redis mutation after commit or use an idempotent/outbox strategy.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'Redis mutation');

                continue;
            }

            if ($handle['kind'] === 'redis' && in_array($method, ['pipeline', 'transaction'], true)) {
                [$mutates, $unknown] = $this->redisCallbackMutationState($this->statementAt($offset));
                if ($mutates || $unknown) {
                    $this->appendFinding($findings, $offset, 'TG020', Severity::Warning,
                        $mutates
                            ? 'A Redis pipeline/transaction callback mutates Redis while a database transaction is open.'
                            : 'A Redis pipeline/transaction callback cannot be proven read-only while a database transaction is open.',
                        'Keep Redis callback mutations after the database commit.', $mutates ? 'high' : 'medium');
                    $this->appendRetryFinding($findings, $offset, $tx, 'Redis callback mutation');
                }

                continue;
            }

            if ($handle['kind'] === 'redis') {
                $kind = OperationCatalog::redisMethodKind($method);
                if (in_array($kind, ['read', 'control'], true)) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG020', Severity::Warning,
                    'Redis state may be mutated through a locally assigned Redis connection while a database transaction is open.',
                    'Move unknown Redis operations after commit or classify the command explicitly when it is read-only.',
                    $kind === 'mutation' ? 'high' : 'medium');
                $this->appendRetryFinding($findings, $offset, $tx, 'Redis operation');

                continue;
            }

            if ($handle['kind'] === 'process' && in_array($method, ['run', 'start', 'pipe', 'pool'], true)) {
                $this->appendFinding($findings, $offset, 'TG009', Severity::Error,
                    'An external process is started through a locally assigned Laravel process handle while a database transaction is open.',
                    'Run external processes after commit.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'external process');

                continue;
            }

            if ($handle['kind'] === 'db' && in_array($method, [
                'insert', 'insertgetid', 'insertorignore', 'insertusing', 'update', 'updateorinsert', 'upsert', 'delete',
                'truncate', 'increment', 'decrement', 'statement', 'unprepared', 'affectingstatement',
            ], true)) {
                $this->reportCrossConnectionWrite($findings, $offset, $handle['connection'] ?? $this->config->defaultDatabaseConnection);
            }
        }

        $builderMutations = OperationCatalog::alternation(OperationCatalog::QUERY_MUTATIONS);
        foreach ($this->matches('/(?P<var>\$[A-Za-z_][A-Za-z0-9_]*)\s*->\s*(?:table|query)\s*\((?:(?!;).)*?\)(?:(?!;).)*?\b(?P<method>'.$builderMutations.')\s*\(/is') as $match) {
            $offset = $match['offset'];
            if ($this->eligibleTransaction($offset) === null) {
                continue;
            }

            $handle = $this->localFacadeHandleForVariable($offset, $this->captured($match, 'var'));
            if ($handle === null || $handle['kind'] !== 'db') {
                continue;
            }

            $this->reportCrossConnectionWrite(
                $findings,
                $offset,
                $handle['connection'] ?? $this->config->defaultDatabaseConnection,
            );
        }
    }

    /** @return array{kind:string,connection:string|null}|null */
    private function localFacadeHandleForVariable(int $offset, string $variable): ?array
    {
        $scope = $this->callableScopeAt($offset);
        $resolved = null;
        $assignments = 0;
        $count = count($this->tokens);
        $facades = [
            'http' => ['Illuminate\\Support\\Facades\\Http', 'Http'],
            'storage' => ['Illuminate\\Support\\Facades\\Storage', 'Storage'],
            'cache' => ['Illuminate\\Support\\Facades\\Cache', 'Cache'],
            'redis' => ['Illuminate\\Support\\Facades\\Redis', 'Redis'],
            'process' => ['Illuminate\\Support\\Facades\\Process', 'Process'],
            'db' => ['Illuminate\\Support\\Facades\\DB', 'DB'],
        ];

        for ($i = 0; $i < $count; $i++) {
            $token = $this->tokens[$i];
            if ($token['offset'] >= $offset || $token['id'] !== T_VARIABLE || $token['text'] !== $variable) {
                continue;
            }
            if ($this->callableScopeAt($token['offset']) !== $scope) {
                continue;
            }
            $assign = $this->nextSignificantToken($i + 1);
            if ($assign === null || $this->tokens[$assign]['text'] !== '=') {
                continue;
            }
            $assignments++;
            if ($assignments > 1 || $this->conditionalControlScopeAt($token['offset']) !== null) {
                return null;
            }

            $raw = $this->statementAt($token['offset']);
            $code = $this->codeOnlyFragment($raw);
            $resolved = null;
            foreach ($facades as $kind => [$fqcn, $fallback]) {
                foreach ($this->facadeAliases($fqcn, $fallback) as $alias) {
                    if (! $this->facadeAliasValidAt($alias, $fqcn, $fallback, $token['offset'])) {
                        continue;
                    }
                    $pattern = '/^\s*'.preg_quote($variable, '/').'\s*=\s*'.preg_quote($alias, '/').'\s*::/i';
                    if (preg_match($pattern, $code) !== 1) {
                        continue;
                    }

                    $connection = null;
                    if ($kind === 'cache' && preg_match('/::\s*(?:lock|restoreLock)\s*\(/i', $code) === 1) {
                        $kind = 'cache_lock';
                    }
                    if ($kind === 'db') {
                        $connection = $this->config->defaultDatabaseConnection;
                        if (preg_match('/::\s*connection\s*\(/i', $code, $call, PREG_OFFSET_CAPTURE) === 1) {
                            $connection = $this->literalStringArgumentFromCall(substr($raw, $call[0][1])) ?? '@dynamic';
                        }
                    }
                    $resolved = ['kind' => $kind, 'connection' => $connection];
                    break 2;
                }
            }
        }

        return $resolved;
    }

    private function localDatabaseConnectionForVariable(int $offset, string $variable): ?string
    {
        $handle = $this->localFacadeHandleForVariable($offset, $variable);

        return $handle !== null && $handle['kind'] === 'db' ? ($handle['connection'] ?? $this->config->defaultDatabaseConnection) : null;
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
            if (! $this->isGlobalFunctionCall($offset)) {
                continue;
            }
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

        foreach ($this->facadeAliases('Illuminate\Support\Facades\Cache', 'Cache') as $alias) {
            $methods = OperationCatalog::alternation(OperationCatalog::CACHE_MUTATIONS);
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::(?:(?!;).)*?\b(?P<method>'.$methods.')\s*\(/is';
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

            $lockTerminals = OperationCatalog::alternation(OperationCatalog::CACHE_LOCK_TERMINALS);
            $lockPattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*(?:lock|restoreLock)\s*\((?:(?!;).)*?\)\s*->\s*(?P<method>'.$lockTerminals.')\s*\(/is';
            foreach ($this->matches($lockPattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG008', Severity::Warning,
                    'A cache lock is acquired/released while a database transaction is open.',
                    'Acquire or release distributed cache locks after commit unless their lifecycle is explicitly compensatable.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'cache lock mutation');
            }
        }
    }

    /** @param list<Finding> $findings */
    private function scanRateLimiter(array &$findings): void
    {
        if (! $this->sourceContainsAny(['ratelimiter', 'rate limiter'])) {
            return;
        }

        foreach ($this->facadeAliases('Illuminate\Support\Facades\RateLimiter', 'RateLimiter') as $alias) {
            $methods = OperationCatalog::alternation(OperationCatalog::RATE_LIMITER_MUTATIONS);
            foreach ($this->matches('/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*(?P<method>'.$methods.')\s*\(/i') as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG008', Severity::Warning,
                    'RateLimiter mutates cache-backed rate-limit state while a database transaction is open.',
                    'Update rate-limit state after commit when it is coupled to transactional business state.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'rate limiter mutation');
            }
        }
    }

    /** @param list<Finding> $findings */
    private function scanRedis(array &$findings): void
    {
        if (! $this->sourceContainsAny(['redis'])) {
            return;
        }
        $mutating = OperationCatalog::alternation(OperationCatalog::REDIS_MUTATIONS);

        foreach ($this->facadeAliases('Illuminate\Support\Facades\Redis', 'Redis') as $alias) {
            $directPattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*(?P<method>[A-Za-z_][A-Za-z0-9_]*)\s*\(/i';
            foreach ($this->matches($directPattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $method = strtolower($this->captured($match, 'method'));
                $kind = OperationCatalog::redisMethodKind($method);
                if (in_array($kind, ['read', 'control', 'mutation'], true)) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG020', Severity::Warning,
                    "Redis::{$method}() cannot be proven read-only while a database transaction is open.",
                    'Move unknown Redis operations after commit or classify the command explicitly when it is read-only.', 'medium');
                $this->appendRetryFinding($findings, $offset, $tx, "Redis {$method}");
            }

            $connectionPattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*connection\s*\([^;]*?\)\s*->\s*(?P<method>[A-Za-z_][A-Za-z0-9_]*)\s*\(/is';
            foreach ($this->matches($connectionPattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $method = strtolower($this->captured($match, 'method'));
                $kind = OperationCatalog::redisMethodKind($method);
                if (in_array($kind, ['read', 'control', 'mutation'], true)) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG020', Severity::Warning,
                    "Redis connection method {$method}() cannot be proven read-only while a database transaction is open.",
                    'Move unknown Redis operations after commit or classify the command explicitly when it is read-only.', 'medium');
                $this->appendRetryFinding($findings, $offset, $tx, "Redis {$method}");
            }

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

            $commandPattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*command\s*\(\s*[\'\"](?P<command>[A-Za-z0-9_]+)[\'\"]/i';
            foreach ($this->matches($commandPattern, ['command']) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }

                $command = strtoupper($this->captured($match, 'command'));
                $kind = OperationCatalog::redisCommandKind($command);
                if ($kind === 'read') {
                    continue;
                }
                $severity = $command === 'PUBLISH' ? Severity::Error : Severity::Warning;
                $this->appendFinding($findings, $offset, 'TG020', $severity,
                    $kind === 'mutation'
                        ? "Redis command {$command} mutates non-transactional state while a database transaction is open."
                        : "Redis command {$command} cannot be proven read-only while a database transaction is open.",
                    'Move Redis mutations after commit; review unknown/script commands explicitly.',
                    $kind === 'mutation' ? 'high' : 'medium');
                $this->appendRetryFinding($findings, $offset, $tx, "Redis {$command}");
            }

            foreach ($this->matches('/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*(?P<method>pipeline|transaction)\s*\(/i') as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                [$mutates, $unknown] = $this->redisCallbackMutationState($this->statementAt($offset));
                if (! $mutates && ! $unknown) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG020', Severity::Warning,
                    $mutates
                        ? 'A Redis pipeline/transaction callback mutates Redis while a database transaction is open.'
                        : 'A Redis pipeline/transaction callback cannot be proven read-only while a database transaction is open.',
                    'Keep Redis callback mutations after the database commit.', $mutates ? 'high' : 'medium');
                $this->appendRetryFinding($findings, $offset, $tx, 'Redis callback mutation');
            }
        }
    }

    /** @return array{bool,bool} mutates, unknown */
    private function redisCallbackMutationState(string $statement): array
    {
        $code = $this->codeOnlyFragment($statement);
        $mutations = OperationCatalog::alternation(OperationCatalog::REDIS_MUTATIONS);
        if (preg_match('/->\s*(?:'.$mutations.')\s*\(/i', $code) === 1) {
            return [true, false];
        }

        if (preg_match_all('/->\s*(?<method>[A-Za-z_][A-Za-z0-9_]*)\s*\(/i', $code, $calls, PREG_SET_ORDER) > 0) {
            foreach ($calls as $call) {
                $kind = OperationCatalog::redisMethodKind((string) $call['method']);
                if (in_array($kind, ['read', 'control'], true)) {
                    continue;
                }
                if ($kind === 'mutation') {
                    return [true, false];
                }

                return [false, true];
            }
        }

        $hasInlineCallable = preg_match('/(?:pipeline|transaction)\s*\(\s*(?:static\s+)?(?:function|fn)\b/i', $code) === 1;

        return [false, ! $hasInlineCallable];
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
            if (! $this->isGlobalFunctionCall($offset)) {
                continue;
            }
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
            foreach ($this->matches($this->facadeStaticMethodPattern($alias, 'run|defer')) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $method = strtolower($this->captured($match, 'method'));
                $this->appendFinding($findings, $offset, 'TG018', Severity::Warning,
                    "Concurrency::{$method}() is invoked while a database transaction is open; child/deferred work is not part of that transaction boundary.",
                    'Move concurrent/deferred work after commit or register it from DB::afterCommit().', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, "concurrency {$method}");
            }
        }

        foreach ($this->matches('/(?<![A-Za-z0-9_])defer\s*\(/i') as $match) {
            $offset = $match['offset'];
            if (! $this->isGlobalFunctionCall($offset)) {
                continue;
            }
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

        $mutations = OperationCatalog::alternation(OperationCatalog::QUERY_MUTATIONS);

        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\DB', 'DB') as $alias) {
            $counterPattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*connection\s*\(\s*(?P<quote>[\'\"])(?P<connection>[^\'\"]+)\k<quote>\s*\)\s*->(?:(?![;{}]|'.preg_quote($alias, '/').'\s*::\s*connection).)*?\b(?:incrementEach|decrementEach)\s*\(/is';
            foreach ($this->matches($counterPattern) as $match) {
                $this->reportCrossConnectionWrite($findings, $match['offset'], $this->captured($match, 'connection'));
            }
        }

        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\DB', 'DB') as $alias) {
            $connectionPattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*connection\s*\(\s*(?P<quote>[\'\"])(?P<connection>[^\'\"]+)\k<quote>\s*\)\s*->(?:(?![;{}]|'.preg_quote($alias, '/').'\s*::\s*connection).)*?\b(?P<method>'.$mutations.')\s*\(/is';
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
    private function scanEloquentCrossConnectionWrites(array &$findings): void
    {
        $methods = OperationCatalog::alternation(OperationCatalog::ELOQUENT_STATIC_MUTATIONS);
        foreach ($this->matches('/(?<![A-Za-z0-9_\\\\])(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)\s*::(?:(?![;{}]).)*?\b(?P<method>'.$methods.')\s*\(/is') as $match) {
            $offset = $match['offset'];
            if ($this->eligibleTransaction($offset) === null) {
                continue;
            }
            $class = $this->classIndex->contextFor($this->file, $match['offset'])->resolve($this->captured($match, 'class'));
            if (! $this->classIndex->isEloquentModel($class)) {
                continue;
            }
            $connection = $this->eloquentConnectionFromStatement($this->statementAt($offset), $class);
            $this->reportCrossConnectionWrite($findings, $offset, $connection);
        }

        $instanceMethods = OperationCatalog::alternation(OperationCatalog::ELOQUENT_INSTANCE_MUTATIONS);
        foreach ($this->matches('/(?P<var>\$[A-Za-z_][A-Za-z0-9_]*)\s*->\s*(?P<method>'.$instanceMethods.')\s*\(/i') as $match) {
            $offset = $match['offset'];
            if ($this->eligibleTransaction($offset) === null) {
                continue;
            }
            $class = $this->localNewClassForVariable($offset, $this->captured($match, 'var'));
            if ($class === null || ! $this->classIndex->isEloquentModel($class)) {
                continue;
            }
            $connection = $this->localModelConnectionForVariable(
                $offset,
                $this->captured($match, 'var'),
                $class,
            );
            $this->reportCrossConnectionWrite($findings, $offset, $connection);
        }

        $relationMethods = OperationCatalog::alternation(OperationCatalog::RELATION_MUTATIONS);
        foreach ($this->matches('/(?P<var>\$[A-Za-z_][A-Za-z0-9_]*)\s*->\s*(?P<relation>[A-Za-z_][A-Za-z0-9_]*)\s*\(\s*\)\s*->\s*(?P<method>'.$relationMethods.')\s*\(/i') as $match) {
            $offset = $match['offset'];
            if ($this->eligibleTransaction($offset) === null) {
                continue;
            }
            $class = $this->localNewClassForVariable($offset, $this->captured($match, 'var'));
            if ($class === null || ! $this->classIndex->isEloquentModel($class)) {
                continue;
            }
            $target = $this->classIndex->modelRelationTarget($class, $this->captured($match, 'relation'));
            if ($target === null) {
                continue;
            }
            $parentConnection = $this->localModelConnectionForVariable($offset, $this->captured($match, 'var'), $class);
            $connection = $this->classIndex->modelConnection($target) ?? $parentConnection;
            $this->reportCrossConnectionWrite($findings, $offset, $connection);
        }
    }

    private function localModelConnectionForVariable(int $offset, string $variable, string $class): string
    {
        $connection = $this->classIndex->modelConnection($class) ?? $this->config->defaultDatabaseConnection;
        $scope = $this->callableScopeAt($offset);
        $seen = false;

        foreach ($this->matches('/'.preg_quote($variable, '/').'\s*->\s*setConnection\s*\(/i') as $match) {
            if ($match['offset'] >= $offset || $this->callableScopeAt($match['offset']) !== $scope) {
                continue;
            }
            if ($seen || $this->conditionalControlScopeAt($match['offset']) !== null) {
                return '@dynamic';
            }
            $seen = true;
            $literal = StaticExpressionResolver::firstStringArgument(substr($this->source, $match['offset'] + strpos($match['matches'][0][0], 'setConnection')));
            $connection = $literal ?? '@dynamic';
        }

        return $connection;
    }

    private function eloquentConnectionFromStatement(string $statement, string $class): string
    {
        $code = $this->codeOnlyFragment($statement);
        if (preg_match('/->\s*on\s*\(/i', $code, $call, PREG_OFFSET_CAPTURE) === 1) {
            return $this->literalStringArgumentFromCall(substr($statement, $call[0][1])) ?? '@dynamic';
        }

        return $this->classIndex->modelConnection($class) ?? $this->config->defaultDatabaseConnection;
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
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                [$severity, $driver, $semantics] = $this->implicitCommitSeverity($tx);
                $this->appendFinding($findings, $offset, 'TG012', $severity,
                    'Schema/DDL work is executed inside an application transaction; implicit-commit behavior depends on the active database driver.',
                    'Keep schema changes in migrations or explicit schema-management flows outside normal application transactions.', 'high',
                    ['database_driver' => $driver ?? 'unknown', 'database_connection' => $tx['connection'], 'ddl_semantics' => $semantics]);
            }
        }

        foreach ($this->facadeAliases('Illuminate\Support\Facades\DB', 'DB') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*(?P<method>statement|unprepared)\s*\(/i';
            foreach ($this->matches($pattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $call = substr($this->source, $offset + strpos($match['matches'][0][0], $this->captured($match, 'method')));
                $sql = StaticExpressionResolver::firstStringArgument($call);
                if ($sql === null || preg_match('/^(CREATE|ALTER|DROP|TRUNCATE|RENAME|LOCK\s+TABLES|UNLOCK\s+TABLES|SET\s+AUTOCOMMIT)\b/i', ltrim($sql)) !== 1) {
                    continue;
                }
                [$severity, $driver, $semantics] = $this->implicitCommitSeverity($tx);
                $this->appendFinding($findings, $offset, 'TG012', $severity,
                    'A statically resolved SQL statement with driver-specific transaction semantics is executed inside a Laravel transaction.',
                    'Move DDL/implicit-commit statements outside normal application transactions and let migrations/schema tooling own schema changes.', 'high',
                    ['database_driver' => $driver ?? 'unknown', 'database_connection' => $tx['connection'], 'ddl_semantics' => $semantics]);
            }
        }
    }

    /**
     * @param  TransactionRegion  $tx
     * @return array{Severity,string|null,string}
     */
    private function implicitCommitSeverity(array $tx): array
    {
        $driver = $this->config->databaseDriver($tx['connection']);
        $policy = DatabaseDriverPolicy::ddl($driver);

        return [$policy['severity'], $driver, $policy['semantics']];
    }

    /** @param list<Finding> $findings */
    private function scanCustomPatterns(array &$findings): void
    {
        foreach ($this->config->customRegexes() as $regex) {
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

            if (! in_array($call['type'], ['commit', 'rollback'], true) || ($stacks[$key] ?? []) === []) {
                continue;
            }

            $start = end($stacks[$key]);
            if (! $this->manualTerminalCloses($start, $call) || $this->manualRegionHasEarlyExit($start, $call)) {
                continue;
            }

            array_pop($stacks[$key]);
        }

        foreach ($stacks as $stack) {
            foreach ($stack as $call) {
                $this->appendFinding($findings, $call['offset'], 'TG013', Severity::Critical,
                    "A manually started database transaction on [{$call['connection']}] has no matching commit() or rollBack() on every statically visible branch.",
                    'Prefer DB::transaction() or guarantee a same-connection commit/rollback on every branch and exception path.', 'medium',
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
            $inlineClosure = $closure !== null;
            if ($closure === null) {
                $argument = $this->nextSignificantToken($open + 1);
                if ($argument !== null && $this->tokens[$argument]['id'] === T_VARIABLE) {
                    $closure = $this->localClosureForVariableBefore($offset, $this->tokens[$argument]['text']);
                }
            }
            if ($closure === null) {
                $this->appendFinding($this->preScanFindings, $offset, 'TG014', Severity::Info,
                    'A database transaction callback could not be resolved statically, so its body was not analyzed as a transaction region.',
                    'Use an inline closure or a simple local closure variable when practical, or review the callback manually.', 'low');

                continue;
            }

            $attempts = $inlineClosure
                ? $this->transactionAttempts($closure['endToken'], $callClose)
                : $this->transactionAttemptsFromCall($open, $callClose);
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

            /** @param DatabaseControlCall|null $start */
            $flush = function (?array $start, ?int $endOffset) use (&$regions): void {
                if ($start === null) {
                    return;
                }

                /** @var DatabaseControlCall $start */
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

                if ($groupStart === null || ! $this->manualTerminalCloses($groupStart, $call)) {
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
                    'conditionalScope' => $this->conditionalControlScopeAt($offset),
                ];
            }
        }

        usort($calls, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        return $calls;
    }

    /**
     * @param  DatabaseControlCall  $start
     * @param  DatabaseControlCall  $terminal
     */
    private function manualTerminalCloses(array $start, array $terminal): bool
    {
        return $terminal['conditionalScope'] === null
            || $terminal['conditionalScope'] === $start['conditionalScope'];
    }

    private function conditionalControlScopeAt(int $offset): ?string
    {
        $target = $this->tokenIndexContainingOrAfterOffset($offset);
        if ($target === null) {
            return null;
        }

        /** @var list<string|null> $blocks */
        $blocks = [];
        for ($i = 0; $i < $target; $i++) {
            $text = $this->tokens[$i]['text'];
            if ($text === '{') {
                $blocks[] = $this->openingBraceStartsConditionalBlock($i)
                    ? 'block:'.$this->tokens[$i]['offset']
                    : null;

                continue;
            }
            if ($text === '}') {
                array_pop($blocks);
            }
        }

        for ($i = count($blocks) - 1; $i >= 0; $i--) {
            if ($blocks[$i] !== null) {
                return $blocks[$i];
            }
        }

        for ($i = $target - 1; $i >= 0; $i--) {
            $token = $this->tokens[$i];
            if (in_array($token['text'], [';', '{', '}'], true)) {
                break;
            }
            if (in_array($token['id'], [T_IF, T_ELSEIF, T_FOR, T_FOREACH, T_WHILE, T_DO], true)) {
                return 'statement:'.$token['offset'];
            }
        }

        return null;
    }

    private function openingBraceStartsConditionalBlock(int $braceIndex): bool
    {
        $previous = $this->previousSignificantToken($braceIndex - 1);
        if ($previous === null) {
            return false;
        }

        $id = $this->tokens[$previous]['id'];
        if (in_array($id, [T_ELSE, T_DO], true)) {
            return true;
        }
        if ($this->tokens[$previous]['text'] !== ')') {
            return false;
        }

        $open = $this->matchingOpeningToken($previous, '(', ')');
        if ($open === null) {
            return false;
        }
        $control = $this->previousSignificantToken($open - 1);
        if ($control === null) {
            return false;
        }

        return in_array($this->tokens[$control]['id'], [T_IF, T_ELSEIF, T_FOR, T_FOREACH, T_WHILE, T_SWITCH], true);
    }

    private function previousSignificantToken(int $start): ?int
    {
        for ($i = $start; $i >= 0; $i--) {
            $id = $this->tokens[$i]['id'];
            if ($id !== null && in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    private function matchingOpeningToken(int $close, string $openText, string $closeText): ?int
    {
        $depth = 0;
        for ($i = $close; $i >= 0; $i--) {
            $text = $this->tokens[$i]['text'];
            if ($text === $closeText) {
                $depth++;
            } elseif ($text === $openText) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
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

        foreach ($this->matches('/(?P<var>\$[A-Za-z_][A-Za-z0-9_]*)\s*->\s*'.preg_quote($method, '/').'\s*\(/i') as $match) {
            $connection = $this->localDatabaseConnectionForVariable($match['offset'], $this->captured($match, 'var'));
            if ($connection === null) {
                continue;
            }
            $calls[] = [
                'offset' => $match['offset'],
                'connection' => $connection,
            ];
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

    /** @return array{start:int,end:int,startToken:int,endToken:int}|null */
    private function localClosureForVariableBefore(int $offset, string $variable): ?array
    {
        $scope = $this->callableScopeAt($offset);
        $resolved = null;
        $assignments = 0;
        $count = count($this->tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $this->tokens[$i];
            if ($token['offset'] >= $offset || $token['id'] !== T_VARIABLE || $token['text'] !== $variable) {
                continue;
            }
            if ($this->callableScopeAt($token['offset']) !== $scope) {
                continue;
            }

            $assign = $this->nextSignificantToken($i + 1);
            if ($assign === null || $this->tokens[$assign]['text'] !== '=') {
                continue;
            }
            $assignments++;
            if ($assignments > 1 || $this->conditionalControlScopeAt($token['offset']) !== null) {
                return null;
            }

            $value = $this->nextSignificantToken($assign + 1);
            if ($value !== null && $this->tokens[$value]['id'] === T_STATIC) {
                $value = $this->nextSignificantToken($value + 1);
            }
            if ($value === null || ! in_array($this->tokens[$value]['id'], [T_FUNCTION, T_FN], true)) {
                $resolved = null;

                continue;
            }

            if ($this->tokens[$value]['id'] === T_FUNCTION) {
                $open = $this->nextTokenText($value + 1, '{');
                $close = $open !== null ? $this->matchingToken($open, '{', '}') : null;
                $resolved = $open !== null && $close !== null ? [
                    'start' => $this->tokens[$open]['end'],
                    'end' => $this->tokens[$close]['offset'],
                    'startToken' => $open,
                    'endToken' => $close,
                ] : null;

                continue;
            }

            $arrow = $this->nextTokenText($value + 1, '=>');
            if ($arrow === null) {
                $resolved = null;

                continue;
            }
            $expressionEnd = $this->arrowExpressionEnd($arrow + 1);
            $resolved = [
                'start' => $this->tokens[$arrow]['end'],
                'end' => $expressionEnd,
                'startToken' => $arrow,
                'endToken' => $this->tokenIndexBeforeOffset($expressionEnd) ?? $arrow,
            ];
        }

        return $resolved;
    }

    private function transactionAttemptsFromCall(int $openToken, int $callCloseToken): int
    {
        $start = $this->tokens[$openToken]['end'];
        $end = $this->tokens[$callCloseToken]['offset'];
        $arguments = substr($this->source, $start, max(0, $end - $start));

        if (preg_match('/\battempts\s*:\s*(\d+)/i', $arguments, $match) === 1) {
            return max(1, (int) $match[1]);
        }
        if (preg_match('/,\s*(\d+)\s*$/s', $arguments, $match) === 1) {
            return max(1, (int) $match[1]);
        }
        if (str_contains($arguments, ',')) {
            return 0;
        }

        return 1;
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
        if ($ok === false) {
            $key = hash('sha256', $pattern.'|'.preg_last_error_msg());
            if (! isset($this->regexErrors[$key])) {
                $this->regexErrors[$key] = true;
                $this->preScanFindings[] = new Finding(
                    rule: 'TG902',
                    severity: Severity::Error,
                    message: 'Analyzer regular expression failed: '.preg_last_error_msg(),
                    file: $this->file,
                    line: 1,
                    snippet: '',
                    remediation: 'Report this analyzer failure; analysis results for this file may be incomplete.',
                    confidence: 'high',
                    projectRoot: $this->config->projectRoot,
                );
            }

            return [];
        }
        if ($ok === 0) {
            return [];
        }

        foreach ($matches as $match) {
            $offset = $match[0][1];
            if ($this->offsetIsNonCode($offset) || $this->semanticCaptureIsNonCode($match, $allowNonCodeCaptures)) {
                continue;
            }
            if (! $this->capturedMethodIsTopLevel($match)) {
                continue;
            }
            if (! $this->staticFacadeMatchUsesValidContext($pattern, $match, $offset)) {
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

    /** @param array{offset:int,matches:array<int|string,mixed>} $match */
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
            if ($this->nestedCallableRunsEagerly($callable)) {
                return false;
            }

            return true;
        }

        return false;
    }

    /** @param array{start:int,end:int} $callable */
    private function nestedCallableRunsEagerly(array $callable): bool
    {
        $token = $this->tokenIndexBeforeOffset($callable['start']);
        if ($token === null) {
            return false;
        }

        $callableToken = null;
        for ($i = $token; $i >= 0; $i--) {
            if (in_array($this->tokens[$i]['id'], [T_FUNCTION, T_FN], true)) {
                $callableToken = $i;
                break;
            }
            if ($this->tokens[$i]['text'] === ';') {
                break;
            }
        }
        if ($callableToken === null) {
            return false;
        }

        $depth = 0;
        $open = null;
        for ($i = $callableToken - 1; $i >= 0; $i--) {
            $text = $this->tokens[$i]['text'];
            if ($text === ')') {
                $depth++;

                continue;
            }
            if ($text !== '(') {
                continue;
            }
            if ($depth > 0) {
                $depth--;

                continue;
            }
            $open = $i;
            break;
        }
        if ($open === null) {
            return false;
        }

        $nameIndex = $this->previousSignificantToken($open - 1);
        if ($nameIndex === null) {
            return false;
        }
        $rawName = ltrim($this->tokens[$nameIndex]['text'], '\\');
        $parts = explode('\\', $rawName);
        $name = strtolower(end($parts) ?: $rawName);
        if (! in_array($name, [
            'tap', 'retry', 'array_map', 'array_filter', 'array_reduce',
            'array_walk', 'array_walk_recursive', 'usort', 'uasort', 'uksort',
        ], true)) {
            return false;
        }

        $beforeName = $this->previousSignificantToken($nameIndex - 1);

        return $beforeName === null || ! in_array($this->tokens[$beforeName]['id'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
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

    /**
     * @param  list<Finding>  $findings
     * @param  TransactionRegion  $tx
     */
    private function appendPendingDispatchLifecycleFindings(array &$findings, int $offset, array $tx, ?ClassMetadata $metadata): void
    {
        if ($metadata === null) {
            return;
        }
        if ($metadata->preparesForDispatch()) {
            $this->appendFinding($findings, $offset, 'TG022', Severity::Warning,
                "Job [{$this->basename($metadata->name)}] implements PreparesForDispatch; prepareForDispatch() runs synchronously before Laravel can defer queueing until commit.",
                'Keep prepareForDispatch() free of irreversible work or construct/dispatch the job after commit.', 'high',
                ['transaction_type' => $tx['type']]);
        }
        if ($metadata->uniqueBeforeDispatch() || $metadata->debounced) {
            $kind = $metadata->uniqueBeforeDispatch() && $metadata->debounced ? 'unique/debounce' : ($metadata->uniqueBeforeDispatch() ? 'unique' : 'debounce');
            $this->appendFinding($findings, $offset, 'TG023', Severity::Warning,
                "PendingDispatch may acquire {$kind} cache state for [{$this->basename($metadata->name)}] before queue after-commit deferral takes effect.",
                'Dispatch after commit when pre-commit cache-lock state is not acceptable; Laravel may compensate locks on rollback but the state exists while the transaction is open.', 'high',
                ['transaction_type' => $tx['type'], 'lock_kind' => $kind]);
        }
    }

    private function conditionalDispatchIsSkipped(string $statement, string $method): bool
    {
        if (! in_array($method, ['dispatchIf', 'dispatchUnless'], true)) {
            return false;
        }
        $condition = StaticExpressionResolver::booleanFirstArgument($statement, $method);
        if ($condition === null) {
            return false;
        }

        return $method === 'dispatchIf' ? ! $condition : $condition;
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

    private function notificationDispatchIsAfterCommitSafe(string $statement, ?ClassMetadata $metadata): bool
    {
        if ($this->statementContainsBeforeCommit($statement) || $metadata?->explicitlyBeforeCommit() === true) {
            return false;
        }
        if ($this->statementContainsAfterCommit($statement) || $metadata?->queueAfterCommit() === true) {
            return true;
        }
        if (! $this->queueConnectionDispatchesAfterCommit($statement, $metadata)) {
            return false;
        }
        if ($metadata === null) {
            return true;
        }

        foreach ($this->classIndex->notificationChannelConnections($metadata->name) as $connection) {
            if ($connection === '@dynamic' || ! $this->config->queueDispatchesAfterCommit($connection)) {
                return false;
            }
        }

        return true;
    }

    private function queueConnectionDispatchesAfterCommit(string $statement, ?ClassMetadata $metadata = null): bool
    {
        $connection = $this->queueConnectionFromStatement($statement, $metadata);

        return $this->config->queueDispatchesAfterCommit($connection);
    }

    private function queueConnectionFromStatement(string $statement, ?ClassMetadata $metadata = null): ?string
    {
        $code = $this->codeOnlyFragment($statement);
        $instanceConnection = null;
        if (preg_match('/->\s*onConnection\s*\(/i', $code, $call, PREG_OFFSET_CAPTURE) === 1) {
            $instanceConnection = $this->literalStringArgumentFromCall(substr($statement, $call[0][1])) ?? '@dynamic';
        }

        foreach ($this->facadeAliases('Illuminate\Support\Facades\Queue', 'Queue') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*connection\s*\(/i';
            if (preg_match($pattern, $code, $call, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $literal = $this->literalStringArgumentFromCall(substr($statement, $call[0][1]));

            return $literal ?? '@dynamic';
        }

        if ($metadata !== null) {
            return $this->classIndex->queueConnection($metadata->name, $instanceConnection);
        }

        return $instanceConnection;
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

    private function facadeStaticMethodPattern(string $alias, string $methods): string
    {
        return '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*(?P<method>'.$methods.')\s*\(/i';
    }

    /** @return list<string> */
    private function facadeAliases(string $fqcn, string $fallback): array
    {
        $cacheKey = strtolower(ltrim($fqcn, '\\')).'|'.$fallback;
        $normalized = ltrim($fqcn, '\\');

        if (isset($this->facadeAliasCache[$cacheKey])) {
            $aliases = $this->facadeAliasCache[$cacheKey];
            $this->activateFacadeAliasTargets($aliases, $normalized, $fallback);

            return $aliases;
        }

        $aliases = ['\\'.$normalized];
        foreach ($this->classIndex->contextsFor($this->file) as $context) {
            $fallbackImport = $context->importForAlias($fallback);
            if ($fallbackImport === null || strcasecmp(ltrim($fallbackImport, '\\'), $normalized) === 0) {
                $aliases[] = $fallback;
            }
            foreach ($context->imports as $alias => $import) {
                if (strcasecmp(ltrim($import, '\\'), $normalized) === 0) {
                    $aliases[] = $alias;
                }
            }
        }

        $aliases = array_values(array_unique($aliases));
        $this->activateFacadeAliasTargets($aliases, $normalized, $fallback);

        return $this->facadeAliasCache[$cacheKey] = $aliases;
    }

    /** @param list<string> $aliases */
    private function activateFacadeAliasTargets(array $aliases, string $fqcn, string $fallback): void
    {
        $this->activeFacadeAliasTargets = [];
        foreach ($aliases as $alias) {
            $this->activeFacadeAliasTargets[strtolower(ltrim($alias, '\\'))] = [
                'fqcn' => $fqcn,
                'fallback' => $fallback,
            ];
        }
    }

    private function facadeAliasValidAt(string $alias, string $fqcn, string $fallback, int $offset): bool
    {
        return $this->facadeAliasValidInContext(
            $alias,
            $fqcn,
            $fallback,
            $this->classIndex->contextFor($this->file, $offset),
        );
    }

    private function facadeAliasValidInContext(string $alias, string $fqcn, string $fallback, FileContext $context): bool
    {
        $normalized = ltrim($fqcn, '\\');
        if (str_starts_with($alias, '\\')) {
            return strcasecmp(ltrim($alias, '\\'), $normalized) === 0;
        }

        $import = $context->importForAlias($alias);
        if ($import !== null) {
            return strcasecmp(ltrim($import, '\\'), $normalized) === 0;
        }

        return strcasecmp($alias, $fallback) === 0;
    }

    /** @param array<int|string, mixed> $match */
    private function staticFacadeMatchUsesValidContext(string $pattern, array $match, int $offset): bool
    {
        $full = $match[0] ?? null;
        if (! is_array($full) || ! isset($full[0]) || ! is_string($full[0])) {
            return true;
        }

        $separator = strpos($full[0], '::');
        if ($separator === false) {
            return true;
        }
        $alias = trim(substr($full[0], 0, $separator));
        $normalizedAlias = ltrim($alias, '\\');
        if ($normalizedAlias === '') {
            return true;
        }
        foreach (explode('\\', $normalizedAlias) as $segment) {
            if ($segment === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $segment) !== 1) {
                return true;
            }
        }

        // Generic class-static matchers also flow through matches(). Only enforce facade
        // binding when the current regex literally embeds the alias returned by facadeAliases().
        if (stripos($pattern, preg_quote($alias, '/')) === false) {
            return true;
        }

        $target = $this->activeFacadeAliasTargets[strtolower($normalizedAlias)] ?? null;
        if ($target === null) {
            return true;
        }

        return $this->facadeAliasValidAt($alias, $target['fqcn'], $target['fallback'], $offset);
    }

    private function resolveClassAt(string $class, int $offset): string
    {
        return $this->classIndex->contextFor($this->file, $offset)->resolve($class);
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
            column: $this->sourceIndex->columnAt($offset),
            endColumn: $this->sourceIndex->columnAt($offset) + max(0, strlen($this->capturedTokenTextAt($offset)) - 1),
            projectRoot: $this->config->projectRoot,
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

    private function isGlobalFunctionCall(int $offset): bool
    {
        $index = $this->tokenIndexContainingOrAfterOffset($offset);
        if ($index === null) {
            return false;
        }
        $previous = $this->previousSignificantToken($index - 1);
        if ($previous === null) {
            return true;
        }

        return ! in_array($this->tokens[$previous]['id'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
    }

    /** @param array<int|string, mixed> $match */
    private function capturedMethodIsTopLevel(array $match): bool
    {
        $capture = $match['method'] ?? null;
        if (! is_array($capture) || ! isset($capture[1]) || ! is_int($capture[1]) || $capture[1] < 0) {
            return true;
        }
        $full = $match[0] ?? null;
        if (! is_array($full) || ! isset($full[0], $full[1]) || ! is_string($full[0]) || ! is_int($full[1])) {
            return true;
        }
        $prefixLength = $capture[1] - $full[1];
        if ($prefixLength <= 0) {
            return true;
        }
        $prefix = substr($full[0], 0, $prefixLength);
        if (! str_contains($prefix, '::')) {
            return true;
        }

        $paren = $bracket = $brace = 0;
        foreach (token_get_all('<?php '.$prefix) as $token) {
            if (is_array($token)) {
                continue;
            }
            if ($token === '(') {
                $paren++;
            } elseif ($token === ')') {
                $paren--;
            } elseif ($token === '[') {
                $bracket++;
            } elseif ($token === ']') {
                $bracket--;
            } elseif ($token === '{') {
                $brace++;
            } elseif ($token === '}') {
                $brace--;
            }
        }

        return $paren === 0 && $bracket === 0 && $brace === 0;
    }

    /**
     * @param  DatabaseControlCall  $start
     * @param  DatabaseControlCall  $terminal
     */
    private function manualRegionHasEarlyExit(array $start, array $terminal): bool
    {
        $hasCatchOrFinally = false;
        foreach ($this->tokens as $token) {
            if ($token['offset'] <= $start['end'] || $token['offset'] >= $terminal['offset']) {
                continue;
            }
            if (in_array($token['id'], [T_CATCH, T_FINALLY], true)) {
                $hasCatchOrFinally = true;
            }
            if (in_array($token['id'], [T_RETURN, T_EXIT], true)) {
                return true;
            }
            if ($token['id'] === T_THROW && ! $hasCatchOrFinally) {
                return true;
            }
        }

        return false;
    }

    private function capturedTokenTextAt(int $offset): string
    {
        $index = $this->tokenIndexContainingOrAfterOffset($offset);

        return $index === null ? '' : $this->tokens[$index]['text'];
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
