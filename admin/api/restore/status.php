<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

restore_admin_api_require_get();

try {
    $admin = restore_admin_api_admin();
    $pdo = restore_admin_api_pdo();
    orange_restore_admin_require_view($admin, $pdo);

    $mayFull = orange_restore_admin_may_view_full($admin, $pdo);
    $mayCountry = orange_restore_admin_may_view_country($admin, $pdo);
    $projectRoot = restore_admin_api_project_root();
    $ctx = orange_restore_admin_context($projectRoot);
    $backupRoot = $ctx['backup_root'];
    $workRoot = $ctx['work_root'];

    $action = trim((string) ($_GET['action'] ?? ''));

    if ($action === 'job') {
        $jobId = trim((string) ($_GET['job_id'] ?? ''));
        if ($workRoot === '') {
            json_response(['success' => false, 'message' => 'Restore work root unavailable'], 503);
        }
        orange_restore_admin_assert_job_allowlisted($workRoot, $jobId);
        $job = orange_restore_job_read($workRoot, $jobId);
        orange_restore_admin_assert_job_type_visible((string) ($job['job_type'] ?? ''), $mayFull, $mayCountry);
        json_response([
            'success' => true,
            'read_only' => true,
            'job' => orange_restore_admin_job_detail($projectRoot, $workRoot, $jobId),
        ]);
    }

    if ($action === 'view_file') {
        $packageType = trim((string) ($_GET['package_type'] ?? ''));
        $packageId = trim((string) ($_GET['package_id'] ?? ''));
        $countryCode = trim((string) ($_GET['country_code'] ?? ''));
        $fileName = trim((string) ($_GET['file'] ?? ''));

        if ($packageType === 'full_disaster') {
            if (!$mayFull) {
                throw new RuntimeException('Operator lacks backup_restore_full permission.');
            }
            $packagePath = orange_backup_admin_resolve_full_package_path($backupRoot, $packageId);
        } elseif ($packageType === 'country_recovery') {
            if (!$mayCountry) {
                throw new RuntimeException('Operator lacks backup_restore_country permission.');
            }
            $packagePath = orange_backup_admin_resolve_country_package_path($backupRoot, $countryCode, $packageId);
        } else {
            json_response(['success' => false, 'message' => 'نوع الحزمة غير مدعوم'], 422);
        }

        $fileResult = orange_backup_admin_read_package_file($packagePath, $fileName);
        json_response([
            'success' => $fileResult['ok'],
            'read_only' => true,
            'file' => $fileName,
            'data' => $fileResult['data'],
            'raw_text' => $fileResult['raw_text'] !== null ? orange_restore_admin_redact_text((string) $fileResult['raw_text']) : null,
            'errors' => $fileResult['errors'],
        ]);
    }

    if ($action === 'package_detail') {
        $packageType = trim((string) ($_GET['package_type'] ?? ''));
        $packageId = trim((string) ($_GET['package_id'] ?? ''));
        $countryCode = trim((string) ($_GET['country_code'] ?? ''));
        if ($packageType === 'full_disaster' && !$mayFull) {
            throw new RuntimeException('Operator lacks backup_restore_full permission.');
        }
        if ($packageType === 'country_recovery' && !$mayCountry) {
            throw new RuntimeException('Operator lacks backup_restore_country permission.');
        }
        json_response([
            'success' => true,
            'read_only' => true,
            'package' => orange_restore_admin_package_detail($backupRoot, $packageType, $packageId, $countryCode),
        ]);
    }

    if ($action === 'job_file') {
        $jobId = trim((string) ($_GET['job_id'] ?? ''));
        $fileName = trim((string) ($_GET['file'] ?? ''));
        if ($workRoot === '') {
            json_response(['success' => false, 'message' => 'Restore work root unavailable'], 503);
        }
        orange_restore_admin_assert_job_allowlisted($workRoot, $jobId);
        $job = orange_restore_job_read($workRoot, $jobId);
        orange_restore_admin_assert_job_type_visible((string) ($job['job_type'] ?? ''), $mayFull, $mayCountry);
        $fileResult = orange_restore_admin_read_job_file($workRoot, $jobId, $fileName);
        json_response([
            'success' => $fileResult['ok'],
            'read_only' => true,
            'file' => $fileName,
            'data' => $fileResult['data'],
            'raw_text' => $fileResult['raw_text'],
            'errors' => $fileResult['errors'],
        ]);
    }

    if ($action === 'rollback_anchor') {
        $jobId = trim((string) ($_GET['job_id'] ?? ''));
        if ($workRoot === '') {
            json_response(['success' => false, 'message' => 'Restore work root unavailable'], 503);
        }
        orange_restore_admin_assert_job_allowlisted($workRoot, $jobId);
        $job = orange_restore_job_read($workRoot, $jobId);
        orange_restore_admin_assert_job_type_visible((string) ($job['job_type'] ?? ''), $mayFull, $mayCountry);
        json_response([
            'success' => true,
            'read_only' => true,
            'rollback_anchor' => orange_restore_admin_redact_secrets([
                'ref' => orange_restore_admin_public_path((string) ($job['fresh_backup_path'] ?? '')),
                'checksum' => (string) ($job['fresh_backup_checksum'] ?? ''),
                'rollback_anchor_job_only' => (bool) ($job['rollback_anchor_job_only'] ?? true),
                'checkpoint' => (string) ($job['rollback_checkpoint'] ?? ''),
                'recorded_at' => (string) ($job['reauth_verified_at'] ?? ''),
            ]),
        ]);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, orange_restore_admin_safe_message($e));
}
