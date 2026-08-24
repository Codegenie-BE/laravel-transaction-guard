<?php

declare(strict_types=1);

$scenarios = [
    'PreparesForDispatch is visible even with afterCommit' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\PreparesForDispatch;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
class PrepareOrder implements ShouldQueueAfterCommit, PreparesForDispatch { public function prepareForDispatch() {} }
DB::transaction(fn () => PrepareOrder::dispatch());
PHP,
        'rules' => ['TG022'],
        'absent' => ['TG001'],
    ],
    'ShouldBeUnique PendingDispatch exposes precommit cache lock' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
class UniqueOrder implements ShouldQueueAfterCommit, ShouldBeUnique {}
DB::transaction(fn () => UniqueOrder::dispatch());
PHP,
        'rules' => ['TG023'],
        'absent' => ['TG001'],
    ],
    'DebounceFor PendingDispatch exposes precommit cache lock' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\Attributes\DebounceFor;
use Illuminate\Support\Facades\DB;
#[DebounceFor(5)]
class DebouncedOrder implements ShouldQueueAfterCommit {}
DB::transaction(fn () => DebouncedOrder::dispatch());
PHP,
        'rules' => ['TG023'],
        'absent' => ['TG001'],
    ],
    'dispatchIf false is statically skipped' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(fn () => ProcessOrder::dispatchIf(false));
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'dispatchUnless true is statically skipped' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(fn () => ProcessOrder::dispatchUnless(true));
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'cache lock block is a cache mutation' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
DB::transaction(fn () => Cache::lock('x')->block(5, fn () => true));
PHP,
        'rules' => ['TG008'],
    ],
    'rate limiter hit is a cache mutation' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
DB::transaction(fn () => RateLimiter::hit('login'));
PHP,
        'rules' => ['TG008'],
    ],
    'read-only Redis pipeline is not a mutation' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
DB::transaction(fn () => Redis::pipeline(fn ($pipe) => $pipe->get('x')));
PHP,
        'rules' => [],
        'absent' => ['TG020'],
    ],
    'mutating Redis pipeline is detected' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
DB::transaction(fn () => Redis::pipeline(fn ($pipe) => $pipe->set('x', 'y')));
PHP,
        'rules' => ['TG020'],
    ],
    'unknown Redis command is conservative' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
DB::transaction(fn () => Redis::command('FUTUREWRITE', ['x']));
PHP,
        'rules' => ['TG020'],
    ],
    'incrementEach cross connection is detected' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::connection('mysql')->transaction(fn () => DB::connection('pgsql')->table('counters')->incrementEach(['a' => 1]));
PHP,
        'rules' => ['TG021'],
        'config' => ['database_default' => 'mysql'],
    ],
    'local model setConnection participates in TG021' => [
        'code' => <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
class Order extends Model {}
DB::connection('mysql')->transaction(function () { $order = new Order; $order->setConnection('pgsql'); $order->save(); });
PHP,
        'rules' => ['TG021'],
        'config' => ['database_default' => 'mysql'],
    ],
    'relationship target connection participates in TG021' => [
        'code' => <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
class Role extends Model { protected $connection = 'pgsql'; }
class User extends Model { public function roles() { return $this->belongsToMany(Role::class); } }
DB::connection('mysql')->transaction(function () { $user = new User; $user->roles()->attach(1); });
PHP,
        'rules' => ['TG021'],
        'config' => ['database_default' => 'mysql'],
    ],
    'Bus dispatch variable payload is detected' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { $job = new ProcessOrder; Bus::dispatch($job); });
PHP,
        'rules' => ['TG001'],
    ],
    'Event static dispatch uses Dispatchable trait outside Events namespace' => [
        'code' => <<<'PHP'
<?php
namespace App\Domain;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\DB;
class OrderChanged { use Dispatchable; }
DB::transaction(fn () => OrderChanged::dispatch());
PHP,
        'rules' => ['TG002'],
    ],
    'heredoc DDL is detected' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { DB::statement(<<<'SQL'
CREATE TABLE example (id INT)
SQL); });
PHP,
        'rules' => ['TG012'],
    ],
];

return array_merge($scenarios, require __DIR__.'/CoverageContracts.php');
