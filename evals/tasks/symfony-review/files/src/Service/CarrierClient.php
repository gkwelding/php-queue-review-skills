<?php

namespace App\Service;

interface CarrierClient
{
    /** Books a collection and returns the URL of the printable label. */
    public function createLabel(string $reference, string $address, int $weightGrams): string;
}
