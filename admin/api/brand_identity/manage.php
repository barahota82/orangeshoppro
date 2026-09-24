<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/brand_identity_admin.php';

/**
 * @return array<string, mixed>
 */
function orange_m05_req(): array
{
    $data = get_json_input();
    if (is_array($data) && $data !== []) {
        return $data;
    }

    return $_POST;
}

function orange_m05_control(): PDO
{
    $pdo = orange_brand_identity_runtime_control_pdo();
    if ($pdo === null) {
        throw new RuntimeException('BRAND_IDENTITY_CONTROL_UNAVAILABLE');
    }

    return $pdo;
}

function orange_m05_perm_action(string $action): string
{
    if (in_array($action, ['current', 'slot_archive', 'audit'], true)) {
        return 'view';
    }

    return 'edit';
}

try {
    $data = orange_m05_req();
    $action = trim((string) ($data['action'] ?? 'current'));
    $admin = orange_brand_identity_require_admin(orange_m05_perm_action($action));
    $actor = (int) ($admin['id'] ?? 0);
    if ($actor <= 0) {
        $actor = 1;
    }

    if ($action === 'current') {
        $control = orange_brand_identity_runtime_control_pdo();
        if ($control === null) {
            json_response([
                'success' => true,
                'control_available' => false,
                'message' => 'Control identity store is not configured on this host.',
                'slots' => [],
                'locales' => [],
                'permission_page' => orange_brand_identity_api_permission_page(),
                'permission_resource' => 'settings',
            ]);
        }
        json_response(['success' => true, 'data' => orange_brand_identity_admin_snapshot($control)]);
    }

    $control = orange_m05_control();

    if ($action === 'upload_slot') {
        $slot = trim((string) ($data['slot_code'] ?? ''));
        orange_brand_identity_assert_slot_code($slot);
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            json_response(['success' => false, 'code' => 'missing_file', 'message' => 'الملف مطلوب'], 422);
        }
        $tmp = (string) ($_FILES['file']['tmp_name'] ?? '');
        $name = (string) ($_FILES['file']['name'] ?? 'upload.bin');
        $uploaded = orange_brand_identity_upload_slot_with_derivative($control, $slot, $tmp, $name, $actor);
        json_response([
            'success' => true,
            'slot_version_id' => $uploaded['slot_version_id'],
            'object' => $uploaded['object'],
            'derivative' => $uploaded['derivative'],
            'data' => orange_brand_identity_admin_snapshot($control),
        ]);
    }

    if ($action === 'create_preview_release') {
        $slotIds = $data['slot_version_ids'] ?? [];
        if (!is_array($slotIds)) {
            $slotIds = [];
        }
        $norm = orange_brand_identity_merge_slot_version_ids(
            $slotIds,
            orange_brand_identity_current_slot_id_map($control)
        );
        $defaultLocale = orange_brand_identity_runtime_normalize_ui_locale((string) ($data['default_locale'] ?? 'en'));
        $trIn = $data['translations'] ?? [];
        $translations = [];
        if (is_array($trIn)) {
            foreach ($trIn as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $translations[] = [
                    'locale' => (string) ($row['locale'] ?? ''),
                    'text_key' => (string) ($row['text_key'] ?? 'STOREFRONT_SLOGAN'),
                    'text_value' => (string) ($row['text_value'] ?? ''),
                ];
            }
        }
        $iid = orange_brand_identity_create_identity_version(
            $control,
            $defaultLocale,
            $translations,
            $actor,
            trim((string) ($data['change_note'] ?? 'm05 preview'))
        );
        orange_brand_identity_transition_identity($control, $iid, 'PREVIEW_READY', $actor);
        foreach ($norm as $sid) {
            $sv = orange_brand_identity_slot_version($control, $sid);
            if ((string) ($sv['state'] ?? '') === 'DRAFT') {
                orange_brand_identity_transition_slot($control, $sid, 'PREVIEW_READY', $actor);
            }
        }
        $rid = orange_brand_identity_create_release($control, $iid, $norm, $actor, 'm05 preview');
        orange_brand_identity_transition_release($control, $rid, 'PREVIEW_READY', $actor);
        $token = orange_brand_identity_visual_review_ensure_token($rid, $control);
        json_response([
            'success' => true,
            'identity_version_id' => $iid,
            'release_id' => $rid,
            'preview_required' => true,
            'preview_token' => $token,
            'visual_review_complete' => false,
            'reused_slot_version_ids' => $norm,
            'data' => orange_brand_identity_admin_snapshot($control),
        ]);
    }

    if ($action === 'record_visual_review') {
        $rid = (int) ($data['release_id'] ?? 0);
        if ($rid <= 0) {
            throw new RuntimeException('BRAND_IDENTITY_RELEASE_NOT_FOUND');
        }
        $rel = orange_brand_identity_release($control, $rid);
        if ((string) ($rel['state'] ?? '') !== 'PREVIEW_READY') {
            throw new RuntimeException('BRAND_IDENTITY_RELEASE_NOT_PREVIEW');
        }
        $measured = is_array($data['measured'] ?? null) ? $data['measured'] : [];
        $record = orange_brand_identity_visual_review_record(
            $rid,
            trim((string) ($data['surface'] ?? '')),
            trim((string) ($data['viewport'] ?? '')),
            $measured,
            $actor,
            $control
        );
        json_response([
            'success' => true,
            'visual_review' => $record,
            'visual_review_complete' => orange_brand_identity_visual_review_is_complete($record),
            'data' => orange_brand_identity_admin_snapshot($control),
        ]);
    }

    if ($action === 'activate') {
        $rid = (int) ($data['release_id'] ?? 0);
        if ($rid <= 0) {
            throw new RuntimeException('BRAND_IDENTITY_RELEASE_NOT_FOUND');
        }
        $review = orange_brand_identity_visual_review_load($rid, $control);
        if (!orange_brand_identity_visual_review_is_complete($review)) {
            json_response([
                'success' => false,
                'code' => 'visual_review_required',
                'message' => 'المراجعة البصرية الفعلية لسطح المكتب والجوال إلزامية قبل التفعيل',
                'visual_review' => $review,
                'visual_review_required' => orange_brand_identity_visual_review_required_keys(),
            ], 422);
        }
        orange_brand_identity_activate_release($control, $rid, $actor);
        json_response(['success' => true, 'data' => orange_brand_identity_admin_snapshot($control)]);
    }

    if ($action === 'rollback') {
        $rid = (int) ($data['historic_release_id'] ?? 0);
        if ($rid <= 0) {
            throw new RuntimeException('BRAND_IDENTITY_RELEASE_NOT_FOUND');
        }
        $newId = orange_brand_identity_rollback_full($control, $rid, $actor);
        json_response(['success' => true, 'new_release_id' => $newId, 'data' => orange_brand_identity_admin_snapshot($control)]);
    }

    if ($action === 'rollback_slot') {
        $slot = trim((string) ($data['slot_code'] ?? ''));
        $hid = (int) ($data['historic_slot_version_id'] ?? 0);
        if ($hid <= 0) {
            throw new RuntimeException('BRAND_IDENTITY_SLOT_NOT_FOUND');
        }
        $newId = orange_brand_identity_rollback_slot($control, $slot, $hid, $actor);
        json_response(['success' => true, 'new_release_id' => $newId, 'data' => orange_brand_identity_admin_snapshot($control)]);
    }

    json_response(['success' => false, 'code' => 'unknown_action', 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تنفيذ هوية العلامة');
}
