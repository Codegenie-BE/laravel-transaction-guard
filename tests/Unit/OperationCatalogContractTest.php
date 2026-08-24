<?php

declare(strict_types=1);

use Codegenie\TransactionGuard\Analysis\OperationCatalog;

it('keeps every finite operation catalog duplicate free', function (): void {
    foreach ([
        'CACHE_MUTATIONS' => OperationCatalog::CACHE_MUTATIONS,
        'CACHE_LOCK_TERMINALS' => OperationCatalog::CACHE_LOCK_TERMINALS,
        'RATE_LIMITER_MUTATIONS' => OperationCatalog::RATE_LIMITER_MUTATIONS,
        'REDIS_CONTROL_METHODS' => OperationCatalog::REDIS_CONTROL_METHODS,
        'REDIS_MUTATIONS' => OperationCatalog::REDIS_MUTATIONS,
        'REDIS_MUTATING_COMMANDS' => OperationCatalog::REDIS_MUTATING_COMMANDS,
        'REDIS_READ_COMMANDS' => OperationCatalog::REDIS_READ_COMMANDS,
        'REDIS_SCRIPT_COMMANDS' => OperationCatalog::REDIS_SCRIPT_COMMANDS,
        'QUERY_MUTATIONS' => OperationCatalog::QUERY_MUTATIONS,
        'ELOQUENT_STATIC_MUTATIONS' => OperationCatalog::ELOQUENT_STATIC_MUTATIONS,
        'ELOQUENT_INSTANCE_MUTATIONS' => OperationCatalog::ELOQUENT_INSTANCE_MUTATIONS,
        'RELATION_MUTATIONS' => OperationCatalog::RELATION_MUTATIONS,
    ] as $name => $operations) {
        expect($operations, $name)->not->toBe([])
            ->and(array_values(array_unique($operations)), $name)->toBe(array_values($operations));
    }
});

it('keeps Redis method and command mutation catalogs equivalent', function (): void {
    $methods = array_map('strtoupper', OperationCatalog::REDIS_MUTATIONS);
    $commands = OperationCatalog::REDIS_MUTATING_COMMANDS;
    sort($methods);
    sort($commands);

    expect($methods)->toBe($commands);
});

it('keeps Redis command classifications disjoint and exhaustive', function (): void {
    expect(array_intersect(OperationCatalog::REDIS_MUTATING_COMMANDS, OperationCatalog::REDIS_READ_COMMANDS))->toBe([])
        ->and(array_intersect(OperationCatalog::REDIS_MUTATING_COMMANDS, OperationCatalog::REDIS_SCRIPT_COMMANDS))->toBe([])
        ->and(array_intersect(OperationCatalog::REDIS_READ_COMMANDS, OperationCatalog::REDIS_SCRIPT_COMMANDS))->toBe([]);

    foreach (OperationCatalog::REDIS_MUTATING_COMMANDS as $command) {
        expect(OperationCatalog::redisCommandKind($command), $command)->toBe('mutation');
    }
    foreach (OperationCatalog::REDIS_READ_COMMANDS as $command) {
        expect(OperationCatalog::redisCommandKind($command), $command)->toBe('read');
    }
    foreach (OperationCatalog::REDIS_SCRIPT_COMMANDS as $command) {
        expect(OperationCatalog::redisCommandKind($command), $command)->toBe('script');
    }
    foreach (OperationCatalog::REDIS_CONTROL_METHODS as $method) {
        expect(OperationCatalog::redisMethodKind($method), $method)->toBe('control');
    }

    expect(OperationCatalog::redisCommandKind('FUTURE_COMMAND'))->toBe('unknown')
        ->and(OperationCatalog::redisMethodKind('futureMethod'))->toBe('unknown');
});
