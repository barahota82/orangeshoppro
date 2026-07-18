<?php

declare(strict_types=1);

/**
 * Static fence: Phase-2 production cutover symbols must not be called outside tests/tombstones.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
$passes = 0;
$failures = 0;
function pf2_test(bool $c, string $l): void
{
    global $passes, $failures;
    if ($c) {
        echo "PASS: {$l}\n";
        $passes++;
    } else {
        echo "FAIL: {$l}\n";
        $failures++;
    }
}

echo "=== self_test_phase2_callsite_fence ===\n";

$forbidden = [
    'orange_restore_orchestrator_database_cutover',
    'orange_restore_e2e_start_full',
];
// Only scan operator-facing surfaces. Phase-2 libraries may call each other internally
// but must not be reached from admin/api/scripts entrypoints (tombstones already fence scripts).
$scanPrefixes = ['admin/', 'api/', 'scripts/backup/', 'pages/'];
$allowPathHints = [
    'self_test_',
    'restore_full_database_cutover.php',
    'restore_full_uploads_cutover.php',
    'restore_full_rollback.php',
    'restore_run_full.php',
    'restore_resume_full.php',
    'restore_approve_merge.php',
    'restore_full_post_validate.php',
    'restore_full_post_validate_finalize.php',
    'RESTORE_PHASE2',
    'ENTERPRISE_FINAL_AUDIT',
    'phase2_callsite_fence',
];

$violations = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $rel = str_replace('\\', '/', substr($path, strlen($projectRoot) + 1));
    if (str_contains($rel, '/vendor/') || str_starts_with($rel, 'vendor/')) {
        continue;
    }
    $inScan = false;
    foreach ($scanPrefixes as $prefix) {
        if (str_starts_with($rel, $prefix)) {
            $inScan = true;
            break;
        }
    }
    if (!$inScan) {
        continue;
    }
    $allowed = false;
    foreach ($allowPathHints as $hint) {
        if (str_contains($rel, $hint)) {
            $allowed = true;
            break;
        }
    }
    if ($allowed) {
        continue;
    }
    $src = (string) @file_get_contents($path);
    foreach ($forbidden as $fn) {
        // Match call sites only — not `function name(` definitions.
        if (preg_match('/(?<!function\s)\b' . preg_quote($fn, '/') . '\s*\(/', $src) === 1) {
            $violations[] = $rel . ' calls ' . $fn;
        }
    }
}

pf2_test($violations === [], 'no Phase-2 cutover call sites outside allowlist');
if ($violations !== []) {
    foreach (array_slice($violations, 0, 20) as $v) {
        echo "VIOLATION: {$v}\n";
    }
}

echo "\nRESULT: {$passes} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
