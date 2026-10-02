<?php

namespace App\Service;

interface PaymentGateway
{
    /**
     * Refunds part or all of a charge and returns the gateway's refund id.
     *
     * When $idempotencyKey is given, a repeated call with the same key returns the
     * original refund id instead of refunding again.
     */
    public function refund(string $chargeId, int $amountCents, ?string $idempotencyKey = null): string;
}
