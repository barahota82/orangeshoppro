<?php

declare(strict_types=1);

/**
 * Restore Center Step 6 — mandatory pre-restore Full Backup.
 *
 * PHASE 1 FREEZE (Owner 2026-08-11) — REMOVE IN PHASE 2:
 * Fail-closed: do not invoke any Backup engine / worker / CLI / package bind / state advance.
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

    // PHASE 1 FREEZE — REMOVE IN PHASE 2 (after live Backup Center validation + Step 6 adapter rebuild).
    // No Full Backup invoke, no worker schedule, no detached path, no package bind, no Restore state advance.
    json_response([
        'success' => false,
        'code' => 'step6_temporarily_frozen',
        'read_only_execution' => false,
        'execution_started' => false,
        'scheduled' => false,
        'detached' => false,
        'cli_needed' => false,
        'cli_command' => '',
        'operator_action_required' => false,
        'idempotent' => false,
        'rollback_package_id' => '',
        'job' => null,
        'record' => null,
        'csrf_token' => orange_backup_admin_csrf_token(),
        'message' => 'تم إيقاف تنفيذ الخطوة 6 مؤقتًا حتى اكتمال التحقق الحي من استقرار مركز النسخ الاحتياطي. لم يبدأ أي استرداد.',
        'warning' => 'تم إيقاف تنفيذ الخطوة 6 مؤقتًا حتى اكتمال التحقق الحي من استقرار مركز النسخ الاحتياطي. لم يبدأ أي استرداد.',
        'phase1_freeze' => true,
    ], 409);
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
    } elseif (str_starts_with($code, 'illegal_framework_status_transition:') || $code === 'retry_state_conflict') {
        $safe = 'تعذر بدء إعادة المحاولة لأن حالة المهمة الحالية تتعارض مع بدء تنفيذ جديد. حدّث الحالة ثم أعد المحاولة من نفس الخطوة.';
        $code = 'retry_state_conflict';
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
