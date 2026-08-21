<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

use ParseError;

final class SourceScanner
{
    /** @var list<array{id:int|null,text:string,line:int,offset:int,end:int}> */
    private array $tokens = [];

    /** @var list<array{start:int,end:int,line:int,type:string,attempts:int,callableStart:int,callableEnd:int}> */
    private array $transactions = [];

    /** @var list<array{start:int,end:int}> */
    private array $callables = [];

    /** @var list<array{start:int,end:int}> */
    private array $afterCommitCallbacks = [];

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

        $this->callables = $this->findCallableRegions();
        $this->transactions = array_merge($this->findClosureTransactions(), $this->findManualTransactions());
        $this->afterCommitCallbacks = $this->findAfterCommitCallbacks();

        $findings = [];

        foreach ($this->findExplicitBeforeCommitCalls() as $match) {
            $this->appendFinding($findings, $match['offset'], 'TG010', Severity::Error,
                'beforeCommit() explicitly forces dispatch before the surrounding database transaction commits.',
                'Remove beforeCommit(), use afterCommit(), implement an after-commit contract, or move the dispatch outside the transaction.');
        }

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

    /** @param list<Finding> $findings */
    private function scanJobDispatches(array &$findings): void
    {
        $patterns = [
            '/(?<![A-Za-z0-9_\\\\])(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)\s*::\s*(?P<method>dispatchSync|dispatchAfterResponse|dispatchIf|dispatchUnless|dispatch)\s*\(/',
            '/(?<![A-Za-z0-9_])dispatch\s*\(\s*new\s+(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)/i',
            '/(?<![A-Za-z0-9_])(?P<method>dispatch_sync)\s*\(\s*new\s+(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)/i',
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

                // Avoid treating arbitrary domain ::dispatch() methods as Laravel jobs.
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
                        'high', ['transaction_type' => $tx['type']]);
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
                    // TG010 already reports the explicit override.
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
    }

    /** @param list<Finding> $findings */
    private function scanBusAndQueue(array &$findings): void
    {
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

                if ($method === 'dispatchAfterResponse') {
                    $this->appendFinding($findings, $offset, 'TG017', Severity::Warning,
                        'Bus::dispatchAfterResponse() does not guarantee that the surrounding transaction committed successfully.',
                        'Use an after-commit dispatch when the work depends on committed state.', 'medium');
                    continue;
                }

                if (in_array($method, ['dispatchSync', 'dispatch_sync'], true)) {
                    $this->appendFinding($findings, $offset, 'TG016', Severity::Warning,
                        'Bus::dispatchSync() executes while the database transaction is still open.',
                        'Move synchronous work outside the transaction when it can cause irreversible side effects.', 'high');
                    $this->appendRetryFinding($findings, $offset, $tx, 'synchronous bus dispatch');
                    continue;
                }

                if (in_array($method, ['chain', 'batch'], true)) {
                    $this->appendFinding($findings, $offset, 'TG001', Severity::Warning,
                        "Bus::{$method}() is created/dispatched from inside a database transaction and cannot be proven commit-safe statically.",
                        'Create and dispatch the chain/batch after commit, or wrap the dispatch in DB::afterCommit().', 'medium');
                    $this->appendRetryFinding($findings, $offset, $tx, "bus {$method} dispatch");
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
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::.*?\b(?P<method>push|later|bulk|pushOn|laterOn)\s*\(/s';
            foreach ($this->matches($pattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $statement = $this->statementAt($offset);
                if ($this->queueConnectionDispatchesAfterCommit($statement)) {
                    continue;
                }

                $this->appendFinding($findings, $offset, 'TG001', Severity::Error,
                    'A job is pushed directly to a queue while the surrounding database transaction is still open.',
                    'Enable after_commit for that queue connection or push the job from DB::afterCommit()/after the transaction.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'queue push');
            }
        }
    }

    /** @param list<Finding> $findings */
    private function scanEvents(array &$findings): void
    {
        $patterns = ['/\bevent\s*\(\s*new\s+(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)/i'];
        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Event', 'Event') as $alias) {
            $patterns[] = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*dispatch\s*\(\s*new\s+(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)/i';
        }

        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Event', 'Event') as $alias) {
            foreach ($this->matches('/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\\s*::\\s*defer\\s*\\(/i') as $match) {
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

        // Event classes commonly use Illuminate\Foundation\Events\Dispatchable and call EventClass::dispatch(...).
        foreach ($this->matches('/(?<![A-Za-z0-9_\\\\])(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)\s*::\s*dispatch\s*\(/') as $match) {
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
                "Event [{$this->basename($class)}] is dispatched before the surrounding transaction commits; synchronous listeners execute immediately.",
                'Implement ShouldDispatchAfterCommit on the event, use DB::afterCommit(), or dispatch after the transaction.',
                $metadata === null ? 'medium' : 'high');
            $this->appendRetryFinding($findings, $offset, $tx, 'event dispatch');
        }
    }

    /** @param list<Finding> $findings */
    private function scanMail(array &$findings): void
    {
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

                if ($queued && ! $this->statementContainsBeforeCommit($statement)
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

                if ($queued && ! $this->statementContainsBeforeCommit($statement)
                    && ($this->statementContainsAfterCommit($statement) || $metadata?->queueAfterCommit() === true || $this->queueConnectionDispatchesAfterCommit($statement, $metadata))) {
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

    /** @param list<Finding> $findings */
    private function scanBroadcasts(array &$findings): void
    {
        foreach ($this->matches('/\bbroadcast\s*\(\s*new\s+(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)/i') as $match) {
            $offset = $match['offset'];
            $tx = $this->eligibleTransaction($offset);
            if ($tx === null) {
                continue;
            }
            $class = $this->context->resolve($this->captured($match, 'class'));
            $metadata = $this->classIndex->metadata($class);
            $statement = $this->statementAt($offset);
            $broadcastNow = $metadata?->implements('Illuminate\\Contracts\\Broadcasting\\ShouldBroadcastNow') === true;

            if (! $broadcastNow && ($metadata?->eventAfterCommit() === true || $this->queueConnectionDispatchesAfterCommit($statement, $metadata))) {
                continue;
            }

            $this->appendFinding($findings, $offset, 'TG005', Severity::Error,
                "Broadcast [{$this->basename($class)}] may run before the surrounding database transaction commits.",
                'Dispatch the event after commit, implement ShouldDispatchAfterCommit, or configure queued broadcasting to dispatch after commit.',
                $metadata === null ? 'medium' : 'high');
            $this->appendRetryFinding($findings, $offset, $tx, 'broadcast');
        }
    }

    /** @param list<Finding> $findings */
    private function scanHttp(array &$findings): void
    {
        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Http', 'Http') as $alias) {
            $methods = $this->config->detectReadHttpCalls ? 'get|head|post|put|patch|delete|send' : 'post|put|patch|delete|send';
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::(?:(?!;).)*?(?P<method>'.$methods.')\s*\(/is';
            foreach ($this->matches($pattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $method = strtoupper($this->captured($match, 'method'));
                $severity = in_array(strtolower($method), ['get', 'head'], true) ? Severity::Warning : Severity::Error;
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
    }

    /** @param list<Finding> $findings */
    private function scanFilesystem(array &$findings): void
    {
        $facades = [
            'Illuminate\\Support\\Facades\\Storage' => ['Storage', 'put|putFile|putFileAs|delete|move|copy|append|prepend|setVisibility|makeDirectory|deleteDirectory'],
            'Illuminate\\Support\\Facades\\File' => ['File', 'put|replace|delete|move|copy|append|prepend|makeDirectory|deleteDirectory|cleanDirectory'],
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

        foreach ($this->matches('/\b(?P<fn>file_put_contents|unlink|rename|mkdir|rmdir)\s*\(/i') as $match) {
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

    /** @param list<Finding> $findings */
    private function scanCache(array &$findings): void
    {
        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Cache', 'Cache') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::(?:(?!;).)*?\b(?P<method>put|add|forever|forget|flush|increment|decrement|pull)\s*\(/is';
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
            foreach ($this->matches($commandPattern) as $match) {
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

    /** @param list<Finding> $findings */
    private function scanProcesses(array &$findings): void
    {
        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\Process', 'Process') as $alias) {
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::(?:(?!;).)*?\b(?P<method>run|start|pipe)\s*\(/is';
            foreach ($this->matches($pattern) as $match) {
                $offset = $match['offset'];
                $tx = $this->eligibleTransaction($offset);
                if ($tx === null) {
                    continue;
                }
                $this->appendFinding($findings, $offset, 'TG009', Severity::Error,
                    'An external process is started while a database transaction is open.',
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

    /** @param list<Finding> $findings */
    private function scanImplicitCommits(array &$findings): void
    {
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

    /** @param list<Finding> $findings */
    private function scanManualTransactionBalance(array &$findings): void
    {
        $stack = [];
        foreach ($this->manualControlCalls() as $call) {
            if ($call['type'] === 'begin') {
                $stack[] = $call;
                continue;
            }
            if (in_array($call['type'], ['commit', 'rollback'], true) && $stack !== []) {
                array_pop($stack);
            }
        }

        foreach ($stack as $call) {
            $this->appendFinding($findings, $call['offset'], 'TG013', Severity::Critical,
                'A manually started database transaction has no matching commit() or rollBack() in the same source flow.',
                'Prefer DB::transaction() or guarantee commit/rollback with a try/catch/finally structure.', 'medium');
        }
    }

    /** @return list<array{start:int,end:int,line:int,type:string,attempts:int,callableStart:int,callableEnd:int}> */
    private function findClosureTransactions(): array
    {
        $regions = [];
        foreach ($this->dbTransactionCallOffsets('transaction') as $offset) {
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
                'callableStart' => $closure['start'],
                'callableEnd' => $closure['end'],
            ];
        }

        return $regions;
    }

    /** @return list<array{start:int,end:int,line:int,type:string,attempts:int,callableStart:int,callableEnd:int}> */
    private function findManualTransactions(): array
    {
        $regions = [];
        $calls = $this->manualControlCalls();
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
                'callableStart' => $groupStart['end'],
                'callableEnd' => $end,
            ];

            $groupStart = null;
            $groupEnd = null;
            $depth = 0;
        };

        foreach ($calls as $call) {
            if ($groupStart !== null && $call['scope'] !== $groupStart['scope']) {
                $flush();
            }

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

            // A commit/rollback can be an alternative branch terminal. Keep the
            // manual region open through orphan terminals until the next begin.
            $groupEnd = $call['offset'];
            if ($depth > 0) {
                $depth--;
            }
        }

        $flush();

        return $regions;
    }

    /** @return list<array{type:string,offset:int,end:int,scope:string}> */
    private function manualControlCalls(): array
    {
        $calls = [];
        foreach (['beginTransaction' => 'begin', 'commit' => 'commit', 'rollBack' => 'rollback'] as $method => $type) {
            foreach ($this->dbTransactionCallOffsets($method) as $offset) {
                $calls[] = [
                    'type' => $type,
                    'offset' => $offset,
                    'end' => $this->statementEnd($offset),
                    'scope' => $this->callableScopeAt($offset),
                ];
            }
        }

        usort($calls, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);

        return $calls;
    }

    private function callableScopeAt(int $offset): string
    {
        $matches = array_values(array_filter(
            $this->callables,
            static fn (array $callable): bool => $offset >= $callable['start'] && $offset <= $callable['end'],
        ));

        if ($matches === []) {
            return 'global';
        }

        usort($matches, static fn (array $a, array $b): int => (($a['end'] - $a['start']) <=> ($b['end'] - $b['start'])));
        $scope = $matches[0];

        return $scope['start'].':'.$scope['end'];
    }

    /** @return list<int> */
    private function dbTransactionCallOffsets(string $method): array
    {
        $offsets = [];
        foreach ($this->facadeAliases('Illuminate\\Support\\Facades\\DB', 'DB') as $alias) {
            $patterns = [
                '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*'.preg_quote($method, '/').'\s*\(/i',
                '/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*connection\s*\([^;]*?\)\s*->\s*'.preg_quote($method, '/').'\s*\(/is',
            ];
            foreach ($patterns as $pattern) {
                foreach ($this->matches($pattern) as $match) {
                    $full = $match['matches'][0][0] ?? '';
                    $relative = strripos((string) $full, $method);
                    $offsets[] = $match['offset'] + ($relative === false ? 0 : $relative);
                }
            }
        }

        sort($offsets);

        return array_values(array_unique($offsets));
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

    /** @return list<array{offset:int,matches:array<int|string,array{0:string,1:int}|string>}> */
    private function matches(string $pattern): array
    {
        $result = [];
        $ok = @preg_match_all($pattern, $this->source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($ok === false || $ok === 0) {
            return [];
        }

        foreach ($matches as $match) {
            $offset = $match[0][1];
            if ($this->offsetIsNonCode($offset)) {
                continue;
            }
            $result[] = ['offset' => $offset, 'matches' => $match];
        }

        return $result;
    }

    private function offsetIsNonCode(int $offset): bool
    {
        $ignored = [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];

        foreach ($this->tokens as $token) {
            if ($token['offset'] > $offset) {
                break;
            }
            if ($offset >= $token['offset'] && $offset < $token['end']) {
                return $token['id'] !== null && in_array($token['id'], $ignored, true);
            }
        }

        return false;
    }

    /** @param array{matches:array<int|string,mixed>} $match */
    private function captured(array $match, string $name): string
    {
        $value = $match['matches'][$name] ?? '';
        if (is_array($value)) {
            return (string) $value[0];
        }

        return (string) $value;
    }

    /** @return list<array{offset:int}> */
    private function findExplicitBeforeCommitCalls(): array
    {
        $matches = [];
        foreach ($this->matches('/->\s*beforeCommit\s*\(/i') as $match) {
            if ($this->eligibleTransaction($match['offset']) !== null) {
                $matches[] = ['offset' => $match['offset']];
            }
        }

        return $matches;
    }

    /** @return array{start:int,end:int,line:int,type:string,attempts:int,callableStart:int,callableEnd:int}|null */
    private function eligibleTransaction(int $offset): ?array
    {
        $tx = $this->transactionAt($offset);
        if ($tx === null || $this->isInsideAfterCommitCallback($offset) || $this->isDeferredNestedCallable($offset, $tx)) {
            return null;
        }

        return $tx;
    }

    /** @return array{start:int,end:int,line:int,type:string,attempts:int,callableStart:int,callableEnd:int}|null */
    private function transactionAt(int $offset): ?array
    {
        $matches = array_values(array_filter($this->transactions, static fn (array $tx): bool => $offset >= $tx['start'] && $offset <= $tx['end']));
        if ($matches === []) {
            return null;
        }
        usort($matches, static fn (array $a, array $b): int => (($a['end'] - $a['start']) <=> ($b['end'] - $b['start'])));

        return $matches[0];
    }

    /** @param array{start:int,end:int,line:int,type:string,attempts:int,callableStart:int,callableEnd:int} $tx */
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
        if ($this->statementContainsBeforeCommit($statement) || $metadata?->constructorBeforeCommit === true) {
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
        if (preg_match('/->\s*onConnection\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/i', $statement, $match) === 1) {
            return $match[1];
        }
        if (preg_match('/->\s*onConnection\s*\(/i', $statement) === 1) {
            return '@dynamic';
        }

        foreach ($this->facadeAliases('Illuminate\Support\Facades\Queue', 'Queue') as $alias) {
            if (preg_match('/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*connection\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/i', $statement, $match) === 1) {
                return $match[1];
            }
            if (preg_match('/(?<![A-Za-z0-9_])'.preg_quote($alias, '/').'\s*::\s*connection\s*\(/i', $statement) === 1) {
                return '@dynamic';
            }
        }

        if ($metadata?->constructorQueueConnection !== null) {
            return $metadata->constructorQueueConnection;
        }

        return $metadata === null ? null : $this->classIndex->queueRouteConnection($metadata->name);
    }

    private function statementContainsAfterCommit(string $statement): bool
    {
        return preg_match('/->\s*afterCommit\s*\(/i', $statement) === 1;
    }

    private function statementContainsBeforeCommit(string $statement): bool
    {
        return preg_match('/->\s*beforeCommit\s*\(/i', $statement) === 1;
    }

    private function statementContainsAfterResponse(string $statement): bool
    {
        return preg_match('/->\s*afterResponse\s*\(/i', $statement) === 1;
    }

    private function newClassFromStatement(string $statement): ?string
    {
        if (preg_match('/\bnew\s+(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)/', $statement, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /** @return list<string> */
    private function facadeAliases(string $fqcn, string $fallback): array
    {
        $aliases = [$fallback];
        foreach ($this->context->imports as $alias => $import) {
            if (strcasecmp(ltrim($import, '\\'), ltrim($fqcn, '\\')) === 0) {
                $aliases[] = $alias;
            }
        }

        return array_values(array_unique($aliases));
    }

    /** @param list<Finding> $findings */
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

    /** @param list<Finding> $findings @param array{attempts:int,type:string} $tx */
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

    /** @return array{start:int,end:int,line:int,type:string,attempts:int,callableStart:int,callableEnd:int}|null */
    private function retryableTransactionAt(int $offset): ?array
    {
        $matches = array_values(array_filter(
            $this->transactions,
            static fn (array $candidate): bool => $offset >= $candidate['start']
                && $offset <= $candidate['end']
                && $candidate['attempts'] !== 1,
        ));

        if ($matches === []) {
            return null;
        }

        usort($matches, static function (array $a, array $b): int {
            $aKnown = $a['attempts'] > 1 ? 1 : 0;
            $bKnown = $b['attempts'] > 1 ? 1 : 0;
            if ($aKnown !== $bKnown) {
                return $bKnown <=> $aKnown;
            }

            return $b['attempts'] <=> $a['attempts'];
        });

        return $matches[0];
    }

    private function suppressed(int $offset, string $rule): bool
    {
        $line = $this->lineAtOffset($offset);
        $lines = preg_split('/\R/', $this->source) ?: [];

        $current = $lines[$line - 1] ?? '';
        if ($this->suppressionDirectiveMatches($current, 'transaction-guard-ignore', $rule, rejectNextLine: true)) {
            return true;
        }

        $previous = $lines[$line - 2] ?? '';

        return $this->suppressionDirectiveMatches($previous, 'transaction-guard-ignore-next-line', $rule);
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
        $start = $offset;
        while ($start > 0 && ! in_array($this->source[$start - 1], [';', '{', '}'], true)) {
            $start--;
        }
        $end = strpos($this->source, ';', $offset);
        if ($end === false) {
            $end = min(strlen($this->source), $offset + 500);
        } else {
            $end++;
        }

        return substr($this->source, $start, max(0, $end - $start));
    }

    private function statementEnd(int $offset): int
    {
        $end = strpos($this->source, ';', $offset);

        return $end === false ? min(strlen($this->source), $offset + 1) : $end + 1;
    }

    private function basename(string $class): string
    {
        $parts = explode('\\', trim($class, '\\'));

        return end($parts) ?: $class;
    }

    private function lineAtOffset(int $offset): int
    {
        return substr_count(substr($this->source, 0, max(0, $offset)), "\n") + 1;
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
        foreach ($this->tokens as $i => $token) {
            if ($token['offset'] < $offset) {
                continue;
            }
            if ($token['text'] === $text) {
                return $i;
            }
        }

        return null;
    }

    private function tokenIndexBeforeOffset(int $offset): ?int
    {
        $found = null;
        foreach ($this->tokens as $i => $token) {
            if ($token['offset'] >= $offset) {
                break;
            }
            $found = $i;
        }

        return $found;
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
            if ($text === '(') $paren++;
            elseif ($text === ')') {
                if ($paren === 0 && $bracket === 0 && $brace === 0) return $this->tokens[$i]['offset'];
                $paren = max(0, $paren - 1);
            } elseif ($text === '[') $bracket++;
            elseif ($text === ']') $bracket = max(0, $bracket - 1);
            elseif ($text === '{') $brace++;
            elseif ($text === '}') $brace = max(0, $brace - 1);
            elseif ($text === ',' && $paren === 0 && $bracket === 0 && $brace === 0) return $this->tokens[$i]['offset'];
            elseif ($text === ';' && $paren === 0 && $bracket === 0 && $brace === 0) return $this->tokens[$i]['offset'];
        }

        return $this->tokens[$limit]['end'] ?? strlen($this->source);
    }
}
