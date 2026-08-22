<?php

declare(strict_types=1);

return [
    'object exec method is not PHP exec' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () use ($service) { $service->exec('safe-domain-call'); });
PHP,
        'rules' => [], 'absent' => ['TG009'],
    ],
    'object touch method is not native filesystem touch' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () use ($model) { $model->touch(); });
PHP,
        'rules' => [], 'absent' => ['TG007'],
    ],
    'object defer method is not Laravel defer helper' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () use ($scheduler) { $scheduler->defer(fn () => null); });
PHP,
        'rules' => [], 'absent' => ['TG018'],
    ],
    'conditional local job assignment is not guessed' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class A implements ShouldQueue {}
class B implements ShouldQueue {}
DB::transaction(function () use ($flag) { if ($flag) { $job = new A(); } else { $job = new B(); } dispatch($job); });
PHP,
        'rules' => ['TG001'],
    ],
    'dynamic notification viaConnections is not accepted as safety proof' => [
        'code' => <<<'PHP'
<?php
namespace App\Notifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
class Alert extends Notification implements ShouldQueue { public function viaConnections(): array { return $this->connections(); } private function connections(): array { return []; } }
DB::transaction(function () use ($user) { $user->notify(new Alert()); });
PHP,
        'rules' => ['TG004'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
    'manual transaction early return is unclosed' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
function run(bool $skip): void { DB::beginTransaction(); if ($skip) { return; } DB::commit(); }
PHP,
        'rules' => ['TG013'],
    ],
    'eloquent saveQuietly on another model connection is reported' => [
        'code' => <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
class Audit extends Model { protected $connection = 'pgsql'; }
DB::connection('mysql')->transaction(function () { $audit = new Audit(); $audit->saveQuietly(); });
PHP,
        'rules' => ['TG021'],
        'config' => ['database_default' => 'mysql'],
    ],
    'eloquent update instance on another model connection is reported' => [
        'code' => <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
class Audit extends Model { protected $connection = 'pgsql'; }
DB::connection('mysql')->transaction(function () { $audit = new Audit(); $audit->update(['ok' => 1]); });
PHP,
        'rules' => ['TG021'],
        'config' => ['database_default' => 'mysql'],
    ],
    'queue connection enum attribute is resolved' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Support\Facades\DB;
enum QueueConnection: string { case Redis = 'redis'; }
#[Connection(QueueConnection::Redis)]
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [], 'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['database' => false, 'redis' => true]],
    ],
];
