<?php

/**
 * Issue 1 — the accounting report tree collapses on every fetch.
 *
 * `selectedAccounts` stores the open rows together with a snapshot of their
 * children, so a refetch that leaves rows open keeps showing the previous
 * numbers. The reset therefore has to live in the fetch itself: it covers
 * a filter change and a delete refetch alike.
 *
 * Source-inspection only — no DB, no Yii, same style as the rest of the suite.
 *
 * Run:  php frontend/modules/hq/tests/AccountingReportTreeTest.php
 * Exits non-zero when a check fails.
 */

$failures = 0;
$checks = 0;

function ok($condition, $label)
{
    global $failures, $checks;
    $checks++;

    if (!$condition) {
        $failures++;
        echo "  FAIL  $label\n";
    }
}

$repo = dirname(__DIR__, 6);
$file = $repo . '/frontend/hq/src/pages/accounting-data/report.tsx';

ok(file_exists($file), 'the accounting report page exists');

$source = file_exists($file) ? file_get_contents($file) : '';

$start = strpos($source, 'const fetchAccountingDataReport = async');
$end = strpos($source, 'const handleDelete');

ok($start !== false && $end !== false && $end > $start, 'the fetch function can be located in the page');

$fetch = $start !== false && $end !== false && $end > $start ? substr($source, $start, $end - $start) : '';

ok($fetch !== '', 'the fetch function body was extracted');

$setData = strpos($fetch, 'setAccountingData(res?.data)');
$reset = strpos($fetch, 'setSelectedAccounts({})');

ok($reset !== false, 'the fetch resets the open rows');
ok($setData !== false, 'the fetch stores the new data');
ok($setData < $reset, 'rows collapse after the new data lands, not before');
ok(
    substr_count($source, 'setSelectedAccounts({})') === 1,
    'the reset happens in exactly one place (the fetch)'
);

echo "\n$checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
