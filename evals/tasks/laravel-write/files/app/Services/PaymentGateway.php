<?php

namespace App\Services;

interface PaymentGateway
{
    /**
     * Charges the customer's saved card and returns the charge id.
     *
     * A repeated call with the same idempotency key returns the original charge id
     * instead of charging again (keys are kept for 24 hours).
     *
     * @throws PaymentDeclined when the card is declined
     * @throws GatewayUnavailable when the gateway can't be reached or times out
     */
    public function charge(string $customerEmail, int $amountCents, string $idempotencyKey): string;
}
