<?php

namespace App\Observers;

use App\Jobs\SyncProductToSearchIndex;
use App\Models\Product;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class ProductObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Product $product): void
    {
        SyncProductToSearchIndex::dispatch($product->id);
    }

    public function deleted(Product $product): void
    {
        SyncProductToSearchIndex::dispatch($product->id);
    }
}
