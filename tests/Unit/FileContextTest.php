<?php

declare(strict_types=1);

use Codegenie\TransactionGuard\Analysis\FileContext;

it('resolves imported class aliases case insensitively', function (): void {
    $context = new FileContext('App\\Jobs', [
        'QueuedJob' => 'Vendor\\Package\\QueuedJob',
    ]);

    expect($context->resolve('queuedjob'))->toBe('Vendor\\Package\\QueuedJob')
        ->and($context->resolve('QUEUEDJOB\\Nested'))->toBe('Vendor\\Package\\QueuedJob\\Nested');
});

it('keeps namespace fallback behavior for unknown aliases', function (): void {
    $context = new FileContext('App\\Domain', [
        'Known' => 'Vendor\\Known',
    ]);

    expect($context->resolve('Unknown'))->toBe('App\\Domain\\Unknown');
});
