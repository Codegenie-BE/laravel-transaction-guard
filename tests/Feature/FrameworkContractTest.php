<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\PreparesForDispatch;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Queue\Queue;

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
