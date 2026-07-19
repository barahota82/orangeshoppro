<?php

declare(strict_types=1);

/**
 * Phase C6 — Country Shadow Restore status (GET only).
 * No execution, no production restore.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../includes/backup/restore/restore_country_shadow.php';

restore_admin_api_require_get();

try {
    $admin = restore_admin_api_admin();
    $pdo = restore_admin_api_pdo();
    orange_restore_admin_require_view($admin, $pdo);

    if (!orange_restore_admin_may_view_country($admin, $pdo)) {
        json_response(['success' => false, 'code' => 'forbidden', 'message' => 'Country restore view not permitted.'], 403);
    }

    $runId = trim((string) ($_GET['run_id'] ?? $_GET['id'] ?? ''));
    if ($runId === '') {
        json_response(['success' => false, 'code' => 'invalid_run_id', 'message' => 'run_id required.'], 422);
    }

    $projectRoot = restore_admin_api_project_root();
    $ctx = orange_restore_admin_context($projectRoot);
    $payload = orange_country_shadow_status((string) $ctx['work_root'], $runId);

    json_response([
        'success' => true,
        'read_only' => true,
        'execution_started' => false,
        'production_touched' => false,
        'country_production_restore_enabled' => false,
        'run_id' => $payload['run_id'] ?? $runId,
        'status' => $payload['status'] ?? '',
        'meta' => $payload['meta'] ?? null,
        'report' => $payload['report'] ?? null,
        'warning' => 'Country Shadow Restore status only — production was not modified.',
    ]);
} catch (Throwable $e) {
    $code = trim($e->getMessage());
    if ($code === 'country_shadow_run_not_found' || $code === 'invalid_country_shadow_run_id') {
        json_response(['success' => false, 'code' => $code, 'message' => $code], 404);
    }
    orange_admin_api_catch($e, orange_restore_admin_safe_message($e));
}
