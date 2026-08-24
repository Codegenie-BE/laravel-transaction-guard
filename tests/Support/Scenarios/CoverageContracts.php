<?php

declare(strict_types=1);

return [
    'resolved transaction callback does not emit unresolved diagnostic' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { DB::table('orders')->count(); });
PHP,
        'rules' => [],
        'absent' => ['TG014'],
    ],
    'ordinary queued dispatch does not imply after response risk' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueue {}
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => ['TG001'],
        'absent' => ['TG017'],
    ],
    'ordinary after commit queued job has no pre dispatch hook or lock risk' => [
        'code' => <<<'PHP'
<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
class ProcessOrder implements ShouldQueueAfterCommit {}
DB::transaction(function () { ProcessOrder::dispatch(); });
PHP,
        'rules' => [],
        'absent' => ['TG001', 'TG022', 'TG023'],
    ],
    'non matching custom side effect pattern remains clean' => [
        'code' => <<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
DB::transaction(function () { SmsGateway::preview('hello'); });
PHP,
        'rules' => [],
        'absent' => ['TG100'],
        'config' => ['custom_side_effect_patterns' => ['/SmsGateway::send\\s*\\(/']],
    ],
];
