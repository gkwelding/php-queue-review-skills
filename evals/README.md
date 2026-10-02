# Evals

Checks whether the skills find more real queue bugs, and fewer false ones, than a plain prompt, and whether `write-background-job` produces a job that meets stated reliability requirements. Every score is objective: planted bugs found, decoys flagged, hidden tests passed.

`run.sh` scaffolds a fresh Laravel or Symfony app, adds a task's files, and runs `claude -p` twice:

| Variant | Review prompt | Write prompt |
|---|---|---|
| `without` | "Review the background jobs in `<target>` for reliability bugs: read the jobs and handlers, where they are dispatched, and the queue configuration and worker commands. Report each finding with file:line, the failure scenario, severity and the fix. Don't change any files." | The task description, plus "Follow the project's conventions, lint what you write and run the existing tests." |
| `with` | `/review-background-jobs <target>` | `/write-background-job <task description>` |

`with` copies this repo's `skills/` into the app's `.claude/skills/`. Both variants get the same tools: review runs may only `Read`, `Glob`, `Grep` and read-only `git`; write runs may also edit files and run `php`, PHPUnit, Pint, PHPStan and `composer dump-autoload`.

## Review tasks

Each fixture app has planted bugs, one per class of bug the skill covers, and **decoys**: code that looks like the same bug but is fine, so a reviewer that flags every risky-looking pattern is caught out. The planted code is written for the fixture, not copied from the rule files' examples.

| Task | Planted bugs | Decoys |
|---|---|---|
| `laravel-review` (Laravel 13) | dispatch inside `DB::transaction()` without after-commit; loyalty points incremented before a CRM call, with `$tries = 4`; `$timeout = 300` on a connection with `retry_after` 180; `SerializesModels` on a cart that checkout deletes before the delayed job runs; `ShouldBeUnique` without `uniqueId()`; `RateLimited('mailchimp')` when only `mailchimp-api` is registered | a `ShouldQueueAfterCommit` job dispatched in the same transaction; a refund job with retries that is guarded and uses a stable idempotency key; `$timeout = 900` on a connection with `retry_after` 1000; `SerializesModels` with `$deleteWhenMissingModels`; a unique job with `uniqueId()` and `uniqueFor`; a `RateLimited` job whose limiter exists; an observer that dispatches with `ShouldHandleEventsAfterCommit` |
| `symfony-review` (Symfony 8.1, ORM 3.7) | dispatch to a Redis transport inside `wrapInTransaction()`; a refund handler with no idempotency key or already-refunded check; a permanent error (`UnknownSkuException`) rethrown as `RecoverableMessageHandlingException`; an entity in a message; no `failure_transport` | a dispatch inside the transaction to the Doctrine transport on the ORM's connection (the send joins the transaction); a dispatch after the transaction; an ERP handler that is guarded and upserts by a stable external id |

`tasks/<task>/answer-key.json` lists each item: `id`, `kind` (`bug` or `decoy`), `class`, `file`, `lines`, the `claim` a report would make, and for decoys `why_fine`. Only `files/` is copied into the app; the answer key never is.

### Scoring

For each report, one tool-less `claude -p --json-schema` call (the matcher) gets the report and every answer-key item's file, lines and claim, in random order. It is not told which variant wrote the report or which items are bugs, and answers for each item whether the report makes that claim, with a quote. `score.php` turns that into:

| Column | Meaning |
|---|---|
| `recall`, `found`, `bugs` | Planted bugs the report claims, out of the planted total |
| `decoy_hits`, `decoys` | Decoys the report flags as problems. These are definite false positives |
| `other_findings` | Problems reported that match no item. Not scored: some are real (the fixtures are not bug-free beyond what was planted), some are noise. Read them in the report |
| `files_changed` | Files the run changed in the app. Should be 0 for a review |
| `cost_usd`, `turns`, `minutes` | From `claude -p --output-format json` |

`classes.csv` has per-class counts (found / planted), and `run.sh` prints per-class recall by variant at the end.

### Proving the scores can fail

Every review task also runs the matcher on two control reports built from the answer key: `control-empty` ("No issues found") and `control-decoys` (one High finding per decoy, quoting its claim at its file:line). They must score recall 0 with no decoy hits, and recall 0 with every decoy hit. If they don't, the matcher is wrong and the run's scores can't be trusted.

## Write task

`laravel-write` asks for `App\Jobs\ChargeOrder`, dispatched from a service that creates the order inside `DB::transaction()` (`tasks/laravel-write/prompt.txt`). The prompt states outcomes, not mechanisms: never charge twice even if retried or dispatched twice; never charge a rolled-back or deleted order and don't fail the job when it's missing; declined cards are final; outages are retried with backoff for up to an hour.

After the run, `hidden/tests/Eval/ChargeOrderTest.php` is copied in and run (`PAO_DISABLE=1`, JUnit XML). Its 8 tests use a fake gateway that honours idempotency keys as the gateway's interface promises, `withFakeQueueInteractions()` for release/fail, and `Queue::fake()` for after-commit dispatch. `hidden_passed` / `hidden_tests` are the score.

The hidden suite was checked both ways: `tasks/laravel-write/reference/` (a correct job and dispatch site) passes 8/8, and a naive job (no guard, `Str::uuid()` key, plain `ShouldQueue`) passes 2/8. To recheck after editing the tests, copy `reference/.` over `evals/.work/laravel` after a run and rerun `PAO_DISABLE=1 php vendor/bin/phpunit tests/Eval` there.

## Running

Needs `composer`, `git`, `php` (with `pdo_sqlite` for the write task) and the `claude` CLI.

```
evals/run.sh                  # all three tasks
evals/run.sh review           # both review tasks
evals/run.sh symfony          # tasks for one framework
evals/run.sh laravel-write    # one task
MODEL=claude-sonnet-5-5 BUDGET_USD=3 evals/run.sh review
```

The first run scaffolds the apps into `evals/.work/` (gitignored) and tags the skeleton; every run resets to that tag and copies the task's files in, so fixture edits apply without rebuilding. Delete `evals/.work/<framework>` to pick up newer framework releases. Each run writes `results.csv`, `classes.csv`, every report, matcher prompt and verdict, diffs and PHPUnit logs to `evals/.work/results/<timestamp>/`.

**Cost:** 6 variant runs for `all`, each capped by `BUDGET_USD` (default 5), plus 8 matcher calls (2 reports and 2 controls per review task) capped by `MATCH_BUDGET_USD` (default 1). In the first run a review variant cost about $0.70 and a matcher call $0.04 to $0.19.

**Noise:** one run per variant is one sample. Run each a few times before trusting a difference, and compare like with like (same model, same framework versions).

### Windows

Run it from Git Bash. The script handles:

- Git Bash rewriting `/review-background-jobs ...` into a file path when it is passed to a native exe: `claude` runs with `MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'`.
- A native Windows `php` that can't open MSYS paths such as `/tmp/...`: php is given paths relative to the directory it runs in.
- `laravel/pao` in Laravel 11+ skeletons, which switches PHPUnit to JSON output when it detects an agent: hidden tests run with `PAO_DISABLE=1`.

To test the plumbing without API spend, put a stub `claude` first on `PATH`. Add the directory as `$(cygpath -u 'C:\path\to\stub')`, because a `C:/...` entry breaks `PATH` at the colon, and check `command -v claude` resolves to the stub before running.

Laravel skeletons ship a `CLAUDE.md` / `AGENTS.md` for Laravel Boost, and your user-level Claude Code plugins and skills load in every run. Both apply to the two variants equally.

## First result (one sample)

Symfony review only, `BUDGET_USD=3`, default model, 2026-10-02:

| variant | recall | decoy hits | other findings | files changed | cost | turns | minutes |
|---|---|---|---|---|---|---|---|
| `without` | 5/5 | 0/3 | 7 | 0 | $0.71 | 33 | 3.6 |
| `with` | 5/5 | 0/3 | 1 | 0 | $0.70 | 11 | 3.7 |
| `control-empty` | 0/5 | 0/3 | 0 | | | | |
| `control-decoys` | 0/5 | 3/3 | 0 | | | | |

Both variants found every planted bug and flagged no decoy, so on this fixture recall can't tell them apart: the bugs are within reach of a plain prompt. The visible difference is focus. The skill's report had 6 findings (the 5 planted bugs plus a low-severity lost dispatch when Redis is down) in 11 turns. The plain prompt had 11 findings in 33 turns: the 5 bugs plus 6 more on Redis consumer names, outbox retry defaults, refund validation and worker flags. Some of those are real, but they aren't scored. The controls scored as expected, so the matcher is not just saying yes. The run cost $1.77 in all: $1.41 for the two variants and $0.36 for four matcher calls ($0.04 to $0.19 each). Harder fixtures, the Laravel task and repeated runs are the next step before reading anything into a difference.
