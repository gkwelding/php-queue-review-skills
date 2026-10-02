<?php

// Scoring helpers for evals/run.sh. Paths are relative to the current directory or Windows-style,
// so this also works with a native Windows php.
//
//   php score.php schema                              JSON schema for the matcher's structured output
//   php score.php prompt <answer-key> <report>        matcher prompt: the report and every answer-key item,
//                                                     in random order, without saying which are bugs or decoys
//   php score.php controls <answer-key> <prefix>      writes <prefix>-control-empty.txt and <prefix>-control-decoys.txt
//   php score.php row <answer-key> <match.json> <classes.csv> <task> <variant>
//                                                     prints recall,found,bugs,decoy_hits,decoys,other_findings
//                                                     and appends one row per bug class to classes.csv
//   php score.php junit <junit.xml>                   prints hidden_passed,hidden_tests
//   php score.php claude <claude.json>                prints cost_usd,turns,minutes
//   php score.php summary <classes.csv>               per-class recall by variant

[, $command] = $argv + [1 => ''];
$key = fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['items'];
$json = fn (string $path): array => json_decode((string) @file_get_contents($path), true) ?? [];

switch ($command) {
    case 'schema':
        echo json_encode([
            'type' => 'object',
            'properties' => [
                'items' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'claimed' => ['type' => 'boolean'],
                        'quote' => ['type' => 'string'],
                    ],
                    'required' => ['id', 'claimed', 'quote'],
                ]],
                'other_findings' => ['type' => 'integer'],
            ],
            'required' => ['items', 'other_findings'],
        ]), "\n";
        break;

    case 'prompt':
        $items = array_map(fn (array $i) => [
            'id' => $i['id'], 'file' => $i['file'], 'lines' => implode('-', $i['lines']), 'claim' => $i['claim'],
        ], $key($argv[2]));
        shuffle($items);
        $itemsJson = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $report = trim((string) file_get_contents($argv[3]));
        echo <<<PROMPT
        You are scoring a code review report of a PHP application's background jobs against a list of items. Each item is a location in the reviewed code and a claim about it. Some claims are true and some are false; that is not your concern. For each item, decide only whether the REPORT makes that claim.

        claimed = true when the report presents that problem as an issue to fix (any severity), at that location or unmistakably about the same code and the same failure. A finding that cites a nearby line or the other end of the same mechanism (the dispatch site instead of the job class, or the reverse) still counts.
        claimed = false when the report does not mention it, mentions it only as fine or checked, or raises a different problem about the same file.

        For each item give the shortest quote from the report that makes the claim (empty when claimed is false).
        Then set other_findings to the number of distinct problems the report presents as issues to fix that match none of the items.

        <items>
        {$itemsJson}
        </items>

        <report>
        {$report}
        </report>

        PROMPT;
        break;

    case 'controls':
        file_put_contents("{$argv[3]}-control-empty.txt", "## Background job review\n\nNo issues found.\n");
        $decoys = array_values(array_filter($key($argv[2]), fn (array $i) => $i['kind'] === 'decoy'));
        $report = "## Background job review\n";
        foreach ($decoys as $n => $i) {
            $report .= sprintf("\n### %d. [High] %s\n- **Where:** %s:%d\n- **Scenario:** %s\n- **Fix:** change it.\n",
                $n + 1, strtok($i['claim'], '.'), $i['file'], $i['lines'][0], $i['claim']);
        }
        file_put_contents("{$argv[3]}-control-decoys.txt", $report);
        break;

    case 'row':
        [, , $keyPath, $matchPath, $classesPath, $task, $variant] = $argv;
        $match = $json($matchPath)['structured_output'] ?? null;
        if ($match === null) {
            echo ",,,,,\n"; // matcher failed: leave the scores blank rather than scoring zero
            break;
        }
        $claimed = [];
        foreach ($match['items'] ?? [] as $m) {
            $claimed[$m['id']] = (bool) $m['claimed'];
        }
        $counts = ['bug' => [0, 0], 'decoy' => [0, 0]];
        $classes = [];
        foreach ($key($keyPath) as $i) {
            $hit = (int) ($claimed[$i['id']] ?? false);
            $counts[$i['kind']][0] += $hit;
            $counts[$i['kind']][1]++;
            if ($i['kind'] === 'bug') {
                $classes[$i['class']] = [($classes[$i['class']][0] ?? 0) + 1, ($classes[$i['class']][1] ?? 0) + $hit];
            }
        }
        $out = fopen($classesPath, 'a');
        foreach ($classes as $class => [$planted, $found]) {
            fputcsv($out, [$task, $variant, $class, $planted, $found], escape: '');
        }
        [$found, $bugs] = $counts['bug'];
        echo implode(',', [round($found / max($bugs, 1), 2), $found, $bugs, ...$counts['decoy'], (int) ($match['other_findings'] ?? 0)]), "\n";
        break;

    case 'junit':
        $suite = is_file($argv[2]) ? @simplexml_load_file($argv[2])?->testsuite : null;
        if (! $suite) {
            echo ",\n";
            break;
        }
        $tests = (int) $suite['tests'];
        echo $tests - (int) $suite['failures'] - (int) $suite['errors'] - (int) $suite['skipped'], ",$tests\n";
        break;

    case 'claude':
        $run = $json($argv[2]);
        echo isset($run['total_cost_usd']) ? round($run['total_cost_usd'], 2) : '', ',',
            $run['num_turns'] ?? '', ',',
            isset($run['duration_ms']) ? round($run['duration_ms'] / 60000, 1) : '', "\n";
        break;

    case 'summary':
        $rows = array_map(fn ($l) => str_getcsv($l, escape: ''), array_slice(file($argv[2], FILE_IGNORE_NEW_LINES), 1));
        $tally = [];
        foreach ($rows as [, $variant, $class, $planted, $found]) {
            $tally[$class][$variant] = [($tally[$class][$variant][0] ?? 0) + $planted, ($tally[$class][$variant][1] ?? 0) + $found];
        }
        $variants = array_values(array_unique(array_column($rows, 1)));
        printf("%-32s%s\n", 'class (found/planted)', implode('', array_map(fn ($v) => sprintf('%16s', $v), $variants)));
        foreach ($tally as $class => $byVariant) {
            printf("%-32s%s\n", $class, implode('', array_map(
                fn ($v) => sprintf('%16s', isset($byVariant[$v]) ? "{$byVariant[$v][1]}/{$byVariant[$v][0]}" : '-'),
                $variants,
            )));
        }
        break;

    default:
        fwrite(STDERR, "unknown command: {$command}\n");
        exit(1);
}
