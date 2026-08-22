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

replaceOnce('src/Analysis/SourceScanner.php', 'dispatchSync|dispatchAfterResponse|dispatch|chain|batch', 'dispatchSync|dispatchAfterResponse|dispatch|bulk|chain|batch');

$bulk = <<<'PHP'
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

PHP;
replaceOnce('src/Analysis/SourceScanner.php', "                if (in_array(\$method, ['chain', 'batch'], true)\n", $bulk."                if (in_array(\$method, ['chain', 'batch'], true)\n");

$helper = <<<'PHP'
    /** @return list<string> */
    private function newClassesFromStatement(string $statement): array
    {
        $result = preg_match_all('/\bnew\s+(\\?[A-Za-z_][A-Za-z0-9_\\]*)/', $statement, $matches);
        if ($result === false || $result === 0) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

PHP;
replaceOnce('src/Analysis/SourceScanner.php', "    /** @return list<string> */\n    private function facadeAliases", $helper."    /** @return list<string> */\n    private function facadeAliases");
