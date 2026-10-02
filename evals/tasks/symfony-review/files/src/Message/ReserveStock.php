<?php

namespace App\Message;

final class ReserveStock
{
    public function __construct(public readonly int $orderId)
    {
    }
}
