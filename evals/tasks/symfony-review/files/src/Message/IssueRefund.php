<?php

namespace App\Message;

final class IssueRefund
{
    public function __construct(
        public readonly int $paymentId,
        public readonly int $amountCents,
    ) {
    }
}
