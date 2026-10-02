<?php

namespace App\Services;

use App\Jobs\GeocodeCustomerAddress;
use App\Jobs\PushContactToMailchimp;
use App\Models\Customer;

class CustomerProfileService
{
    public function update(Customer $customer, array $attributes): Customer
    {
        $customer->update($attributes);

        if ($customer->wasChanged('address')) {
            GeocodeCustomerAddress::dispatch($customer->id);
        }

        if ($customer->wasChanged(['email', 'first_name', 'last_name', 'newsletter_opt_in'])) {
            PushContactToMailchimp::dispatch($customer->id);
        }

        return $customer;
    }
}
