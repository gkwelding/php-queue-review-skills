---
name: review-background-jobs
description: "Review background jobs and message handling in Laravel (queued jobs, queued listeners, observers that dispatch, Bus::batch, Bus::chain, job middleware, config/queue.php, Horizon) or Symfony (Messenger messages, handlers, messenger.yaml) for reliability bugs: dispatching inside database transactions, handlers that aren't safe to retry or redeliver, wrong tries/backoff/timeout/retry_after, missing failure handling, serialised models and entities, unique/overlap/rate-limit misuse, and routing that silently runs async work synchronously. Read-only: reports each finding with file:line, the failure scenario, severity and the idiomatic fix. Use when the user asks to review, audit or sanity-check queue code, e.g. 'review this job', 'is this handler safe to retry?', 'why does this job run twice?', 'check our Messenger config', 'audit the queue setup'. Not for writing a new job or handler (use write-background-job) or for writing tests."
allowed-tools: Read, Glob, Grep, Bash(git diff:*), Bash(git status:*), Bash(git log:*)
---

# Review Background Jobs

Review queued jobs, listeners, batches, chains and Messenger handlers for the reliability bugs that pass every test and then lose, duplicate or stall work in production. Report findings; change nothing.

**Target to review:** $ARGUMENTS

If no target was given (the line above is empty or shows a literal `$ARGUMENTS` placeholder), review what changed: files from `git diff --name-only main...HEAD` (use the repo's default branch) plus `git status --porcelain`, keeping jobs, listeners, observers, Messenger messages and handlers, queue/Messenger config, and any file that dispatches (`::dispatch(`, `dispatch(`, `Bus::`, `->dispatch(`). If there are none, ask for a target.

## Quality Standards

- Every finding has a concrete failure scenario: what happens, in what order, with what result. "Consider making this idempotent" is not a finding.
- Name only APIs that exist in the installed version. Read the version from `composer.lock`; when a rule says an API is version-specific, check `vendor/` before recommending it.
- Read the config and the worker command before reporting on retries or timeouts. A job that looks wrong may be fine because of the connection, Horizon or transport settings.
- Read the dispatch sites, not just the job. Transaction bugs live in the caller, often in another file (an observer, a Doctrine listener, a service wrapped in a transaction).
- No finding beats a wrong one. Leave out style, naming and performance unless they cause a reliability failure.
- Read-only. Don't edit files, run workers or dispatch jobs.

---

## Step 1: Detect the Stack

From `composer.lock`: `laravel/framework`, `laravel/horizon`, `symfony/messenger`, `symfony/framework-bundle`, `doctrine/doctrine-bundle`, `doctrine/orm`, `symfony/lock`. Note exact major.minor; the rules flag behaviour that differs between Laravel 10-13 and Symfony 6.4-8.1.

## Step 2: Read the Config

**Laravel:** `config/queue.php` (on 11+ it may be unpublished; then `vendor/laravel/framework/config/queue.php` applies): default connection, `after_commit` and `retry_after` per connection. `config/horizon.php` supervisors (`tries`, `timeout`, `queue`). The worker command wherever it lives (Supervisor `.conf`, `Procfile`, Docker/Compose, deploy scripts): `--tries`, `--timeout`, `--backoff`, `--queue`. `phpunit.xml` / `.env.testing` `QUEUE_CONNECTION`. Rate limiters registered with `RateLimiter::for()`.

**Symfony:** `config/packages/messenger.yaml` (and `when@test` / `test/` overrides): transports and DSNs, `retry_strategy`, `failure_transport`, `routing`, bus `middleware` (is `doctrine_transaction` there?). `config/packages/lock.yaml` if deduplication or locks are used. The worker command (`messenger:consume` options).

If the worker command isn't in the repository, say so and review against the defaults (`--tries=1`, `--timeout=60`), marking findings that depend on it.

## Step 3: Read the Target and Its Dispatch Sites

1. Read the job / listener / message and handler fully, plus its middleware, `failed()` and any trait it uses.
2. Find every dispatch site with Grep: `ClassName::dispatch`, `dispatch(new ClassName`, `Bus::chain`, `Bus::batch`, `->dispatch(new ClassName`, and event classes whose listeners queue it.
3. For each site, establish whether it runs inside a database transaction: `DB::transaction`, `beginTransaction`, `wrapInTransaction`, a model observer or model event, a Doctrine lifecycle listener, a Messenger handler on a bus with `doctrine_transaction`.

## Step 4: Check Against the Rules

Read the rule files for the stack (see Rules Reference) and go through each:

1. **Transactions:** dispatched or side effect performed before commit?
2. **Idempotency:** what repeats if it runs twice or stops halfway? Can two copies run at once?
3. **Retries and failure:** attempts, backoff, `retryUntil`, `$timeout` vs `retry_after`, middleware releases, swallowed exceptions, `failed()` / failure transport, recoverable vs unrecoverable.
4. **Payload:** models or entities in the payload, missing-model behaviour, large or unserialisable data.
5. **Uniqueness, overlap and rate limits:** keys, lock expiry, limiter names, version-specific constructor units.
6. **Batches and chains:** cancellation honoured, `then` vs `finally`, chain `catch`.
7. **Routing and config:** unrouted Messenger messages, sync connections hiding problems, redelivery windows.

Confirm each candidate finding against the code and config before keeping it. Drop it if the config or another layer already handles it.

## Step 5: Report

```
## Background job review: {target}

Stack: {e.g. Laravel 11.44, redis queue (retry_after 90), Horizon supervisor tries 3 / timeout 60}

### 1. [High] {one-line title}
- **Where:** {path}:{line}
- **Scenario:** {one or two sentences: the sequence of events and the result}
- **Fix:** {the idiomatic change, with a short code snippet}
- **Verify:** {the test hook that proves it, one line}

### 2. [Medium] ...

### Checked, no issue
- {one line per area checked that is fine, so the user knows it was looked at}

### Assumptions
- {anything not visible in the repo that a finding depends on, e.g. the worker's --timeout}
```

Order findings by severity, then by file.

| Severity | Meaning |
|---|---|
| **High** | In normal operation: duplicate charges, emails or writes; work silently dropped or never run; job runs on missing or uncommitted data |
| **Medium** | Breaks under retries, redelivery, deploys, worker crashes or load; failures go unnoticed (no failure transport, swallowed exceptions, stuck locks) |
| **Low** | Fragile but currently safe: wasted work, slow recovery, a setting one change away from a High |

---

## Troubleshooting

**Target not found.** Say which paths you searched and ask.

**Not Laravel or Symfony.** Apply `general/delivery-and-idempotency.md` only, and say the framework rules didn't apply.

**Version can't be determined** (no `composer.lock`). Say so, and give fixes that work across the versions the rules cover, noting where behaviour differs.

**Behaviour depends on infrastructure outside the repo** (SQS visibility timeout, worker flags set in a hosting panel). Put it under Assumptions, and phrase the finding as conditional on it.

**Nothing found.** Say so, list what was checked, and stop. Don't pad the report.

---

## Example

```
User: /review-background-jobs app/Jobs/ChargeSubscription.php

Step 1: Laravel 11.31, Horizon 5.29.
Step 2: config/queue.php redis retry_after 90, after_commit false. horizon.php
        supervisor tries 3, timeout 60. RateLimiter::for('stripe') in AppServiceProvider.
Step 3: ChargeSubscription uses SerializesModels, $tries = 3, $timeout = 120,
        middleware RateLimited('stripe'). Dispatched from RenewalService::renew()
        inside DB::transaction() after updating the subscription period.

## Background job review: app/Jobs/ChargeSubscription.php

### 1. [High] Charge repeats on retry
- Where: app/Jobs/ChargeSubscription.php:41
- Scenario: Stripe charge succeeds, then the invoice insert on line 44 throws;
  the retry charges the card again.
- Fix: check for an existing paid invoice first and pass a stable idempotency key
  ("subscription-{id}-period-{start}") to the charge call.
- Verify: call handle() twice on the same job; assert one charge.

### 2. [High] $timeout 120 is longer than retry_after 90
- Where: app/Jobs/ChargeSubscription.php:22, config/queue.php:68
- Scenario: a slow charge is still running at 90 s, Redis hands the job to a
  second worker, and both charge.
- Fix: $timeout below retry_after, or a separate connection with retry_after 180.

### 3. [Medium] Rate-limit releases use up $tries
...

### 4. [Medium] Dispatched before the transaction commits
...
```

---

## Rules Reference

Paths are relative to `./rules/`.

> These files are duplicated in `write-background-job/rules/`. CI keeps both copies identical.

### Always

- `general/delivery-and-idempotency.md` - at-least-once delivery, side effects before failure, check-then-act, dedup is not idempotency
- `general/verifying-fixes.md` - test setups that hide queue bugs, and the hook that proves each fix

### By Target

| Target | Also read |
|---|---|
| **Laravel** job, queued listener, observer or event that dispatches, queued mail/notification | `laravel/dispatch-and-transactions.md`, `laravel/retries-timeouts-failures.md`, `laravel/serialisation-batches-chains.md` |
| **Laravel** job with `ShouldBeUnique` or `middleware()` | `laravel/uniqueness-and-middleware.md` |
| **Laravel** `Bus::batch`, `Bus::chain` | `laravel/serialisation-batches-chains.md` |
| **Laravel** `config/queue.php`, `config/horizon.php`, worker commands | `laravel/retries-timeouts-failures.md` |
| **Symfony** code that dispatches messages, Doctrine listeners, buses with `doctrine_transaction` | `symfony/dispatch-and-transactions.md` |
| **Symfony** message handler | `symfony/messages-and-deduplication.md`, `symfony/retries-failures-routing.md` |
| **Symfony** message class, `DeduplicateStamp`, locks | `symfony/messages-and-deduplication.md` |
| **Symfony** `messenger.yaml`, workers | `symfony/retries-failures-routing.md` |
