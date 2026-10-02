<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ArchiveOldOrders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $timeout = 900;

    public function __construct()
    {
        $this->onConnection('redis-long');
        $this->onQueue('long');
    }

    public function handle(): void
    {
        Order::query()
            ->whereNull('archived_at')
            ->where('created_at', '<', now()->subYears(2))
            ->chunkById(500, function (Collection $orders) {
                Order::whereKey($orders->modelKeys())->update(['archived_at' => now()]);
            });
    }
}
