<?php
declare(strict_types=1);

/**
 * GAP-09 — scan FROM/JOIN journal_vouchers and accounts; classify country scope risk.
 * Usage: php scripts/gap09_gl_country_audit.php
 */

$root = dirname(__DIR__);
$patterns = [
    '/\b(FROM|JOIN)\s+journal_vouchers\b/i',
    '/\b(FROM|JOIN)\s+accounts\b/i',
];

$okSignals = [
    'country_id',
    'orange_gl_voucher_country_bind',
    'orange_accounts_fetch',
    'orange_accounts_sql_country_filter',
    'orange_accounts_filter',
    'orange_accounts_count_posting_leaves',
    'orange_accounts_posting_leaf',
    'orange_gl_pending_row_visible_for_country',
    'orange_fy_pl_summary',
    'orange_accounts_fy_pl_summary',
    'orange_voucher_by_reference',
    'orange_gl_voucher_next_id_preview',
    'ctxCountryId',
    'glCountryId',
    '$countryId',
    'WHERE id =',
    'WHERE a.id =',
    'WHERE jv.id =',
    'fiscal_year',
    'orange_fiscal_',
    'per country',
    'scoped',
];

$maintScopedSignals = [
    '@country_id',
    '@cid',
    '@has_acct_country',
    '@has_jv_country',
    'country_id = ',
    'jv.country_id',
    'a.country_id = c.id',
    'c.country_id = a.country_id',
    'post-v52',
    'per country',
    'SIGNAL SQLSTATE',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$detail = [];
$byFile = [];

foreach ($iterator as $file) {
    $path = $file->getPathname();
    $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
    if (!preg_match('#\.(php|sql)$#', $rel)) {
        continue;
    }
    if (preg_match('#^(vendor/|\.git/|\.pl_extract|ref_extract|\.tmp_|_compare_)#', $rel)) {
        continue;
    }
    if ($rel === 'scripts/gap09_gl_country_audit.php') {
        continue;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        continue;
    }

    $fileText = implode("\n", $lines);
    $isMaintPath = (bool) preg_match('#^scripts/(migrations/|maintenance_|mysql-)#', $rel);
    $isMaintScoped = false;
    if ($isMaintPath) {
        foreach ($maintScopedSignals as $sig) {
            if (stripos($fileText, $sig) !== false) {
                $isMaintScoped = true;
                break;
            }
        }
    }

    foreach ($lines as $i => $line) {
        $isJv = (bool) preg_match($patterns[0], $line);
        $isAcc = (bool) preg_match($patterns[1], $line);
        if (!$isJv && !$isAcc) {
            continue;
        }

        $start = max(0, $i - 12);
        $end = min(count($lines) - 1, $i + 12);
        $ctx = implode("\n", array_slice($lines, $start, $end - $start + 1));

        $risk = 'LOW';
        $note = 'Review — entity-scoped or legacy path';

        if ($isMaintPath) {
            if ($isMaintScoped) {
                $risk = 'OK';
                $note = 'MAINT script — country scoped (manual run only)';
            } else {
                $risk = 'MAINT';
                $note = 'MAINT script — review country scope';
            }
        } elseif (preg_match('#includes/countries\.php|includes/country_provision\.php#', $rel)) {
            $risk = 'OK';
            $note = 'Explicit country filter or wrapper';
        } else {
            foreach ($okSignals as $sig) {
                if (stripos($ctx, $sig) !== false) {
                    $risk = 'OK';
                    $note = 'Scoped signal in context window';
                    break;
                }
            }
            if ($risk === 'LOW' && preg_match('/WHERE\s+\w+\.id\s*=\s*\?/i', $ctx)) {
                $risk = 'OK';
                $note = 'BY PK lookup';
            }
            if ($risk === 'LOW' && preg_match('/orange_accounts_[a-z_]+\(\s*\$pdo[^)]*\$[a-zA-Z]*[Cc]ountry/i', $ctx)) {
                $risk = 'OK';
                $note = 'accounts helper with country arg';
            }
        }

        $detail[] = [
            'file' => $rel,
            'line' => $i + 1,
            'table' => $isJv ? 'journal_vouchers' : 'accounts',
            'risk' => $risk,
            'snippet' => trim(preg_replace('/\s+/', ' ', $line)),
        ];

        if (!isset($byFile[$rel])) {
            $byFile[$rel] = ['hits' => 0, 'jv' => 0, 'acc' => 0, 'worst' => 'OK', 'note' => ''];
        }
        $byFile[$rel]['hits']++;
        if ($isJv) {
            $byFile[$rel]['jv']++;
        }
        if ($isAcc) {
            $byFile[$rel]['acc']++;
        }
        $rank = ['OK' => 0, 'LOW' => 1, 'MAINT' => 2, 'HIGH' => 3];
        if ($rank[$risk] > $rank[$byFile[$rel]['worst']]) {
            $byFile[$rel]['worst'] = $risk;
            $byFile[$rel]['note'] = $note;
        }
    }
}

$date = date('Y-m-d');
$exportDir = $root . '/docs/exports';
if (!is_dir($exportDir)) {
    mkdir($exportDir, 0755, true);
}

$detailPath = $exportDir . "/GAP09_GL_country_audit_detail_{$date}.csv";
$byFilePath = $exportDir . "/GAP09_GL_country_audit_by_file_{$date}.csv";
$summaryPath = $exportDir . "/GAP09_GL_country_audit_summary_{$date}.csv";

$fh = fopen($detailPath, 'w');
fputcsv($fh, ['File', 'Line', 'Table', 'Risk', 'Snippet']);
foreach ($detail as $row) {
    fputcsv($fh, [$row['file'], $row['line'], $row['table'], $row['risk'], $row['snippet']]);
}
fclose($fh);

uksort($byFile, 'strcmp');
$fh = fopen($byFilePath, 'w');
fputcsv($fh, ['File', 'Hits', 'JV', 'Acc', 'WorstRisk', 'Note']);
foreach ($byFile as $file => $stats) {
    fputcsv($fh, [$file, $stats['hits'], $stats['jv'], $stats['acc'], $stats['worst'], $stats['note']]);
}
fclose($fh);

$counts = ['OK' => 0, 'LOW' => 0, 'MAINT' => 0, 'HIGH' => 0];
$jvHits = 0;
$accHits = 0;
foreach ($detail as $row) {
    $counts[$row['risk']]++;
    if ($row['table'] === 'journal_vouchers') {
        $jvHits++;
    } else {
        $accHits++;
    }
}

$fh = fopen($summaryPath, 'w');
fputcsv($fh, ['Metric', 'Value']);
fputcsv($fh, ['Scan_date', $date]);
fputcsv($fh, ['Total_SQL_hits', (string) count($detail)]);
fputcsv($fh, ['Unique_files', (string) count($byFile)]);
fputcsv($fh, ['journal_vouchers_hits', (string) $jvHits]);
fputcsv($fh, ['accounts_hits', (string) $accHits]);
foreach ($counts as $k => $v) {
    fputcsv($fh, ["Risk_{$k}", (string) $v]);
}
fclose($fh);

echo "GAP-09 audit {$date}\n";
echo "Hits: " . count($detail) . " | Files: " . count($byFile) . "\n";
echo "OK: {$counts['OK']} LOW: {$counts['LOW']} MAINT: {$counts['MAINT']} HIGH: {$counts['HIGH']}\n";
echo "Written:\n  {$detailPath}\n  {$byFilePath}\n  {$summaryPath}\n";

if ($counts['LOW'] > 0) {
    echo "\nLOW files:\n";
    foreach ($byFile as $file => $stats) {
        if ($stats['worst'] === 'LOW') {
            echo "  {$file} ({$stats['hits']} hits)\n";
        }
    }
}
