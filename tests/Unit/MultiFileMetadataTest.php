<?php

declare(strict_types=1);

use Codegenie\TransactionGuard\Analysis\ClassMetadataIndex;

it('keeps inherited queue metadata stable regardless of scan order', function (): void {
    $root = sys_get_temp_dir().'/tg-multifile-'.bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    $base = $root.'/BaseJob.php';
    $child = $root.'/ChildJob.php';

    file_put_contents($base, <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
class BaseJob implements ShouldQueueAfterCommit {}
PHP);
    file_put_contents($child, <<<'PHP'
<?php
namespace App\Jobs;
class ChildJob extends BaseJob {}
PHP);

    try {
        $forward = ClassMetadataIndex::fromFiles([$base, $child])->metadata('App\\Jobs\\ChildJob');
        $reverse = ClassMetadataIndex::fromFiles([$child, $base])->metadata('App\\Jobs\\ChildJob');

        expect($forward)->not->toBeNull()
            ->and($reverse)->not->toBeNull()
            ->and($forward->queued())->toBeTrue()
            ->and($reverse->queued())->toBeTrue()
            ->and($forward->queueAfterCommit())->toBeTrue()
            ->and($reverse->queueAfterCommit())->toBeTrue();
    } finally {
        @unlink($base);
        @unlink($child);
        @rmdir($root);
    }
});

it('resolves cross-file model relation targets and their connections', function (): void {
    $root = sys_get_temp_dir().'/tg-relations-'.bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    $role = $root.'/Role.php';
    $user = $root.'/User.php';

    file_put_contents($role, <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Role extends Model { protected $connection = 'pgsql'; }
PHP);
    file_put_contents($user, <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class User extends Model { public function roles() { return $this->belongsToMany(Role::class); } }
PHP);

    try {
        $index = ClassMetadataIndex::fromFiles([$user, $role]);

        expect($index->modelRelationTarget('App\\Models\\User', 'roles'))->toBe('App\\Models\\Role')
            ->and($index->modelConnection('App\\Models\\Role'))->toBe('pgsql');
    } finally {
        @unlink($role);
        @unlink($user);
        @rmdir($root);
    }
});
