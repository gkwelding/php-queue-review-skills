<?php

namespace App\Message;

final class ExportOrderToErp
{
    public function __construct(public readonly int $orderId)
    {
    }
}
