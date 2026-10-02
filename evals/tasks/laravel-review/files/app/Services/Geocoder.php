<?php

namespace App\Services;

interface Geocoder
{
    /** @return array{lat: float, lng: float}|null */
    public function locate(string $address): ?array;
}
