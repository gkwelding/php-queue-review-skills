<?php

namespace App\Models;

use App\Observers\ProductObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(ProductObserver::class)]
class Product extends Model
{
    protected $guarded = [];

    public function toSearchableArray(): array
    {
        return $this->only(['id', 'name', 'description', 'price_cents', 'in_stock']);
    }
}
