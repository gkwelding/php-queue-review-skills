---
title: Laravel Retries, Timeouts and Failure Handling
tags: laravel, queues, tries, backoff, retryUntil, timeout, retry_after, failed, horizon
---

## Retries, Timeouts and Failure Handling

### Defaults You Are Reviewing Against (10-13)

| Setting | Default |
|---|---|
| `queue:work --tries` | `1` (no retry) |
| `queue:work --timeout` | `60` seconds |
| `queue:work --backoff` | `0` (retry immediately) |
| `retry_after` in the shipped `config/queue.php` (database, redis, beanstalkd) | `90` seconds |
| Horizon 5 published `config/horizon.php` supervisor | `'tries' => 1`, `'timeout' => 60` |

On 11+ an app may not publish `config/queue.php`; the framework's copy (`vendor/laravel/framework/config/queue.php`) applies. Read the worker command (Supervisor config, Procfile, Docker, Forge/Vapor/Horizon config) before reporting on retries: job properties override `--tries`, `--timeout` and `--backoff`, but nothing in the job overrides `retry_after`.

The retry settings (`tries`, `backoff`, `timeout`, `retryUntil`, `maxExceptions`, `failOnTimeout`) are written into the payload **at dispatch**. Changing them doesn't affect jobs already queued, and `retryUntil()` is evaluated when the job is dispatched, not when it runs.

### `$timeout` Must Be Shorter Than `retry_after`

`retry_after` is how long the queue waits before handing a reserved job to another worker. If a job can run longer, a second worker starts a copy while the first is still running.

**Incorrect:** a 120-second export on a connection with `retry_after` 90.

```php
class ExportReport implements ShouldQueue
{
    public $timeout = 120;
}
```

**Correct:** keep `$timeout` (and `--timeout`) several seconds below `retry_after`; raise `retry_after` on a dedicated connection for long jobs.

```php
'redis-long' => [
    'driver' => 'redis',
    'connection' => 'default',
    'queue' => 'long',
    'retry_after' => 660,
    'block_for' => null,
],
```

```php
public $timeout = 600;
```

Timeout enforcement uses `SIGALRM` and needs the `pcntl` extension; without it nothing stops a hung job. A timed-out job kills the worker process, counts as an attempt, and comes back only after `retry_after`. `public $failOnTimeout = true;` fails it instead. SQS has no `retry_after`; the queue's visibility timeout in AWS plays the same role.

### Every Release Is an Attempt

`$tries` counts attempts, and `release()` (yours, or one done by `RateLimited`, `WithoutOverlapping` or `ThrottlesExceptions`) uses one up. A job held back by middleware fails with `MaxAttemptsExceededException` without ever running.

**Incorrect:**

```php
public $tries = 3;

public function middleware(): array
{
    return [new RateLimited('crm')];
}
```

**Correct:** bound by time, and cap real exceptions separately.

```php
public $maxExceptions = 3;

public function retryUntil(): DateTime
{
    return now()->addHours(2);
}
```

When `retryUntil()` (or `$retryUntil`) is set, `$tries` is ignored entirely. `$tries = 0` means unlimited attempts; with release-based middleware and no `retryUntil()` that job can cycle forever. `$maxExceptions` counts only thrown exceptions, using the cache, keyed by job UUID.

### Backoff

`$backoff` (property) or `backoff()` (method) returns seconds: an int, or an array used per attempt with the last value repeating. Without it a failed job is retried at once (`--backoff=0`), which hammers a struggling API.

```php
public function backoff(): array
{
    return [10, 60, 300];
}
```

Laravel 13 also accepts class attributes from `Illuminate\Queue\Attributes`: `#[Tries(3)]`, `#[Backoff(10, 60, 300)]`, `#[Timeout(120)]`, `#[MaxExceptions(3)]`, `#[FailOnTimeout]`, `#[UniqueFor(3600)]`. `#[DeleteWhenMissingModels]` and `#[WithoutRelations]` work on 11+ (`WithoutRelations` on 10 too).

### `release()`, `fail()` and `delete()` Don't Stop `handle()`

**Incorrect:** the rest of `handle()` still runs after the release.

```php
if (! $this->gatewayIsUp()) {
    $this->release(30);
}

$this->charge();
```

**Correct:**

```php
if (! $this->gatewayIsUp()) {
    $this->release(30);

    return;
}
```

### Swallowed Exceptions

Catching and logging inside `handle()` marks the job successful: no retry, no `failed_jobs` row, no `failed()` call.

**Incorrect:**

```php
try {
    $crm->push($this->contactId);
} catch (Throwable $e) {
    Log::error($e->getMessage());
}
```

**Correct:** let it throw so the retry policy applies, or decide explicitly:

```php
try {
    $crm->push($this->contactId);
} catch (ContactRejected $e) {
    $this->fail($e); // permanent: don't retry

    return;
}
```

### `failed()`

`failed(Throwable $exception): void` runs once, when the job is finally marked failed, not per attempt. It runs on a freshly unserialised copy of the job: properties set during `handle()` are gone. Use it to compensate or notify (release a reservation, mark the record failed), not to retry. Jobs that leave records in an intermediate state ("processing") without a `failed()` that resets them leave those records stuck.

### Long-Running Workers

`queue:work` keeps the application booted between jobs. Static properties, singletons and memoised state carry over from one job to the next, and new code isn't loaded until the worker restarts (`queue:restart` on deploy; Horizon: `horizon:terminate`).
