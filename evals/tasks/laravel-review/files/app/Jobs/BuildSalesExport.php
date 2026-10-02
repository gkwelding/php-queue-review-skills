<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class BuildSalesExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 2;

    public $timeout = 300;

    public function __construct(public string $month)
    {
        $this->onConnection('redis');
        $this->onQueue('reports');
    }

    public function handle(): void
    {
        $start = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();
        $csv = "order_id,customer_id,total_cents,status,created_at\n";

        Order::query()
            ->whereBetween('created_at', [$start, $start->copy()->endOfMonth()])
            ->lazyById(1000)
            ->each(function (Order $order) use (&$csv) {
                $csv .= "{$order->id},{$order->customer_id},{$order->total_cents},{$order->status},{$order->created_at}\n";
            });

        Storage::put("exports/sales-{$this->month}.csv", $csv);
    }
}
