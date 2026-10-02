<?php

namespace App\Jobs;

use App\Models\Review;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Message;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendReviewReplyNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $deleteWhenMissingModels = true;

    public function __construct(public Review $review)
    {
    }

    public function handle(): void
    {
        Mail::raw(
            'The shop has replied to your review: '.$this->review->reply,
            fn (Message $message) => $message
                ->to($this->review->customer->email)
                ->subject('Reply to your review'),
        );
    }
}
