<?php

namespace App\Jobs;

use App\Models\Cart;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Message;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendAbandonedCartReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Cart $cart)
    {
    }

    public function handle(): void
    {
        Mail::raw(
            "You left {$this->cart->items->count()} items in your basket. They are still waiting for you.",
            fn (Message $message) => $message
                ->to($this->cart->customer->email)
                ->subject('Still thinking it over?'),
        );
    }
}
