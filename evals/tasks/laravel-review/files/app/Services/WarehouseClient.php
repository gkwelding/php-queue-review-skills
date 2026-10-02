<?php

namespace App\Services;

interface WarehouseClient
{
    /**
     * Creates the stock reservation for $reference, or replaces it if one already exists.
     *
     * @param  list<array{product_id: int, quantity: int}>  $lines
     */
    public function reserve(string $reference, array $lines): void;
}
