<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderLine;
use App\Services\WarehouseClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ReserveWarehouseStock implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $backoff = [10, 60, 300];

    public function __construct(public int $orderId)
    {
    }

    public function handle(WarehouseClient $warehouse): void
    {
        $order = Order::with('lines')->findOrFail($this->orderId);

        $warehouse->reserve(
            "order-{$order->id}",
            $order->lines
                ->map(fn (OrderLine $line) => ['product_id' => $line->product_id, 'quantity' => $line->quantity])
                ->all(),
        );
    }
}
