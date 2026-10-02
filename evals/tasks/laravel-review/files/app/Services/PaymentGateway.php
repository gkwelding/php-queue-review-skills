<?php

namespace App\Services;

interface PaymentGateway
{
    /**
     * Refunds part or all of a charge and returns the refund id.
     *
     * A repeated call with the same idempotency key returns the original refund id
     * instead of refunding again (keys are kept for 30 days).
     */
    public function refund(string $chargeId, int $amountCents, string $idempotencyKey): string;
}
