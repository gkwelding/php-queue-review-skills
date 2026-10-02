---
title: Symfony Messenger Retries, Failures, Routing and Workers
tags: symfony, messenger, retry_strategy, failure_transport, UnrecoverableMessageHandlingException, RecoverableMessageHandlingException, routing, redeliver_timeout
---

## Retries, Failures, Routing and Workers

### Routing

A message class with no `routing` entry (and, from 7.2, no `#[AsMessage(transport: ...)]`) is **handled synchronously** inside `dispatch()`, because `allow_no_senders` defaults to `true`. Moving or renaming a message class without updating `routing` silently turns async work into sync work in the web request.

```yaml
framework:
    messenger:
        transports:
            async: '%env(MESSENGER_TRANSPORT_DSN)%'
        routing:
            App\Message\SendOrderConfirmation: async
```

- Routing matches the class, its parents and its interfaces, then `'*'`. Routing an interface (`App\Message\AsyncMessage: async`) covers every message that implements it.
- `routing` config wins over `#[AsMessage]`, so an environment can override the attribute.
- 8.1 deprecates the nested `senders:` key in routing; use a flat list of transport names.
- Check `when@test` / `config/packages/test/messenger.yaml`: routing everything to `sync://` in tests hides serialisation, retry and ordering problems.

### Retry Strategy

Per transport, under `retry_strategy`. Defaults (6.4-8.1): `max_retries: 3`, `delay: 1000`, `multiplier: 2`, `max_delay: 0` (no cap). 7.1+ adds `jitter: 0.1`. **Delays are milliseconds**, unlike Laravel's seconds.

```yaml
framework:
    messenger:
        failure_transport: failed
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                retry_strategy:
                    max_retries: 5
                    delay: 2000
                    multiplier: 3
                    max_delay: 600000
            failed: 'doctrine://default?queue_name=failed'
```

### Failure Transport

Without `failure_transport` (global, or per transport), a message that runs out of retries is **removed from the transport and lost**; the only trace is a `critical` log line ("Removing from transport after N retries"). Every async transport needs one, and someone needs to watch it (`messenger:failed:show`, `messenger:failed:retry`).

### Recoverable vs Unrecoverable

| Thrown from the handler | Effect |
|---|---|
| Any other exception | Retried per `retry_strategy`, then failure transport |
| `UnrecoverableMessageHandlingException` (or anything implementing `UnrecoverableExceptionInterface`) | No retry; straight to the failure transport |
| `RecoverableMessageHandlingException` (or `RecoverableExceptionInterface`) | **Always retried, ignoring `max_retries`** |

When the handler is wrapped in `HandlerFailedException`, the retry listener looks inside it: any recoverable wrapped exception means retry; only all-unrecoverable means no retry.

**Incorrect:** a permanent condition marked recoverable retries forever.

```php
$order = $this->orders->find($message->orderId)
    ?? throw new RecoverableMessageHandlingException('Order not found');
```

**Correct:**

```php
$order = $this->orders->find($message->orderId)
    ?? throw new UnrecoverableMessageHandlingException(sprintf('Order %d not found', $message->orderId));
```

Use `RecoverableMessageHandlingException` only for conditions that will clear (a lock held elsewhere, a dependency starting up). 7.2+ accepts a delay in milliseconds as the fourth constructor argument (`retryDelay`); 8.1 adds a fifth, `forceRetry` (default `true`), and `forceRetry: false` makes it respect `max_retries`. On 6.4 the constructor takes only `$message`, `$code`, `$previous`.

### `HandlerFailedException` Around Synchronous Handling

When a message is handled synchronously (unrouted, or `sync://`), the bus wraps handler exceptions in `HandlerFailedException`.

**Incorrect:** never matches.

```php
try {
    $this->bus->dispatch(new ChargeCustomer($orderId));
} catch (PaymentDeclined $e) {
    // ...
}
```

**Correct:**

```php
try {
    $this->bus->dispatch(new ChargeCustomer($orderId));
} catch (HandlerFailedException $e) {
    $declined = $e->getWrappedExceptions(PaymentDeclined::class);
    if ($declined === []) {
        throw $e;
    }
    // ...
}
```

### Several Handlers, One Message

On retry, handlers that already succeeded (they have a `HandledStamp`) are skipped and only the failed ones run again. `doctrine_transaction` removes those stamps when it rolls back, so on that bus every handler re-runs: a sibling that sent an email sends it again when another handler for the same message fails.

### Workers and Redelivery

- The Doctrine transport hands a message to another worker when it has been "in handling" longer than `redeliver_timeout` (default `3600` seconds). A handler that can run longer is handled twice. Raise the option on that transport, or on 7.3+ run `messenger:consume --keepalive`.
- `messenger:consume` is long-running: services keep state between messages. Stop workers regularly (`--time-limit`, `--memory-limit`, `--limit`) and restart them on deploy (`messenger:stop-workers`).
- With `sync://` or an unrouted message there is no retry: the exception goes straight back to the caller.
