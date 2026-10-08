<?php
declare(strict_types=1);

/**
 * Departments and country language settings bridge.
 * Page open and this file do not create tables or seed roles.
 * The server admin row is the only authority. Request actor/full_access is ignored.
 * Does not load config.php.
 */

require_once __DIR__ . '/arabic_name_duplicate.php';
require_once __DIR__ . '/orange_content_languages.php';
require_once __DIR__ . '/orange_translate_contract.php';
require_once __DIR__ . '/orange_translate_request_guard.php';
require_once __DIR__ . '/orange_department_locale_save.php';
require_once __DIR__ . '/orange_department_record.php';

function orange_department_integration_table_exists(PDO $pdo, string $table): bool
{
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
            $st->execute([$table]);
            return (bool) $st->fetchColumn();
        }
        $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $st->execute([$table]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function orange_department_integration_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $st = $pdo->query('PRAGMA table_info(' . $table . ')');
            foreach ($st ? $st->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
                if ((string) ($row['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }
        $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $st->execute([$table, $column]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function orange_department_integration_page_state(PDO $pdo, int $countryId): array
{
    $rolesTable = orange_department_integration_table_exists($pdo, 'orange_country_locale_role');
    $textTable = orange_department_integration_table_exists($pdo, 'orange_content_locale_text');
    $refTable = orange_department_integration_table_exists($pdo, 'orange_language_reference');
    $blank = [
        'tables_present' => false,
        'mode' => 'tables_absent',
        'notice' => 'جداول لغات المحتوى غير موجودة. المسار القديم للأقسام يبقى. فتح الصفحة لا ينشئ جداول ولا يفترض لغة الدولة.',
        'base' => null,
        'content' => [],
        'admin_ui' => [],
        'customer' => [],
        'customer_wired' => false,
        'visitor_list_activated' => false,
        'admin_ui_language_changed' => false,
        'active' => [],
        'panel' => [],
    ];
    if (!$rolesTable || !$textTable) {
        return $blank;
    }
    $roles = orange_country_locale_roles_read($pdo, $countryId);
    $active = $refTable ? orange_language_reference_active_codes($pdo) : [];
    $roles['tables_present'] = true;
    $roles['active'] = $active;
    $roles['panel'] = orange_content_panel_locales($roles, $active);
    $roles['visitor_list_activated'] = false;
    $roles['admin_ui_language_changed'] = false;
    $roles['notice'] = ($roles['mode'] ?? '') === 'configured'
        ? 'إعداد لغات المحتوى مهيأ لهذه الدولة.'
        : 'إعداد لغات المحتوى غير مهيأ لهذه الدولة. المسار القديم يبقى إلى أن يُحفظ إعداد صريح. لا تُفترض العربية.';
    return $roles;
}

function orange_department_integration_response(bool $ok, string $message, int $status, array $extra = []): array
{
    return [
        'status' => $status,
        'body' => array_merge(['success' => $ok, 'message' => $message], $extra),
    ];
}

function orange_department_integration_legacy_names(array $data): ?array
{
    $names = [
        'name_ar' => trim((string) ($data['name_ar'] ?? '')),
        'name_en' => trim((string) ($data['name_en'] ?? '')),
        'name_fil' => trim((string) ($data['name_fil'] ?? '')),
        'name_hi' => trim((string) ($data['name_hi'] ?? '')),
    ];
    foreach ($names as $value) {
        if ($value === '') {
            return null;
        }
    }
    return $names;
}

function orange_department_integration_legacy_write(PDO $pdo, array $serverAdmin, int $countryId, array $data, ?int $id): array
{
    orange_department_assert_global_admin($serverAdmin);
    $names = orange_department_integration_legacy_names($data);
    if ($names === null) {
        return orange_department_integration_response(false, 'يجب إضافة خانات الأسماء الأربع قبل الحفظ', 422, ['code' => 'legacy_names_required', 'mode' => 'legacy']);
    }
    $slug = trim((string) ($data['slug'] ?? ''));
    if ($slug === '') {
        return orange_department_integration_response(false, 'يجب إضافة خانة Slug قبل الحفظ', 422, ['code' => 'slug_required', 'mode' => 'legacy']);
    }
    $rows = $pdo->query('SELECT id, name_ar FROM departments')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (orange_rows_normalized_arabic_conflict($rows, 'id', 'name_ar', $names['name_ar'], $id)) {
        return orange_department_integration_response(false, orange_arabic_duplicate_blocked_message(), 409, ['code' => 'arabic_duplicate', 'mode' => 'legacy']);
    }
    $slugText = orange_department_unique_slug($pdo, $slug, $id);
    $sort = (int) ($data['sort_order'] ?? 0);
    if ($sort <= 0) {
        $sort = $id === null ? orange_department_next_sort($pdo) : (int) $pdo->query('SELECT sort_order FROM departments WHERE id = ' . (int) $id)->fetchColumn();
    }
    $hasFil = orange_department_integration_has_column($pdo, 'departments', 'name_fil');
    $hasHi = orange_department_integration_has_column($pdo, 'departments', 'name_hi');
    if ($pdo->inTransaction()) {
        throw new RuntimeException('transaction_already_open');
    }
    $pdo->beginTransaction();
    try {
        if ($id === null) {
            if ($hasFil && $hasHi) {
                $st = $pdo->prepare('INSERT INTO departments (name_en, name_ar, name_fil, name_hi, slug, is_active, sort_order) VALUES (?, ?, ?, ?, ?, 1, ?)');
                $st->execute([$names['name_en'], $names['name_ar'], $names['name_fil'], $names['name_hi'], $slugText, $sort]);
            } else {
                $st = $pdo->prepare('INSERT INTO departments (name_en, name_ar, slug, is_active, sort_order) VALUES (?, ?, ?, 1, ?)');
                $st->execute([$names['name_en'], $names['name_ar'], $slugText, $sort]);
            }
            $id = (int) $pdo->lastInsertId();
            if (orange_department_integration_table_exists($pdo, 'countries') && orange_department_integration_table_exists($pdo, 'department_countries')) {
                orange_department_seed_inactive_countries($pdo, $id);
            }
        } else {
            if ($hasFil && $hasHi) {
                $st = $pdo->prepare('UPDATE departments SET name_en=?, name_ar=?, name_fil=?, name_hi=?, slug=?, sort_order=? WHERE id=?');
                $st->execute([$names['name_en'], $names['name_ar'], $names['name_fil'], $names['name_hi'], $slugText, $sort, $id]);
            } else {
                $st = $pdo->prepare('UPDATE departments SET name_en=?, name_ar=?, slug=?, sort_order=? WHERE id=?');
                $st->execute([$names['name_en'], $names['name_ar'], $slugText, $sort, $id]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    $state = orange_department_integration_page_state($pdo, $countryId);
    return orange_department_integration_response(true, 'تم حفظ القسم', 200, [
        'id' => $id,
        'slug' => $slugText,
        'mode' => $state['mode'],
        'locale_rows_written' => 0,
    ]);
}

function orange_department_integration_message(Throwable $e): array
{
    $code = $e->getMessage();
    $map = [
        'admin_forbidden' => [403, 'إدارة الأقسام العامة للمشرف العام فقط'],
        'english_reference_inactive' => [422, 'مرجع الإنجليزية غير نشط، ولا يمكن حفظ إعداد محتوى يعتمد عليه'],
        'base_required' => [422, 'يلزم أساس واحد بالضبط'],
        'multiple_base' => [422, 'لا يمكن حفظ أكثر من أساس'],
        'locale_not_active' => [422, 'اللغة غير نشطة في مرجع اللغات'],
        'admin_ui_not_ready' => [422, 'واجهة الإدارة تقبل العربية والإنجليزية والفلبينية والهندية فقط'],
        'base_not_in_content' => [422, 'لغة الأساس يجب أن تكون ضمن لغات المحتوى'],
        'role_invalid' => [422, 'دور اللغة غير معروف'],
        'country_required' => [422, 'الدولة مطلوبة'],
        'base_text_required' => [422, 'نص اللغة الأساسية مطلوب'],
        'slug_required' => [422, 'يجب إضافة خانة Slug قبل الحفظ'],
        'arabic_duplicate' => [409, 'لا يمكن الحفظ: الاسم العربي مكرر أو يطابق اسماً موجوداً عند اعتبار الحروف المتشابهة'],
        'department_missing' => [404, 'القسم غير موجود'],
    ];
    if (isset($map[$code])) {
        return orange_department_integration_response(false, $map[$code][1], $map[$code][0], ['code' => $code]);
    }
    if (str_starts_with($code, 'locale_settings_')) {
        return orange_department_integration_response(false, 'إعداد اللغات غير مهيأ', 422, ['code' => $code]);
    }
    return orange_department_integration_response(false, 'تعذر إكمال الطلب', 422, ['code' => 'integration_error']);
}

function orange_department_integration_handle(PDO $pdo, array $serverAdmin, string $action, array $data, int $countryId, ?callable $fetch = null): array
{
    unset($data['actor'], $data['full_access']);
    try {
        orange_department_assert_global_admin($serverAdmin);
        $state = orange_department_integration_page_state($pdo, $countryId);
        if ($action === 'page_state') {
            return orange_department_integration_response(true, $state['notice'], 200, ['state' => $state]);
        }
        if ($action === 'save_roles') {
            if (!$state['tables_present']) {
                return orange_department_integration_response(false, $state['notice'], 422, ['code' => 'tables_absent']);
            }
            $assignments = is_array($data['assignments'] ?? null) ? $data['assignments'] : [];
            orange_country_locale_roles_replace($pdo, $countryId, $assignments);
            $saved = orange_department_integration_page_state($pdo, $countryId);
            return orange_department_integration_response(true, 'حُفظ اختيار الأدوار. قائمة الزائر ولغة واجهة البرنامج لم تُفعَّلا.', 200, [
                'state' => $saved,
                'customer_wired' => false,
                'visitor_list_activated' => false,
                'admin_ui_language_changed' => false,
            ]);
        }
        if ($action === 'suggest') {
            if (($state['mode'] ?? '') !== 'configured') {
                return orange_department_integration_response(false, $state['notice'], 422, ['code' => 'not_configured', 'suggestions' => []]);
            }
            $corrected = array_key_exists('corrected_english', $data) ? (string) $data['corrected_english'] : null;
            $previous = array_key_exists('previous_english', $data) ? (string) $data['previous_english'] : null;
            try {
                $result = orange_department_suggest(
                    (string) $state['base'],
                    (string) ($data['base_text'] ?? ''),
                    is_array($data['panel'] ?? null) ? $data['panel'] : $state['panel'],
                    is_array($data['known'] ?? null) ? $data['known'] : [],
                    is_array($data['explicit'] ?? null) ? $data['explicit'] : [],
                    $fetch,
                    1000,
                    $corrected,
                    $previous
                );
            } catch (Throwable $e) {
                return orange_department_integration_response(false, 'تعذر طلب الترجمة', 200, [
                    'code' => 'transport_failed',
                    'suggestions' => [],
                ]);
            }
            $result['success'] = true;
            $result['message'] = 'ok';
            return ['status' => 200, 'body' => $result];
        }
        if ($action === 'read') {
            $id = (int) ($data['id'] ?? 0);
            if (($state['mode'] ?? '') !== 'configured') {
                $st = $pdo->prepare('SELECT * FROM departments WHERE id = ?');
                $st->execute([$id]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                return orange_department_integration_response($row !== false, $row ? 'legacy' : 'القسم غير موجود', $row ? 200 : 404, [
                    'mode' => $state['mode'],
                    'department' => $row ?: null,
                ]);
            }
            $view = orange_department_locale_read($pdo, $countryId, $id);
            return orange_department_integration_response(true, 'ok', 200, ['mode' => 'configured', 'view' => $view]);
        }
        if ($action === 'create' || $action === 'update') {
            if (($state['mode'] ?? '') !== 'configured') {
                $existing = $action === 'update' ? (int) ($data['id'] ?? 0) : null;
                if ($action === 'update' && ($existing === null || $existing <= 0)) {
                    return orange_department_integration_response(false, 'معرف القسم مطلوب', 422, ['code' => 'department_id']);
                }
                return orange_department_integration_legacy_write($pdo, $serverAdmin, $countryId, $data, $action === 'update' ? $existing : null);
            }
            $locales = is_array($data['locales'] ?? null) ? $data['locales'] : [];
            $baseText = (string) ($data['base_text'] ?? '');
            $slug = (string) ($data['slug'] ?? '');
            $sort = (int) ($data['sort_order'] ?? 0);
            if ($action === 'create') {
                $saved = orange_department_record_create($pdo, $serverAdmin, $countryId, $baseText, $locales, $slug, $sort);
            } else {
                $saved = orange_department_record_update($pdo, $serverAdmin, $countryId, (int) ($data['id'] ?? 0), $baseText, $locales, $slug, $sort);
            }
            return orange_department_integration_response(true, 'تم حفظ القسم', 200, [
                'saved' => $saved,
                'mode' => 'configured',
            ]);
        }
        return orange_department_integration_response(false, 'طلب غير معروف', 422, ['code' => 'unknown_action']);
    } catch (Throwable $e) {
        if ($e instanceof RuntimeException || $e instanceof InvalidArgumentException) {
            return orange_department_integration_message($e);
        }
        return orange_department_integration_response(false, 'تعذر إكمال الطلب', 500, ['code' => 'integration_error']);
    }
}

function orange_department_integration_resolve_settings_country(array $serverAdmin, int $requestedCountryId, int $contextCountryId): array
{
    try {
        orange_department_assert_global_admin($serverAdmin);
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'country_id' => 0,
            'status' => 403,
            'code' => 'admin_forbidden',
            'message' => 'إدارة الأقسام العامة للمشرف العام فقط',
        ];
    }
    if ($requestedCountryId <= 0) {
        return [
            'ok' => false,
            'country_id' => 0,
            'status' => 422,
            'code' => 'country_required',
            'message' => 'حدد الدولة المعروضة قبل الحفظ',
        ];
    }
    if ($contextCountryId > 0 && $contextCountryId !== $requestedCountryId) {
        // The countries page shows the edited country. A global admin may save that
        // country. The session context is not a silent replacement target.
    }
    return [
        'ok' => true,
        'country_id' => $requestedCountryId,
        'status' => 200,
        'code' => '',
        'message' => '',
    ];
}

function orange_department_integration_save_locale_roles(PDO $pdo, array $serverAdmin, array $data, int $contextCountryId): array
{
    unset($data['actor'], $data['full_access']);
    $requested = (int) ($data['country_id'] ?? 0);
    $resolved = orange_department_integration_resolve_settings_country($serverAdmin, $requested, $contextCountryId);
    if (!$resolved['ok']) {
        return orange_department_integration_response(false, (string) $resolved['message'], (int) $resolved['status'], [
            'code' => (string) $resolved['code'],
        ]);
    }
    $action = (string) ($data['action'] ?? 'save_roles');
    if ($action !== 'save_roles' && $action !== 'page_state') {
        $action = 'page_state';
    }
    return orange_department_integration_handle($pdo, $serverAdmin, $action, $data, (int) $resolved['country_id'], null);
}
