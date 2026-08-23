<?php

declare(strict_types=1);

use Codegenie\TransactionGuard\Analysis\ClassMetadataIndex;

it('resolves queue contracts inherited from a lazily indexed Composer parent', function (): void {
    $temporary = tempnam(sys_get_temp_dir(), 'tg-lazy-parent-');
    expect($temporary)->not->toBeFalse();
    $file = $temporary.'.php';
    rename($temporary, $file);

    file_put_contents($file, <<<'PHP'
<?php
namespace App\Jobs;
use Codegenie\TransactionGuard\Tests\Support\AutoloadedAfterCommitJob;
class ChildJob extends AutoloadedAfterCommitJob {}
PHP);

    try {
        $index = ClassMetadataIndex::fromFiles([$file]);
        $metadata = $index->metadata('App\\Jobs\\ChildJob');

        expect($metadata)->not->toBeNull()
            ->and($metadata->queued())->toBeTrue()
            ->and($metadata->queueAfterCommit())->toBeTrue();
    } finally {
        @unlink($file);
    }
});

it('terminates lazy metadata resolution for cyclic parent graphs', function (): void {
    $temporary = tempnam(sys_get_temp_dir(), 'tg-lazy-cycle-');
    expect($temporary)->not->toBeFalse();
    $file = $temporary.'.php';
    rename($temporary, $file);

    file_put_contents($file, <<<'PHP'
<?php
namespace App\Jobs;
class FirstJob extends SecondJob {}
class SecondJob extends FirstJob {}
PHP);

    try {
        $index = ClassMetadataIndex::fromFiles([$file]);
        $metadata = $index->metadata('App\\Jobs\\FirstJob');

        expect($metadata)->not->toBeNull()
            ->and($metadata->parent)->toBe('App\\Jobs\\SecondJob')
            ->and($index->notificationChannelConnections('App\\Jobs\\FirstJob'))->toBe([])
            ->and($index->modelConnection('App\\Jobs\\FirstJob'))->toBeNull()
            ->and($index->modelRelationTarget('App\\Jobs\\FirstJob', 'orders'))->toBeNull();
    } finally {
        @unlink($file);
    }
});
