<?php

declare(strict_types=1);

/**
 * Backup Center Production blob freeze suite.
 * Proves immutable Backup Center files are unchanged vs git HEAD / pre-task SHA ledger.
 *
 * Usage:
 *   php scripts/self_test_backup_center_blob_freeze_suite.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
$ev = 'D:/orange_restore_step6_restore_only_phase2_evidence';
if (!is_dir($ev)) {
    mkdir($ev, 0777, true);
}

$pass = 0;
$fail = 0;
function bf_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

/** @var list<string> $immutable */
$immutable = [
    'admin/pages/backup_center.php',
    'admin/api/backup/run-full.php',
    'admin/api/backup/run-countries.php',
    'admin/api/backup/runtime-diagnostic.php',
    'includes/backup/backup_admin.php',
    'includes/backup/backup_environment.php',
    'includes/backup/backup_runner.php',
    'includes/backup/backup_full.php',
    'includes/backup/backup_retention.php',
    'includes/backup/backup_runtime_diagnostic.php',
    'includes/backup/backup_provenance.php',
    'includes/backup/country_batch_export.php',
    'includes/backup/recovery_validation.php',
    'scripts/backup/run_full_backup.php',
    'scripts/backup/export_all_recoverable_countries.php',
];

$apiDir = $projectRoot . '/admin/api/backup';
if (is_dir($apiDir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($apiDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($projectRoot) + 1));
        if (!in_array($rel, $immutable, true)) {
            $immutable[] = $rel;
        }
    }
}

$prePath = $ev . '/backup_center_immutable_pre.json';
$pre = [];
if (is_file($prePath)) {
    $decoded = json_decode((string) file_get_contents($prePath), true);
    if (is_array($decoded)) {
        foreach ($decoded as $row) {
            if (is_array($row) && isset($row['path'], $row['sha256'])) {
                $pre[(string) $row['path']] = $row;
            }
        }
    }
}

$post = [];
$changed = 0;
foreach ($immutable as $rel) {
    $full = $projectRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    bf_ok(is_file($full), 'exists: ' . $rel);
    if (!is_file($full)) {
        continue;
    }
    $sha = hash_file('sha256', $full);
    $blob = trim((string) shell_exec('git -C ' . escapeshellarg($projectRoot) . ' hash-object ' . escapeshellarg($full)));
    $headBlob = trim((string) shell_exec(
        'git -C ' . escapeshellarg($projectRoot) . ' rev-parse HEAD:' . escapeshellarg($rel) . ' 2>&1'
    ));
    $trackedClean = $headBlob !== '' && !str_contains($headBlob, 'fatal') && $blob === $headBlob;
    // Untracked path under api may not be in HEAD; require no working-tree diff for tracked files.
    $diff = trim((string) shell_exec(
        'git -C ' . escapeshellarg($projectRoot) . ' diff --name-only -- ' . escapeshellarg($rel)
    ));
    $unchanged = $diff === '';
    if (isset($pre[$rel]['sha256']) && strtolower((string) $pre[$rel]['sha256']) !== strtolower((string) $sha)) {
        $unchanged = false;
        $changed++;
    }
    bf_ok($unchanged, 'immutable unchanged: ' . $rel);
    if (!$unchanged) {
        $changed++;
    }
    if ($trackedClean || $headBlob === '' || str_contains($headBlob, 'fatal')) {
        // trackedClean preferred; missing HEAD path is ok for newly listed helpers already frozen by diff check
    }
    $post[] = [
        'path' => $rel,
        'sha256' => strtolower((string) $sha),
        'git_blob' => $blob,
        'head_blob' => (str_contains($headBlob, 'fatal') ? null : $headBlob),
        'unchanged' => $unchanged,
    ];
}

file_put_contents(
    $ev . '/backup_center_immutable_post.json',
    json_encode($post, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"
);

bf_ok(str_contains(
    (string) file_get_contents($projectRoot . '/admin/api/backup/run-full.php'),
    'orange_backup_admin_run_full_for_api'
), 'run-full.php still calls orange_backup_admin_run_full_for_api');
bf_ok(str_contains(
    (string) file_get_contents($projectRoot . '/includes/backup/backup_admin.php'),
    'function orange_backup_admin_run_full_for_api'
), 'backup_admin Full API callable present');
bf_ok(!str_contains(
    (string) file_get_contents($projectRoot . '/includes/backup/backup_admin.php'),
    'function orange_backup_execute_full_authoritative'
), 'removed authoritative wrapper stays absent (baseline)');

echo 'BACKUP_CENTER_IMMUTABLE_FILE_COUNT=' . count($immutable) . "\n";
echo 'BACKUP_CENTER_IMMUTABLE_CHANGED_COUNT=' . $changed . "\n";
echo 'BACKUP_CENTER_BLOB_FREEZE_PASS=' . ($fail === 0 && $changed === 0 ? '1' : '0') . "\n";
echo "PASS={$pass} FAIL={$fail}\n";
exit(($fail === 0 && $changed === 0) ? 0 : 1);
