<?php

declare(strict_types=1);

/**
 * Stage 4B — read-only batch qualification status transport (server-authoritative).
 * Identity per item: package_type + package_id + country_code (no filesystem paths).
 * Max 5 items. Does not create a new state authority — wraps public_status per item.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../includes/backup/backup_qualification.php';

backup_admin_api_require_get();

try {
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
    }

    $admin = backup_admin_api_admin();
    $pdo = backup_admin_api_pdo();
    orange_backup_admin_require_view($admin, $pdo);

    $raw = trim((string) ($_GET['packages'] ?? ''));
    if ($raw === '') {
        json_response([
            'success' => false,
            'code' => 'empty_batch',
            'message' => 'دفعة الحالات فارغة.',
        ], 422);
    }
    if (strlen($raw) > 8192) {
        json_response([
            'success' => false,
            'code' => 'batch_payload_too_large',
            'message' => 'حمولة الدفعة كبيرة جداً.',
        ], 422);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        json_response([
            'success' => false,
            'code' => 'invalid_batch_json',
            'message' => 'صيغة دفعة الحالات غير صالحة.',
        ], 422);
    }

    // Reject top-level path smuggling.
    if (array_key_exists('package_path', $decoded) || array_key_exists('path', $decoded)
        || array_key_exists('report_path', $decoded)) {
        json_response([
            'success' => false,
            'code' => 'path_not_allowed',
            'message' => 'مسارات الملفات غير مقبولة في طلب الحالة.',
        ], 422);
    }

    /** @var list<array<string, mixed>> $items */
    $items = array_is_list($decoded) ? $decoded : (isset($decoded['packages']) && is_array($decoded['packages'])
        ? $decoded['packages']
        : []);

    $ctx = orange_backup_admin_context_for_view(backup_admin_api_project_root());
    $backupRoot = (string) $ctx['backup_root'];

    $batch = orange_backup_qualification_public_status_batch(
        $backupRoot,
        $items,
        $admin,
        $pdo,
        ORANGE_BACKUP_QUAL_STATUS_BATCH_MAX_ITEMS
    );

    if (empty($batch['ok'])) {
        $code = (string) ($batch['code'] ?? 'batch_failed');
        $http = match ($code) {
            'permission_denied', 'country_scope_denied' => 403,
            'batch_too_large', 'empty_batch', 'unsafe_package_id', 'unsafe_country_code',
            'invalid_batch_item', 'path_not_allowed', 'unsupported_package_type' => 422,
            default => 422,
        };
        json_response([
            'success' => false,
            'code' => $code,
            'message' => (string) ($batch['message'] ?? 'تعذر قراءة دفعة حالات التأهيل.'),
        ], $http);
    }

    json_response([
        'success' => true,
        'results' => $batch['results'] ?? [],
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, backup_admin_api_safe_message($e));
}
