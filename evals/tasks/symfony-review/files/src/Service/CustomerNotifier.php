<?php

namespace App\Service;

interface CustomerNotifier
{
    public function orderConfirmed(string $email, int $orderId, int $totalCents): void;

    public function refundIssued(string $email, int $amountCents): void;
}
