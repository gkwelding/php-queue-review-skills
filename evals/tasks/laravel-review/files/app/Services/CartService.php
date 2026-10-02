<?php

namespace App\Services;

use App\Jobs\SendAbandonedCartReminder;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;

class CartService
{
    public function addItem(Customer $customer, Product $product, int $quantity): Cart
    {
        $cart = Cart::firstOrCreate(['customer_id' => $customer->id]);

        $cart->items()->updateOrCreate(
            ['product_id' => $product->id],
            ['quantity' => $quantity, 'unit_price_cents' => $product->price_cents],
        );

        if ($cart->wasRecentlyCreated) {
            SendAbandonedCartReminder::dispatch($cart)->delay(now()->addHours(3));
        }

        return $cart;
    }
}
