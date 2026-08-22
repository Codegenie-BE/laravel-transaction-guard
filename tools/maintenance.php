<?php

declare(strict_types=1);

function replaceOnce(string $text, string $old, string $new, string $label): string
{
    if (! str_contains($text, $old)) {
        throw new RuntimeException("{$label}: expected source block not found");
    }

    $count = 0;
    $result = str_replace($old, $new, $text, $count);
    if ($count !== 1) {
        throw new RuntimeException("{$label}: expected one replacement, got {$count}");
    }

    return $result;
}

$sourcePath = __DIR__.'/../src/Analysis/SourceScanner.php';
$source = file_get_contents($sourcePath);
if ($source === false) {
    throw new RuntimeException('Unable to read SourceScanner.php');
}

$marker = <<<'PHP'
                $globalDispatchHelper = $method === '';
                $looksLikeJob = ($globalDispatchHelper && $metadata === null)
PHP;
$replacement = <<<'PHP'
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
PHP;
$source = replaceOnce($source, $marker, $replacement, 'known synchronous dispatch classification');
file_put_contents($sourcePath, $source);

$matrixPath = __DIR__.'/../tests/Support/ScenarioMatrix.php';
$matrix = file_get_contents($matrixPath);
if ($matrix === false) {
    throw new RuntimeException('Unable to read ScenarioMatrix.php');
}

$scenarioMarker = "    'afterCommit text inside string does not make dispatch safe' => [\n";
$scenarios = <<<'PHP'
    'global dispatch helper with known non queueable class is synchronous' => [
        'code' => <<<'CODE'
<?php
namespace App\Actions;
use Illuminate\Support\Facades\DB;
class RecalculateOrder {}
DB::transaction(function () { dispatch(new RecalculateOrder()); });
CODE,
        'rules' => ['TG016'],
        'absent' => ['TG001'],
    ],
    'static dispatch on known non queueable Jobs class is synchronous' => [
        'code' => <<<'CODE'
<?php
namespace App\Jobs;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
class ImmediateJob { use Dispatchable; }
DB::transaction(function () { ImmediateJob::dispatch(); });
CODE,
        'rules' => ['TG016'],
        'absent' => ['TG001'],
    ],
PHP;
$matrix = replaceOnce($matrix, $scenarioMarker, $scenarios.$scenarioMarker, 'synchronous dispatch regressions');
file_put_contents($matrixPath, $matrix);
