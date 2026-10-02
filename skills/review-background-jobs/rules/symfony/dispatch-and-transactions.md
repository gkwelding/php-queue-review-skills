---
title: Symfony Messenger Dispatching and Doctrine Transactions
tags: symfony, messenger, doctrine, transactions, DispatchAfterCurrentBusStamp, doctrine_transaction
---

## Dispatching and Doctrine Transactions

A message routed to an AMQP, Redis or SQS transport is sent the moment `dispatch()` is called. If the rows it refers to aren't committed yet, a worker can handle it first: `find()` returns `null`, or the handler reads the old state. If the transaction then rolls back, the message is still out.

### In Controllers and Services

**Incorrect:**

```php
$this->em->persist($order);
$this->bus->dispatch(new SendOrderConfirmation($order->getId())); // id may still be null; row not committed
$this->em->flush();
```

```php
$this->em->wrapInTransaction(function () use ($order): void {
    $this->em->persist($order);
    $this->em->flush();
    $this->bus->dispatch(new SendOrderConfirmation($order->getId())); // still inside the transaction
});
```

**Correct:** dispatch after `flush()` and after any explicit transaction has committed.

```php
$this->em->persist($order);
$this->em->flush();

$this->bus->dispatch(new SendOrderConfirmation($order->getId()));
```

Doctrine lifecycle callbacks and listeners (`prePersist`, `postPersist`, `postUpdate`, `onFlush`) run inside `flush()`'s transaction (ORM 3: between `beginTransaction()` and `commit()` in `UnitOfWork::commit()`). Dispatching from them has the same bug. Collect the messages there and dispatch after `flush()` returns, or from `postFlush` when no outer transaction is open.

### Inside Handlers: `doctrine_transaction` and `DispatchAfterCurrentBusStamp`

`doctrine_transaction` (from DoctrineBundle) wraps the rest of the bus in `beginTransaction()` / `flush()` / `commit()`. It isn't in the default stack; it's added per bus:

```yaml
framework:
    messenger:
        buses:
            command.bus:
                middleware:
                    - doctrine_transaction
```

A handler on that bus that dispatches a follow-up message sends it **inside** the open transaction. Add `DispatchAfterCurrentBusStamp`: the default `dispatch_after_current_bus` middleware sits before your custom middleware, so stamped messages are dispatched after the outer message (and its transaction) has finished.

**Incorrect:**

```php
#[AsMessageHandler]
final class PlaceOrderHandler
{
    public function __invoke(PlaceOrder $command): void
    {
        $order = Order::place($command->customerId, $command->lines);
        $this->orders->save($order);

        $this->bus->dispatch(new SendOrderConfirmation($order->getId()));
    }
}
```

**Correct:**

```php
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

$this->bus->dispatch(
    new SendOrderConfirmation($order->getId()),
    [new DispatchAfterCurrentBusStamp()],
);
```

Behaviour (6.4-8.1, from `DispatchAfterCurrentBusMiddleware`):

- The stamp only defers when it's used **during another dispatch** (a handler, including handlers run by `messenger:consume`). Dispatched from a controller, the stamp is removed and the message goes out at once, so it doesn't fix the controller case above.
- If the outer handler throws, the deferred messages are dropped. That is usually what you want.
- If a deferred message fails, a `DelayedMessageHandlingException` is thrown after the outer message has already committed.

### The Doctrine Transport Is the Exception

The Doctrine transport sends with an `INSERT` on the DBAL connection named in its DSN (`doctrine://default`). When that is the same connection the entity manager uses, the insert joins the open transaction and rolls back with it: dispatching inside the transaction is safe, and is effectively an outbox. Before reporting a transaction bug, check which transport the message is routed to.

### Synchronous Handling

A message with no routing (or routed to `sync://`) is handled inside `dispatch()`, in the caller's transaction and request. Handler exceptions then come back wrapped in `HandlerFailedException` (see `retries-failures-routing.md`).
