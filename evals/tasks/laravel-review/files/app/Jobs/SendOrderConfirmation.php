<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Message;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendOrderConfirmation implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
    }

    public function handle(): void
    {
        Mail::raw(
            "Thanks for your order #{$this->order->id}. We will email you again when it ships.",
            fn (Message $message) => $message
                ->to($this->order->customer->email)
                ->subject("Order #{$this->order->id} confirmed"),
        );
    }
}
