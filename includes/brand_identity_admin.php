<?php

declare(strict_types=1);

/**
 * M05-local Brand Identity admin helpers.
 * Does not call orange_catalog_ensure_schema().
 * Does not stamp live Rev6 or open the dormant DB router.
 */

require_once __DIR__ . '/brand_identity_runtime.php';

/**
 * @return list<string>
 */
function orange_brand_identity_visual_review_required_keys(): array
{
    return [
        'storefront:desktop',
        'storefront:mobile',
        'admin:desktop',
        'admin:mobile',
        'login:desktop',
        'login:mobile',
    ];
}

/**
 * @return array<string, int>
 */
function orange_brand_identity_preview_viewport_widths(): array
{
    return [
        'desktop' => 1280,
        'mobile' => 390,
    ];
}

/**
 * @return list<string>
 */
function orange_brand_identity_preview_surfaces(): array
{
    return ['storefront', 'admin', 'login'];
}

function orange_brand_identity_api_permission_page(): string
{
    return 'brand_identity';
}

function orange_brand_identity_visual_review_dir(): string
{
    return orange_brand_identity_drafts_dir() . DIRECTORY_SEPARATOR . 'reviews';
}

function orange_brand_identity_visual_review_path(int $releaseId): string
{
    return orange_brand_identity_visual_review_legacy_path($releaseId);
}

function orange_brand_identity_visual_review_legacy_path(int $releaseId): string
{
    return orange_brand_identity_visual_review_dir() . DIRECTORY_SEPARATOR . $releaseId . '.json';
}

/**
 * @param array<string, mixed> $release
 */
function orange_brand_identity_visual_review_bind_key(array $release): string
{
    return hash('sha256', implode("\n", [
        (string) ($release['id'] ?? ''),
        (string) ($release['identity_version_id'] ?? ''),
        (string) ($release['created_at'] ?? ''),
        (string) ($release['note'] ?? ''),
    ]));
}

function orange_brand_identity_visual_review_bound_path(int $releaseId, string $bindKey): string
{
    return orange_brand_identity_visual_review_dir()
        . DIRECTORY_SEPARATOR
        . $releaseId . '.' . substr($bindKey, 0, 16) . '.json';
}

/**
 * @return array{release_id:int,token:string,reviews:array<string, mixed>,bind_key:string}
 */
function orange_brand_identity_visual_review_empty(int $releaseId): array
{
    return [
        'release_id' => $releaseId,
        'token' => '',
        'reviews' => [],
        'bind_key' => '',
    ];
}

/**
 * @param array<string, mixed> $raw
 * @return array{release_id:int,token:string,reviews:array<string, mixed>,bind_key:string}
 */
function orange_brand_identity_visual_review_normalize(int $releaseId, array $raw): array
{
    return [
        'release_id' => $releaseId,
        'token' => (string) ($raw['token'] ?? ''),
        'reviews' => is_array($raw['reviews'] ?? null) ? $raw['reviews'] : [],
        'bind_key' => (string) ($raw['bind_key'] ?? ''),
    ];
}

/**
 * Load a review only when it is bound to the actual Control release row.
 * Legacy drafts/reviews/{id}.json without bind_key is preserved on disk and ignored.
 *
 * @return array{release_id:int,token:string,reviews:array<string, mixed>,bind_key:string}
 */
function orange_brand_identity_visual_review_load(int $releaseId, ?PDO $controlPdo = null): array
{
    $empty = orange_brand_identity_visual_review_empty($releaseId);
    if (!($controlPdo instanceof PDO) || $releaseId <= 0) {
        return $empty;
    }
    try {
        $release = orange_brand_identity_release($controlPdo, $releaseId);
    } catch (Throwable $e) {
        return $empty;
    }
    $bind = orange_brand_identity_visual_review_bind_key($release);
    $candidates = [
        orange_brand_identity_visual_review_bound_path($releaseId, $bind),
        orange_brand_identity_visual_review_legacy_path($releaseId),
    ];
    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            continue;
        }
        $stored = (string) ($raw['bind_key'] ?? '');
        if ($stored !== '' && hash_equals($bind, $stored)) {
            return orange_brand_identity_visual_review_normalize($releaseId, $raw);
        }
    }

    return $empty;
}

/**
 * Writes only the namespaced bound path. Never overwrites sealed {id}.json history.
 *
 * @param array<string, mixed> $record
 */
function orange_brand_identity_visual_review_save(int $releaseId, array $record, PDO $controlPdo): void
{
    $release = orange_brand_identity_release($controlPdo, $releaseId);
    $bind = orange_brand_identity_visual_review_bind_key($release);
    $record['release_id'] = $releaseId;
    $record['bind_key'] = $bind;
    orange_brand_identity_ensure_dir(orange_brand_identity_visual_review_dir());
    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('BRAND_IDENTITY_REVIEW_STORE_FAILED');
    }
    $path = orange_brand_identity_visual_review_bound_path($releaseId, $bind);
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException('BRAND_IDENTITY_REVIEW_STORE_FAILED');
    }
}

function orange_brand_identity_visual_review_ensure_token(int $releaseId, PDO $controlPdo): string
{
    $record = orange_brand_identity_visual_review_load($releaseId, $controlPdo);
    if ($record['token'] === '') {
        $record['token'] = bin2hex(random_bytes(16));
        orange_brand_identity_visual_review_save($releaseId, $record, $controlPdo);
    }

    return $record['token'];
}

/**
 * @param array<string, mixed> $record
 */
function orange_brand_identity_visual_review_is_complete(array $record): bool
{
    if (trim((string) ($record['bind_key'] ?? '')) === '') {
        return false;
    }
    $reviews = is_array($record['reviews'] ?? null) ? $record['reviews'] : [];
    foreach (orange_brand_identity_visual_review_required_keys() as $key) {
        if (empty($reviews[$key]['ok'])) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string, mixed> $measured
 * @return array<string, mixed>
 */
function orange_brand_identity_visual_review_record(
    int $releaseId,
    string $surface,
    string $viewport,
    array $measured,
    int $actorId,
    PDO $controlPdo
): array {
    $surfaces = orange_brand_identity_preview_surfaces();
    $viewports = orange_brand_identity_preview_viewport_widths();
    if (!in_array($surface, $surfaces, true) || !isset($viewports[$viewport])) {
        throw new RuntimeException('BRAND_IDENTITY_REVIEW_SURFACE_INVALID');
    }
    $key = $surface . ':' . $viewport;
    $record = orange_brand_identity_visual_review_load($releaseId, $controlPdo);
    if ($record['token'] === '') {
        $record['token'] = bin2hex(random_bytes(16));
    }
    $record['reviews'][$key] = [
        'ok' => true,
        'actor_id' => $actorId,
        'at' => orange_brand_identity_now(),
        'measured' => $measured,
    ];
    orange_brand_identity_visual_review_save($releaseId, $record, $controlPdo);

    return orange_brand_identity_visual_review_load($releaseId, $controlPdo);
}

/**
 * @param array<string, int|string> $incoming
 * @param array<string, int> $currentActive
 * @return array<string, int>
 */
function orange_brand_identity_merge_slot_version_ids(array $incoming, array $currentActive): array
{
    $norm = [];
    foreach (orange_brand_identity_slot_codes() as $code) {
        $id = (int) ($incoming[$code] ?? 0);
        if ($id <= 0) {
            $id = (int) ($currentActive[$code] ?? 0);
        }
        if ($id <= 0) {
            throw new RuntimeException('BRAND_IDENTITY_RELEASE_INCOMPLETE');
        }
        $norm[$code] = $id;
    }

    return $norm;
}

/**
 * @return array<string, int>
 */
function orange_brand_identity_current_slot_id_map(PDO $controlPdo): array
{
    $cur = orange_brand_identity_current_release($controlPdo);
    $map = [];
    if ($cur === null) {
        return $map;
    }
    foreach (orange_brand_identity_release_slots($controlPdo, (int) $cur['id']) as $row) {
        $map[(string) $row['slot_code']] = (int) $row['slot_version_id'];
    }

    return $map;
}

function orange_brand_identity_object_is_current_active(PDO $controlPdo, string $sha256): bool
{
    foreach (orange_brand_identity_slot_codes() as $code) {
        $sv = orange_brand_identity_current_slot_version($controlPdo, $code);
        if (!is_array($sv)) {
            continue;
        }
        $cur = trim((string) ($sv['derivative_object_id'] ?? ''));
        if ($cur === '') {
            $cur = trim((string) ($sv['original_object_id'] ?? ''));
        }
        if ($cur !== '' && hash_equals($cur, $sha256)) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<array<string, mixed>>
 */
function orange_brand_identity_slot_version_archive(PDO $controlPdo, string $slotCode): array
{
    orange_brand_identity_assert_slot_code($slotCode);
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $st = $pdo->prepare(
        'SELECT id, slot_code, original_object_id, derivative_object_id, state, change_note,
                created_at, previewed_at, activated_at, archived_at
         FROM orange_brand_slot_versions
         WHERE slot_code = ?
         ORDER BY id DESC
         LIMIT 40'
    );
    $st->execute([$slotCode]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string, mixed>>
 */
function orange_brand_identity_audit_recent(PDO $controlPdo, int $limit = 40): array
{
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $limit = max(1, min(80, $limit));
    $st = $pdo->query(
        'SELECT id, event_type, actor_id, created_at, entity_table, entity_id, release_id,
                slot_code, identity_version_id, slot_version_id, before_id, after_id, change_note
         FROM orange_brand_identity_audit_events
         ORDER BY id DESC
         LIMIT ' . $limit
    );

    return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

/**
 * @return array<string, mixed>
 */
function orange_brand_identity_admin_snapshot(PDO $controlPdo): array
{
    $cur = orange_brand_identity_current_release($controlPdo);
    $slots = [];
    $draftIds = orange_brand_identity_current_slot_id_map($controlPdo);
    $archives = [];
    foreach (orange_brand_identity_slot_codes() as $code) {
        $sv = $cur ? orange_brand_identity_current_slot_version($controlPdo, $code) : null;
        $url = '';
        $obj = null;
        $original = null;
        $originalUrl = '';
        if (is_array($sv)) {
            $sha = trim((string) ($sv['derivative_object_id'] ?? ''));
            if ($sha === '') {
                $sha = trim((string) ($sv['original_object_id'] ?? ''));
            }
            $obj = $sha !== '' ? orange_brand_identity_object($controlPdo, $sha) : null;
            $url = $sha !== '' ? orange_brand_identity_runtime_object_public_url($sha) : '';
            $origSha = trim((string) ($sv['original_object_id'] ?? ''));
            if ($origSha !== '') {
                $original = orange_brand_identity_object($controlPdo, $origSha);
                $originalUrl = orange_brand_identity_runtime_object_public_url($origSha);
            }
        }
        $slots[$code] = [
            'slot_version' => $sv,
            'object' => $obj,
            'url' => $url,
            'original_object' => $original,
            'original_url' => $originalUrl,
            'is_current' => is_array($sv) && (int) ($sv['id'] ?? 0) === (int) ($draftIds[$code] ?? 0),
            'width' => is_array($obj) ? (int) ($obj['width'] ?? 0) : 0,
            'height' => is_array($obj) ? (int) ($obj['height'] ?? 0) : 0,
            'aspect_ratio' => is_array($obj) ? (float) ($obj['aspect_ratio'] ?? 0) : 0.0,
        ];
        $archives[$code] = orange_brand_identity_slot_version_archive($controlPdo, $code);
    }
    $identity = $cur ? orange_brand_identity_current_identity_version($controlPdo) : null;
    $translations = [];
    if (is_array($identity)) {
        $translations = orange_brand_identity_identity_translations($controlPdo, (int) $identity['id']);
    }
    $hist = $controlPdo->query(
        'SELECT id, identity_version_id, state, note, current_flag, created_at, activated_at, archived_at
         FROM orange_brand_releases ORDER BY id DESC LIMIT 40'
    );
    $previewRid = 0;
    $previewSt = $controlPdo->query(
        "SELECT id FROM orange_brand_releases WHERE state = 'PREVIEW_READY' ORDER BY id DESC LIMIT 1"
    );
    if ($previewSt) {
        $previewRid = (int) $previewSt->fetchColumn();
    }
    $review = $previewRid > 0
        ? orange_brand_identity_visual_review_load($previewRid, $controlPdo)
        : orange_brand_identity_visual_review_empty(0);

    return [
        'current_release' => $cur,
        'identity' => $identity,
        'slots' => $slots,
        'slot_archives' => $archives,
        'translations' => $translations,
        'history' => $hist ? $hist->fetchAll(PDO::FETCH_ASSOC) : [],
        'audit' => orange_brand_identity_audit_recent($controlPdo),
        'slot_codes' => orange_brand_identity_slot_codes(),
        'locales' => orange_brand_identity_approved_locales($controlPdo),
        'draft_slot_version_ids' => $draftIds,
        'preview_release_id' => $previewRid,
        'visual_review' => $review,
        'visual_review_complete' => orange_brand_identity_visual_review_is_complete($review),
        'visual_review_required' => orange_brand_identity_visual_review_required_keys(),
        'control_available' => true,
        'permission_page' => orange_brand_identity_api_permission_page(),
        'permission_resource' => 'settings',
    ];
}

/**
 * M05-local admin gate: session + brand_identity/settings page caps.
 * Does not call orange_catalog_ensure_schema() or require_admin_api().
 *
 * @return array<string, mixed>
 */
function orange_brand_identity_require_admin(string $action = 'view'): array
{
    if (!function_exists('current_admin') || current_admin() === null) {
        if (function_exists('json_response')) {
            json_response(['success' => false, 'code' => 'unauthorized', 'message' => 'غير مصرح'], 401);
        }
        http_response_code(401);
        exit;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM admins WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([(int) ($_SESSION['admin_id'] ?? 0)]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($admin)) {
        if (function_exists('json_response')) {
            json_response(['success' => false, 'code' => 'unauthorized', 'message' => 'غير مصرح'], 401);
        }
        http_response_code(401);
        exit;
    }
    require_once __DIR__ . '/admin_permissions.php';
    if (function_exists('orange_admin_sync_session_country_lock')) {
        orange_admin_sync_session_country_lock($admin);
    }
    $GLOBALS['orange_admin_active_record'] = $admin;
    if (!orange_admin_may_page($admin, $pdo, orange_brand_identity_api_permission_page(), $action)) {
        if (function_exists('json_response')) {
            json_response([
                'success' => false,
                'code' => 'forbidden_brand_identity',
                'message' => 'لا تملك صلاحية هوية العلامة',
            ], 403);
        }
        http_response_code(403);
        exit;
    }
    $rest = __DIR__ . '/backup/restore/restore_maintenance_enforcement.php';
    if (is_file($rest)) {
        require_once $rest;
        if (function_exists('orange_restore_maint_enforcement_http_guard')) {
            orange_restore_maint_enforcement_http_guard(['is_admin' => true]);
        }
    }

    return $admin;
}

function orange_brand_identity_preview_token_ok(int $releaseId, string $token): bool
{
    if ($releaseId <= 0 || $token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
        return false;
    }
    $pdo = orange_brand_identity_runtime_control_pdo();
    if (!($pdo instanceof PDO)) {
        return false;
    }
    $record = orange_brand_identity_visual_review_load($releaseId, $pdo);
    $stored = (string) ($record['token'] ?? '');

    return $stored !== '' && hash_equals($stored, $token);
}
