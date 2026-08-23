<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Contracts\Queue\PreparesForDispatch;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Queue\Queue;
use Illuminate\Queue\QueueManager;

it('tracks Laravel PendingDispatch pre-dispatch ordering', function (): void {
    $file = (new ReflectionClass(PendingDispatch::class))->getFileName();
    expect($file)->toBeString();
    $source = file_get_contents($file);
    expect($source)->toBeString();

    $prepare = strpos($source, 'prepareForDispatch()');
    $unique = strpos($source, 'UniqueLock');
    $dispatch = strpos($source, '->dispatch($this->job)');

    expect($dispatch)->not->toBeFalse();

    if (interface_exists(PreparesForDispatch::class)) {
        expect($prepare)->not->toBeFalse()
            ->and($prepare)->toBeLessThan($dispatch);
    }

    if ($unique !== false) {
        expect($unique)->toBeLessThan($dispatch);
    }
});

it('tracks Laravel queue after-commit enqueue semantics', function (): void {
    $file = (new ReflectionClass(Queue::class))->getFileName();
    expect($file)->toBeString();
    $source = file_get_contents($file);
    expect($source)->toBeString()
        ->and($source)->toContain('shouldDispatchAfterCommit($job)')
        ->and($source)->toContain('addCallback(');
});

it('tracks the framework after-commit marker contracts', function (): void {
    expect(interface_exists(ShouldQueueAfterCommit::class))->toBeTrue()
        ->and(interface_exists(ShouldDispatchAfterCommit::class))->toBeTrue();
});

it('tracks Laravel 13 queue route and optional forward APIs', function (): void {
    $hasRoute = method_exists(QueueManager::class, 'route');
    $hasForward = method_exists(QueueManager::class, 'forward');

    if (! $hasRoute && ! $hasForward) {
        expect($hasForward)->toBeFalse();

        return;
    }

    $file = (new ReflectionClass(QueueManager::class))->getFileName();
    expect($file)->toBeString();
    $source = file_get_contents($file);
    expect($source)->toBeString();

    if ($hasRoute) {
        expect($source)->toContain('function route(');
    }

    if ($hasForward) {
        expect($source)->toContain('function forward(');
    }
});

it('tracks Laravel queue metadata attributes when available', function (): void {
    $attributes = [
        'Illuminate\\Queue\\Attributes\\Connection',
        'Illuminate\\Queue\\Attributes\\Queue',
        'Illuminate\\Queue\\Attributes\\DebounceFor',
    ];

    foreach ($attributes as $attributeClass) {
        if (! class_exists($attributeClass)) {
            continue;
        }

        $reflection = new ReflectionClass($attributeClass);
        expect($reflection->getAttributes(Attribute::class))->not->toBeEmpty();
    }
});
