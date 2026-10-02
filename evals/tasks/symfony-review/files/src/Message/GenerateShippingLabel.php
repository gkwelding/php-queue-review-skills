<?php

namespace App\Message;

use App\Entity\Shipment;

final class GenerateShippingLabel
{
    public function __construct(public readonly Shipment $shipment)
    {
    }
}
