<?php

namespace App\Jobs;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class RecalculateCustomerSegment implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $uniqueFor = 900;

    public function __construct(public int $customerId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->customerId;
    }

    public function handle(): void
    {
        $customer = Customer::find($this->customerId);

        if ($customer === null) {
            return;
        }

        $spent = (int) $customer->orders()->where('created_at', '>=', now()->subYear())->sum('total_cents');

        $customer->update([
            'segment' => match (true) {
                $spent >= 200_000 => 'gold',
                $spent >= 50_000 => 'silver',
                default => 'standard',
            },
        ]);
    }
}
