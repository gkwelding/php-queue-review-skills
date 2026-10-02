<?php

namespace App\Services;

use App\Jobs\ChargeOrder;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class OrderPlacement
{
    public function place(string $customerEmail, int $amountCents): Order
    {
        return DB::transaction(function () use ($customerEmail, $amountCents) {
            $order = Order::create([
                'customer_email' => $customerEmail,
                'amount_cents' => $amountCents,
            ]);

            DB::table('order_events')->insert([
                'order_id' => $order->id,
                'event' => 'placed',
                'created_at' => now(),
            ]);

            ChargeOrder::dispatch($order->id);

            return $order;
        });
    }
}
