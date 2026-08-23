<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

class AutoloadedAfterCommitJob implements ShouldQueueAfterCommit
{
    public function __construct()
    {
        $this->afterCommit();
    }
}
