<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\Geocoder;
use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;

class GeocodeCustomerAddress implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $maxExceptions = 3;

    public function __construct(public int $customerId)
    {
    }

    public function middleware(): array
    {
        return [new RateLimited('geocoding')];
    }

    public function retryUntil(): DateTime
    {
        return now()->addHours(2);
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(Geocoder $geocoder): void
    {
        $customer = Customer::find($this->customerId);

        if ($customer === null || blank($customer->address)) {
            return;
        }

        $point = $geocoder->locate($customer->address);

        $customer->update(['lat' => $point['lat'] ?? null, 'lng' => $point['lng'] ?? null]);
    }
}
