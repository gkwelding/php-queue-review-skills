---
title: Laravel Job Payloads, SerializesModels, Batches and Chains
tags: laravel, queues, SerializesModels, deleteWhenMissingModels, payload, Bus::batch, Bus::chain, Batchable
---

## Payloads, `SerializesModels`, Batches and Chains

### What `SerializesModels` Actually Does

It stores the model's class, key, connection and the **names** of loaded relations. When the job is unserialised it runs `newQueryWithoutScopes()->whereKey($id)->useWritePdo()->firstOrFail()` and loads those relations again. Consequences:

- **Deleted before the job runs:** `ModelNotFoundException`, and the job fails at once with no retries.
- **Global scopes are skipped:** a soft-deleted or other-tenant model is restored without complaint. Check `trashed()` or the status in `handle()` if it matters.
- **Unsaved changes are lost:** the job sees the database row, not the in-memory model at dispatch.
- **Collections drop missing models silently:** an `EloquentCollection` property comes back without the deleted rows.
- **Relations are reloaded by name, without constraints:** `$order->load(['lines' => fn ($q) => $q->where('shipped', false)])` before dispatch comes back with *all* lines.

**Incorrect:** the constraint and the in-memory change are both lost.

```php
$order->status = 'confirmed';                                   // not saved
$order->load(['lines' => fn ($q) => $q->where('shipped', false)]);
ShipLines::dispatch($order);
```

**Correct:** save first, pass what the job needs, and let the job query it.

```php
$order->update(['status' => 'confirmed']);
ShipLines::dispatch($order->withoutRelations());
```

```php
public function handle(): void
{
    $lines = $this->order->lines()->where('shipped', false)->get();
    // ...
}
```

`#[WithoutRelations]` (class or property, 10+) does the same as `withoutRelations()` for every model on the job.

### Missing Models: `$deleteWhenMissingModels`

`public $deleteWhenMissingModels = true;` deletes the job quietly instead of failing it. Use it when a missing model means the work is moot (a notification for a deleted comment), not when it hides a race (a job dispatched before commit, `dispatch-and-transactions.md`).

- Laravel 10-12 read it from the class's **declared default** (`getDefaultProperties()`), or on 11-12 from a `#[DeleteWhenMissingModels]` class attribute. Setting `$this->deleteWhenMissingModels = true` in the constructor does nothing there.
- Laravel 13 reads it at dispatch (property or attribute) and stores it in the payload.
- In a chain, a job deleted this way doesn't dispatch the rest of the chain. The remaining jobs are dropped without a failure.

### Payload Size and Contents

Pass ids and small scalars. Not file contents, API responses, large arrays, or objects holding services, closures, PDO connections or streams; those either fail to serialise or bloat every retry. Pass a storage path for files. Queued closures (`dispatch(function () { ... })`) serialise everything they capture; a closure capturing `$this` serialises the whole controller or service.

### Batches (`Bus::batch`)

- Every job in the batch needs the `Batchable` trait.
- When a job fails (after its own retries) and `allowFailures()` isn't set, the batch is **cancelled**, but jobs already queued **still run** unless they check for it.
- `then()` runs only when every job has succeeded. A failed job stays counted as pending, so with `allowFailures()` one failure means `then()` never runs. `catch()` runs on the first failure; `finally()` runs once every job has run.

**Incorrect:**

```php
class ImportRow implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        $this->importRow(); // keeps importing after the batch was cancelled
    }
}
```

**Correct:**

```php
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;

public function middleware(): array
{
    return [new SkipIfBatchCancelled()];
}
```

or `if ($this->batch()?->cancelled()) { return; }` at the top of `handle()`.

If the code that reads the batch's result lives in `then()`, check the batch can't complete partially: either no `allowFailures()`, or move the completion work to `finally()` and inspect `$batch->failedJobs` there.

### Chains (`Bus::chain`)

- A job that fails (after its retries) stops the chain. The remaining jobs never run; register `->catch(fn (Throwable $e) => ...)` to record it.
- `$this->fail()` inside `handle()` stops the chain; `$this->delete()` does not, and the next job runs.
- A job that `release()`s itself keeps its place; the chain continues after it eventually succeeds.

```php
Bus::chain([
    new ReserveStock($orderId),
    new ChargeCustomer($orderId),
    new ShipOrder($orderId),
])->catch(function (Throwable $e) use ($orderId) {
    MarkOrderFailed::dispatch($orderId);
})->dispatch();
```

Each step still needs to be idempotent: a step that fails after its side effect is retried as a whole (`general/delivery-and-idempotency.md`).
