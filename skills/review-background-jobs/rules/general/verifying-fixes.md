---
title: Verifying a Fix
tags: testing, laravel, fakes, withFakeQueueInteractions, symfony, messenger, in-memory-transport
---

## Verifying a Fix

Name the test that would prove the fix, briefly. These skills don't write it; `generate-php-tests` (from `php-unit-tests-skills`) does, if installed.

### What Hides Queue Bugs in Tests

- **Laravel** `QUEUE_CONNECTION=sync` (the Laravel 10 and 13 skeletons' `phpunit.xml` set it): jobs run inline, inside the caller's transaction, once, with exceptions thrown back to the caller. Retries, `retry_after`, timeouts and middleware releases never happen; on 10, after-commit is ignored too.
- **Laravel** `Queue::fake()` / `Bus::fake()` keep the job object as dispatched: no serialisation (so no `SerializesModels` re-fetch), and after-commit is ignored.
- **Symfony** routing to `sync://` in the test env: no serialisation, no retries, handler exceptions wrapped in `HandlerFailedException`.
- **Symfony** `in-memory://` without `?serialize=true` keeps the original message object.

### Laravel Hooks (10-13 unless noted)

| To prove | Use |
|---|---|
| Job dispatched with the right ids | `Queue::fake()`; `Queue::assertPushed(SendInvoice::class, fn (SendInvoice $job) => $job->invoiceId === 42)` |
| Job survives serialisation | `Queue::fake()->serializeAndRestore()`, then assert on the restored job |
| Job is after-commit | Assert the class implements `ShouldQueueAfterCommit`, or `fn ($job) => $job->afterCommit === true`; the fakes won't drop a job on rollback |
| Chain or batch contents | `Bus::fake()`; `Bus::assertChained([...])`, `Bus::assertBatched(fn (PendingBatch $batch) => ...)` |
| `release()`, `fail()`, `delete()` from `handle()` | 11+: `$job = (new ImportFeed($id))->withFakeQueueInteractions(); $job->handle(...); $job->assertReleased(delay: 60);` / `assertFailed()` / `assertDeleted()` |
| Batch cancellation honoured | `[$job, $batch] = (new ImportRow($id))->withFakeBatch(); $batch->cancel(); $job->handle();` then assert nothing was imported |
| Idempotency | Call `handle()` twice on the same job and assert the side effect happened once |
| `failed()` compensation | Call `$job->failed(new RuntimeException('boom'))` directly and assert its effect |

### Symfony Hooks (6.4-8.1)

```yaml
# config/packages/messenger.yaml
when@test:
    framework:
        messenger:
            transports:
                async: 'in-memory://?serialize=true'
```

| To prove | Use |
|---|---|
| Message sent, with the right ids | `$transport = static::getContainer()->get('messenger.transport.async');` then `$transport->getSent()` |
| Message survives serialisation | `?serialize=true` on the in-memory DSN |
| Handler is idempotent | Invoke the handler twice with the same message and assert one side effect |
| Permanent failure isn't retried | Assert the handler throws `UnrecoverableMessageHandlingException` for that case |
| Deferred dispatch | In a unit test with a doubled bus, capture the stamps and assert `DispatchAfterCurrentBusStamp` |

If `zenstruck/messenger-test` is installed, use its assertions instead of reading the transport directly.
