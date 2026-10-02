---
title: Laravel Dispatching Inside Database Transactions
tags: laravel, queues, transactions, after-commit, events, observers, listeners
---

## Dispatching Inside Database Transactions

A job dispatched inside `DB::transaction()` is pushed straight away unless after-commit applies. A worker can run it before the commit: `SerializesModels` throws `ModelNotFoundException` for a row it can't see yet, or the job reads the old state. If the transaction rolls back, the job still runs, for data that never existed.

**Incorrect:**

```php
DB::transaction(function () use ($data) {
    $order = Order::create($data);
    SendOrderConfirmation::dispatch($order);
    $order->lines()->createMany($data['lines']); // the job may run before these exist
});
```

**Correct** (any one):

```php
SendOrderConfirmation::dispatch($order)->afterCommit();
```

```php
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

class SendOrderConfirmation implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    // ...
}
```

Or `'after_commit' => true` on the connection in `config/queue.php`, which covers every job on that connection. Opt a single job out with `->beforeCommit()`.

Outside a transaction, after-commit jobs are pushed immediately, so marking a job after-commit is never harmful.

### How Laravel Decides (10-13)

`Queue::shouldDispatchAfterCommit()` checks, in order:

1. The job implements `ShouldQueueAfterCommit` (it extends `ShouldQueue`): after commit, unless `->beforeCommit()` set `$afterCommit = false`.
2. `$afterCommit` is set on the job (by `->afterCommit()` / `->beforeCommit()`): that value.
3. Otherwise the connection's `after_commit`. The shipped config sets `false`.

Version notes:

- `ShouldQueueAfterCommit` is in Laravel 10.50 and every later major. On an older 10.x, check `vendor/laravel/framework/src/Illuminate/Contracts/Queue/ShouldQueueAfterCommit.php` exists before recommending it; `->afterCommit()` works on all of them.
- **Laravel 10's `sync` driver ignores after-commit** and runs the job on the spot, inside the transaction. From 11 the `sync` connection honours `after_commit` and `->afterCommit()`.
- `ShouldBeUnique` takes its lock at dispatch. If the transaction of an after-commit unique job rolls back, current 12.x and 13 release the lock; 10 and 11 don't, so re-dispatch is silently dropped until `uniqueFor` expires (with no `uniqueFor`, the Redis lock never expires).

### Don't Redeclare `Queueable` Properties

`Queueable` declares `$afterCommit`, `$connection`, `$queue`, `$delay`, `$middleware`, `$chained`, `$chainConnection`, `$chainQueue` and `$chainCatchCallbacks`. Redeclaring one with a different default is a fatal error when the class loads.

**Incorrect:**

```php
class SendOrderConfirmation implements ShouldQueue
{
    use Queueable;

    public $afterCommit = true; // Fatal: definition differs from the trait's
    public $queue = 'mail';     // Fatal for the same reason
}
```

**Correct:** use `ShouldQueueAfterCommit`, or set them in the constructor:

```php
public function __construct(public int $orderId)
{
    $this->afterCommit();
    $this->onQueue('mail');
}
```

`$tries`, `$timeout`, `$backoff`, `$maxExceptions` and `$deleteWhenMissingModels` are not trait properties and can be declared. Queued listeners that don't use `Queueable` can declare `public $afterCommit = true;`.

### Events, Observers, Listeners, Mail

Model events (`created`, `updated`, `saved`, `deleted`) fire inside the caller's transaction, so an observer that dispatches a job or sends mail has the same bug. It is easy to miss because the transaction is in a different file: Grep the callers of `save()` / `create()` for the model.

| What | After-commit mechanism (10-13) |
|---|---|
| Whole event dispatch | Event class `implements ShouldDispatchAfterCommit` |
| Non-queued listener or model observer | `implements ShouldHandleEventsAfterCommit` |
| Queued listener | `implements ShouldQueueAfterCommit`, or `public $afterCommit = true;` |
| Queued mailable / notification | `implements ShouldQueueAfterCommit`, or `->afterCommit()` (from `Queueable`) |
| Anything else (HTTP call, cache bust, search index) | `DB::afterCommit(fn () => ...)` |

**Incorrect:**

```php
class OrderObserver
{
    public function created(Order $order): void
    {
        Http::post('https://crm.example.com/orders', ['id' => $order->id]); // runs before commit, and on rollback
    }
}
```

**Correct:**

```php
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class OrderObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Order $order): void
    {
        SyncOrderToCrm::dispatch($order->id);
    }
}
```

### Testing Hides This

- `QUEUE_CONNECTION=sync` in `phpunit.xml` runs jobs inline, inside the transaction. On Laravel 10 after-commit is ignored there.
- `Queue::fake()` and `Bus::fake()` record the job at dispatch and ignore after-commit, so a fake records the job even when the transaction rolls back. Assert the configuration instead (`fn ($job) => $job->afterCommit === true`, or that the class implements `ShouldQueueAfterCommit`).
- With `RefreshDatabase` / `DatabaseTransactions`, Laravel ignores the wrapping test transaction: after-commit callbacks run when the code's own transaction commits.
