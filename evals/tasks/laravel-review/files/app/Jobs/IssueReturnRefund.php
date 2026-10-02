<?php

namespace App\Jobs;

use App\Models\CustomerReturn;
use App\Services\PaymentGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class IssueReturnRefund implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 5;

    public $backoff = [30, 120, 600];

    public function __construct(public int $returnId)
    {
    }

    public function handle(PaymentGateway $payments): void
    {
        $return = CustomerReturn::with('order')->findOrFail($this->returnId);

        if ($return->refunded_at !== null) {
            return;
        }

        $refundId = $payments->refund(
            $return->order->charge_id,
            $return->amount_cents,
            "customer-return-{$return->id}",
        );

        $return->update(['refund_id' => $refundId, 'refunded_at' => now()]);
    }
}
