<?php

declare(strict_types=1);

/**
 * Brand Identity admin helpers.
 * Does not call orange_catalog_ensure_schema() and does not create tables on HTTP reads.
 */

require_once __DIR__ . '/brand_identity_runtime.php';

/**
 * Dropdown catalogue rows. Authority is the language reference, not
 * storefront_lang_options(). Storefront-enabled is a badge only.
 * $configuredAdminLocale is presentation-only. Null means unknown; Arabic is not assumed.
 *
 * @return list<array{code:string,label:string,is_english:bool,is_country_admin:bool,is_storefront_enabled:bool,roles:list<string>}>
 */
function orange_brand_identity_slogan_editor_rows(PDO $controlPdo, ?string $configuredAdminLocale = null): array
{
    $ref = orange_brand_identity_language_reference($controlPdo);
    $enabled = [];
    try {
        $enabled = orange_brand_identity_approved_locales($controlPdo);
    } catch (Throwable $e) {
        $enabled = [];
    }
    $admin = $configuredAdminLocale !== null && $configuredAdminLocale !== ''
        ? orange_brand_identity_normalize_locale_code($configuredAdminLocale)
        : null;
    $rows = [];
    foreach ($ref['entries'] as $entry) {
        $code = $entry['code'];
        $isEn = $code === 'en';
        $isAdmin = $admin !== null && $code === $admin;
        $isSf = in_array($code, $enabled, true);
        $roles = [];
        if ($isAdmin) {
            $roles[] = 'لغة أدمن الدولة';
        }
        if ($isEn) {
            $roles[] = 'الإنجليزية';
        }
        if ($isSf) {
            $roles[] = 'واجهة المتجر';
        }
        $rows[] = [
            'code' => $code,
            'label' => $entry['label'],
            'native' => $entry['native'] ?? $entry['label'],
            'is_english' => $isEn,
            'is_country_admin' => $isAdmin,
            'is_storefront_enabled' => $isSf,
            'roles' => $roles,
            'role_text' => $roles !== [] ? implode(' · ', $roles) : '',
        ];
    }

    return $rows;
}

/**
 * Presentation-only: configured country Admin locale if already stored.
 * Does not create ctrl_locales, does not invent a catalogue, and does not
 * default to Arabic when the configuration is missing.
 */
function orange_brand_identity_slogan_configured_admin_locale(PDO $controlPdo, int $countryId = 0): ?string
{
    if ($countryId <= 0) {
        return null;
    }
    try {
        $st = $controlPdo->prepare('SELECT locale_code FROM ctrl_country_locales WHERE country_id = ? AND is_default_admin = 1 LIMIT 1');
        $st->execute([$countryId]);
        $raw = $st->fetchColumn();
        if ($raw === false) {
            return null;
        }
        $norm = orange_brand_identity_normalize_locale_code((string) $raw);
        if ($norm === null) {
            return null;
        }
        $approved = orange_brand_identity_approved_locales($controlPdo);
        if (!in_array($norm, $approved, true)) {
            return null;
        }

        return $norm;
    } catch (Throwable $e) {
        return null;
    }
}

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

/**
 * Server-side slogan payload for create_preview_release: same merge base the
 * editor displays. Client base IDs are validated against that source only.
 *
 * @param array<string, mixed> $data
 * @return list<array{locale:string,text_key:string,text_value:string}>
 */
function orange_brand_identity_admin_error_message(string $code): string
{
    return match ($code) {
        'BRAND_IDENTITY_DISPLAYED_SOURCE_MISMATCH' => 'مصدر نص الشعار لم يعد صالحاً. لم يُحفظ شيء. أعد فتح المصدر ثم حاول مرة أخرى.',
        'BRAND_IDENTITY_SLOGAN_LOCALE_REQUIRED' => 'يوجد نص شعار بلا لغة مختارة. اختر اللغة قبل إنشاء المعاينة.',
        'BRAND_IDENTITY_LANGUAGE_REFERENCE_UNAVAILABLE' => 'مرجع اللغات غير قابل للاستخدام حالياً. لا يمكن حفظ شعار جديد.',
        'BRAND_IDENTITY_UNSUPPORTED_LOCALE' => 'اللغة غير مدعومة في مرجع اللغات.',
        'BRAND_IDENTITY_DUPLICATE_LOCALE' => 'لا تكرر اللغة نفسها في صفّين.',
        default => $code,
    };
}

function orange_brand_identity_admin_prepare_preview_translations(PDO $controlPdo, array $data): array
{
    orange_brand_identity_assert_language_reference_writable($controlPdo);
    if (!array_key_exists('base_identity_version_id', $data)
        && !array_key_exists('displayed_identity_version_id', $data)) {
        throw new RuntimeException('BRAND_IDENTITY_DISPLAYED_SOURCE_MISMATCH');
    }
    $clientBase = array_key_exists('base_identity_version_id', $data)
        ? $data['base_identity_version_id']
        : $data['displayed_identity_version_id'];
    $baseId = orange_brand_identity_resolve_displayed_merge_base($controlPdo, $clientBase);
    $trIn = $data['translations'] ?? [];
    $incoming = [];
    if (is_array($trIn)) {
        foreach ($trIn as $row) {
            if (!is_array($row)) {
                continue;
            }
            $incoming[] = [
                'locale' => (string) ($row['locale'] ?? ''),
                'text_key' => (string) ($row['text_key'] ?? 'STOREFRONT_SLOGAN'),
                'text_value' => (string) ($row['text_value'] ?? ''),
            ];
        }
    }

    return orange_brand_identity_merge_identity_translations($controlPdo, $incoming, $baseId);
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
function orange_brand_identity_admin_snapshot(PDO $controlPdo, ?int $focusReleaseId = null): array
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
    $textSource = orange_brand_identity_editor_text_source($controlPdo, $focusReleaseId);
    $identity = is_array($textSource['identity'] ?? null) ? $textSource['identity'] : null;
    $translations = is_array($textSource['translations'] ?? null) ? $textSource['translations'] : [];
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

    $countryId = 0;
    if (function_exists('db') && function_exists('orange_admin_context_country_id')) {
        try {
            $countryId = (int) orange_admin_context_country_id(db());
        } catch (Throwable $e) {
            $countryId = 0;
        }
    }
    $configuredAdmin = orange_brand_identity_slogan_configured_admin_locale($controlPdo, $countryId);
    $localeRows = orange_brand_identity_slogan_editor_rows($controlPdo, $configuredAdmin);
    $reference = orange_brand_identity_language_reference($controlPdo);
    $storefrontLocales = [];
    try {
        $storefrontLocales = orange_brand_identity_approved_locales($controlPdo);
    } catch (Throwable $e) {
        $storefrontLocales = [];
    }

    return [
        'current_release' => $cur,
        'identity' => $identity,
        'slots' => $slots,
        'slot_archives' => $archives,
        'translations' => $translations,
        'displayed_identity_version_id' => (int) ($textSource['identity_version_id'] ?? 0),
        'displayed_release_id' => (int) ($textSource['release_id'] ?? 0),
        'displayed_source_kind' => (string) ($textSource['kind'] ?? 'none'),
        'history' => $hist ? $hist->fetchAll(PDO::FETCH_ASSOC) : [],
        'audit' => orange_brand_identity_audit_recent($controlPdo),
        'slot_codes' => orange_brand_identity_slot_codes(),
        'locales' => $storefrontLocales,
        'locale_rows' => $localeRows,
        'language_reference' => $reference['entries'],
        'language_reference_source' => $reference['source'],
        'language_reference_pending' => $reference['pending'],
        'language_reference_write_blocked' => !empty($reference['write_blocked']),
        'language_reference_state' => (string) ($reference['state'] ?? ''),
        'language_reference_notice_ar' => orange_brand_identity_language_reference_notice_ar($reference),
        'configured_admin_locale' => $configuredAdmin,
        'locale_authority' => 'language_reference',
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
