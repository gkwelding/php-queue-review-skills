---
title: Symfony Messenger Message Classes, Serialisation and Deduplication
tags: symfony, messenger, messages, serialisation, entities, DeduplicateStamp, lock, idempotency
---

## Message Classes, Serialisation and Deduplication

### Messages Are Small DTOs, Not Entities

The default serializer is `messenger.transport.native_php_serializer` (PHP `serialize()`). An entity in a message is serialised with its whole loaded graph, as it was at dispatch. The handler receives a copy the EntityManager doesn't manage: it holds stale data, and changes to it are not flushed.

**Incorrect:**

```php
final class SendInvoice
{
    public function __construct(public readonly Invoice $invoice) {}
}
```

**Correct:** identifiers and small scalars; the handler loads current state.

```php
final class SendInvoice
{
    public function __construct(public readonly int $invoiceId) {}
}
```

```php
#[AsMessageHandler]
final class SendInvoiceHandler
{
    public function __construct(private InvoiceRepository $invoices, private MailerInterface $mailer) {}

    public function __invoke(SendInvoice $message): void
    {
        $invoice = $this->invoices->find($message->invoiceId)
            ?? throw new UnrecoverableMessageHandlingException(sprintf('Invoice %d not found', $message->invoiceId));

        if ($invoice->isSent()) {
            return; // redelivered or retried after the send succeeded
        }

        // ...
    }
}
```

Also flag in message classes:

- Services, closures, streams, `UploadedFile` or other resources as properties. `serialize()` fails on closures, and services drag their dependencies in. Pass a storage path for files.
- Large payloads (file contents, API responses). They are stored and re-sent on every retry.
- With `messenger.transport.symfony_serializer` (JSON), properties must be normalisable and the class denormalisable from its constructor or public properties.
- Renaming or restructuring a message class strands messages already queued: they fail to decode (`MessageDecodingFailedException`). Keep the old class until the queue has drained, or add a new message class.

### Deduplication (`DeduplicateStamp`, 7.3+)

`DeduplicateStamp(string|Key $key, ?float $ttl = 300.0, bool $onlyDeduplicateInQueue = false)`. Needs `symfony/lock` with the Lock component enabled; framework-bundle then adds `deduplicate_middleware` to every bus. Not available on 6.4 or 7.0-7.2.

```php
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

$this->bus->dispatch(
    new RebuildSearchIndex($productId),
    [new DeduplicateStamp('rebuild-search-index-'.$productId, ttl: 600)],
);
```

How it behaves (`DeduplicateMiddleware`):

- On dispatch it tries to acquire the lock for the key; if another holder has it, the message is **dropped silently**.
- By default the lock is released after the worker has handled the message. With `onlyDeduplicateInQueue: true` it is released when the worker receives the message, so a new copy can be queued while the first runs.
- The TTL caps everything: a handler that runs longer than `ttl` lets duplicates in.
- On 7.3-8.0, a message that fails and won't be retried keeps its lock until the TTL expires. 8.1 adds `ReleaseDeduplicationLockOnFailureListener`, which releases it.
- The default lock store is `semaphore` or `flock`, both local to one host. Web servers and workers on different hosts need a shared store (`framework.lock` pointing at Redis or a database) or deduplication silently does nothing.
- It stops duplicate **dispatch**; it doesn't make the handler safe to run twice (`general/delivery-and-idempotency.md`).

### Concurrent Handlers for the Same Record

Several workers on one transport can handle messages for the same aggregate at the same time. Protect read-modify-write handlers with a database constraint or atomic update, or with a lock held for the duration of the handler:

```php
$lock = $this->lockFactory->createLock('invoice-'.$message->invoiceId, ttl: 120);

if (! $lock->acquire()) {
    throw new RecoverableMessageHandlingException('Invoice is being processed', retryDelay: 5000); // 7.2+
}

try {
    // ...
} finally {
    $lock->release();
}
```

A `RecoverableMessageHandlingException` here retries without limit (`retries-failures-routing.md`); make sure the lock is always released or has a TTL, or the message loops.
