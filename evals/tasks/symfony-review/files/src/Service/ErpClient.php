<?php

namespace App\Service;

interface ErpClient
{
    /**
     * Creates or updates the sales order with this external id and returns the ERP's document number.
     */
    public function upsertSalesOrder(string $externalId, array $order): string;
}
