<?php

namespace App\Service;

final class UnknownSkuException extends \RuntimeException
{
    public static function for(string $sku): self
    {
        return new self(sprintf('SKU "%s" is not in the warehouse catalogue', $sku));
    }
}
