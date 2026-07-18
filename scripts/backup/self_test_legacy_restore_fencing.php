<?php

declare(strict_types=1);

/**
 * P0-2 / 3B.4I — Static + runtime fencing for legacy Phase-2 production restore CLIs.
 *
 * Usage:
 *   php scripts/backup/self_test_legacy_restore_fencing.php
 */

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
    . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_cli_policy.php';

$passes = 0;
$failures = 0;

/**
 * @param mixed $cond
 */
function lrf_self_test(bool $cond, string $label): void
{
    global $passes, $failures;
    if ($cond) {
        echo 'PASS: ' . $label . PHP_EOL;
        $passes++;
    } else {
        echo 'FAIL: ' . $label . PHP_EOL;
        $failures++;
    }
}

/**
 * @return array{exit:int, out:string, err:string}
 */
function lrf_run_php(string $script, array $extraArgv = []): array
{
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $cmd = array_merge([$php, $script], $extraArgv);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, dirname($script));
    if (!is_resource($proc)) {
        return ['exit' => 127, 'out' => '', 'err' => 'proc_open_failed'];
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) ?: '';
    $err = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    return ['exit' => (int) $code, 'out' => $out, 'err' => $err];
}

echo "=== self_test_legacy_restore_fencing (P0-2) ===\n";

$tombstones = orange_restore_legacy_production_cli_tombstones();
$approved = orange_restore_approved_production_mutation_cli_workers();
$nonMutation = orange_restore_approved_non_mutation_restore_clis();

lrf_self_test(count($approved) === 4, 'approved production mutation allowlist size=4');
lrf_self_test(count($tombstones) === 8, 'legacy tombstone catalog size=8');
lrf_self_test(
    ORANGE_RESTORE_LEGACY_ENTRYPOINT_DISABLED === 'legacy_restore_entrypoint_disabled',
    'stable disabled reason constant'
);

// Each tombstone exits non-zero with stable reason; no destructive requires.
foreach ($tombstones as $rel) {
    $abs = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    lrf_self_test(is_file($abs), 'tombstone exists: ' . $rel);
    $src = (string) file_get_contents($abs);
    lrf_self_test(
        str_contains($src, 'legacy_restore_entrypoint_disabled')
        && str_contains($src, 'LEGACY_RESTORE_ENTRYPOINT: DISABLED'),
        'tombstone emits stable disabled markers: ' . basename($rel)
    );
    lrf_self_test(
        !preg_match('/str_starts_with\s*\(\s*\$arg\s*,\s*[\'"]--password=/', $src)
        && !str_contains($src, '--password=')
        && !str_contains($src, '--db-password='),
        'tombstone has no argv password parsing: ' . basename($rel)
    );
    lrf_self_test(
        !preg_match('/\b(require|include)(_once)?\b/i', $src)
        && !preg_match('/\bconfig\.php\b/', $src),
        'tombstone rejects before loading destructive modules: ' . basename($rel)
    );
    lrf_self_test(
        !preg_match('/\borange_restore_(orchestrator|e2e|maint_enforcement|merge_|prod_import_|prod_uploads_|prod_rollback_|prod_finalize_)\w*\s*\(/', $src)
        && !preg_match('/\b(mysqli_connect|new\s+PDO|PDO\s*\()\b/', $src)
        && !preg_match('/\b(rename|unlink|rmdir|mkdir)\s*\(/', $src),
        'tombstone has no production mutation / maint calls: ' . basename($rel)
    );

    $run = lrf_run_php($abs, [
        '--job=fake_job',
        '--admin-id=1',
        '--password=should_never_be_accepted',
        '--confirm=RESTORE',
    ]);
    $combined = $run['out'] . $run['err'];
    lrf_self_test($run['exit'] !== 0, 'tombstone exits non-zero: ' . basename($rel));
    lrf_self_test(
        str_contains($combined, 'legacy_restore_entrypoint_disabled'),
        'tombstone stdout/stderr reason code: ' . basename($rel)
    );
    lrf_self_test(
        !str_contains($combined, 'should_never_be_accepted')
        && !str_contains($combined, '--password='),
        'secrets absent from tombstone output: ' . basename($rel)
    );
    lrf_self_test(
        preg_match('/LEGACY_RESTORE_ENTRYPOINT: DISABLED\r?\nREASON: legacy_restore_entrypoint_disabled\r?\nUSE: approved_3b_restore_workflow\r?\n/', $combined) === 1,
        'compact UTF-8 disabled output valid: ' . basename($rel)
    );
}

// Approved 3B workers remain allowlisted and must not be tombstones.
foreach ($approved as $rel) {
    $abs = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    lrf_self_test(is_file($abs), 'approved worker exists: ' . $rel);
    $src = (string) file_get_contents($abs);
    lrf_self_test(
        !str_contains($src, 'LEGACY_RESTORE_ENTRYPOINT: DISABLED'),
        'approved worker not tombstoned: ' . basename($rel)
    );
    lrf_self_test(
        !preg_match('/str_starts_with\s*\(\s*\$arg\s*,\s*[\'"]--password=/', $src)
        && !str_contains($src, '--db-password='),
        'approved worker has no argv password parsing: ' . basename($rel)
    );
    lrf_self_test(
        str_contains($src, 'orange_restore_maint_enforcement_cli_restore_worker')
        || str_contains($src, '--job='),
        'approved worker remains job-scoped: ' . basename($rel)
    );
}

// Static catalog: every scripts/backup/restore_*.php must be classified.
$backupDir = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup';
$classified = array_fill_keys(array_merge($approved, $tombstones, $nonMutation), true);
$unknown = [];
foreach (glob($backupDir . DIRECTORY_SEPARATOR . 'restore_*.php') ?: [] as $path) {
    $base = basename($path);
    $rel = 'scripts/backup/' . $base;
    if ($base === 'restore_self_test_helpers.php') {
        continue;
    }
    if (!isset($classified[$rel])) {
        $unknown[] = $rel;
    }
}
lrf_self_test($unknown === [], 'no unknown restore_*.php outside explicit allowlists'
    . ($unknown !== [] ? (' missing=' . implode(',', $unknown)) : ''));

// Repo-wide: no admin endpoint / scheduled task invokes legacy CLIs.
$adminHits = [];
$adminRoot = $projectRoot . DIRECTORY_SEPARATOR . 'admin';
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($adminRoot, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $fileInfo) {
    if (!$fileInfo->isFile()) {
        continue;
    }
    $ext = strtolower((string) $fileInfo->getExtension());
    if (!in_array($ext, ['php', 'js', 'md', 'txt'], true)) {
        continue;
    }
    $text = (string) @file_get_contents($fileInfo->getPathname());
    foreach ($tombstones as $rel) {
        $name = basename($rel);
        if (str_contains($text, $name)) {
            $adminHits[] = str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $fileInfo->getPathname()) . ':' . $name;
        }
    }
}
lrf_self_test($adminHits === [], 'no admin invocation of legacy CLIs'
    . ($adminHits !== [] ? (' hits=' . implode(',', array_slice($adminHits, 0, 5))) : ''));

$scheduleHits = [];
foreach (['scripts', 'docs'] as $top) {
    $root = $projectRoot . DIRECTORY_SEPARATOR . $top;
    if (!is_dir($root)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        $name = $fileInfo->getFilename();
        if (!preg_match('/\.(ps1|bat|cmd|sh|xml|yml|yaml|json|md|txt)$/i', $name)) {
            continue;
        }
        $text = (string) @file_get_contents($fileInfo->getPathname());
        // Active instruction patterns only (php … legacy script).
        foreach ($tombstones as $rel) {
            $base = basename($rel);
            if (preg_match('/php\s+[^\r\n]*' . preg_quote($base, '/') . '/i', $text)) {
                // Allow audit/remediation docs that say DISABLED / do not run.
                if (preg_match('/DISABLED|tombstone|do not run|legacy_restore_entrypoint_disabled|permanently disabled/i', $text)) {
                    continue;
                }
                $scheduleHits[] = $name . ':' . $base;
            }
        }
    }
}
lrf_self_test($scheduleHits === [], 'no active scheduled/doc command runs legacy CLIs'
    . ($scheduleHits !== [] ? (' hits=' . implode(',', array_slice($scheduleHits, 0, 8))) : ''));

// Active operator docs must not instruct --password= on restore CLIs.
$docPasswordHits = [];
foreach ([
    'docs/backup/RESTORE_PHASE2_CLI_ENTRYPOINTS.md',
    'docs/backup/PRODUCTION_CUTOVER_AND_ROLLBACK_DESIGN.md',
    'docs/backup/ORANGE_DR_OPERATOR_RUNBOOK.md',
    'docs/archive/ORANGE_BACKUP_RECOVERY_RUNBOOK.md',
    'docs/archive/ORANGE_RESTORE_ARCHITECTURE.txt',
] as $docRel) {
    $docPath = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $docRel);
    if (!is_file($docPath)) {
        continue;
    }
    $text = (string) file_get_contents($docPath);
    if (preg_match('/php\s+[^\r\n]*--password=/i', $text)) {
        $docPasswordHits[] = $docRel;
    }
}
lrf_self_test($docPasswordHits === [], 'operator docs have no php --password= restore commands'
    . ($docPasswordHits !== [] ? (' docs=' . implode(',', $docPasswordHits)) : ''));

// Bridge points at approved workers, not legacy cutover.
$bridge = (string) file_get_contents(
    $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
    . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_execution_bridge.php'
);
lrf_self_test(
    str_contains($bridge, 'restore_import_production.php')
    && str_contains($bridge, 'restore_production_cli_policy.php')
    && !str_contains($bridge, "'scripts/backup/restore_run_full.php'"),
    'bridge primary path uses approved 3B import worker'
);

// Library functions retained (orchestrator still exists for 3B/tests).
$orch = $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
    . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_orchestrator.php';
$orchSrc = is_file($orch) ? (string) file_get_contents($orch) : '';
lrf_self_test(
    str_contains($orchSrc, 'function orange_restore_orchestrator_database_cutover')
    && str_contains($orchSrc, 'function orange_restore_orchestrator_uploads_cutover')
    && str_contains($orchSrc, 'function orange_restore_orchestrator_rollback'),
    'underlying orchestrator functions retained'
);

echo "\nRESULT: {$passes} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
