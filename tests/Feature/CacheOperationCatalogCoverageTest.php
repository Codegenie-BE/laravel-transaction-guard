<?php

declare(strict_types=1);

use Codegenie\TransactionGuard\Analysis\OperationCatalog;
use Codegenie\TransactionGuard\Tests\Support\CatalogScanner;

it('detects every catalogued cache mutation', function (): void {
    $statements = array_map(
        static fn (string $method): string => "Cache::{$method}('key', 'value');",
        OperationCatalog::CACHE_MUTATIONS,
    );
    $source = "<?php\nuse Illuminate\\Support\\Facades\\Cache;\nuse Illuminate\\Support\\Facades\\DB;\nDB::transaction(function () {\n".implode("\n", $statements)."\n});\n";
    $snippets = array_map(
        static fn ($finding): string => $finding->snippet,
        array_values(array_filter(CatalogScanner::scan($source), static fn ($finding): bool => $finding->rule === 'TG008')),
    );
    $joined = implode("\n", $snippets);

    foreach (OperationCatalog::CACHE_MUTATIONS as $method) {
        expect($joined, $method)->toContain("Cache::{$method}(");
    }
});

it('detects every catalogued cache lock terminal', function (): void {
    $statements = array_map(
        static fn (string $method): string => "Cache::lock('key')->{$method}('value');",
        OperationCatalog::CACHE_LOCK_TERMINALS,
    );
    $source = "<?php\nuse Illuminate\\Support\\Facades\\Cache;\nuse Illuminate\\Support\\Facades\\DB;\nDB::transaction(function () {\n".implode("\n", $statements)."\n});\n";
    $joined = implode("\n", array_map(
        static fn ($finding): string => $finding->snippet,
        array_values(array_filter(CatalogScanner::scan($source), static fn ($finding): bool => $finding->rule === 'TG008')),
    ));

    foreach (OperationCatalog::CACHE_LOCK_TERMINALS as $method) {
        expect($joined, $method)->toContain("->{$method}(");
    }
});

it('detects every catalogued rate limiter mutation', function (): void {
    $statements = array_map(
        static fn (string $method): string => "RateLimiter::{$method}('key', 'value');",
        OperationCatalog::RATE_LIMITER_MUTATIONS,
    );
    $source = "<?php\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Facades\\RateLimiter;\nDB::transaction(function () {\n".implode("\n", $statements)."\n});\n";
    $joined = implode("\n", array_map(
        static fn ($finding): string => $finding->snippet,
        array_values(array_filter(CatalogScanner::scan($source), static fn ($finding): bool => $finding->rule === 'TG008')),
    ));

    foreach (OperationCatalog::RATE_LIMITER_MUTATIONS as $method) {
        expect($joined, $method)->toContain("RateLimiter::{$method}(");
    }
});

it('keeps representative cache reads clean', function (): void {
    $source = <<<'PHP'
<?php
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
DB::transaction(function () {
    Cache::get('key');
    Cache::has('key');
    Cache::many(['a', 'b']);
});
PHP;

    $rules = array_map(static fn ($finding): string => $finding->rule, CatalogScanner::scan($source));

    expect($rules)->not->toContain('TG008');
});
