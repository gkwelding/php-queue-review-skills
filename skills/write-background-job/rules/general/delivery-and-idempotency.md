---
title: Delivery Guarantees and Idempotency
tags: queues, idempotency, at-least-once, retries, redelivery, duplicates
---

## Delivery Guarantees and Idempotency

Every queue these rules cover delivers **at least once**. A job or handler runs more than once when:

- it throws and is retried (Laravel `$tries` / `backoff`, Messenger `retry_strategy`)
- the worker dies mid-run (deploy, OOM, timeout kill) and the message becomes visible again (Laravel `retry_after`, the SQS visibility timeout, the Doctrine transport's `redeliver_timeout`)
- it runs longer than that redelivery window, so a second worker takes a copy while the first is still working
- the same work is dispatched twice (double submit, an observer and a controller both dispatching, a retried HTTP request)

For every job ask: **what happens if this runs twice, or stops halfway and runs again?**

### Side Effects Before the Failure Point Repeat

**Incorrect:** the charge is repeated on every retry of the line below it.

```php
public function handle(PaymentGateway $gateway): void
{
    $gateway->charge($this->orderId, $this->amount);
    Order::whereKey($this->orderId)->update(['status' => 'paid']);
}
```

**Correct:** detect "already done" from durable state, and give the external call a key derived from the work, not from the attempt.

```php
public function handle(PaymentGateway $gateway): void
{
    $order = Order::findOrFail($this->orderId);

    if ($order->status === 'paid') {
        return;
    }

    $gateway->charge($order->id, $order->amount, idempotencyKey: "order-{$order->id}-charge");
    $order->update(['status' => 'paid']);
}
```

The key must be the same on every attempt: `"order-{$id}-charge"`, never `Str::uuid()`, `uniqid()` or a timestamp generated inside the handler. Whether a provider honours idempotency keys is part of that provider's API; check before relying on it.

### Check-Then-Act Is Not Enough on Its Own

Two copies running at once both pass `if ($order->status === 'paid')`. Claim the work atomically, or let a unique index reject the second write.

**Incorrect:**

```php
if (! $order->invoice_sent) {
    Mail::to($order->customer)->send(new InvoiceMail($order));
    $order->update(['invoice_sent' => true]);
}
```

**Correct:**

```php
$claimed = Order::whereKey($order->id)
    ->where('invoice_sent', false)
    ->update(['invoice_sent' => true]);

if ($claimed === 0) {
    return; // another run already took it
}

Mail::to($order->customer)->send(new InvoiceMail($order));
```

Claiming first trades "may send twice" for "may not send if the mail call then fails". Pick the failure the business can live with and say which one in the finding. When neither is acceptable, record the send in an outbox row (unique on the natural key) in the same transaction as the state change and deliver from that.

Doctrine: a unique constraint on the natural key (for example `(order_id, type)` on a `payments` table) and catching `Doctrine\DBAL\Exception\UniqueConstraintViolationException`, or a DQL `UPDATE ... WHERE status = 'pending'` and checking the affected row count.

### Operations That Are Never Idempotent

Counters (`increment()`, `+= 1`), "insert a row" without a unique key, emails, SMS, push notifications, outgoing webhooks and payment calls. Each needs a guard from the sections above.

### Deduplicating Dispatch Is Not Idempotency

`ShouldBeUnique` (Laravel) and `DeduplicateStamp` (Symfony 7.3+) stop a second copy being **queued** while a lock is held. They do nothing about a retry or redelivery of the copy that was queued. Use them to cut duplicate work, never as the only guard on a side effect that must happen once.

### Review Questions

- What runs before the first line that can throw? Is it safe to repeat?
- Does every external call use a key that is stable across attempts?
- Can two copies run at the same time (several workers, redelivery while still running)?
- Is "already done" read from durable state, not from the payload?
