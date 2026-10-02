#!/usr/bin/env bash
# Run each eval task with and without the skills and score the result.
#
#   review tasks: a fixture app with planted reliability bugs and decoys (code that looks risky but is
#                 fine). A tool-less claude -p call, blind to the variant, matches the report against
#                 the task's answer-key.json: recall, decoys flagged, other findings, per-class recall.
#                 Two control reports (empty, and one that flags every decoy) are matched too, to show
#                 the scores can fail.
#   write task:   a job with stated reliability requirements, scored by a hidden PHPUnit suite that is
#                 copied in only after the job is written.
#
# Usage: evals/run.sh [all|review|write|laravel|symfony|<task>]   e.g. evals/run.sh symfony-review
# Env:   MODEL             model for claude -p (default: your claude default)
#        BUDGET_USD        spend cap per variant run (default 5)
#        MATCH_BUDGET_USD  spend cap per matcher call (default 1)
#        WORK              scratch directory for the scaffolded apps (default evals/.work)
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
work=${WORK:-$root/evals/.work}
budget=${BUDGET_USD:-5}
match_budget=${MATCH_BUDGET_USD:-1}
which=${1:-all}
stamp=$(date +%Y%m%d-%H%M%S)
results=$work/results/$stamp
mkdir -p "$results"
score="php $root/evals/score.php"
echo "task,variant,recall,found,bugs,decoy_hits,decoys,other_findings,hidden_passed,hidden_tests,files_changed,cost_usd,turns,minutes" > "$results/results.csv"
echo "task,variant,class,planted,found" > "$results/classes.csv"

tasks=(laravel-review symfony-review laravel-write)

scaffold() {
    local fw=$1 dir=$work/$1
    if [ ! -d "$dir/.git" ]; then
        rm -rf "$dir"
        case $fw in
            # Laravel 13 skeletons ship CLAUDE.md/AGENTS.md telling agents to install Laravel Boost; a run that follows it adds ~75 files and skews the scores.
            laravel) composer create-project -n --quiet laravel/laravel "$dir" && rm -f "$dir/CLAUDE.md" "$dir/AGENTS.md" ;;
            symfony)
                composer create-project -n --quiet symfony/skeleton "$dir"
                (cd "$dir" && composer config extra.symfony.docker false \
                    && composer require -n --quiet symfony/messenger symfony/doctrine-messenger symfony/redis-messenger \
                        doctrine/doctrine-bundle doctrine/orm symfony/lock) ;;
        esac
        (cd "$dir" && git init -q && git config core.longpaths true && git config core.autocrlf false && git add -A \
            && git -c user.name=eval -c user.email=eval@localhost commit -qm skeleton && git tag skeleton)
    fi
}

# Fresh skeleton plus the task's files, committed so the agent sees a clean tree and git status
# afterwards shows exactly what it changed. Delete $work/<framework> to rebuild the skeleton.
reset_app() {
    local dir=$1 task=$2
    (cd "$dir" && git reset -q --hard skeleton && git clean -qfd)
    cp -r "$root/evals/tasks/$task/files/." "$dir/"
    (cd "$dir" && git add -A && git -c user.name=eval -c user.email=eval@localhost commit -qm "$task")
}

# Blind match of one report against the answer key; appends to classes.csv and prints the score columns.
match() {
    local task=$1 variant=$2 report=$3 name=$1-$2
    (cd "$results" && $score prompt "$root/evals/tasks/$task/answer-key.json" "$report" > "$name.match-prompt.txt" \
        && claude -p --tools "" --setting-sources project --output-format json --json-schema "$($score schema)" --max-budget-usd "$match_budget" \
            --no-session-persistence ${MODEL:+--model "$MODEL"} < "$name.match-prompt.txt" > "$name.match.json" 2> "$name.match.err") \
        || echo "   matcher failed, see $results/$name.match.err" >&2
    (cd "$results" && $score row "$root/evals/tasks/$task/answer-key.json" "$name.match.json" classes.csv "$task" "$variant")
}

run_one() {
    local task=$1 variant=$2 fw=${1%%-*} kind=${1#*-} dir=$work/${1%%-*} name=$1-$2 prompt tools mode=default
    local out=../results/$stamp/$name # relative to $dir, so it also works with a native Windows php

    reset_app "$dir" "$task"
    if [ "$variant" = with ]; then
        mkdir -p "$dir/.claude/skills"
        cp -r "$root"/skills/* "$dir/.claude/skills/"
    fi

    if [ "$kind" = review ]; then
        local target
        target=$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["target"];' "$root/evals/tasks/$task/answer-key.json")
        if [ "$variant" = with ]; then
            prompt="/review-background-jobs $target"
        else
            prompt="Review the background jobs in $target for reliability bugs: read the jobs and handlers, where they are dispatched, and the queue configuration and worker commands. Report each finding with file:line, the failure scenario, severity and the fix. Don't change any files."
        fi
        tools="Read,Glob,Grep,Bash(git diff:*),Bash(git status:*),Bash(git log:*)"
    else
        local description
        description=$(cat "$root/evals/tasks/$task/prompt.txt")
        if [ "$variant" = with ]; then
            prompt="/write-background-job $description"
        else
            prompt="$description Follow the project's conventions, lint what you write and run the existing tests."
        fi
        tools="Read,Write,Edit,Glob,Grep,Bash(php:*),Bash(vendor/bin/phpunit:*),Bash(vendor/bin/pint:*),Bash(vendor/bin/phpstan:*),Bash(composer dump-autoload:*),Bash(git diff:*),Bash(git status:*)"
        mode=acceptEdits
    fi

    echo "== $name"
    # Prompt on stdin: as an argument, Git Bash rewrites "/review-background-jobs" into a file path, and MSYS_NO_PATHCONV
    # would leak into Claude's own shell. --setting-sources project keeps user plugins and hooks out.
    (cd "$dir" && printf '%s' "$prompt" | claude -p --setting-sources project ${MODEL:+--model "$MODEL"} \
        --max-budget-usd "$budget" --no-session-persistence --permission-mode "$mode" --output-format json \
        --allowedTools "$tools" > "$out.claude.json" 2> "$out.claude.err") || echo "   claude exited non-zero, see $results/$name.claude.err"
    (cd "$dir" && php -r '$j = json_decode((string) @file_get_contents($argv[1]), true); file_put_contents($argv[2], $j["result"] ?? "");' \
        "$out.claude.json" "$out.txt")

    local changed review=",,,,," hidden=","
    changed=$(cd "$dir" && git status --porcelain -- . ':!.claude' | wc -l | tr -d ' ')
    (cd "$dir" && git add -A -- . ':!.claude' && git diff --cached > "$out.diff")

    if [ "$kind" = review ]; then
        review=$(match "$task" "$variant" "$name.txt")
    else
        # The hidden suite goes in after generation, so it can't be read or tuned against.
        # PAO_DISABLE=1: Laravel 11+ skeletons ship laravel/pao, which switches PHPUnit to JSON output
        # when it detects an agent (any run started from Claude Code).
        cp -r "$root/evals/tasks/$task/hidden/." "$dir/"
        (cd "$dir" && PAO_DISABLE=1 php -d auto_prepend_file= vendor/bin/phpunit tests/Eval --log-junit "$out.junit.xml" \
            > "$out.phpunit.txt" 2>&1) || true
        hidden=$(cd "$results" && $score junit "$name.junit.xml")
    fi

    echo "$task,$variant,$review,$hidden,$changed,$(cd "$results" && $score claude "$name.claude.json")" >> "$results/results.csv"
}

for task in "${tasks[@]}"; do
    [ "$which" = all ] || [ "$which" = "$task" ] || [ "$which" = "${task%%-*}" ] || [ "$which" = "${task#*-}" ] || continue
    scaffold "${task%%-*}"
    for variant in without with; do
        run_one "$task" "$variant"
    done
    if [ "${task#*-}" = review ]; then
        # Controls: the same matcher on a report that finds nothing and one that flags every decoy.
        (cd "$results" && $score controls "$root/evals/tasks/$task/answer-key.json" "$task")
        for control in control-empty control-decoys; do
            echo "== $task-$control"
            echo "$task,$control,$(match "$task" "$control" "$task-$control.txt"),,,,,," >> "$results/results.csv"
        done
    fi
done

echo
column -s, -t < "$results/results.csv" 2>/dev/null || cat "$results/results.csv"
echo
(cd "$results" && $score summary classes.csv)
echo
echo "Reports, matcher verdicts, diffs and CSVs: $results"
