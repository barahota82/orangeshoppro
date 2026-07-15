<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

backup_admin_api_require_get();

try {
    $admin = backup_admin_api_admin();
    $pdo = backup_admin_api_pdo();
    orange_backup_admin_require_view($admin, $pdo);

    $projectRoot = backup_admin_api_project_root();
    $ctx = orange_backup_admin_context_for_view($projectRoot);
    $backupRoot = $ctx['backup_root'];

    $action = trim((string) ($_GET['action'] ?? 'locks'));

    if ($action === 'locks') {
        json_response([
            'success' => true,
            'full_lock' => orange_backup_admin_full_lock_status($backupRoot),
            'country_lock' => orange_backup_admin_country_lock_status($backupRoot),
        ]);
    }

    if ($action === 'view_file') {
        $packageType = trim((string) ($_GET['package_type'] ?? ''));
        $packageId = trim((string) ($_GET['package_id'] ?? ''));
        $countryCode = trim((string) ($_GET['country_code'] ?? ''));
        $fileName = trim((string) ($_GET['file'] ?? ''));

        if ($packageType === 'full_disaster') {
            $packagePath = orange_backup_admin_resolve_full_package_path($backupRoot, $packageId);
        } elseif ($packageType === 'country_recovery') {
            $packagePath = orange_backup_admin_resolve_country_package_path($backupRoot, $countryCode, $packageId);
        } else {
            json_response(['success' => false, 'message' => 'نوع الحزمة غير مدعوم'], 422);
        }

        $fileResult = orange_backup_admin_read_package_file($packagePath, $fileName);
        json_response([
            'success' => $fileResult['ok'],
            'file' => $fileName,
            'data' => $fileResult['data'],
            'raw_text' => $fileResult['raw_text'],
            'errors' => $fileResult['errors'],
        ]);
    }

    if ($action === 'log_tail') {
        $logName = basename(trim((string) ($_GET['log'] ?? '')));
        if ($logName === '' || $logName !== trim((string) ($_GET['log'] ?? ''))) {
            json_response(['success' => false, 'message' => 'اسم السجل غير صالح'], 422);
        }
        $logsDir = orange_backup_path_inside_root($backupRoot, 'logs');
        $logPath = $logsDir . DIRECTORY_SEPARATOR . $logName;
        $resolved = realpath($logPath);
        $resolvedLogs = realpath($logsDir);
        if ($resolved === false || $resolvedLogs === false || !str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $resolvedLogs))) {
            json_response(['success' => false, 'message' => 'السجل غير موجود'], 404);
        }
        $discoveredLogs = orange_backup_admin_list_logs($backupRoot, 200);
        $allowedLogNames = array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $discoveredLogs);
        if (!in_array($logName, $allowedLogNames, true)) {
            json_response(['success' => false, 'message' => 'السجل غير موجود'], 404);
        }
        $maxBytes = 65536;
        $size = filesize($resolved) ?: 0;
        $content = '';
        if ($size > 0) {
            $handle = fopen($resolved, 'rb');
            if ($handle !== false) {
                if ($size > $maxBytes) {
                    fseek($handle, -$maxBytes, SEEK_END);
                }
                $content = (string) stream_get_contents($handle);
                fclose($handle);
            }
        }
        json_response([
            'success' => true,
            'log' => $logName,
            'size_bytes' => $size,
            'tail' => orange_backup_admin_redact_text($content),
        ]);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, backup_admin_api_safe_message($e));
}
