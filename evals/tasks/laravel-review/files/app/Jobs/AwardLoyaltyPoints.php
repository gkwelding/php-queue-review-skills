<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\CrmClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class AwardLoyaltyPoints implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 4;

    public $backoff = 60;

    public function __construct(public int $customerId, public int $points)
    {
    }

    public function handle(CrmClient $crm): void
    {
        $customer = Customer::findOrFail($this->customerId);

        $customer->increment('loyalty_points', $this->points);

        $crm->recordEvent($customer->crm_contact_id, 'loyalty_points_awarded', [
            'points' => $this->points,
            'balance' => $customer->loyalty_points,
        ]);
    }
}
