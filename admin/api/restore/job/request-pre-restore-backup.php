<?php

declare(strict_types=1);

/**
 * Restore Center Step 6 — mandatory pre-restore Full Backup.
 *
 * Authorized caller of the shared Full Backup service
 * (orange_backup_execute_full_authoritative), same engine path as Backup Center.
 * Does not schedule a Step-6 orchestrator worker / launch.cmd.
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once dirname(__DIR__, 4) . '/includes/backup/restore/restore_pre_restore_backup.php';

restore_admin_api_require_post();

try {
    $admin = restore_admin_api_admin();
    $pdo = restore_admin_api_pdo();
    orange_restore_admin_require_view($admin, $pdo);

    $data = restore_admin_api_json_body();
    restore_admin_api_require_csrf($data);

    $jobId = trim((string) ($data['job_id'] ?? $data['id'] ?? ''));
    if ($jobId === '') {
        json_response(['success' => false, 'code' => 'invalid_job_id', 'message' => 'Invalid restore job id.'], 422);
    }

    $projectRoot = restore_admin_api_project_root();
    $ctx = orange_restore_admin_context($projectRoot);
    $result = orange_restore_admin_fw_execute_pre_restore_backup(
        $projectRoot,
        (string) $ctx['backup_root'],
        (string) $ctx['work_root'],
        $admin,
        $pdo,
        $jobId
    );

    $ok = !empty($result['ok']) || !empty($result['success']);
    json_response([
        'success' => $ok,
        'read_only_execution' => false,
        'execution_started' => false,
        'scheduled' => false,
        'detached' => false,
        'cli_needed' => false,
        'cli_command' => '',
        'operator_action_required' => false,
        'shared_full_backup_service' => 'orange_backup_execute_full_authoritative',
        'idempotent' => (bool) ($result['idempotent'] ?? false),
        'rollback_package_id' => (string) ($result['rollback_package_id'] ?? ''),
        'job' => $result['job'] ?? null,
        'record' => $result['record'] ?? null,
        'csrf_token' => orange_backup_admin_csrf_token(),
        'message' => (string) ($result['message'] ?? ($ok
            ? 'اكتملت النسخة الاحتياطية الإلزامية قبل الاسترداد.'
            : 'تعذر إكمال النسخة الاحتياطية الإلزامية قبل الاسترداد.')),
        'warning' => 'لن يبدأ الاسترداد قبل إنشاء نسخة Full احتياطية موثقة ومثبتة ضد الحذف.',
        'code' => (string) ($result['code'] ?? ($ok ? 'pre_restore_backup_ready' : 'pre_restore_backup_failed')),
    ], $ok ? 200 : 409);
} catch (Throwable $e) {
    $code = trim($e->getMessage());
    $status = 422;
    if ($code === 'country_production_restore_not_enabled') {
        $status = 403;
    }
    $safe = orange_restore_admin_safe_message($e);
    if ($code === 'restore_job_cancelled') {
        $safe = 'لا يمكن تنفيذ النسخة الاحتياطية لمهمة استرداد ملغاة.';
    } elseif ($code === 'restore_job_completed') {
        $safe = 'لا يمكن تنفيذ النسخة الاحتياطية لمهمة استرداد مكتملة.';
    } elseif ($code === 'pre_restore_backup_lock_active') {
        $safe = 'محرك النسخ الاحتياطي مشغول حالياً بقفل مشترك. انتظر انتهاء النسخة الجارية ثم أعد المحاولة.';
    }
    json_response([
        'success' => false,
        'code' => $code !== '' ? $code : 'pre_restore_backup_request_failed',
        'message' => $safe,
        'execution_started' => false,
        'scheduled' => false,
        'detached' => false,
        'cli_needed' => false,
        'cli_command' => '',
        'csrf_token' => orange_backup_admin_csrf_token(),
    ], $status);
}
