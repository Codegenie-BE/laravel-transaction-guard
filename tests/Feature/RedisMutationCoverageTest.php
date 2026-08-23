<?php

declare(strict_types=1);

use Codegenie\TransactionGuard\Analysis\AnalysisConfig;
use Codegenie\TransactionGuard\TransactionGuard;

it('detects additional direct Redis mutations', function (string $call): void {
    $temporary = tempnam(sys_get_temp_dir(), 'tg-redis-mutation-');
    expect($temporary)->not->toBeFalse();
    $file = $temporary.'.php';
    rename($temporary, $file);

    file_put_contents($file, <<<PHP
<?php
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Redis;
DB::transaction(fn () => Redis::{$call});
PHP);

    try {
        $result = (new TransactionGuard(new AnalysisConfig))->analyze([$file]);
        $rules = array_map(static fn ($finding): string => $finding->rule, $result->findings);

        expect($rules)->toContain('TG020');
    } finally {
        @unlink($file);
    }
})->with([
    'SETNX' => ["setnx('lock', '1')"],
    'LSET' => ["lset('queue', 0, 'x')"],
    'RENAME' => ["rename('old', 'new')"],
    'XGROUP' => ["xgroup('CREATE', 'stream', 'group', '0')"],
]);
