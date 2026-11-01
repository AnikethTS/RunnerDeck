<?php

declare(strict_types=1);

$file = $argv[1] ?? '';
$min = (float) ($argv[2] ?? 0);

$xml = is_file($file) ? simplexml_load_file($file) : false;
if ($xml === false) {
    fwrite(STDERR, "cannot read clover report: {$file}\n");
    exit(2);
}

$metrics = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percent = $statements > 0 ? $covered / $statements * 100 : 0.0;

printf("line coverage %.2f%% (%d/%d), minimum %.2f%%\n", $percent, $covered, $statements, $min);
exit($percent + 0.0001 >= $min ? 0 : 1);
