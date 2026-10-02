<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\MailchimpClient;
use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;

class PushContactToMailchimp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $maxExceptions = 3;

    public function __construct(public int $customerId)
    {
    }

    public function middleware(): array
    {
        return [new RateLimited('mailchimp')];
    }

    public function retryUntil(): DateTime
    {
        return now()->addHours(6);
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(MailchimpClient $mailchimp): void
    {
        $customer = Customer::find($this->customerId);

        if ($customer === null) {
            return;
        }

        $mailchimp->upsertMember(config('services.mailchimp.list_id'), $customer->email, [
            'FNAME' => $customer->first_name,
            'LNAME' => $customer->last_name,
            'OPTIN' => $customer->newsletter_opt_in ? 'yes' : 'no',
        ]);
    }
}
