<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../includes/backup/backup_qualification.php';

backup_admin_api_require_post();

try {
    $admin = backup_admin_api_admin();
    $pdo = backup_admin_api_pdo();
    orange_backup_admin_require_verify($admin, $pdo);

    $data = backup_admin_api_json_body();
    backup_admin_api_require_csrf($data);

    $packageType = trim((string) ($data['package_type'] ?? ''));
    $packageId = trim((string) ($data['package_id'] ?? ''));
    $countryCode = trim((string) ($data['country_code'] ?? ''));

    $projectRoot = backup_admin_api_project_root();
    $startedAt = gmdate('c');
    $blockMessage = orange_backup_admin_manual_actions_block_message($projectRoot);
    if ($blockMessage !== null) {
        orange_backup_admin_audit(
            'recovery_validation',
            $packageType !== '' ? $packageType : 'unknown',
            $packageId,
            $startedAt,
            gmdate('c'),
            false,
            $blockMessage
        );
        json_response(['success' => false, 'message' => $blockMessage], 422);
    }

    // DRV writes sibling reports — requires writable BackupRoot.
    $ctx = orange_backup_admin_context_for_mutation($projectRoot);
    $backupRoot = (string) $ctx['backup_root'];
    if ($packageType === 'full_disaster') {
        $packagePath = orange_backup_admin_resolve_full_package_path($backupRoot, $packageId);
    } elseif ($packageType === 'country_recovery') {
        orange_backup_admin_assert_country_package_in_context($pdo, $countryCode);
        $packagePath = orange_backup_admin_resolve_country_package_path($backupRoot, $countryCode, $packageId);
    } else {
        json_response(['success' => false, 'message' => 'نوع الحزمة غير مدعوم'], 422);
    }

    $run = orange_backup_qualification_endpoint_drv(
        $backupRoot,
        $packageType,
        $packagePath,
        $packageId,
        $countryCode
    );

    if (!empty($run['in_progress'])) {
        $qualification = orange_backup_qualification_public_status(
            $backupRoot,
            $packageType,
            $packageId,
            $countryCode,
            $admin,
            $pdo
        );
        json_response([
            'success' => false,
            'code' => 'qualification_in_progress',
            'message' => (string) ($run['message'] ?? 'فحص قابلية الاسترداد قيد التنفيذ حالياً.'),
            'in_progress' => true,
            'qualification' => !empty($qualification['ok']) ? [
                'package' => $qualification['package'],
                'verify' => $qualification['verify'],
                'drv' => $qualification['drv'],
            ] : null,
        ], 409);
    }

    $ok = (bool) ($run['success'] ?? false);
    $result = is_array($run['result'] ?? null) ? $run['result'] : [];
    $qualification = orange_backup_qualification_public_status(
        $backupRoot,
        $packageType,
        $packageId,
        $countryCode,
        $admin,
        $pdo
    );
    $safeResult = orange_backup_admin_redact_secrets([
        'overall_result' => $result['overall_result'] ?? 'fail',
        'recovery_score' => $result['recovery_score'] ?? 0,
        'errors' => $result['errors'] ?? [],
        'warnings' => $result['warnings'] ?? [],
        'from_saved_report' => (bool) ($result['from_saved_report'] ?? false),
    ]);
    unset($safeResult['report_path']);
    json_response([
        'success' => $ok,
        'message' => (string) ($run['message'] ?? ($ok ? 'اجتازت الحزمة فحص قابلية الاسترداد.' : 'فشل فحص قابلية الاسترداد.')),
        'short_circuited' => (bool) ($run['short_circuited'] ?? false),
        'heavy_executed' => (bool) ($run['heavy_executed'] ?? false),
        'result' => $safeResult,
        'qualification' => !empty($qualification['ok']) ? [
            'package' => $qualification['package'],
            'verify' => $qualification['verify'],
            'drv' => $qualification['drv'],
        ] : null,
    ], $ok ? 200 : 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, backup_admin_api_safe_message($e));
}
