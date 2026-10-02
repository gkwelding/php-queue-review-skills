# PHP Queue Review Skills

Agent skills for designing and reviewing background jobs in Laravel (queues, jobs, listeners, batches, chains) and Symfony Messenger. They catch the reliability bugs that pass every test and then lose, duplicate or stall work in production: dispatching before the transaction commits, handlers that aren't safe to run twice, timeouts longer than the redelivery window, retries that never happen or never stop, and failures nobody sees.

## Skills

| Skill | What it does |
|---|---|
| `review-background-jobs` | Reads the job or handler, its dispatch sites, the queue/Messenger config and the worker command, then reports findings: file:line, the failure scenario in one or two sentences, severity and the idiomatic fix. Changes nothing. |
| `write-background-job` | Decides payload, idempotency guard, dispatch timing, retry/timeout and failure handling, prints those decisions, then writes the job (or message, handler and routing), lints it and runs the neighbouring tests. |

## What's Covered

**General:** at-least-once delivery and why jobs run twice, side effects before the failure point, check-then-act races, stable idempotency keys, why deduplicating dispatch isn't idempotency, and which test setups hide queue bugs.

**Laravel 10-13:** dispatching inside `DB::transaction()` (`->afterCommit()`, `ShouldQueueAfterCommit`, connection `after_commit`, the Laravel 10 `sync` driver ignoring it), observers and events (`ShouldDispatchAfterCommit`, `ShouldHandleEventsAfterCommit`, `DB::afterCommit()`), the fatal error from redeclaring `Queueable` properties, `$tries` / `$maxExceptions` / `backoff()` / `retryUntil()`, `$timeout` vs `retry_after`, releases counting as attempts, `release()` not stopping `handle()`, swallowed exceptions, `failed()`, `SerializesModels` re-fetching (missing models, skipped global scopes, unconstrained relations), `$deleteWhenMissingModels` across versions, `ShouldBeUnique` / `uniqueId()` / `uniqueFor`, `WithoutOverlapping` lock expiry, `RateLimited` with an undefined limiter, `ThrottlesExceptions` constructor units changing between 10 and 11, batch cancellation and `then` vs `finally`, chain failure, Horizon defaults, and the fakes for proving a fix.

**Symfony 6.4-8.1:** dispatching before `flush()` and inside Doctrine lifecycle events, `doctrine_transaction` with `DispatchAfterCurrentBusStamp`, the Doctrine transport joining the transaction, unrouted messages running synchronously, `#[AsMessage]`, `retry_strategy` defaults (milliseconds), lost messages without a `failure_transport`, `UnrecoverableMessageHandlingException` vs `RecoverableMessageHandlingException` (which ignores `max_retries`), `HandlerFailedException` around sync handling, multiple handlers on retry, `redeliver_timeout` and `--keepalive`, messages as DTOs rather than entities, `DeduplicateStamp` (7.3+) and its lock store, and the in-memory transport with `?serialize=true`.

## Install

### Claude Code plugin

```
/plugin marketplace add gkwelding/php-unit-tests-skills
/plugin install php-queue-review-skills@blackpug
```

Commands become `/php-queue-review-skills:review-background-jobs <target>` and `/php-queue-review-skills:write-background-job <description>`.

### Copy into a project or user skills folder

```
cp -r skills/review-background-jobs skills/write-background-job ~/.claude/skills/
# or per project:
cp -r skills/* .claude/skills/
```

### claude.ai

Build the packages, then upload `dist/review-background-jobs.skill` and `dist/write-background-job.skill` (Settings → Capabilities → Skills):

```
sh scripts/build-skills.sh
```

The script packages the committed files at `HEAD`; commit edits first.

## Usage

```
/review-background-jobs app/Jobs/ChargeSubscription.php
/review-background-jobs src/MessageHandler/SyncOrderToCrmHandler.php
/review-background-jobs config/packages/messenger.yaml
/review-background-jobs          # no target: queue-related files changed on this branch
/write-background-job send the invoice PDF after the invoice is finalised
```

## Ground Rules the Skills Enforce

- Every finding has a concrete failure scenario; no generic "consider idempotency" advice
- Only APIs that exist in the installed version, checked against `composer.lock` and `vendor/`
- Config and worker commands are read before anything is said about retries or timeouts
- Dispatch sites are read, not just the job, because transaction bugs live in the caller
- The review skill is read-only; the write skill asks before changing connection- or transport-wide settings

## Layout

```
skills/
├── review-background-jobs/
│   ├── SKILL.md
│   └── rules/
│       ├── general/        # delivery and idempotency, verifying fixes
│       ├── laravel/        # transactions, retries/timeouts, uniqueness/middleware, payloads/batches/chains
│       └── symfony/        # transactions, retries/failures/routing, messages/deduplication
└── write-background-job/
    ├── SKILL.md
    └── rules/              # copy of the above, CI-checked identical
scripts/build-skills.sh     # packages dist/*.skill for claude.ai
evals/                      # with/without-skill evals: run.sh, score.php, tasks/ (fixtures, answer keys, hidden tests)
```

`rules/` exists in both skills so each can be installed alone. CI (`.github/workflows/check-rules.yml`) fails if the copies differ. Check locally with:

```
diff -r skills/review-background-jobs/rules skills/write-background-job/rules
```

## Status

First version. Every class, method, property, config key and default the rules name was checked against the installed sources of Laravel 10.50, 11.57, 12.69 and 13.34, Horizon 5.50, Symfony Messenger and FrameworkBundle 6.4, 7.2, 7.4, 8.0 and 8.1, DoctrineBundle 3.3 and Doctrine ORM 3.7; version differences are noted where they exist. Treat it as a strong starting point and adjust the rules to your own house style.

## Evals

`evals/run.sh` runs each task with and without the skills and scores it objectively. Review tasks are Laravel and Symfony fixture apps with planted reliability bugs and decoys (code that looks risky but is fine); a blind matcher scores each report's recall, decoys flagged and per-class recall against an answer key, with an empty and an all-decoys control report to show the scores can fail. The write task asks for a job with stated reliability requirements and scores it with a hidden PHPUnit suite. Cost, turns and minutes come from `claude -p`. See [evals/README.md](evals/README.md) for what is measured, how to run it, and the first result.

## Licence

MIT. See [LICENSE](LICENSE).
