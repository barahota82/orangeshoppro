<?php

declare(strict_types=1);

/**
 * FSR D5 — Country SQL chunk grammar / numeric order (FSR-D5-CRP-CHUNK-01).
 *
 * Usage: php scripts/self_test_final_review_d5_country_chunk_grammar.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$mainRoot = dirname(__DIR__);
require_once $mainRoot . '/includes/backup/restore/restore_country_staging.php';

$passes = 0;
$failures = 0;
$started = microtime(true);

function d5chk_assert(bool $ok, string $label): void
{
    global $passes, $failures;
    if ($ok) {
        echo "PASS  {$label}\n";
        $passes++;
    } else {
        echo "FAIL  {$label}\n";
        $failures++;
    }
}

echo 'NOTE  suite=d5_country_chunk_grammar start=' . gmdate('c') . "\n";

$accept = [
    '000_session_preamble.sql',
    '001_table.sql',
    '099_accounts.sql',
    '100_orders.sql',
    '999_session_postamble.sql',
    '1000_table.sql',
    '1050_accounts.sql',
    '1100_products.sql',
    'D:/pkg/sql/1050_accounts.sql',
    'D:\\pkg\\sql\\2100_orders.sql',
];
foreach ($accept as $name) {
    $p = orange_restore_country_staging_parse_sql_chunk($name);
    d5chk_assert(is_array($p), 'accept ' . $name);
    if (is_array($p)) {
        d5chk_assert(isset($p['prefix_int']) && is_int($p['prefix_int']), 'prefix_int int for ' . basename($name));
    }
}

$p1050 = orange_restore_country_staging_parse_sql_chunk('1050_accounts.sql');
d5chk_assert(is_array($p1050) && (int) $p1050['prefix_int'] === 1050, '1050_accounts prefix_int=1050');
d5chk_assert(is_array($p1050) && $p1050['table'] === 'accounts', '1050_accounts table=accounts');

$hist = orange_restore_country_staging_parse_sql_chunk('010_orders.sql');
d5chk_assert(is_array($hist) && (int) $hist['prefix_int'] === 10, 'historical 010_orders still valid');

$reject = [
    '10_table.sql',
    '99_table.sql',
    '+100_table.sql',
    '100.5_table.sql',
    '100 _table.sql',
    '100table.sql',
    '100_.sql',
    '100_table',
    '100_table.SQL',
    '100_table.sql.gz',
    '../001_table.sql',
    '001_../table.sql',
    '001_tab/le.sql',
    '001_tab\\le.sql',
    "100_table\0.sql",
    '100_table;.sql',
    '100_table space.sql',
];
foreach ($reject as $name) {
    d5chk_assert(orange_restore_country_staging_parse_sql_chunk($name) === null, 'reject ' . var_export($name, true));
}

// Numeric order: 1000 must not sort before 950 lexicographically-trap
$names = ['1000_z.sql', '950_a.sql', '999_b.sql', '095_c.sql', '100_d.sql', '1050_e.sql', '1100_f.sql'];
$parsed = [];
foreach ($names as $n) {
    $p = orange_restore_country_staging_parse_sql_chunk($n);
    d5chk_assert(is_array($p), 'boundary parse ' . $n);
    if (is_array($p)) {
        $parsed[] = $p;
    }
}
usort($parsed, static fn (array $a, array $b): int => $a['prefix_int'] <=> $b['prefix_int']);
$order = array_map(static fn (array $p): int => $p['prefix_int'], $parsed);
d5chk_assert($order === [95, 100, 950, 999, 1000, 1050, 1100], 'numeric ascending order across 999/1000');

$lex = $names;
sort($lex, SORT_STRING);
$lexIdx950 = array_search('950_a.sql', $lex, true);
$lexIdx1000 = array_search('1000_z.sql', $lex, true);
d5chk_assert(
    is_int($lexIdx1000) && is_int($lexIdx950) && $lexIdx1000 < $lexIdx950,
    'mutation trap: lexical sort puts 1000 before 950'
);
d5chk_assert($order[0] === 95 && $order[4] === 1000, 'numeric sort places 1000 after 950/999');

$src = (string) file_get_contents($mainRoot . '/includes/backup/restore/restore_country_staging.php');
d5chk_assert(str_contains($src, '\\d{3,}'), 'parser source uses {3,} digit minimum');
d5chk_assert(!preg_match('/\\^\\(\\\\d\\{3\\}\\)_/', $src), 'parser no longer locked to exactly three digits only');

$expSrc = (string) file_get_contents($mainRoot . '/includes/backup/country_export.php');
d5chk_assert(str_contains($expSrc, "sprintf('%03d_%s.sql'"), 'exporter still sprintf %03d (min width 3)');

$dur = round(microtime(true) - $started, 3);
echo "\nPASS={$passes} FAIL={$failures} SKIP=0\n";
echo "DURATION_SEC={$dur}\n";
if ($failures > 0) {
    echo "RESULT=FSR_D5_CHUNK_GRAMMAR_FAIL\n";
    exit(1);
}
echo "RESULT=FSR_D5_CHUNK_GRAMMAR_OK\n";
exit(0);
