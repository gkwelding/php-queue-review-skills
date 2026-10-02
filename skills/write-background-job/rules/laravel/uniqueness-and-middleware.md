---
title: Laravel Unique Jobs, WithoutOverlapping, RateLimited, ThrottlesExceptions
tags: laravel, queues, ShouldBeUnique, uniqueId, uniqueFor, WithoutOverlapping, RateLimited, ThrottlesExceptions, locks
---

## Unique Jobs and Job Middleware

All of these use cache locks or the rate limiter, so they need a cache store shared by every web and worker host (Redis, database, Memcached, DynamoDB). The `file` or `array` store on more than one host gives each host its own locks.

### `ShouldBeUnique`

The lock is taken **at dispatch**. While it is held, further dispatches with the same key are dropped silently: no exception, no log. It is released when the job finishes or finally fails, but not when it is released back for a retry. `ShouldBeUniqueUntilProcessing` releases it just before `handle()` runs, so a new copy can be queued while the first is running.

**Incorrect:** no `uniqueId()`, so the key is the class alone and only one `RecalculateInvoice` can be queued at a time across all invoices.

```php
class RecalculateInvoice implements ShouldQueue, ShouldBeUnique
{
    public function __construct(public int $invoiceId) {}
}
```

**Correct:**

```php
class RecalculateInvoice implements ShouldQueue, ShouldBeUnique
{
    public $uniqueFor = 3600;

    public function __construct(public int $invoiceId) {}

    public function uniqueId(): string
    {
        return (string) $this->invoiceId;
    }
}
```

- Without `uniqueFor` (default `0`) the Redis lock has no expiry. If the job never reaches completion or failure (deleted by hand, lost with a flushed queue), every later dispatch for that key is dropped until someone clears the lock. Set `uniqueFor`.
- `uniqueVia()` returns the cache repository for the lock when the default store isn't shared.
- Unique is about queueing, not running: it doesn't stop a retry or a redelivered copy running twice (`general/delivery-and-idempotency.md`).
- After-commit unique jobs on 10 and 11 keep the lock when the transaction rolls back (`dispatch-and-transactions.md`).

### `WithoutOverlapping`

`new WithoutOverlapping($key = '', $releaseAfter = 0, $expiresAfter = 0)`. The lock key is the job class plus `$key` (`->shared()` drops the class so different job classes share it).

**Incorrect:**

```php
public $tries = 3;

public function middleware(): array
{
    return [new WithoutOverlapping()];
}
```

Three bugs: no key, so every job of the class is serialised, not just the ones for the same record; `releaseAfter` 0 puts the blocked job straight back and each release uses an attempt, so it fails within seconds; `expiresAfter` 0 means the lock never expires, and a worker killed mid-job (timeout, OOM, deploy) skips the `finally` that releases it, blocking that key for good.

**Correct:**

```php
public $timeout = 120;

public function middleware(): array
{
    return [
        (new WithoutOverlapping((string) $this->accountId))
            ->releaseAfter(30)
            ->expireAfter(180), // longer than $timeout
    ];
}

public function retryUntil(): DateTime
{
    return now()->addHour();
}
```

`->dontRelease()` drops the overlapping copy instead of retrying it. Use it only when the copy is redundant.

### `RateLimited`

`new RateLimited('name')` uses a limiter registered with `RateLimiter::for('name', ...)` (usually in `AppServiceProvider::boot()`). **If no limiter has that name, the middleware lets the job through unthrottled.** Check the name exists.

```php
RateLimiter::for('crm', fn (object $job) => Limit::perMinute(60));
```

Rate-limited jobs are released until the limiter allows them, and each release is an attempt: pair with `retryUntil()`, not `$tries` (`retries-timeouts-failures.md`). `->dontRelease()` drops throttled jobs. `->releaseAfter($seconds)` exists on recent 12.x and 13. `RateLimitedWithRedis` is the Redis-backed variant.

### `ThrottlesExceptions`

Catches exceptions from the job, releases it, and after `$maxAttempts` exceptions waits before trying again.

- **Constructor units changed:** 10 is `($maxAttempts = 10, $decayMinutes = 10)`; 11+ is `($maxAttempts = 10, $decaySeconds = 600)`. `new ThrottlesExceptions(5, 300)` means 300 minutes on Laravel 10.
- `->backoff($minutes)` is in minutes on every version. Without it the released job is retried immediately while under the threshold.
- The caught exception is not rethrown, so it isn't reported and doesn't count towards `$maxExceptions`. On 11+ add `->report()`; on 10 nothing reports it.
- Every release is an attempt: use `retryUntil()`.
- `->when(fn (Throwable $e) => ...)` limits throttling to matching exceptions; others are rethrown. `->deleteWhen(...)` and `->failWhen(...)` exist on recent 12.x and 13.

```php
public function middleware(): array
{
    return [
        (new ThrottlesExceptions(10, 5 * 60))   // 11+: seconds
            ->backoff(5)
            ->when(fn (Throwable $e) => $e instanceof ConnectionException)
            ->report(),
    ];
}

public function retryUntil(): DateTime
{
    return now()->addHours(6);
}
```
