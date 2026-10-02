<?php

namespace App\Services;

use App\Jobs\AwardLoyaltyPoints;
use App\Jobs\RecalculateCustomerSegment;
use App\Jobs\ReserveWarehouseStock;
use App\Jobs\SendOrderConfirmation;
use App\Models\Cart;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class CheckoutService
{
    public function checkout(Cart $cart): Order
    {
        $order = DB::transaction(function () use ($cart) {
            $order = Order::create([
                'customer_id' => $cart->customer_id,
                'total_cents' => $cart->totalCents(),
                'status' => 'placed',
            ]);

            foreach ($cart->items as $item) {
                $order->lines()->create([
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'unit_price_cents' => $item->unit_price_cents,
                ]);
            }

            SendOrderConfirmation::dispatch($order);
            ReserveWarehouseStock::dispatch($order->id);

            $cart->customer->increment('lifetime_orders');
            $cart->delete();

            return $order;
        });

        AwardLoyaltyPoints::dispatch($order->customer_id, intdiv($order->total_cents, 100));
        RecalculateCustomerSegment::dispatch($order->customer_id);

        return $order;
    }
}
