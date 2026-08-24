<?php

declare(strict_types=1);

use Codegenie\TransactionGuard\Analysis\OperationCatalog;
use Codegenie\TransactionGuard\Tests\Support\CatalogScanner;

it('detects every catalogued direct Redis mutation', function (): void {
    $statements = array_map(
        static fn (string $method): string => $method === 'getex'
            ? "Redis::getex('key', 'EX', 60);"
            : "Redis::{$method}('key', 'value');",
        OperationCatalog::REDIS_MUTATIONS,
    );
    $source = "<?php\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Redis;\nDB::transaction(function () {\n".implode("\n", $statements)."\n});\n";
    $joined = implode("\n", array_map(
        static fn ($finding): string => $finding->snippet,
        array_values(array_filter(CatalogScanner::scan($source), static fn ($finding): bool => $finding->rule === 'TG020')),
    ));

    foreach (OperationCatalog::REDIS_MUTATIONS as $method) {
        expect($joined, $method)->toContain("Redis::{$method}(");
    }
});

it('detects every catalogued mutating Redis command', function (): void {
    $statements = array_map(
        static fn (string $command): string => $command === 'GETEX'
            ? "Redis::command('GETEX', ['key', 'EX', 60]);"
            : "Redis::command('{$command}', ['key', 'value']);",
        OperationCatalog::REDIS_MUTATING_COMMANDS,
    );
    $source = "<?php\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Redis;\nDB::transaction(function () {\n".implode("\n", $statements)."\n});\n";
    $joined = implode("\n", array_map(
        static fn ($finding): string => $finding->snippet,
        array_values(array_filter(CatalogScanner::scan($source), static fn ($finding): bool => $finding->rule === 'TG020')),
    ));

    foreach (OperationCatalog::REDIS_MUTATING_COMMANDS as $command) {
        expect($joined, $command)->toContain("'{$command}'");
    }
});

it('keeps every catalogued Redis read command clean', function (): void {
    $statements = array_map(
        static fn (string $command): string => "Redis::command('{$command}', ['key']);",
        OperationCatalog::REDIS_READ_COMMANDS,
    );
    $source = "<?php\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Redis;\nDB::transaction(function () {\n".implode("\n", $statements)."\n});\n";
    $rules = array_map(static fn ($finding): string => $finding->rule, CatalogScanner::scan($source));

    expect($rules)->not->toContain('TG020');
});

it('reports every catalogued Redis script command conservatively', function (): void {
    $statements = array_map(
        static fn (string $command): string => "Redis::command('{$command}', ['return 1']);",
        OperationCatalog::REDIS_SCRIPT_COMMANDS,
    );
    $source = "<?php\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\Redis;\nDB::transaction(function () {\n".implode("\n", $statements)."\n});\n";
    $joined = implode("\n", array_map(
        static fn ($finding): string => $finding->snippet,
        array_values(array_filter(CatalogScanner::scan($source), static fn ($finding): bool => $finding->rule === 'TG020')),
    ));

    foreach (OperationCatalog::REDIS_SCRIPT_COMMANDS as $command) {
        expect($joined, $command)->toContain("'{$command}'");
    }
});

it('does not treat Redis control wrappers as mutations by themselves', function (): void {
    $source = <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
DB::transaction(function () {
    Redis::connection('cache')->get('key');
    Redis::command('GET', ['key']);
    Redis::pipeline(fn ($pipe) => $pipe->get('key'));
    Redis::transaction(fn ($pipe) => $pipe->get('key'));
});
PHP;

    $rules = array_map(static fn ($finding): string => $finding->rule, CatalogScanner::scan($source));

    expect($rules)->not->toContain('TG020');
});
