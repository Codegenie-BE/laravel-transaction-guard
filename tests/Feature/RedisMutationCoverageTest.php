<?php

declare(strict_types=1);

use Codegenie\TransactionGuard\Analysis\AnalysisConfig;
use Codegenie\TransactionGuard\TransactionGuard;

function analyzeRedisBody(string $body): array
{
    $temporary = tempnam(sys_get_temp_dir(), 'tg-redis-');
    expect($temporary)->not->toBeFalse();
    $file = $temporary.'.php';
    rename($temporary, $file);

    file_put_contents($file, "<?php\n"
        ."use Illuminate\\Support\\Facades\\DB;\n"
        ."use Illuminate\\Support\\Facades\\Redis;\n"
        ."DB::transaction(function () {\n{$body}\n});\n");

    try {
        return (new TransactionGuard(new AnalysisConfig))->analyze([$file])->findings;
    } finally {
        @unlink($file);
    }
}

it('detects direct Redis mutations including current commands', function (string $call): void {
    $findings = analyzeRedisBody("Redis::{$call};");
    $rules = array_map(static fn ($finding): string => $finding->rule, $findings);

    expect($rules)->toContain('TG020');
})->with([
    'SETNX' => ["setnx('lock', '1')"],
    'LSET' => ["lset('queue', 0, 'x')"],
    'RENAME' => ["rename('old', 'new')"],
    'XGROUP' => ["xgroup('CREATE', 'stream', 'group', '0')"],
    'DELEX' => ["delex('key', 'IFEQ', 'value')"],
    'HGETDEL' => ["hgetdel('hash', ['field'])"],
    'HSETEX' => ["hsetex('hash', 60, ['field' => 'value'])"],
    'XDELEX' => ["xdelex('stream', ['1-0'])"],
    'XACKDEL' => ["xackdel('stream', 'group', ['1-0'])"],
]);

it('keeps known direct Redis reads clean', function (string $call): void {
    $findings = analyzeRedisBody("Redis::{$call};");
    $rules = array_map(static fn ($finding): string => $finding->rule, $findings);

    expect($rules)->not->toContain('TG020');
})->with([
    'GET' => ["get('key')"],
    'STRLEN' => ["strlen('key')"],
    'HGETALL' => ["hgetall('hash')"],
    'GEOSEARCH' => ["geosearch('geo', 1, 2, 3, 'km')"],
]);

it('reports unknown methods on a proven Redis receiver conservatively', function (string $body): void {
    $findings = analyzeRedisBody($body);
    $redis = collect($findings)->firstWhere('rule', 'TG020');

    expect($redis)->not->toBeNull()
        ->and($redis->confidence)->toBe('medium');
})->with([
    'facade' => ["Redis::futureWriteCommand('key');"],
    'connection chain' => ["Redis::connection()->futureWriteCommand('key');"],
    'local connection handle' => ["\$redis = Redis::connection();\n\$redis->futureWriteCommand('key');"],
    'pipeline callback' => ["Redis::pipeline(fn (\$redis) => \$redis->futureWriteCommand('key'));"],
]);

it('keeps read-only GETEX forms clean', function (string $body): void {
    $findings = analyzeRedisBody($body);
    $rules = array_map(static fn ($finding): string => $finding->rule, $findings);

    expect($rules)->not->toContain('TG020');
})->with([
    'direct GETEX without expiry' => ["Redis::getex('key');"],
    'PhpRedis GETEX empty options' => ["Redis::getex('key', []);"],
    'PhpRedis GETEX named empty options' => ["Redis::getex('key', options: []);"],
    'command GETEX with key only' => ["Redis::command('GETEX', ['key']);"],
    'local Redis handle GETEX without expiry' => ["\$redis = Redis::connection();\n\$redis->getex('key');"],
    'pipeline GETEX without expiry' => ["Redis::pipeline(fn (\$redis) => \$redis->getex('key'));"],
]);

it('detects GETEX forms that change expiry state', function (string $body): void {
    $findings = analyzeRedisBody($body);
    $redis = collect($findings)->firstWhere('rule', 'TG020');

    expect($redis)->not->toBeNull();
})->with([
    'raw GETEX EX' => ["Redis::getex('key', 'EX', 60);"],
    'raw GETEX PERSIST' => ["Redis::getex('key', 'PERSIST');"],
    'PhpRedis GETEX EX array' => ["Redis::getex('key', ['EX' => 60]);"],
    'PhpRedis GETEX PX array' => ["Redis::getex('key', ['PX' => 500]);"],
    'PhpRedis GETEX PERSIST array' => ["Redis::getex('key', ['PERSIST' => true]);"],
    'PhpRedis GETEX named options' => ["Redis::getex('key', options: ['EX' => 60]);"],
    'command GETEX EX' => ["Redis::command('GETEX', ['key', 'EX', 60]);"],
    'local Redis handle GETEX EX' => ["\$redis = Redis::connection();\n\$redis->getex('key', ['EX' => 60]);"],
    'pipeline GETEX PERSIST' => ["Redis::pipeline(fn (\$redis) => \$redis->getex('key', ['PERSIST' => true]));"],
]);

it('keeps dynamic GETEX options conservative', function (string $body): void {
    $findings = analyzeRedisBody($body);
    $redis = collect($findings)->firstWhere('rule', 'TG020');

    expect($redis)->not->toBeNull()
        ->and($redis->confidence)->toBe('medium');
})->with([
    'dynamic positional modifier' => ["\$modifier = getenv('REDIS_GETEX_MODIFIER');\nRedis::getex('key', \$modifier, 60);"],
    'dynamic PhpRedis options' => ["\$options = config('cache.getex');\nRedis::getex('key', \$options);"],
]);
