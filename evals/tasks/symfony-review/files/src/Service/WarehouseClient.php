<?php

namespace App\Service;

interface WarehouseClient
{
    /**
     * Creates the stock reservation for $reference, or replaces it if one already exists.
     *
     * @param list<array{sku: string, quantity: int, unit_price_cents: int}> $lines
     *
     * @throws UnknownSkuException when a line's SKU is not in the warehouse catalogue
     */
    public function reserve(string $reference, array $lines): void;
}
