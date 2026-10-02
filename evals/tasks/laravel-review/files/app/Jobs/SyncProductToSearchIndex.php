<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\SearchIndex;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SyncProductToSearchIndex implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $uniqueFor = 600;

    public function __construct(public int $productId)
    {
    }

    public function handle(SearchIndex $search): void
    {
        $product = Product::find($this->productId);

        if ($product === null) {
            $search->delete('products', $this->productId);

            return;
        }

        $search->upsert('products', $product->id, $product->toSearchableArray());
    }
}
