<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/orange_dictionary_locale.php';
require_admin_api();

function orange_sizing_dictionary_flag_message(array $locales): ?string
{
    foreach ($locales as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if (!empty($entry['preserve']) || array_key_exists('origin', $entry)) {
            return 'لا يُقبل preserve أو origin من الحفظ.';
        }
    }

    return null;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function orange_sizing_dictionary_attach_locale(PDO $pdo, array $rows, string $kind): array
{
    $countryId = function_exists('orange_admin_context_country_id') ? (int) orange_admin_context_country_id($pdo) : 0;
    if ($countryId <= 0 || !orange_sizing_dictionary_identity_ready($pdo) || !orange_content_locale_screen_ready($pdo, $countryId)) {
        return $rows;
    }
    $roles = orange_country_locale_roles_read($pdo, $countryId);
    $base = (string) ($roles['base'] ?? '');
    $ids = [];
    foreach ($rows as $row) {
        $ids[] = (int) ($row['id'] ?? 0);
    }
    $bags = orange_dictionary_locale_rows_for($pdo, $kind, $ids);
    foreach ($rows as $ix => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        $stored = $bags[$id] ?? [];
        $rows[$ix]['display_name'] = orange_dictionary_locale_base_text($kind, $base, $row, $stored);
        $rows[$ix]['locale_view'] = orange_dictionary_locale_panel_state($kind, $row, $stored, $base);
    }

    return $rows;
}

/** @return string */
$sanitizeKind = static function (string $raw): string {
    $t = strtolower(trim($raw));
    $t = (string) (preg_replace('/[^a-z0-9_-]/', '', $t) ?? '');

    return strlen($t) > 32 ? substr($t, 0, 32) : $t;
};

/** @return string */
$sanitizeCat = static function (string $raw): string {
    $t = strtolower(trim($raw));
    $t = (string) (preg_replace('/[^a-z0-9_-]/', '', $t) ?? '');

    return strlen($t) > 64 ? substr($t, 0, 64) : $t;
};

/** @return string */
$composeCategoryKey = static function (string $kindKey, string $labelEn) use ($sanitizeCat): string {
    $ck = trim($kindKey);
    $en = $sanitizeCat($labelEn);
    if ($ck === '' || $en === '') {
        return '';
    }
    $combined = $ck . '_' . $en;

    return strlen($combined) > 64 ? substr($combined, 0, 64) : $combined;
};

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $action = trim((string) ($data['action'] ?? ''));

    if (!orange_table_exists($pdo, 'commercial_kind_dictionary')
        || !orange_table_exists($pdo, 'sizing_category_dictionary')
    ) {
        json_response(['success' => false, 'message' => 'جدايل القاموس المرجعي غير متاحة؛ حدّث المخطّط.'], 503);
    }

    switch ($action) {
        case 'list_kinds':
            $idSql = orange_sizing_dictionary_identity_ready($pdo) ? 'id, ' : '';
            $rows = $pdo->query(
                'SELECT ' . $idSql . 'kind_key, label_ar, label_en, sort_order, is_active
                 FROM commercial_kind_dictionary
                 ORDER BY sort_order ASC, kind_key ASC'
            )->fetchAll(PDO::FETCH_ASSOC);
            $rows = is_array($rows) ? $rows : [];
            json_response(['success' => true, 'kinds' => orange_sizing_dictionary_attach_locale($pdo, $rows, 'commercial_kind')]);

        case 'list_categories':
            $ck = $sanitizeKind((string) ($data['commercial_kind_key'] ?? ''));
            if ($ck === '') {
                json_response(['success' => false, 'message' => 'حدد النوع التجاري'], 422);
            }
            $st = $pdo->prepare(
                'SELECT kind_key FROM commercial_kind_dictionary WHERE kind_key = ? LIMIT 1'
            );
            $st->execute([$ck]);
            if (! $st->fetchColumn()) {
                json_response(['success' => false, 'message' => 'النوع التجاري غير موجود'], 404);
            }
            $idSql = orange_sizing_dictionary_identity_ready($pdo) ? 'id, ' : '';
            $st2 = $pdo->prepare(
                'SELECT ' . $idSql . 'commercial_kind_key, category_key, label_ar, label_en, sort_order, is_active
                 FROM sizing_category_dictionary
                 WHERE commercial_kind_key = ?
                 ORDER BY sort_order ASC, category_key ASC'
            );
            $st2->execute([$ck]);
            $rows = $st2->fetchAll(PDO::FETCH_ASSOC);
            $rows = is_array($rows) ? $rows : [];
            json_response(['success' => true, 'categories' => orange_sizing_dictionary_attach_locale($pdo, $rows, 'sizing_category')]);
            break;

        case 'list_all_categories':
            // كل فئات القياس عبر كل الأنواع التجارية، مع تسمية النوع الأب (لجدول مسطّح يظهر دون اختيار نوع).
            $idSql = orange_sizing_dictionary_identity_ready($pdo) ? 'c.id, ' : '';
            $rows = $pdo->query(
                'SELECT ' . $idSql . 'c.commercial_kind_key, c.category_key, c.label_ar, c.label_en, c.sort_order, c.is_active,
                        k.label_ar AS kind_label_ar, k.label_en AS kind_label_en,
                        COALESCE(k.sort_order, 999999) AS kind_sort
                 FROM sizing_category_dictionary c
                 LEFT JOIN commercial_kind_dictionary k ON k.kind_key = c.commercial_kind_key
                 ORDER BY kind_sort ASC, c.commercial_kind_key ASC, c.sort_order ASC, c.category_key ASC'
            )->fetchAll(PDO::FETCH_ASSOC);
            $rows = is_array($rows) ? $rows : [];
            json_response(['success' => true, 'categories' => orange_sizing_dictionary_attach_locale($pdo, $rows, 'sizing_category')]);
            break;

        case 'save_kind':
            $kindKey = $sanitizeKind((string) ($data['kind_key'] ?? ''));
            $oldKind = $sanitizeKind((string) ($data['old_kind_key'] ?? ''));
            $labelAr = trim((string) ($data['label_ar'] ?? ''));
            $labelEn = trim((string) ($data['label_en'] ?? ''));
            $sort = (int) ($data['sort_order'] ?? 0);
            $active = (int) ($data['is_active'] ?? 1) === 0 ? 0 : 1;
            $countryId = function_exists('orange_admin_context_country_id') ? (int) orange_admin_context_country_id($pdo) : 0;
            $identityReady = orange_sizing_dictionary_identity_ready($pdo);
            if (isset($data['locales']) && is_array($data['locales'])) {
                $flag = orange_sizing_dictionary_flag_message($data['locales']);
                if ($flag !== null) {
                    json_response(['success' => false, 'message' => $flag], 422);
                }
                $data['locales'] = orange_dictionary_locale_strip_public($data['locales']);
            }
            $localeMode = $identityReady && orange_dictionary_locale_use_payload($pdo, $countryId, $data);
            $kindId = 0;
            if ($oldKind !== '' && $identityReady) {
                $idSt = $pdo->prepare('SELECT id FROM commercial_kind_dictionary WHERE kind_key = ? LIMIT 1');
                $idSt->execute([$oldKind]);
                $kindId = (int) $idSt->fetchColumn();
            }
            if ($localeMode && $oldKind !== '') {
                $kindKey = $oldKind;
            } elseif ($localeMode) {
                $acceptedEn = orange_dictionary_locale_reference_english(
                    $pdo,
                    $countryId,
                    'commercial_kind',
                    0,
                    (string) ($data['base_text'] ?? ''),
                    is_array($data['locales'] ?? null) ? $data['locales'] : []
                );
                $kindKey = $sanitizeKind($acceptedEn);
            }
            if ($localeMode) {
                $guardEn = orange_dictionary_locale_reference_english(
                    $pdo,
                    $countryId,
                    'commercial_kind',
                    $kindId,
                    (string) ($data['base_text'] ?? ''),
                    is_array($data['locales'] ?? null) ? $data['locales'] : []
                );
                $guard = orange_dictionary_locale_guard($pdo, $countryId, true, (string) ($data['base_text'] ?? ''), $guardEn, $labelAr, $guardEn, 'مفتاح النوع التجاري (بالإنجليزية) مطلوب');
                if ($guard !== null) {
                    json_response(['success' => false, 'message' => $guard], 422);
                }
            }

            if ($kindKey === '') {
                json_response(['success' => false, 'message' => 'مفتاح النوع التجاري (بالإنجليزية) مطلوب'], 422);
            }

            if ($oldKind !== '' && $oldKind !== $kindKey) {
                $chkOld = $pdo->prepare('SELECT 1 FROM commercial_kind_dictionary WHERE kind_key = ? LIMIT 1');
                $chkOld->execute([$oldKind]);
                if (! $chkOld->fetchColumn()) {
                    json_response(['success' => false, 'message' => 'المفتاح القديم غير موجود'], 404);
                }
                $chkNew = $pdo->prepare('SELECT 1 FROM commercial_kind_dictionary WHERE kind_key = ? LIMIT 1');
                $chkNew->execute([$kindKey]);
                if ($chkNew->fetchColumn()) {
                    json_response(['success' => false, 'message' => 'المفتاح الجديد مستخدم بالفعل'], 409);
                }
                $pdo->beginTransaction();
                try {
                    $uFam = $pdo->prepare(
                        'UPDATE size_families SET commercial_kind_key = ? WHERE commercial_kind_key = ?'
                    );
                    $uFam->execute([$kindKey, $oldKind]);
                    $uKind = $pdo->prepare(
                        'UPDATE commercial_kind_dictionary SET
                            kind_key = ?, label_ar = ?, label_en = ?, sort_order = ?, is_active = ?
                         WHERE kind_key = ? LIMIT 1'
                    );
                    $uKind->execute([$kindKey, $labelAr, $labelEn, $sort, $active, $oldKind]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                json_response(['success' => true]);
                break;
            }

            $existingKindId = 0;
            $kindExists = false;
            if ($identityReady) {
                $ex = $pdo->prepare('SELECT id FROM commercial_kind_dictionary WHERE kind_key = ? LIMIT 1');
                $ex->execute([$kindKey]);
                $existingKindId = (int) $ex->fetchColumn();
                $kindExists = $existingKindId > 0;
            } else {
                $ex = $pdo->prepare('SELECT 1 FROM commercial_kind_dictionary WHERE kind_key = ? LIMIT 1');
                $ex->execute([$kindKey]);
                $kindExists = (bool) $ex->fetchColumn();
            }
            $pdo->beginTransaction();
            try {
                if ($kindExists) {
                    if ($localeMode) {
                        $pdo->prepare(
                            'UPDATE commercial_kind_dictionary SET sort_order = ?, is_active = ? WHERE kind_key = ?'
                        )->execute([$sort, $active, $kindKey]);
                    } else {
                        $pdo->prepare(
                            'UPDATE commercial_kind_dictionary SET label_ar = ?, label_en = ?, sort_order = ?, is_active = ?
                             WHERE kind_key = ?'
                        )->execute([$labelAr, $labelEn, $sort, $active, $kindKey]);
                    }
                    $kindId = $existingKindId;
                } else {
                    if ($sort <= 0) {
                        $sort = (int) $pdo->query(
                            'SELECT COALESCE(MAX(sort_order),0)+1 FROM commercial_kind_dictionary'
                        )->fetchColumn();
                        if ($sort <= 0) {
                            $sort = 1;
                        }
                    }
                    $insAr = $localeMode ? '' : $labelAr;
                    $insEn = $localeMode ? '' : $labelEn;
                    $pdo->prepare(
                        'INSERT INTO commercial_kind_dictionary (kind_key, label_ar, label_en, sort_order, is_active)
                         VALUES (?,?,?,?,?)'
                    )->execute([$kindKey, $insAr, $insEn, $sort, $active]);
                    if ($identityReady) {
                        $kindId = (int) $pdo->lastInsertId();
                    }
                }
                if ($localeMode && $kindId > 0) {
                    orange_dictionary_locale_save(
                        $pdo,
                        $countryId,
                        'commercial_kind',
                        $kindId,
                        trim((string) ($data['base_text'] ?? '')),
                        is_array($data['locales'] ?? null) ? $data['locales'] : [],
                        false
                    );
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            json_response(['success' => true, 'id' => $kindId, 'kind_key' => $kindKey]);
            break;

        case 'delete_kind':
            $kindKey = $sanitizeKind((string) ($data['kind_key'] ?? ''));
            if ($kindKey === '') {
                json_response(['success' => false, 'message' => 'حدد نوعاً تجارياً'], 422);
            }
            $cnt = $pdo->prepare(
                'SELECT COUNT(*) FROM size_families WHERE commercial_kind_key = ?'
            );
            $cnt->execute([$kindKey]);
            if ((int) $cnt->fetchColumn() > 0) {
                json_response([
                    'success' => false,
                    'message' => 'لا يمكن حذف هذا النوع لأنه مستخدم في عائلات مقاسات؛ غيّر العائلات أولاً.',
                ], 409);
            }
            if (orange_sizing_dictionary_identity_ready($pdo)) {
                $idSt = $pdo->prepare('SELECT id FROM commercial_kind_dictionary WHERE kind_key = ? LIMIT 1');
                $idSt->execute([$kindKey]);
                $kindId = (int) $idSt->fetchColumn();
                $catSt = $pdo->prepare('SELECT id FROM sizing_category_dictionary WHERE commercial_kind_key = ?');
                $catSt->execute([$kindKey]);
                $catIds = array_map(static fn ($id): int => (int) $id, $catSt->fetchAll(PDO::FETCH_COLUMN) ?: []);
                $pdo->beginTransaction();
                try {
                    orange_dictionary_locale_delete_ids($pdo, 'sizing_category', $catIds);
                    if ($kindId > 0) {
                        orange_dictionary_locale_delete_entity($pdo, 'commercial_kind', $kindId);
                    }
                    $pdo->prepare('DELETE FROM sizing_category_dictionary WHERE commercial_kind_key = ?')->execute([$kindKey]);
                    $pdo->prepare('DELETE FROM commercial_kind_dictionary WHERE kind_key = ?')->execute([$kindKey]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            } else {
                $pdo->prepare('DELETE FROM commercial_kind_dictionary WHERE kind_key = ? LIMIT 1')->execute([$kindKey]);
            }
            json_response(['success' => true]);
            break;

        case 'save_category':
            $ck = $sanitizeKind((string) ($data['commercial_kind_key'] ?? ''));
            $oldCat = $sanitizeCat((string) ($data['old_category_key'] ?? ''));
            $labelAr = trim((string) ($data['label_ar'] ?? ''));
            $labelEn = trim((string) ($data['label_en'] ?? ''));
            $sort = (int) ($data['sort_order'] ?? 0);
            $active = (int) ($data['is_active'] ?? 1) === 0 ? 0 : 1;
            $countryId = function_exists('orange_admin_context_country_id') ? (int) orange_admin_context_country_id($pdo) : 0;
            $identityReady = orange_sizing_dictionary_identity_ready($pdo);
            if (isset($data['locales']) && is_array($data['locales'])) {
                $flag = orange_sizing_dictionary_flag_message($data['locales']);
                if ($flag !== null) {
                    json_response(['success' => false, 'message' => $flag], 422);
                }
                $data['locales'] = orange_dictionary_locale_strip_public($data['locales']);
            }
            $localeMode = $identityReady && orange_dictionary_locale_use_payload($pdo, $countryId, $data);
            $oldComm = $sanitizeKind((string) ($data['old_commercial_kind_key'] ?? ''));
            $catId = 0;
            if ($localeMode && $oldCat !== '') {
                $catKey = $oldCat;
                $lookupKind = $oldComm !== '' ? $oldComm : $ck;
                $idSt = $pdo->prepare('SELECT id FROM sizing_category_dictionary WHERE commercial_kind_key = ? AND category_key = ? LIMIT 1');
                $idSt->execute([$lookupKind, $oldCat]);
                $catId = (int) $idSt->fetchColumn();
                if ($catId <= 0) {
                    json_response(['success' => false, 'message' => 'فئة القياس غير موجودة تحت النوع التجاري السابق'], 404);
                }
            } elseif ($localeMode) {
                $acceptedEn = orange_dictionary_locale_reference_english(
                    $pdo,
                    $countryId,
                    'sizing_category',
                    0,
                    (string) ($data['base_text'] ?? ''),
                    is_array($data['locales'] ?? null) ? $data['locales'] : []
                );
                $catKey = $composeCategoryKey($ck, $acceptedEn);
            } else {
                $catKey = $composeCategoryKey($ck, $labelEn);
            }
            if ($localeMode) {
                $guardEn = orange_dictionary_locale_reference_english(
                    $pdo,
                    $countryId,
                    'sizing_category',
                    $catId,
                    (string) ($data['base_text'] ?? ''),
                    is_array($data['locales'] ?? null) ? $data['locales'] : []
                );
                $guard = orange_dictionary_locale_guard($pdo, $countryId, true, (string) ($data['base_text'] ?? ''), $guardEn, $labelAr, $guardEn, 'النوع التجاري وEnglish مطلوبان لتوليد مفتاح الفئة تلقائياً');
                if ($guard !== null) {
                    json_response(['success' => false, 'message' => $guard], 422);
                }
            }

            if ($ck === '' || $catKey === '') {
                json_response(['success' => false, 'message' => 'النوع التجاري وEnglish مطلوبان لتوليد مفتاح الفئة تلقائياً'], 422);
            }
            $st = $pdo->prepare('SELECT 1 FROM commercial_kind_dictionary WHERE kind_key = ? LIMIT 1');
            $st->execute([$ck]);
            if (! $st->fetchColumn()) {
                json_response(['success' => false, 'message' => 'النوع التجاري غير موجود في القاموس'], 404);
            }

            if ($oldCat !== '' && $oldCat === $catKey && $oldComm !== '' && $oldComm !== $ck) {
                $chkOldRow = $pdo->prepare(
                    'SELECT 1 FROM sizing_category_dictionary WHERE commercial_kind_key = ? AND category_key = ? LIMIT 1'
                );
                $chkOldRow->execute([$oldComm, $catKey]);
                if (! $chkOldRow->fetchColumn()) {
                    json_response(['success' => false, 'message' => 'فئة القياس غير موجودة تحت النوع التجاري السابق'], 404);
                }
                $chkDup = $pdo->prepare(
                    'SELECT 1 FROM sizing_category_dictionary WHERE commercial_kind_key = ? AND category_key = ? LIMIT 1'
                );
                $chkDup->execute([$ck, $catKey]);
                if ($chkDup->fetchColumn()) {
                    json_response(['success' => false, 'message' => 'مفتاح الفئة موجود بالفعل تحت النوع التجاري الجديد'], 409);
                }
                $stOldKind = $pdo->prepare('SELECT 1 FROM commercial_kind_dictionary WHERE kind_key = ? LIMIT 1');
                $stOldKind->execute([$oldComm]);
                if (! $stOldKind->fetchColumn()) {
                    json_response(['success' => false, 'message' => 'النوع التجاري السابق غير معرّف في القاموس'], 404);
                }
                if ($sort <= 0) {
                    $stMax = $pdo->prepare(
                        'SELECT COALESCE(MAX(sort_order),0)+1 FROM sizing_category_dictionary WHERE commercial_kind_key = ?'
                    );
                    $stMax->execute([$ck]);
                    $sort = (int) $stMax->fetchColumn();
                    if ($sort <= 0) {
                        $sort = 1;
                    }
                }
                $pdo->beginTransaction();
                try {
                    if ($localeMode) {
                        $pdo->prepare(
                            'UPDATE sizing_category_dictionary
                             SET commercial_kind_key = ?, sort_order = ?, is_active = ?
                             WHERE commercial_kind_key = ? AND category_key = ? LIMIT 1'
                        )->execute([$ck, $sort, $active, $oldComm, $catKey]);
                    } else {
                        $pdo->prepare(
                            'UPDATE sizing_category_dictionary
                             SET commercial_kind_key = ?, label_ar = ?, label_en = ?, sort_order = ?, is_active = ?
                             WHERE commercial_kind_key = ? AND category_key = ? LIMIT 1'
                        )->execute([$ck, $labelAr, $labelEn, $sort, $active, $oldComm, $catKey]);
                    }
                    $pdo->prepare(
                        'UPDATE size_families SET commercial_kind_key = ?
                         WHERE commercial_kind_key = ? AND sizing_category_key = ?'
                    )->execute([$ck, $oldComm, $catKey]);
                    if ($localeMode && $catId > 0) {
                        orange_dictionary_locale_save(
                            $pdo,
                            $countryId,
                            'sizing_category',
                            $catId,
                            trim((string) ($data['base_text'] ?? '')),
                            is_array($data['locales'] ?? null) ? $data['locales'] : [],
                            false
                        );
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
                json_response(['success' => true, 'id' => $catId, 'commercial_kind_key' => $ck, 'category_key' => $catKey]);
                break;
            }

            if ($oldCat !== '' && $oldCat !== $catKey) {
                $chkOld = $pdo->prepare(
                    'SELECT 1 FROM sizing_category_dictionary WHERE commercial_kind_key = ? AND category_key = ? LIMIT 1'
                );
                $chkOld->execute([$ck, $oldCat]);
                if (! $chkOld->fetchColumn()) {
                    json_response(['success' => false, 'message' => 'فئة القياس القديمة غير موجودة'], 404);
                }
                $chkNew = $pdo->prepare(
                    'SELECT 1 FROM sizing_category_dictionary WHERE commercial_kind_key = ? AND category_key = ? LIMIT 1'
                );
                $chkNew->execute([$ck, $catKey]);
                if ($chkNew->fetchColumn()) {
                    json_response(['success' => false, 'message' => 'مفتاح فئة القياس الجديد مستخدم بالفعل'], 409);
                }
                $pdo->beginTransaction();
                try {
                    $uFam = $pdo->prepare(
                        'UPDATE size_families SET sizing_category_key = ?
                         WHERE commercial_kind_key = ? AND sizing_category_key = ?'
                    );
                    $uFam->execute([$catKey, $ck, $oldCat]);
                    $uRow = $pdo->prepare(
                        'UPDATE sizing_category_dictionary SET
                            category_key = ?, label_ar = ?, label_en = ?, sort_order = ?, is_active = ?
                         WHERE commercial_kind_key = ? AND category_key = ? LIMIT 1'
                    );
                    $uRow->execute([$catKey, $labelAr, $labelEn, $sort, $active, $ck, $oldCat]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                json_response(['success' => true]);
                break;
            }

            $existingCatId = 0;
            $catExists = false;
            if ($identityReady) {
                $ex = $pdo->prepare(
                    'SELECT id FROM sizing_category_dictionary WHERE commercial_kind_key = ? AND category_key = ? LIMIT 1'
                );
                $ex->execute([$ck, $catKey]);
                $existingCatId = (int) $ex->fetchColumn();
                $catExists = $existingCatId > 0;
            } else {
                $ex = $pdo->prepare(
                    'SELECT 1 FROM sizing_category_dictionary WHERE commercial_kind_key = ? AND category_key = ? LIMIT 1'
                );
                $ex->execute([$ck, $catKey]);
                $catExists = (bool) $ex->fetchColumn();
            }
            $pdo->beginTransaction();
            try {
                if ($catExists) {
                    if ($localeMode) {
                        $pdo->prepare(
                            'UPDATE sizing_category_dictionary SET sort_order = ?, is_active = ?
                             WHERE commercial_kind_key = ? AND category_key = ?'
                        )->execute([$sort, $active, $ck, $catKey]);
                    } else {
                        $pdo->prepare(
                            'UPDATE sizing_category_dictionary SET label_ar = ?, label_en = ?, sort_order = ?, is_active = ?
                             WHERE commercial_kind_key = ? AND category_key = ?'
                        )->execute([$labelAr, $labelEn, $sort, $active, $ck, $catKey]);
                    }
                    $catId = $existingCatId;
                } else {
                    if ($sort <= 0) {
                        $stMax = $pdo->prepare(
                            'SELECT COALESCE(MAX(sort_order),0)+1 FROM sizing_category_dictionary WHERE commercial_kind_key = ?'
                        );
                        $stMax->execute([$ck]);
                        $sort = (int) $stMax->fetchColumn();
                        if ($sort <= 0) {
                            $sort = 1;
                        }
                    }
                    $insAr = $localeMode ? '' : $labelAr;
                    $insEn = $localeMode ? '' : $labelEn;
                    $pdo->prepare(
                        'INSERT INTO sizing_category_dictionary
                            (commercial_kind_key, category_key, label_ar, label_en, sort_order, is_active)
                         VALUES (?,?,?,?,?,?)'
                    )->execute([$ck, $catKey, $insAr, $insEn, $sort, $active]);
                    if ($identityReady) {
                        $catId = (int) $pdo->lastInsertId();
                    }
                }
                if ($localeMode && $catId > 0) {
                    orange_dictionary_locale_save(
                        $pdo,
                        $countryId,
                        'sizing_category',
                        $catId,
                        trim((string) ($data['base_text'] ?? '')),
                        is_array($data['locales'] ?? null) ? $data['locales'] : [],
                        false
                    );
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            json_response(['success' => true, 'id' => $catId, 'commercial_kind_key' => $ck, 'category_key' => $catKey]);
            break;

        case 'delete_category':
            $ck = $sanitizeKind((string) ($data['commercial_kind_key'] ?? ''));
            $catKey = $sanitizeCat((string) ($data['category_key'] ?? ''));
            if ($ck === '' || $catKey === '') {
                json_response(['success' => false, 'message' => 'حددا النوع وفئة القياس'], 422);
            }
            $cnt = $pdo->prepare(
                'SELECT COUNT(*) FROM size_families WHERE commercial_kind_key = ? AND sizing_category_key = ?'
            );
            $cnt->execute([$ck, $catKey]);
            if ((int) $cnt->fetchColumn() > 0) {
                json_response([
                    'success' => false,
                    'message' => 'لا يمكن حذف هذه الفئة لأنها مستخدمة في عائلة مقاسات.',
                ], 409);
            }
            if (orange_sizing_dictionary_identity_ready($pdo)) {
                $idSt = $pdo->prepare('SELECT id FROM sizing_category_dictionary WHERE commercial_kind_key = ? AND category_key = ? LIMIT 1');
                $idSt->execute([$ck, $catKey]);
                $catId = (int) $idSt->fetchColumn();
                $pdo->beginTransaction();
                try {
                    if ($catId > 0) {
                        orange_dictionary_locale_delete_entity($pdo, 'sizing_category', $catId);
                    }
                    $pdo->prepare(
                        'DELETE FROM sizing_category_dictionary WHERE commercial_kind_key = ? AND category_key = ? LIMIT 1'
                    )->execute([$ck, $catKey]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            } else {
                $pdo->prepare(
                    'DELETE FROM sizing_category_dictionary WHERE commercial_kind_key = ? AND category_key = ? LIMIT 1'
                )->execute([$ck, $catKey]);
            }
            json_response(['success' => true]);
            break;

        default:
            json_response(['success' => false, 'message' => 'إجراء غير معروف'], 400);
    }
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذّر تنفيذ طلب القاموس المرجعي');
}
