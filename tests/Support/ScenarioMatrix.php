<?php

declare(strict_types=1);

/**
 * Broad regression matrix for Laravel transaction side effects.
 *
 * Each case contains syntactically valid PHP that is parsed but never executed.
 * `rules` lists rules that MUST be present; `absent` lists rules that MUST NOT be present.
 * Optional config keys: queue_default, queue_after_commit, detect_read_http_calls,
 * custom_side_effect_patterns, disabled_rules.
 *
 * @return array<string, array{code:string,rules:list<string>,absent?:list<string>,config?:array<string,mixed>}>
 */
return [
    'job outside transaction is ignored' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
class ProcessOrder {}
ProcessOrder::dispatch();
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'plain database writes inside transaction are safe' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { DB::table('orders')->update(['status' => 'paid']); });
PHP,
        'rules' => [],
    ],
    'queued job without after commit is flagged' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => ['TG001'],
    ],
    'job chained afterCommit is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::dispatch()->afterCommit(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'ShouldQueueAfterCommit job is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueueAfterCommit {}
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'job constructor afterCommit is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue { public function __construct() { $this->afterCommit(); } }
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'queue connection after_commit makes job safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
    'onConnection unsafe override is flagged even if default is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::dispatch()->onConnection('database'); });
PHP,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true, 'database' => false]],
    ],
    'onConnection safe override is accepted' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::dispatch()->onConnection('redis'); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['redis' => true, 'database' => false]],
    ],
    'beforeCommit explicitly unsafe job is flagged' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::dispatch()->beforeCommit(); });
PHP,
        'rules' => ['TG010'],
    ],
    'beforeCommit overrides safe queue config' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::dispatch()->beforeCommit(); });
PHP,
        'rules' => ['TG010'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
    'dispatchSync is reported' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Support\Facades\DB;
class ProcessOrder {}
DB::transaction(function () { ProcessOrder::dispatchSync(); });
PHP,
        'rules' => ['TG016'],
    ],
    'dispatchAfterResponse is not treated as commit safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Support\Facades\DB;
class ProcessOrder {}
DB::transaction(function () { ProcessOrder::dispatchAfterResponse(); });
PHP,
        'rules' => ['TG017'],
    ],
    'Bus dispatch inside transaction is flagged' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { Bus::dispatch(new \App\Jobs\ProcessOrder()); });
PHP,
        'rules' => ['TG001'],
    ],
    'Bus dispatch afterCommit is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue { public function afterCommit(): static { return $this; } }
DB::transaction(function () { Bus::dispatch((new ProcessOrder())->afterCommit()); });
PHP,
        'rules' => [],
        'absent' => ['TG001', 'TG016'],
    ],
    'Bus dispatch ShouldQueueAfterCommit command is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueueAfterCommit {}
DB::transaction(function () { Bus::dispatch(new ProcessOrder()); });
PHP,
        'rules' => [],
        'absent' => ['TG001', 'TG016'],
    ],
    'Bus dispatch known queued command is flagged before commit' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { Bus::dispatch(new ProcessOrder()); });
PHP,
        'rules' => ['TG001'],
        'absent' => ['TG016'],
    ],
    'Bus dispatch known non queueable command is synchronous' => [
        'code' => <<<'PHP'
<?php
namespace App\Commands;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
class RecalculateOrder {}
DB::transaction(function () { Bus::dispatch(new RecalculateOrder()); });
PHP,
        'rules' => ['TG016'],
        'absent' => ['TG001'],
    ],
    'Bus bulk queued job without after commit is flagged' => [
        'code' => <<<'CODE'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
class BulkJob implements ShouldQueue {}
DB::transaction(function () { Bus::bulk([new BulkJob()]); });
CODE,
        'rules' => ['TG001'],
        'absent' => ['TG016'],
    ],
    'Bus bulk ShouldQueueAfterCommit job is safe' => [
        'code' => <<<'CODE'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
class BulkJob implements ShouldQueueAfterCommit {}
DB::transaction(function () { Bus::bulk([new BulkJob()]); });
CODE,
        'rules' => [],
        'absent' => ['TG001', 'TG016'],
    ],
    'Bus bulk non queueable command is synchronous' => [
        'code' => <<<'CODE'
<?php
namespace App\Commands;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
class BulkCommand {}
DB::transaction(function () { Bus::bulk([new BulkCommand()]); });
CODE,
        'rules' => ['TG016'],
        'absent' => ['TG001'],
    ],
    'Bus bulk mixed commands reports synchronous and queued risk' => [
        'code' => <<<'CODE'
<?php
namespace App\Bulk;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
class QueuedWork implements ShouldQueue {}
class ImmediateWork {}
DB::transaction(function () { Bus::bulk([new QueuedWork(), new ImmediateWork()]); });
CODE,
        'rules' => ['TG001', 'TG016'],
    ],    'Bus dispatchSync is reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { Bus::dispatchSync(new \App\Jobs\ProcessOrder()); });
PHP,
        'rules' => ['TG016'],
    ],
    'Bus chain is conservatively reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { Bus::chain([new \App\Jobs\A(), new \App\Jobs\B()])->dispatch(); });
PHP,
        'rules' => ['TG001'],
    ],
    'Queue push is flagged on unsafe connection' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
DB::transaction(function () { Queue::push(new \App\Jobs\ProcessOrder()); });
PHP,
        'rules' => ['TG001'],
    ],
    'Event helper is flagged when event is not after commit' => [
        'code' => <<<'PHP'
<?php
namespace App\Events;
use Illuminate\Support\Facades\DB;
class OrderCreated {}
DB::transaction(function () { event(new OrderCreated()); });
PHP,
        'rules' => ['TG002'],
    ],
    'event ShouldDispatchAfterCommit is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Events;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
class OrderCreated implements ShouldDispatchAfterCommit {}
DB::transaction(function () { event(new OrderCreated()); });
PHP,
        'rules' => [],
        'absent' => ['TG002'],
    ],
    'event class static dispatch is flagged' => [
        'code' => <<<'PHP'
<?php
namespace App\Events;
use Illuminate\Support\Facades\DB;
class OrderCreated {}
DB::transaction(function () { OrderCreated::dispatch(); });
PHP,
        'rules' => ['TG002'],
    ],
    'event class static dispatch after commit contract is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Events;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
class OrderCreated implements ShouldDispatchAfterCommit {}
DB::transaction(function () { OrderCreated::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG002'],
    ],
    'synchronous mail send is flagged' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
DB::transaction(function () { Mail::to('x@example.com')->send(new \App\Mail\Receipt()); });
PHP,
        'rules' => ['TG003'],
    ],
    'queued mailable afterCommit is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Mail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
class Receipt implements ShouldQueue {}
DB::transaction(function () { Mail::to('x@example.com')->queue((new Receipt())->afterCommit()); });
PHP,
        'rules' => [],
        'absent' => ['TG003'],
    ],
    'queued mailable constructor afterCommit is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Mail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
class Receipt implements ShouldQueue { public function __construct() { $this->afterCommit(); } }
DB::transaction(function () { Mail::to('x@example.com')->send(new Receipt()); });
PHP,
        'rules' => [],
        'absent' => ['TG003'],
    ],
    'Mail raw is irreversible and flagged' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
DB::transaction(function () { Mail::raw('hello', fn ($m) => null); });
PHP,
        'rules' => ['TG003'],
    ],
    'sync notification is flagged' => [
        'code' => <<<'PHP'
<?php
namespace App\Notifications;
use Illuminate\Support\Facades\DB;
class ReceiptReady {}
DB::transaction(function () { $user->notify(new ReceiptReady()); });
PHP,
        'rules' => ['TG004'],
    ],
    'queued notification afterCommit is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Notifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ReceiptReady implements ShouldQueue {}
DB::transaction(function () { $user->notify((new ReceiptReady())->afterCommit()); });
PHP,
        'rules' => [],
        'absent' => ['TG004'],
    ],
    'queued notification constructor afterCommit is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Notifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ReceiptReady implements ShouldQueue { public function __construct() { $this->afterCommit(); } }
DB::transaction(function () { $user->notify(new ReceiptReady()); });
PHP,
        'rules' => [],
        'absent' => ['TG004'],
    ],
    'notifyNow is flagged' => [
        'code' => <<<'PHP'
<?php
namespace App\Notifications;
use Illuminate\Support\Facades\DB;
class ReceiptReady {}
DB::transaction(function () { $user->notifyNow(new ReceiptReady()); });
PHP,
        'rules' => ['TG004'],
    ],
    'Notification facade sendNow is flagged' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
DB::transaction(function () { Notification::sendNow($users, new \App\Notifications\ReceiptReady()); });
PHP,
        'rules' => ['TG004'],
    ],
    'broadcast without safe contract is flagged' => [
        'code' => <<<'PHP'
<?php
namespace App\Events;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\DB;
class OrderUpdated implements ShouldBroadcast {}
DB::transaction(function () { broadcast(new OrderUpdated()); });
PHP,
        'rules' => ['TG005'],
    ],
    'ShouldDispatchAfterCommit alone does not defer direct broadcast helper' => [
        'code' => <<<'PHP'
<?php
namespace App\Events;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
class OrderUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit {}
DB::transaction(function () { broadcast(new OrderUpdated()); });
PHP,
        'rules' => ['TG005'],
    ],
    'ShouldBroadcastNow stays unsafe despite queue after_commit' => [
        'code' => <<<'PHP'
<?php
namespace App\Events;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\DB;
class OrderUpdated implements ShouldBroadcastNow {}
DB::transaction(function () { broadcast(new OrderUpdated()); });
PHP,
        'rules' => ['TG005'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
    'HTTP post is flagged' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { Http::post('https://example.test/orders', ['id' => 1]); });
PHP,
        'rules' => ['TG006'],
    ],
    'HTTP delete is flagged through fluent client' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { Http::withToken('x')->delete('https://example.test/orders/1'); });
PHP,
        'rules' => ['TG006'],
    ],
    'generic client mutating request is flagged' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () use ($client) { $client->request('POST', '/capture'); });
PHP,
        'rules' => ['TG006'],
    ],
    'HTTP GET ignored by default' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { Http::get('https://example.test/status'); });
PHP,
        'rules' => [],
        'absent' => ['TG006'],
    ],
    'HTTP GET flagged in strict IO mode' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { Http::get('https://example.test/status'); });
PHP,
        'rules' => ['TG006'],
        'config' => ['detect_read_http_calls' => true],
    ],
    'Storage put is flagged' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
DB::transaction(function () { Storage::put('receipt.txt', 'paid'); });
PHP,
        'rules' => ['TG007'],
    ],
    'File delete is flagged' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
DB::transaction(function () { File::delete('/tmp/a'); });
PHP,
        'rules' => ['TG007'],
    ],
    'native file_put_contents is flagged' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { file_put_contents('/tmp/a', 'x'); });
PHP,
        'rules' => ['TG007'],
    ],
    'Cache put is flagged' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { Cache::put('order:1', 'paid'); });
PHP,
        'rules' => ['TG008'],
    ],
    'Cache forget is flagged' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { Cache::forget('order:1'); });
PHP,
        'rules' => ['TG008'],
    ],
    'Process run is flagged' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
DB::transaction(function () { Process::run('sync-orders'); });
PHP,
        'rules' => ['TG009'],
    ],
    'native exec is flagged' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { exec('sync-orders'); });
PHP,
        'rules' => ['TG009'],
    ],
    'retryable transaction adds duplicate side effect risk' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { Http::post('https://example.test/capture'); }, attempts: 5);
PHP,
        'rules' => ['TG006', 'TG011'],
    ],
    'retryable transaction positional attempts detected' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
DB::transaction(function () { Mail::raw('paid', fn ($m) => null); }, 3);
PHP,
        'rules' => ['TG003', 'TG011'],
    ],
    'afterCommit callback shields irreversible effects' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { DB::afterCommit(function () { Http::post('https://example.test/capture'); }); });
PHP,
        'rules' => [],
        'absent' => ['TG006', 'TG011'],
    ],
    'afterCommit callback in retryable transaction is safe' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { DB::afterCommit(function () { Http::post('https://example.test/capture'); }); }, attempts: 5);
PHP,
        'rules' => [],
        'absent' => ['TG006', 'TG011'],
    ],
    'conditional manual commit does not prove the transaction closed' => [
        'code' => <<<'CODE'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::beginTransaction();
if ($shouldCommit) {
    DB::commit();
}
Http::post('https://example.test/capture');
CODE,
        'rules' => ['TG006', 'TG013'],
    ],
    'unbraced conditional manual commit does not prove the transaction closed' => [
        'code' => <<<'CODE'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::beginTransaction();
if ($shouldCommit) DB::commit();
Http::post('https://example.test/capture');
CODE,
        'rules' => ['TG006', 'TG013'],
    ],
    'manual transaction opened and closed in the same branch is bounded there' => [
        'code' => <<<'CODE'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
if ($enabled) {
    DB::beginTransaction();
    DB::commit();
}
Http::post('https://example.test/outside');
CODE,
        'rules' => [],
        'absent' => ['TG006', 'TG013'],
    ],
    'side effect inside branch-contained manual transaction is detected' => [
        'code' => <<<'CODE'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
if ($enabled) {
    DB::beginTransaction();
    Http::post('https://example.test/capture');
    DB::commit();
}
CODE,
        'rules' => ['TG006'],
        'absent' => ['TG013'],
    ],    'manual transaction side effect before commit is flagged' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::beginTransaction();
Http::post('https://example.test/capture');
DB::commit();
PHP,
        'rules' => ['TG006'],
    ],
    'manual transaction side effect after commit is safe' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::beginTransaction();
DB::table('orders')->update(['paid' => true]);
DB::commit();
Http::post('https://example.test/capture');
PHP,
        'rules' => [],
        'absent' => ['TG006'],
    ],
    'manual transaction side effect after rollback is safe' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::beginTransaction();
DB::rollBack();
Http::post('https://example.test/capture');
PHP,
        'rules' => [],
        'absent' => ['TG006'],
    ],
    'unclosed manual transaction is critical' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::beginTransaction();
DB::table('orders')->update(['paid' => true]);
PHP,
        'rules' => ['TG013'],
    ],
    'connection transaction is detected' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::connection('mysql')->transaction(function () { Http::post('https://example.test/capture'); });
PHP,
        'rules' => ['TG006'],
    ],
    'aliased DB facade is detected' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB as Database;
use Illuminate\Support\Facades\Http;
Database::transaction(function () { Http::post('https://example.test/capture'); });
PHP,
        'rules' => ['TG006'],
    ],
    'arrow transaction side effect is detected' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(fn () => Http::post('https://example.test/capture'));
PHP,
        'rules' => ['TG006'],
    ],
    'side effect in conditional transaction body is detected' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () use ($enabled) { if ($enabled) { Http::post('https://example.test/capture'); } });
PHP,
        'rules' => ['TG006'],
    ],
    'deferred nested closure is not assumed to execute' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { $later = function () { Http::post('https://example.test/capture'); }; });
PHP,
        'rules' => [],
        'absent' => ['TG006'],
    ],
    'tap callback executes eagerly inside transaction' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { tap('value', function () { Http::post('https://example.test/capture'); }); });
PHP,
        'rules' => ['TG006'],
    ],
    'retry callback executes eagerly inside transaction' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { retry(2, function () { Http::post('https://example.test/capture'); }); });
PHP,
        'rules' => ['TG006'],
    ],
    'array map callback executes eagerly inside transaction' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { array_map(function ($id) { Http::post('https://example.test/capture'); }, [1]); });
PHP,
        'rules' => ['TG006'],
    ],
    'assigned nested closure remains deferred' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { $later = function () { Http::post('https://example.test/capture'); }; });
PHP,
        'rules' => [],
        'absent' => ['TG006'],
    ],
    'IIFE side effect is detected' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { (function () { Http::post('https://example.test/capture'); })(); });
PHP,
        'rules' => ['TG006'],
    ],
    'Schema create inside application transaction is critical' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
DB::transaction(function () { Schema::create('temp_items', fn ($table) => null); });
PHP,
        'rules' => ['TG012'],
    ],
    'DB unprepared DDL inside transaction is critical' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { DB::unprepared('CREATE TABLE temp_items (id INT)'); });
PHP,
        'rules' => ['TG012'],
    ],
    'DB statement ALTER inside transaction is critical' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { DB::statement('ALTER TABLE orders ADD foo INT'); });
PHP,
        'rules' => ['TG012'],
    ],
    'ordinary DML statement is not implicit commit' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { DB::statement('UPDATE orders SET paid = 1'); });
PHP,
        'rules' => [],
        'absent' => ['TG012'],
    ],
    'custom side effect pattern is flagged' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { StripeGateway::capture($payment); });
PHP,
        'rules' => ['TG100'],
        'config' => ['custom_side_effect_patterns' => ['/StripeGateway::capture\s*\(/']],
    ],
    'disabled rule is omitted' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { Http::post('https://example.test/capture'); });
PHP,
        'rules' => [],
        'absent' => ['TG006'],
        'config' => ['disabled_rules' => ['TG006']],
    ],
    'suppression text inside same-line string does not suppress finding' => [
        'code' => <<<'CODE'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { $text = 'transaction-guard-ignore TG006'; Http::post('https://example.test/capture'); });
CODE,
        'rules' => ['TG006'],
    ],
    'next-line suppression text inside string does not suppress finding' => [
        'code' => <<<'CODE'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () {
    $text = 'transaction-guard-ignore-next-line TG006';
    Http::post('https://example.test/capture');
});
CODE,
        'rules' => ['TG006'],
    ],    'inline ignore current line suppresses finding' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { Http::post('https://example.test/capture'); // transaction-guard-ignore TG006
});
PHP,
        'rules' => [],
        'absent' => ['TG006'],
    ],
    'inline ignore next line suppresses finding' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () {
    // transaction-guard-ignore-next-line TG006
    Http::post('https://example.test/capture');
});
PHP,
        'rules' => [],
        'absent' => ['TG006'],
    ],
    'different inline rule does not suppress finding' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () {
    // transaction-guard-ignore-next-line TG003
    Http::post('https://example.test/capture');
});
PHP,
        'rules' => ['TG006'],
    ],
    'nested transaction still detects side effect' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { DB::transaction(function () { Http::post('https://example.test/capture'); }); });
PHP,
        'rules' => ['TG006'],
    ],
    'side effect after transaction closure is ignored' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { DB::table('orders')->update(['paid' => 1]); });
Http::post('https://example.test/capture');
PHP,
        'rules' => [],
        'absent' => ['TG006'],
    ],

    'dispatch_sync helper is reported' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Support\Facades\DB;
class ProcessOrder {}
DB::transaction(function () { dispatch_sync(new ProcessOrder()); });
PHP,
        'rules' => ['TG016'],
    ],
    'global dispatch helper unsafe queued job is flagged' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { dispatch(new ProcessOrder()); });
PHP,
        'rules' => ['TG001'],
    ],
    'Concurrency run is reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { Concurrency::run([fn () => 1]); });
PHP,
        'rules' => ['TG018'],
    ],
    'Concurrency defer is not a commit boundary' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { Concurrency::defer([fn () => 1]); });
PHP,
        'rules' => ['TG018'],
    ],
    'global defer is not a commit boundary' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { defer(fn () => report_metrics()); });
PHP,
        'rules' => ['TG018'],
    ],
    'Concurrency inside afterCommit callback is safe' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { DB::afterCommit(function () { Concurrency::run([fn () => 1]); }); });
PHP,
        'rules' => [],
        'absent' => ['TG018'],
    ],

    'nested transaction inherits outer retry risk' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () {
    DB::transaction(function () { Http::post('https://example.test/capture'); });
}, attempts: 5);
PHP,
        'rules' => ['TG006', 'TG011'],
    ],
    'dynamic retry attempts are conservatively reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { Http::post('https://example.test/capture'); }, config('database.transaction_attempts'));
PHP,
        'rules' => ['TG006', 'TG011'],
    ],
    'single transaction attempt does not create duplicate risk' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { Http::post('https://example.test/capture'); }, attempts: 1);
PHP,
        'rules' => ['TG006'],
        'absent' => ['TG011'],
    ],
    'manual try catch rollback branch remains inside transaction' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::beginTransaction();
try {
    DB::table('orders')->update(['paid' => 1]);
    DB::commit();
} catch (\Throwable $e) {
    Http::post('https://example.test/failure');
    DB::rollBack();
}
PHP,
        'rules' => ['TG006'],
    ],
    'manual catch rollback before final commit remains inside transaction' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::beginTransaction();
try {
    DB::table('orders')->update(['paid' => 1]);
} catch (\Throwable $e) {
    Http::post('https://example.test/failure');
    DB::rollBack();
}
DB::commit();
PHP,
        'rules' => ['TG006'],
    ],

    'global dispatch helper with known non queueable class is synchronous' => [
        'code' => <<<'CODE'
<?php
namespace App\Actions;
use Illuminate\Support\Facades\DB;
class RecalculateOrder {}
DB::transaction(function () { dispatch(new RecalculateOrder()); });
CODE,
        'rules' => ['TG016'],
        'absent' => ['TG001'],
    ],
    'static dispatch on known non queueable Jobs class is synchronous' => [
        'code' => <<<'CODE'
<?php
namespace App\Jobs;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
class ImmediateJob { use Dispatchable; }
DB::transaction(function () { ImmediateJob::dispatch(); });
CODE,
        'rules' => ['TG016'],
        'absent' => ['TG001'],
    ],    'afterCommit text inside string does not make dispatch safe' => [
        'code' => <<<'CODE'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class StringAfterCommitJob implements ShouldQueue {}
DB::transaction(function () { StringAfterCommitJob::dispatch('->afterCommit('); });
CODE,
        'rules' => ['TG001'],
    ],
    'beforeCommit text inside string is not an explicit override' => [
        'code' => <<<'CODE'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class StringBeforeCommitJob implements ShouldQueue {}
DB::transaction(function () { StringBeforeCommitJob::dispatch('->beforeCommit('); });
CODE,
        'rules' => ['TG001'],
        'absent' => ['TG010'],
    ],
    'HTTP mutating method text inside string is ignored' => [
        'code' => <<<'CODE'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { Http::withBody('post(')->get('https://example.test'); });
CODE,
        'rules' => [],
        'absent' => ['TG006'],
    ],
    'onConnection text inside string does not override queue connection' => [
        'code' => <<<'CODE'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class StringConnectionJob implements ShouldQueue {}
DB::transaction(function () { StringConnectionJob::dispatch("->onConnection('redis')"); });
CODE,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['database' => false, 'redis' => true]],
    ],    'commented side effect inside transaction is ignored' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () {
    // Http::post('https://example.test/should-not-match');
});
PHP,
        'rules' => [],
        'absent' => ['TG006'],
    ],
    'side effect text inside a string is ignored' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () {
    $example = "Http::post('https://example.test/should-not-match')";
});
PHP,
        'rules' => [],
        'absent' => ['TG006'],
    ],
    'inline suppression does not leak to next line' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () {
    Http::post('https://example.test/first'); // transaction-guard-ignore TG006
    Http::post('https://example.test/second');
});
PHP,
        'rules' => ['TG006'],
    ],
    'job constructor queue connection unsafe override is respected' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue { public function __construct() { $this->onConnection('database'); } }
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true, 'database' => false]],
    ],
    'job constructor queue connection safe override is respected' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue { public function __construct() { $this->onConnection('redis'); } }
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['redis' => true, 'database' => false]],
    ],
    'inherited ShouldQueueAfterCommit contract is respected' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
class BaseJob implements ShouldQueueAfterCommit {}
class ProcessOrder extends BaseJob {}
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'manual transaction state does not leak across function scopes' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
function first(): void {
    DB::beginTransaction();
    DB::table('orders')->update(['paid' => 1]);
    DB::commit();
}
function second(): void {
    Http::post('https://example.test/outside');
    DB::rollBack();
}
PHP,
        'rules' => [],
        'absent' => ['TG006'],
    ],

    'Redis mutation inside transaction is reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
DB::transaction(function () { Redis::set('order:1', 'paid'); });
PHP,
        'rules' => ['TG020'],
    ],
    'Redis publish inside transaction is reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
DB::transaction(function () { Redis::publish('orders', 'paid'); });
PHP,
        'rules' => ['TG020'],
    ],
    'Redis command mutation inside transaction is reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
DB::transaction(function () { Redis::command('HSET', ['order:1', 'status', 'paid']); });
PHP,
        'rules' => ['TG020'],
    ],
    'Redis read inside transaction is ignored' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
DB::transaction(function () { Redis::get('order:1'); });
PHP,
        'rules' => [],
        'absent' => ['TG020'],
    ],
    'Redis mutation after commit callback is safe' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
DB::transaction(function () { DB::afterCommit(fn () => Redis::set('order:1', 'paid')); });
PHP,
        'rules' => [],
        'absent' => ['TG020'],
    ],
    'retryable Redis mutation reports duplicate risk' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
DB::transaction(function () { Redis::incr('orders:processed'); }, attempts: 3);
PHP,
        'rules' => ['TG020', 'TG011'],
    ],
    'job afterResponse chain is classified as lifecycle risk' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::dispatch()->afterResponse(); });
PHP,
        'rules' => ['TG017'],
        'absent' => ['TG001'],
    ],
    'Laravel 13 Connection attribute unsafe connection overrides safe default' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Support\Facades\DB;
#[Connection('database')]
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true, 'database' => false]],
    ],
    'Laravel 13 Connection attribute safe connection overrides unsafe default' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Support\Facades\DB;
#[Connection('redis')]
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['redis' => true, 'database' => false]],
    ],
    'Laravel 13 Connection attribute wins over onConnection property' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Support\Facades\DB;
#[Connection('redis')]
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::dispatch()->onConnection('database'); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['redis' => true, 'database' => false]],
    ],
    'Laravel 13 dynamic Connection attribute is not trusted as safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Support\Facades\DB;
#[Connection(QueueConnections::Primary)]
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
    'Laravel 13 Queue route safe connection is respected' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
class ProcessOrder implements ShouldQueue {}
Queue::route(ProcessOrder::class, connection: 'redis', queue: 'orders');
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['redis' => true, 'database' => false]],
    ],
    'Laravel 13 Queue route unsafe connection overrides safe default' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
class ProcessOrder implements ShouldQueue {}
Queue::route(ProcessOrder::class, connection: 'database', queue: 'orders');
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true, 'database' => false]],
    ],
    'Laravel 13 Queue route on parent class is respected' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
abstract class BaseJob implements ShouldQueue {}
class ProcessOrder extends BaseJob {}
Queue::route(BaseJob::class, connection: 'redis');
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['redis' => true, 'database' => false]],
    ],
    'Laravel 13 Queue route on interface is respected' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
interface HighPriority {}
class ProcessOrder implements ShouldQueue, HighPriority {}
Queue::route(HighPriority::class, connection: 'redis');
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['redis' => true, 'database' => false]],
    ],
    'explicit job connection overrides Laravel 13 Queue route' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
class ProcessOrder implements ShouldQueue {}
Queue::route(ProcessOrder::class, connection: 'redis');
DB::transaction(function () { ProcessOrder::dispatch()->onConnection('database'); });
PHP,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['redis' => true, 'database' => false]],
    ],
    'dynamic job connection is not assumed safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::dispatch()->onConnection(config('queue.jobs')); });
PHP,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],

    'Laravel 13 Queue route array connection follows runtime connection-first semantics' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
class ProcessOrder implements ShouldQueue {}
Queue::route([ProcessOrder::class => ['redis', 'orders']]);
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['redis' => true, 'database' => false]],
    ],
    'Laravel 13 Queue route array queue-only entry keeps default connection' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
class ProcessOrder implements ShouldQueue {}
Queue::route([ProcessOrder::class => 'orders']);
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['redis' => true, 'database' => false]],
    ],
    'exact Laravel 13 Queue route wins over interface route' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
interface HighPriority {}
class ProcessOrder implements ShouldQueue, HighPriority {}
Queue::route(HighPriority::class, connection: 'database');
Queue::route(ProcessOrder::class, connection: 'redis');
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['redis' => true, 'database' => false]],
    ],

    'Event defer inside transaction is not a commit boundary' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
DB::transaction(function () {
    Event::defer(function () { event(new \App\Events\OrderUpdated()); });
});
PHP,
        'rules' => ['TG002'],
    ],
    'Event defer inside retryable transaction also has duplicate risk' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
DB::transaction(function () {
    Event::defer(function () { \App\Models\Order::query()->first()?->touch(); });
}, attempts: 3);
PHP,
        'rules' => ['TG002', 'TG011'],
    ],

    'global dispatch helper with unresolved metadata is conservatively reported' => [
        'code' => <<<'PHP'
<?php
namespace App\Actions;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { dispatch(new \Vendor\Package\RecalculateOrder()); });
PHP,
        'rules' => ['TG001'],
    ],
    'global dispatch helper with unresolved metadata is safe after commit' => [
        'code' => <<<'PHP'
<?php
namespace App\Actions;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { dispatch(new \Vendor\Package\RecalculateOrder())->afterCommit(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'fully qualified DB and Http facades are detected' => [
        'code' => <<<'PHP'
<?php
\Illuminate\Support\Facades\DB::transaction(function () {
    \Illuminate\Support\Facades\Http::post('https://example.test/capture');
});
PHP,
        'rules' => ['TG006'],
    ],
    'queued closure inside transaction is flagged' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { dispatch(function () { metrics_flush(); }); });
PHP,
        'rules' => ['TG001'],
    ],
    'queued closure chained afterCommit is safe' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { dispatch(function () { Http::post('https://example.test/capture'); })->afterCommit(); });
PHP,
        'rules' => [],
        'absent' => ['TG001', 'TG006'],
    ],
    'queued closure uses safe global queue after_commit' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { dispatch(fn () => metrics_flush()); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
    'queued closure afterResponse is lifecycle risk' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { dispatch(fn () => metrics_flush())->afterResponse(); });
PHP,
        'rules' => ['TG017'],
        'absent' => ['TG001'],
    ],
    'dispatch_sync closure is synchronous risk' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { dispatch_sync(fn () => metrics_flush()); });
PHP,
        'rules' => ['TG016'],
    ],
    'job withChain dispatch is flagged' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::withChain([new ProcessOrder()])->dispatch(); });
PHP,
        'rules' => ['TG001'],
    ],
    'job withChain dispatch afterCommit is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::withChain([new ProcessOrder()])->dispatch()->afterCommit(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'Queue pushRaw is unsafe even on after_commit connection' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
DB::transaction(function () { Queue::connection('redis')->pushRaw('{"id":"1"}'); });
PHP,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
    'Queue laterRaw is unsafe even on after_commit connection' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
DB::transaction(function () { Queue::connection('redis')->laterRaw(10, '{"id":"1"}'); });
PHP,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
    'event static dispatchIf is flagged' => [
        'code' => <<<'PHP'
<?php
namespace App\Events;
use Illuminate\Support\Facades\DB;
class OrderUpdated {}
DB::transaction(function () { OrderUpdated::dispatchIf(true); });
PHP,
        'rules' => ['TG002'],
    ],
    'event static dispatchUnless honors ShouldDispatchAfterCommit' => [
        'code' => <<<'PHP'
<?php
namespace App\Events;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
class OrderUpdated implements ShouldDispatchAfterCommit {}
DB::transaction(function () { OrderUpdated::dispatchUnless(false); });
PHP,
        'rules' => [],
        'absent' => ['TG002'],
    ],
    'named event helper is conservatively reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { event('order.updated'); });
PHP,
        'rules' => ['TG002'],
    ],
    'event static broadcast is flagged' => [
        'code' => <<<'PHP'
<?php
namespace App\Events;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\DB;
class OrderUpdated implements ShouldBroadcast {}
DB::transaction(function () { OrderUpdated::broadcast(); });
PHP,
        'rules' => ['TG005'],
    ],
    'direct broadcast explicit afterCommit property is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Events;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\DB;
class OrderUpdated implements ShouldBroadcast { public bool $afterCommit = true; }
DB::transaction(function () { broadcast(new OrderUpdated()); });
PHP,
        'rules' => [],
        'absent' => ['TG005'],
    ],
    'custom interface extending ShouldQueueAfterCommit is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
interface AtomicJob extends ShouldQueueAfterCommit {}
class ProcessOrder implements AtomicJob {}
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'transitive custom interface inheritance is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
interface AtomicJob extends ShouldQueueAfterCommit {}
interface CriticalAtomicJob extends AtomicJob {}
class ProcessOrder implements CriticalAtomicJob {}
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'ShouldQueueAfterCommit can be explicitly overridden false by property' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueueAfterCommit { public bool $afterCommit = false; }
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
    'ShouldQueue job explicit afterCommit property is safe' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue { public bool $afterCommit = true; }
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'constructor last afterCommit call wins over earlier beforeCommit' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue { public function __construct() { $this->beforeCommit(); $this->afterCommit(); } }
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'constructor last beforeCommit call overrides safe queue config' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue { public function __construct() { $this->afterCommit(); $this->beforeCommit(); } }
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
    'Laravel 13 Queue route on direct trait is respected' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
trait RoutedToRedis {}
class ProcessOrder implements ShouldQueue { use RoutedToRedis; }
Queue::route(RoutedToRedis::class, connection: 'redis');
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['database' => false, 'redis' => true]],
    ],
    'Laravel 13 Queue route on recursively used trait is respected' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
trait RoutedToRedis {}
trait FastJob { use RoutedToRedis; }
class ProcessOrder implements ShouldQueue { use FastJob; }
Queue::route(RoutedToRedis::class, connection: 'redis');
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['database' => false, 'redis' => true]],
    ],
    'Laravel 13 Queue forward resolves Queue attribute connection' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Queue as QueueName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
#[QueueName('emails')]
class ProcessOrder implements ShouldQueue {}
Queue::forward('emails', 'critical-emails', connection: 'redis');
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['database' => false, 'redis' => true]],
    ],
    'Laravel 13 Queue forward resolves constructor onQueue' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
class ProcessOrder implements ShouldQueue { public function __construct() { $this->onQueue('emails'); } }
Queue::forward('emails', 'critical-emails', connection: 'redis');
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['database' => false, 'redis' => true]],
    ],
    'manual transactions are balanced per database connection' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::connection('mysql')->beginTransaction();
DB::connection('pgsql')->commit();
PHP,
        'rules' => ['TG013'],
    ],
    'cross connection database write is reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::connection('mysql')->transaction(function () { DB::connection('pgsql')->table('audit')->insert(['ok' => 1]); });
PHP,
        'rules' => ['TG021'],
        'config' => ['database_default' => 'mysql'],
    ],
    'same connection database write stays atomic' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::connection('mysql')->transaction(function () { DB::connection('mysql')->table('audit')->insert(['ok' => 1]); });
PHP,
        'rules' => [],
        'absent' => ['TG021'],
        'config' => ['database_default' => 'mysql'],
    ],
    'named transaction detects write through different default connection' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::connection('mysql')->transaction(function () { DB::table('audit')->insert(['ok' => 1]); });
PHP,
        'rules' => ['TG021'],
        'config' => ['database_default' => 'pgsql'],
    ],
    'dynamic database connection is not guessed as cross connection' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::connection($tenant)->transaction(function () { DB::connection('pgsql')->table('audit')->insert(['ok' => 1]); });
PHP,
        'rules' => [],
        'absent' => ['TG021'],
        'config' => ['database_default' => 'mysql'],
    ],
    'unrelated beforeCommit method is not treated as Laravel dispatch override' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () use ($service) { $service->beforeCommit(); });
PHP,
        'rules' => [],
        'absent' => ['TG010'],
    ],
    'Http pool inside transaction is reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { Http::pool(fn ($pool) => [$pool->post('https://example.test')]); });
PHP,
        'rules' => ['TG006'],
    ],
    'Http batch inside transaction is reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
DB::transaction(function () { Http::batch(fn ($batch) => $batch->post('https://example.test')); });
PHP,
        'rules' => ['TG006'],
    ],
    'Process pool inside transaction is reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
DB::transaction(function () { Process::pool(fn ($pool) => [$pool->command('sync-orders')]); });
PHP,
        'rules' => ['TG009'],
    ],
    'Cache putMany is reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { Cache::putMany(['order:1' => 'paid']); });
PHP,
        'rules' => ['TG008'],
    ],
    'Cache remember is reported because it can mutate cache on miss' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { Cache::remember('order:1', 60, fn () => 'paid'); });
PHP,
        'rules' => ['TG008'],
    ],
    'Storage writeStream is reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
DB::transaction(function () use ($stream) { Storage::writeStream('receipt.txt', $stream); });
PHP,
        'rules' => ['TG007'],
    ],
    'File replaceInFile is reported' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
DB::transaction(function () { File::replaceInFile('a', 'b', '/tmp/a'); });
PHP,
        'rules' => ['TG007'],
    ],

    'Bus chain creation without dispatch has no side effect' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { Bus::chain([new \App\Jobs\A(), new \App\Jobs\B()]); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'Bus batch creation without dispatch has no side effect' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { Bus::batch([new \App\Jobs\A()]); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'Bus invalid post-dispatch afterCommit chain is not accepted as safety proof' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { Bus::dispatch((new \App\Jobs\ProcessOrder())->afterCommit()); });
PHP,
        'rules' => ['TG001'],
    ],
    'Queue push honors ShouldQueueAfterCommit job contract' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
class ProcessOrder implements ShouldQueueAfterCommit {}
DB::transaction(function () { Queue::push(new ProcessOrder()); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['database' => false]],
    ],
    'Queue push honors explicit job afterCommit preference' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { Queue::push((new ProcessOrder())->afterCommit()); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['database' => false]],
    ],
    'Queue push explicit afterCommit false overrides safe queue config' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
class ProcessOrder implements ShouldQueue { public bool $afterCommit = false; }
DB::transaction(function () { Queue::push(new ProcessOrder()); });
PHP,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
    'direct broadcast explicit afterCommit false overrides queue after_commit' => [
        'code' => <<<'PHP'
<?php
namespace App\Events;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\DB;
class OrderUpdated implements ShouldBroadcast { public bool $afterCommit = false; }
DB::transaction(function () { broadcast(new OrderUpdated()); });
PHP,
        'rules' => ['TG005'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],

    'Bus chain creation without dispatch has no side effect' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { Bus::chain([new \App\Jobs\A(), new \App\Jobs\B()]); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'Bus batch creation without dispatch has no side effect' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { Bus::batch([new \App\Jobs\A()]); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
    ],
    'Bus invalid post-dispatch afterCommit chain is not accepted as safety proof' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
DB::transaction(function () { Bus::dispatch(new \App\Jobs\ProcessOrder())->afterCommit(); });
PHP,
        'rules' => ['TG001'],
    ],
    'Queue push honors ShouldQueueAfterCommit job contract' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
class ProcessOrder implements ShouldQueueAfterCommit {}
DB::transaction(function () { Queue::push(new ProcessOrder()); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['database' => false]],
    ],
    'Queue push honors explicit job afterCommit preference' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { Queue::push((new ProcessOrder())->afterCommit()); });
PHP,
        'rules' => [],
        'absent' => ['TG001'],
        'config' => ['queue_default' => 'database', 'queue_after_commit' => ['database' => false]],
    ],
    'Queue push explicit afterCommit false overrides safe queue config' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
class ProcessOrder implements ShouldQueue { public bool $afterCommit = false; }
DB::transaction(function () { Queue::push(new ProcessOrder()); });
PHP,
        'rules' => ['TG001'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],
    'direct broadcast explicit afterCommit false overrides queue after_commit' => [
        'code' => <<<'PHP'
<?php
namespace App\Events;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\DB;
class OrderUpdated implements ShouldBroadcast { public bool $afterCommit = false; }
DB::transaction(function () { broadcast(new OrderUpdated()); });
PHP,
        'rules' => ['TG005'],
        'config' => ['queue_default' => 'redis', 'queue_after_commit' => ['redis' => true]],
    ],

];
