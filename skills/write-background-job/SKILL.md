---
name: write-background-job
description: "Create or change a background job in Laravel (queued job, queued listener, queued mail/notification, Bus::batch or Bus::chain dispatch) or Symfony (Messenger message, handler and routing) so it is safe under retries, redelivery, worker crashes and database transactions. Decides payload, idempotency guard, after-commit dispatch, retry/backoff/timeout and failure handling before writing, then lints and runs the neighbouring tests. Use when the user asks to add, write, create or move work to the background, e.g. 'create a job that sends the invoice', 'make this async', 'queue this', 'add a Messenger handler for OrderPlaced', 'dispatch this after the order is saved'. Not for reviewing existing jobs (use review-background-jobs) or writing their tests."
allowed-tools: Read, Write, Edit, Glob, Grep, Bash
---

# Write Background Job

Write a Laravel job or Symfony Messenger message and handler that survives running twice, running late, and running after the transaction that dispatched it.

**Job to write:** $ARGUMENTS

If no description was given (the line above is empty or shows a literal `$ARGUMENTS` placeholder), ask what the job should do and where it is dispatched from.

## Quality Standards

- Decide the reliability properties before writing code (Step 3). Most queue bugs are design choices made by default.
- Use only APIs that exist in the installed version (`composer.lock`, then `vendor/` when a rule marks something version-specific).
- Follow the project's conventions: directory, naming, base classes, constructor style, how existing jobs pass models.
- Change connection-wide or transport-wide settings (`after_commit`, `retry_after`, `retry_strategy`, `failure_transport`) only after asking. Adding a `routing` entry for a new Messenger message is part of the job.

---

## Step 1: Detect the Stack and Read the Config

As in `review-background-jobs` Steps 1-2: versions from `composer.lock`; `config/queue.php` / `config/horizon.php` / worker command for Laravel; `messenger.yaml` transports, `retry_strategy`, `failure_transport`, `routing`, bus middleware for Symfony. Note the connection or transport the job will use and its `retry_after` / `redeliver_timeout`.

## Step 2: Read the Context

1. The code that will dispatch: is it inside a transaction, an observer, a Doctrine listener, a handler on a bus with `doctrine_transaction`?
2. Two or three existing jobs or handlers, to copy conventions.
3. The models/entities and services the job will touch.

## Step 3: Decide, Then Print the Decisions

Print a short design note before writing:

```
Job: SendInvoice (queue: mail, connection: redis, retry_after 90)
- Payload: invoiceId (int)
- Idempotency: skip if invoices.sent_at is set; mailer called before sent_at is written,
  so a failure between them resends (accepted: a duplicate beats a missing invoice)
- Dispatch: from InvoiceService::finalise() inside DB::transaction -> ShouldQueueAfterCommit
- Retries: retryUntil 2h, maxExceptions 3, backoff [10, 60, 300]; timeout 60 (< retry_after 90)
- Failure: failed() sets send_failed_at
- Concurrency: WithoutOverlapping(invoiceId), expireAfter 120
```

Each line comes from a rule:

| Decision | Rule |
|---|---|
| Payload | `laravel/serialisation-batches-chains.md`, `symfony/messages-and-deduplication.md` |
| Idempotency, concurrency | `general/delivery-and-idempotency.md` |
| Dispatch timing | `laravel/dispatch-and-transactions.md`, `symfony/dispatch-and-transactions.md` |
| Retries, timeout, failure | `laravel/retries-timeouts-failures.md`, `symfony/retries-failures-routing.md` |
| Unique / overlap / rate limit | `laravel/uniqueness-and-middleware.md`, `symfony/messages-and-deduplication.md` |

Then continue to Step 4 without waiting, unless a decision needs a config change (Quality Standards).

## Step 4: Write It

1. Create the job, or the message and handler, following the templates below adapted to the project's conventions.
2. Wire the dispatch site with the dispatch timing chosen in Step 3.
3. Symfony: add the `routing` entry so the message is actually async.
4. Batches and chains: add the `Batchable` trait and a cancellation check to batched jobs, and a `catch()` to chains.

## Step 5: Verify

1. `php -l` every file written; PHPStan/Psalm and Pint/PHP-CS-Fixer if the project has them configured.
2. Run the existing tests for the dispatching code, if any.
3. Report: files created or changed, the design note, any config or ops change the job depends on (worker `--timeout`, `retry_after`, failure transport), and the test that would prove each property (`general/verifying-fixes.md`). Offer `generate-php-tests` if it's installed.

---

## Templates

### Laravel Job (10.50+; on older 10.x use `->afterCommit()` at dispatch instead of `ShouldQueueAfterCommit`)

```php
<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\InvoiceMailer;
use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class SendInvoice implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $maxExceptions = 3;

    public $timeout = 60; // below the connection's retry_after

    public function __construct(public int $invoiceId)
    {
        $this->onQueue('mail'); // not `public $queue`: Queueable declares it
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function retryUntil(): DateTime
    {
        return now()->addHours(2);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->invoiceId))
                ->releaseAfter(30)
                ->expireAfter(120),
        ];
    }

    public function handle(InvoiceMailer $mailer): void
    {
        $invoice = Invoice::find($this->invoiceId);

        if ($invoice === null || $invoice->sent_at !== null) {
            return; // deleted, or sent by an earlier attempt
        }

        $mailer->send($invoice);
        $invoice->update(['sent_at' => now()]);
    }

    public function failed(Throwable $exception): void
    {
        Invoice::whereKey($this->invoiceId)->update(['send_failed_at' => now()]);
    }
}
```

Passing the id keeps the payload small and makes "deleted" an explicit branch. If the project passes models with `SerializesModels`, follow it, and decide `public $deleteWhenMissingModels = true;` deliberately.

### Symfony Message and Handler (6.4-8.1)

```php
<?php

namespace App\Message;

final class SendInvoice
{
    public function __construct(public readonly int $invoiceId)
    {
    }
}
```

```php
<?php

namespace App\MessageHandler;

use App\Message\SendInvoice;
use App\Repository\InvoiceRepository;
use App\Service\InvoiceMailer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final class SendInvoiceHandler
{
    public function __construct(
        private InvoiceRepository $invoices,
        private InvoiceMailer $mailer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(SendInvoice $message): void
    {
        $invoice = $this->invoices->find($message->invoiceId)
            ?? throw new UnrecoverableMessageHandlingException(sprintf('Invoice %d not found', $message->invoiceId));

        if ($invoice->getSentAt() !== null) {
            return; // retried or redelivered after the send succeeded
        }

        $this->mailer->send($invoice);
        $invoice->markSent(new DateTimeImmutable());
        $this->entityManager->flush();
    }
}
```

```yaml
framework:
    messenger:
        failure_transport: failed
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                retry_strategy:
                    max_retries: 3
                    delay: 10000
                    multiplier: 6
            failed: 'doctrine://default?queue_name=failed'
        routing:
            App\Message\SendInvoice: async
```

Dispatch after `flush()`, or with `DispatchAfterCurrentBusStamp` from inside a handler on a `doctrine_transaction` bus (`symfony/dispatch-and-transactions.md`).

---

## Troubleshooting

**No queue configured** (Laravel default connection `sync`, or no Messenger transport). Write the job, say it will run synchronously until a connection or transport is configured, and ask before adding one.

**Dispatch site is inside a transaction you can't change.** Use the after-commit mechanism for the version (`dispatch-and-transactions.md`); never move the dispatch to before the writes it depends on.

**The work can't be made idempotent** (a third-party call with no idempotency key). Say so in the design note, claim the work atomically before the call (`general/delivery-and-idempotency.md`), and tell the user which failure mode remains.

**Static checks fail after 5 attempts.** Stop, show the errors, and ask.

---

## Example

```
User: /write-background-job sync new orders to the CRM after checkout

Step 1: Symfony 7.4, Doctrine transport (doctrine://default), retry_strategy defaults,
        failure_transport failed. command.bus has doctrine_transaction.
Step 2: PlaceOrderHandler (on command.bus) creates the order. CrmClient::upsertOrder()
        accepts an external id.
Step 3: Design note:
        - Payload: orderId
        - Idempotency: CrmClient upsert keyed by "order-{id}"; repeat is harmless
        - Dispatch: from PlaceOrderHandler with DispatchAfterCurrentBusStamp
        - Retries: transport defaults (3, 1 s x2); 4xx from CRM -> Unrecoverable
        - Failure: failure transport 'failed'
Step 4: src/Message/SyncOrderToCrm.php, src/MessageHandler/SyncOrderToCrmHandler.php,
        dispatch added to PlaceOrderHandler, routing entry added to messenger.yaml.
Step 5: php -l ok, PHPStan ok, tests/Unit/PlaceOrderHandlerTest.php passes.
        Proving test: in-memory://?serialize=true transport, assert one SyncOrderToCrm sent.
```

---

## Rules Reference

Paths are relative to `./rules/`.

> These files are duplicated in `review-background-jobs/rules/`. CI keeps both copies identical.

### Always

- `general/delivery-and-idempotency.md`
- `general/verifying-fixes.md`

### By Target

| Target | Also read |
|---|---|
| **Laravel** job, queued listener, queued mail/notification | `laravel/dispatch-and-transactions.md`, `laravel/retries-timeouts-failures.md`, `laravel/serialisation-batches-chains.md` |
| **Laravel** unique, overlapping or rate-limited job | `laravel/uniqueness-and-middleware.md` |
| **Laravel** batch or chain | `laravel/serialisation-batches-chains.md` |
| **Symfony** message and handler | `symfony/messages-and-deduplication.md`, `symfony/retries-failures-routing.md`, `symfony/dispatch-and-transactions.md` |
