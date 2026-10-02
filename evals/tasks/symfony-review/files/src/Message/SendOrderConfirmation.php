<?php

namespace App\Message;

final class SendOrderConfirmation
{
    public function __construct(public readonly int $orderId)
    {
    }
}
