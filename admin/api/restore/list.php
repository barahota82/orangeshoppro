<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 3) . '/includes/backup/restore/restore_center_orchestrator.php';

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

    require_once dirname(__DIR__, 3) . '/includes/countries.php';
    // Country packages follow Admin Country Context (same rule as Backup Center).
    // Full packages stay global. Uncapped country list so Step 1 can show latest-5 after
    // eligibility/context filtering (not a global limit before filter).
    $countryContextCode = orange_admin_context_country_code($pdo);

    // Refresh-only reconcile (no new Step7 attempt / no cancel / no live mutate beyond nested status).
    try {
        $resumable = orange_restore_fw_find_resumable_job($workRoot);
        if (is_array($resumable)) {
            $rjid = trim((string) ($resumable['job_id'] ?? ''));
            if ($rjid !== '') {
                orange_restore_center_reconcile_stale_shadow_restore_public_state($workRoot, $rjid);
                $rj = orange_restore_fw_read($workRoot, $rjid);
                orange_restore_center_reconcile_run_claim($workRoot, $rjid, 'shadow_db', $rj);
            }
        }
    } catch (Throwable) {
        // Refresh must still return a usable list payload.
    }

    $fullPackages = [];
    if ($mayFull) {
        foreach (orange_backup_admin_list_full_snapshots($backupRoot, 100) as $pkg) {
            $fullPackages[] = orange_restore_admin_public_package_row($pkg, 'full_disaster');
        }
    }

    $countryPackages = [];
    if ($mayCountry) {
        foreach (orange_backup_admin_list_country_packages($pdo, $backupRoot, null, $countryContextCode) as $pkg) {
            $countryPackages[] = orange_restore_admin_public_package_row($pkg, 'country_recovery');
        }
    }

    $overview = [];
    try {
        $overview = orange_restore_admin_collect_overview($workRoot);
    } catch (Throwable) {
        $overview = ['error' => 'overview_unavailable'];
    }

    $fwJobs = [];
    try {
        $fwJobs = orange_restore_admin_fw_list_jobs($workRoot, $mayFull, $mayCountry);
    } catch (Throwable) {
        $fwJobs = [];
    }

    $currentJourney = null;
    try {
        $job = orange_restore_fw_find_resumable_job($workRoot);
        if ($job !== null) {
            $type = (string) ($job['package_type'] ?? '');
            if (!(($type === 'full_disaster' && !$mayFull) || ($type === 'country_recovery' && !$mayCountry))) {
                $currentJourney = orange_restore_fw_public_row($job);
            }
        }
    } catch (Throwable) {
        $currentJourney = null;
    }

    $legacyJobs = [];
    try {
        $legacyJobs = orange_restore_admin_list_jobs($workRoot, $mayFull, $mayCountry);
    } catch (Throwable) {
        $legacyJobs = [];
    }

    $maintenance = [];
    try {
        $maintenance = orange_restore_admin_fw_maintenance_status($workRoot);
    } catch (Throwable) {
        $maintenance = ['available' => false];
    }

    json_response([
        'success' => true,
        'read_only' => true,
        'read_only_execution' => true,
        'refresh_safe' => true,
        'csrf_token' => orange_backup_admin_csrf_token(),
        'permissions' => [
            'can_view_full' => $mayFull,
            'can_view_country' => $mayCountry,
            'is_superuser' => orange_admin_is_superuser($admin),
            'can_create_job' => $mayFull || $mayCountry,
            'can_cancel_job' => $mayFull || $mayCountry,
        ],
        'country_context_code' => orange_countries_display_code($countryContextCode),
        'overview' => $overview,
        'full_packages' => $fullPackages,
        'country_packages' => $countryPackages,
        'framework_jobs' => $fwJobs,
        'jobs' => $fwJobs,
        // Wizard journey job only — terminal/history jobs never selected here (Owner 2026-07-24).
        'current_journey_job' => $currentJourney,
        'legacy_engine_jobs' => $legacyJobs,
        'maintenance' => $maintenance,
    ]);
} catch (Throwable $e) {
    // Refresh must never surface generic unexpected / raw English codes.
    $code = trim($e->getMessage());
    $category = 'refresh_unexpected';
    if (str_contains($code, 'permission') || str_contains($code, 'CSRF') || str_contains($code, 'session')) {
        $category = 'refresh_auth';
    } elseif (str_contains($code, 'work root') || str_contains($code, 'work_root')) {
        $category = 'refresh_work_root';
    } elseif (str_starts_with($code, 'STEP7_') || str_contains($code, 'shadow')) {
        $category = 'refresh_step7_state';
    }
    $safe = orange_restore_admin_safe_message($e);
    if ($safe === '' || $safe === 'حدث خطأ غير متوقع' || $safe === $code) {
        $safe = match ($category) {
            'refresh_auth' => 'تعذر تحديث الحالة بسبب جلسة أو صلاحية. أعد تسجيل الدخول ثم حدّث.',
            'refresh_work_root' => 'تعذر تحديث الحالة لأن مجلد عمل الاسترداد غير متاح.',
            'refresh_step7_state' => 'تعذر مزامنة حالة خطوة استعادة قاعدة الظل. حدّث مرة أخرى دون إلغاء المهمة.',
            default => 'تعذر تحديث حالة مركز الاسترداد. أعد المحاولة دون إلغاء المهمة.',
        };
    }
    json_response([
        'success' => false,
        'code' => 'restore_center_refresh_failed',
        'refresh_error_category' => $category,
        'message' => $safe,
        'read_only' => true,
        'execution_started' => false,
        'csrf_token' => orange_backup_admin_csrf_token(),
    ], 422);
}
