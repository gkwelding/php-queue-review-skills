<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\PaymentDeclined;
use App\Services\PaymentGateway;
use DateTime;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

class ChargeOrder implements ShouldQueueAfterCommit
{
    use Queueable;

    public $maxExceptions = 10;

    public function __construct(public int $orderId)
    {
    }

    public function backoff(): array
    {
        return [30, 120, 300, 600];
    }

    public function retryUntil(): DateTime
    {
        return now()->addHour();
    }

    public function handle(PaymentGateway $gateway): void
    {
        $order = Order::find($this->orderId);

        if ($order === null || $order->status !== 'pending') {
            return;
        }

        try {
            $chargeId = $gateway->charge($order->customer_email, $order->amount_cents, "order-{$order->id}-charge");
        } catch (PaymentDeclined $e) {
            $order->update(['status' => 'payment_failed']);
            $this->fail($e);

            return;
        }

        $order->update(['status' => 'paid', 'charge_id' => $chargeId]);
    }
}
