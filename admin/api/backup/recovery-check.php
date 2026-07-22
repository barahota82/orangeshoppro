<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

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

    // DRV writes recovery_validation.json into the package directory — requires writable BackupRoot.
    $ctx = orange_backup_admin_context_for_mutation($projectRoot);
    if ($packageType === 'full_disaster') {
        $packagePath = orange_backup_admin_resolve_full_package_path($ctx['backup_root'], $packageId);
    } elseif ($packageType === 'country_recovery') {
        orange_backup_admin_assert_country_package_in_context($pdo, $countryCode);
        $packagePath = orange_backup_admin_resolve_country_package_path($ctx['backup_root'], $countryCode, $packageId);
    } else {
        json_response(['success' => false, 'message' => 'نوع الحزمة غير مدعوم'], 422);
    }

    $result = orange_backup_admin_recovery_validate($packagePath);
    $finishedAt = gmdate('c');
    $ok = (bool) ($result['ok'] ?? false);

    orange_backup_admin_audit(
        'recovery_validation',
        $packageType,
        $packageType === 'country_recovery' ? strtoupper($countryCode) . '/' . $packageId : $packageId,
        $startedAt,
        $finishedAt,
        $ok,
        $ok ? '' : implode('; ', array_slice($result['errors'] ?? [], 0, 5))
    );

    json_response([
        'success' => $ok,
        'message' => $ok ? 'اجتازت الحزمة فحص قابلية الاسترداد.' : 'فشل فحص قابلية الاسترداد.',
        'result' => orange_backup_admin_redact_secrets([
            'overall_result' => $result['overall_result'] ?? 'fail',
            'recovery_score' => $result['recovery_score'] ?? 0,
            'errors' => $result['errors'] ?? [],
            'warnings' => $result['warnings'] ?? [],
            'report_path' => $result['report_path'] ?? null,
        ]),
    ], $ok ? 200 : 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, backup_admin_api_safe_message($e));
}
