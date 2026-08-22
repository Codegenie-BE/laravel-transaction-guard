<?php

declare(strict_types=1);

$path = 'src/Analysis/SourceScanner.php';
$contents = file_get_contents($path);
if ($contents === false) {
    throw new RuntimeException('Unable to read SourceScanner.php.');
}

$old = <<<'OLD'
                if ($this->callArgumentContainsPreference($statement, 'dispatch', 'afterCommit')
                    || $this->queueConnectionDispatchesAfterCommit($statement)) {
                    continue;
                }

                $this->appendFinding($findings, $offset, 'TG001', Severity::Error,
                    'Bus::dispatch() may execute or enqueue work before the surrounding database transaction commits.',
                    'Chain afterCommit(), enable after_commit on the selected queue connection, or dispatch after the transaction.', 'high');
                $this->appendRetryFinding($findings, $offset, $tx, 'bus dispatch');
OLD;

$updated = str_replace($old, '', $contents, $count);
if ($count !== 1) {
    throw new RuntimeException("Expected one obsolete Bus dispatch block, got {$count}.");
}

file_put_contents($path, $updated);
