<?php

declare(strict_types=1);

use Codegenie\TransactionGuard\Analysis\AnalysisConfig;
use Codegenie\TransactionGuard\Analysis\OperationCatalog;
use Codegenie\TransactionGuard\Tests\Support\CatalogScanner;

it('detects every catalogued cross-connection query mutation', function (): void {
    $statements = array_map(
        static fn (string $method): string => "DB::connection('pgsql')->table('audit')->{$method}(['value' => 1]);",
        OperationCatalog::QUERY_MUTATIONS,
    );
    $source = "<?php\nuse Illuminate\\Support\\Facades\\DB;\nDB::connection('mysql')->transaction(function () {\n".implode("\n", $statements)."\n});\n";
    $joined = implode("\n", array_map(
        static fn ($finding): string => $finding->snippet,
        array_values(array_filter(
            CatalogScanner::scan($source, new AnalysisConfig(defaultDatabaseConnection: 'mysql')),
            static fn ($finding): bool => $finding->rule === 'TG021',
        )),
    ));

    foreach (OperationCatalog::QUERY_MUTATIONS as $method) {
        expect($joined, $method)->toContain("->{$method}(");
    }
});

it('detects every catalogued cross-connection Eloquent static mutation', function (): void {
    $statements = array_map(
        static fn (string $method): string => "Audit::{$method}(['value' => 1]);",
        OperationCatalog::ELOQUENT_STATIC_MUTATIONS,
    );
    $source = "<?php\nnamespace App\\Models;\nuse Illuminate\\Database\\Eloquent\\Model;\nuse Illuminate\\Support\\Facades\\DB;\nclass Audit extends Model { protected \$connection = 'pgsql'; }\nDB::connection('mysql')->transaction(function () {\n".implode("\n", $statements)."\n});\n";
    $joined = implode("\n", array_map(
        static fn ($finding): string => $finding->snippet,
        array_values(array_filter(
            CatalogScanner::scan($source, new AnalysisConfig(defaultDatabaseConnection: 'mysql')),
            static fn ($finding): bool => $finding->rule === 'TG021',
        )),
    ));

    foreach (OperationCatalog::ELOQUENT_STATIC_MUTATIONS as $method) {
        expect($joined, $method)->toContain("Audit::{$method}(");
    }
});

it('detects every catalogued cross-connection Eloquent instance mutation', function (): void {
    $statements = array_map(
        static fn (string $method): string => "\$audit->{$method}(['value' => 1]);",
        OperationCatalog::ELOQUENT_INSTANCE_MUTATIONS,
    );
    $source = "<?php\nnamespace App\\Models;\nuse Illuminate\\Database\\Eloquent\\Model;\nuse Illuminate\\Support\\Facades\\DB;\nclass Audit extends Model { protected \$connection = 'pgsql'; }\nDB::connection('mysql')->transaction(function () {\n\$audit = new Audit;\n".implode("\n", $statements)."\n});\n";
    $joined = implode("\n", array_map(
        static fn ($finding): string => $finding->snippet,
        array_values(array_filter(
            CatalogScanner::scan($source, new AnalysisConfig(defaultDatabaseConnection: 'mysql')),
            static fn ($finding): bool => $finding->rule === 'TG021',
        )),
    ));

    foreach (OperationCatalog::ELOQUENT_INSTANCE_MUTATIONS as $method) {
        expect($joined, $method)->toContain("->{$method}(");
    }
});

it('detects every catalogued cross-connection relation mutation', function (): void {
    $statements = array_map(
        static fn (string $method): string => "\$user->roles()->{$method}(['value' => 1]);",
        OperationCatalog::RELATION_MUTATIONS,
    );
    $source = "<?php\nnamespace App\\Models;\nuse Illuminate\\Database\\Eloquent\\Model;\nuse Illuminate\\Support\\Facades\\DB;\nclass Role extends Model { protected \$connection = 'pgsql'; }\nclass User extends Model { protected \$connection = 'mysql'; public function roles() { return \$this->belongsToMany(Role::class); } }\nDB::connection('mysql')->transaction(function () {\n\$user = new User;\n".implode("\n", $statements)."\n});\n";
    $joined = implode("\n", array_map(
        static fn ($finding): string => $finding->snippet,
        array_values(array_filter(
            CatalogScanner::scan($source, new AnalysisConfig(defaultDatabaseConnection: 'mysql')),
            static fn ($finding): bool => $finding->rule === 'TG021',
        )),
    ));

    foreach (OperationCatalog::RELATION_MUTATIONS as $method) {
        expect($joined, $method)->toContain("->{$method}(");
    }
});
