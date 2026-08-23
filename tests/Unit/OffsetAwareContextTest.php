<?php

declare(strict_types=1);

use Codegenie\TransactionGuard\Analysis\AnalysisConfig;
use Codegenie\TransactionGuard\TransactionGuard;

it('resolves class metadata in the namespace active at each match offset', function (): void {
    $temporary = tempnam(sys_get_temp_dir(), 'tg-context-offset-');
    expect($temporary)->not->toBeFalse();
    $file = $temporary.'.php';
    rename($temporary, $file);

    file_put_contents($file, <<<'PHP'
<?php
namespace App\First;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class FirstJob implements ShouldQueue {}
DB::transaction(fn () => FirstJob::dispatch());

namespace App\Second;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class SecondJob implements ShouldQueue {}
DB::transaction(fn () => SecondJob::dispatch());
PHP);

    try {
        $result = (new TransactionGuard(new AnalysisConfig))->analyze([$file]);
        $jobFindings = array_values(array_filter(
            $result->findings,
            static fn ($finding): bool => $finding->rule === 'TG001',
        ));

        expect($jobFindings)->toHaveCount(2)
            ->and($jobFindings[0]->message)->toContain('FirstJob')
            ->and($jobFindings[1]->message)->toContain('SecondJob');
    } finally {
        @unlink($file);
    }
});
