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

it('keeps read-only GETEX forms clean', function (string $body): void {
    $temporary = tempnam(sys_get_temp_dir(), 'tg-redis-getex-read-');
    expect($temporary)->not->toBeFalse();
    $file = $temporary.'.php';
    rename($temporary, $file);

    file_put_contents($file, "<?php\n"
        ."use Illuminate\\Support\\Facades\\DB;\n"
        ."use Illuminate\\Support\\Facades\\Redis;\n"
        ."DB::transaction(function () {\n{$body}\n});\n");

    try {
        $result = (new TransactionGuard(new AnalysisConfig))->analyze([$file]);
        $rules = array_map(static fn ($finding): string => $finding->rule, $result->findings);

        expect($rules)->not->toContain('TG020');
    } finally {
        @unlink($file);
    }
})->with([
    'direct GETEX without expiry' => ["Redis::getex('key');"],
    'command GETEX with key only' => ["Redis::command('GETEX', ['key']);"],
    'local Redis handle GETEX without expiry' => ["\$redis = Redis::connection();\n\$redis->getex('key');"],
    'pipeline GETEX without expiry' => ["Redis::pipeline(fn (\$redis) => \$redis->getex('key'));"],
]);

it('detects GETEX forms that change expiry state', function (string $body): void {
    $temporary = tempnam(sys_get_temp_dir(), 'tg-redis-getex-write-');
    expect($temporary)->not->toBeFalse();
    $file = $temporary.'.php';
    rename($temporary, $file);

    file_put_contents($file, "<?php\n"
        ."use Illuminate\\Support\\Facades\\DB;\n"
        ."use Illuminate\\Support\\Facades\\Redis;\n"
        ."DB::transaction(function () {\n{$body}\n});\n");

    try {
        $result = (new TransactionGuard(new AnalysisConfig))->analyze([$file]);
        $rules = array_map(static fn ($finding): string => $finding->rule, $result->findings);

        expect($rules)->toContain('TG020');
    } finally {
        @unlink($file);
    }
})->with([
    'direct GETEX EX' => ["Redis::getex('key', 'EX', 60);"],
    'direct GETEX PERSIST' => ["Redis::getex('key', 'PERSIST');"],
    'command GETEX EX' => ["Redis::command('GETEX', ['key', 'EX', 60]);"],
    'local Redis handle GETEX EX' => ["\$redis = Redis::connection();\n\$redis->getex('key', 'EX', 60);"],
    'pipeline GETEX PERSIST' => ["Redis::pipeline(fn (\$redis) => \$redis->getex('key', 'PERSIST'));"],
    'dynamic GETEX modifier remains conservative' => ["\$modifier = getenv('REDIS_GETEX_MODIFIER');\nRedis::getex('key', \$modifier, 60);"],
]);
