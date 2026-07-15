<?php

declare(strict_types=1);

/**
 * TEMPORARY — Super-Admin-only writable probe for BackupRoot (remove after investigation).
 * Does not change production writable checks or invoke backup generation.
 */

require_once __DIR__ . '/_bootstrap.php';

backup_admin_api_require_get();

try {
    $admin = backup_admin_api_admin();
    $pdo = backup_admin_api_pdo();

    if (!orange_admin_is_superuser($admin)) {
        json_response(['success' => false, 'message' => 'Forbidden'], 403);
    }

    $projectRoot = backup_admin_api_project_root();
    $backupRoot = '';

    try {
        $viewRoot = orange_backup_admin_resolve_root_for_view($projectRoot);
        $backupRoot = (string) ($viewRoot['backup_root'] ?? '');
    } catch (Throwable $e) {
        $backupRoot = '';
    }

    $backupRootIsDir = $backupRoot !== '' && is_dir($backupRoot);
    $isReadable = $backupRootIsDir && is_readable($backupRoot);
    $isWritable = $backupRootIsDir && is_writable($backupRoot);

    $probeCreate = false;
    $probeDelete = false;
    $probePath = null;

    if ($backupRootIsDir) {
        $locksDir = rtrim($backupRoot, '\\/') . DIRECTORY_SEPARATOR . 'locks';
        if (!is_dir($locksDir)) {
            @mkdir($locksDir, 0775, true);
        }
        if (is_dir($locksDir)) {
            $probePath = $locksDir . DIRECTORY_SEPARATOR . '.orange_writable_probe_' . bin2hex(random_bytes(8)) . '.tmp';
            $written = @file_put_contents($probePath, 'probe');
            $probeCreate = $written !== false && is_file($probePath);
            if ($probeCreate) {
                $probeDelete = @unlink($probePath);
                if (!$probeDelete && is_file($probePath)) {
                    @unlink($probePath);
                    $probeDelete = !is_file($probePath);
                }
            }
        }
    }

    $actualWritable = $probeCreate && $probeDelete;
    $isWritableFalseNegative = !$isWritable && $actualWritable;

    $appPoolId = trim((string) ($_SERVER['APP_POOL_ID'] ?? getenv('APP_POOL_ID') ?: ''));

    json_response([
        'success' => true,
        'temporary' => true,
        'diagnostic' => 'backup_root_writable_probe',
        'sapi' => PHP_SAPI,
        'app_pool_id' => $appPoolId !== '' ? $appPoolId : null,
        'backup_root_is_dir' => $backupRootIsDir,
        'is_readable' => $isReadable,
        'is_writable' => $isWritable,
        'probe_create' => $probeCreate,
        'probe_delete' => $probeDelete,
        'actual_writable' => $actualWritable,
        'is_writable_false_negative' => $isWritableFalseNegative,
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, backup_admin_api_safe_message($e));
}
