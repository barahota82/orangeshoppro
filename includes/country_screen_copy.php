<?php

declare(strict_types=1);

/**
 * نسخ انتقائي بين الدول — شاشة مركزية (Solution 3).
 */

require_once __DIR__ . '/country_selective_copy.php';
require_once __DIR__ . '/country_provision.php';
require_once __DIR__ . '/gl_settings.php';
require_once __DIR__ . '/invoice_ancillary_lines.php';
require_once __DIR__ . '/analytical_dimensions.php';

function orange_country_screen_copy_log_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    if (!orange_table_exists($pdo, 'orange_country_screen_copy_log')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE orange_country_screen_copy_log (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                screen_key VARCHAR(64) NOT NULL,
                source_country_id INT UNSIGNED NOT NULL,
                target_country_id INT UNSIGNED NOT NULL,
                admin_id INT UNSIGNED NULL DEFAULT NULL,
                summary_ar VARCHAR(512) NOT NULL DEFAULT \'\',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_occscl_target (target_country_id),
                KEY idx_occscl_screen (screen_key),
                KEY idx_occscl_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        orange_schema_invalidate_table_exists('orange_country_screen_copy_log');
    }
    $done = true;
}

/**
 * @return array<string, array{label_ar:string, hint_ar:string, selection:string}>
 */
function orange_country_screen_copy_modules(): array
{
    return [
        'chart_of_accounts' => [
            'label_ar' => 'الدليل المحاسبي',
            'hint_ar' => 'المستويات 1–4 تُنسخ كاملة تلقائياً؛ حدّد حسابات المستوى 5 التي تختلف بين الدول.',
            'selection' => 'coa_tree',
        ],
        'journal_types' => [
            'label_ar' => 'أنواع اليوميات',
            'hint_ar' => 'حدّد الأنواع المراد نسخها — يُحدَّث الاسم في الهدف إن وُجد نفس الكود.',
            'selection' => 'checkbox_list',
        ],
        'gl_account_settings' => [
            'label_ar' => 'حسابات القيود التلقائية',
            'hint_ar' => 'ينسخ ربط القسم ١ (بند → حساب بالكود) وقواعد القسم ٢ (مفاتيح المدين/الدائن). ما لا يجد حساباً مطابقاً في الهدف يُتخطّى.',
            'selection' => 'none',
        ],
        'invoice_line_presets' => [
            'label_ar' => 'قائمة بنود الفاتورة الإضافية',
            'hint_ar' => 'ينسخ البنود المحفوظة ويعيد ربط الحساب بالكود في دليل الهدف.',
            'selection' => 'none',
        ],
        'analytical_dimensions' => [
            'label_ar' => 'الأبعاد التحليلية',
            'hint_ar' => 'ينسخ رؤوس الأبعاد وقيمها (بالكود) — كل دولة تحتفظ بصفوفها المنفصلة.',
            'selection' => 'none',
        ],
    ];
}

function orange_country_screen_copy_module_label(string $screenKey): string
{
    $mods = orange_country_screen_copy_modules();
    $k = trim($screenKey);

    return (string) ($mods[$k]['label_ar'] ?? $k);
}

function orange_country_screen_copy_log_append(
    PDO $pdo,
    string $screenKey,
    int $sourceCountryId,
    int $targetCountryId,
    string $summaryAr,
    ?int $adminId = null
): void {
    orange_country_screen_copy_log_ensure($pdo);
    $st = $pdo->prepare(
        'INSERT INTO orange_country_screen_copy_log
            (screen_key, source_country_id, target_country_id, admin_id, summary_ar)
         VALUES (?,?,?,?,?)'
    );
    $st->execute([
        trim($screenKey),
        $sourceCountryId,
        $targetCountryId,
        $adminId !== null && $adminId > 0 ? $adminId : null,
        mb_substr(trim($summaryAr), 0, 512),
    ]);
}

/**
 * @return list<array<string, mixed>>
 */
function orange_country_screen_copy_log_list(PDO $pdo, int $limit = 40): array
{
    orange_country_screen_copy_log_ensure($pdo);
    $limit = max(1, min(200, $limit));
    $st = $pdo->prepare(
        'SELECT l.*, sc.name_ar AS source_name_ar, tc.name_ar AS target_name_ar
         FROM orange_country_screen_copy_log l
         LEFT JOIN countries sc ON sc.id = l.source_country_id
         LEFT JOIN countries tc ON tc.id = l.target_country_id
         ORDER BY l.id DESC
         LIMIT ' . (int) $limit
    );
    $st->execute();

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array{success:bool, message:string, settings_copied:int, settings_skipped:int, rules_copied:int, rules_skipped:int}
 */
function orange_country_copy_gl_account_settings_selective(
    PDO $pdo,
    int $sourceCountryId,
    int $targetCountryId
): array {
    $out = [
        'success' => false,
        'message' => '',
        'settings_copied' => 0,
        'settings_skipped' => 0,
        'rules_copied' => 0,
        'rules_skipped' => 0,
    ];
    if ($sourceCountryId <= 0 || $targetCountryId <= 0 || $sourceCountryId === $targetCountryId) {
        $out['message'] = 'معرّف الدولة غير صالح.';

        return $out;
    }
    orange_catalog_ensure_gl_account_settings_alloc_tables($pdo);
    $accountMap = orange_country_build_account_id_map_by_code($pdo, $sourceCountryId, $targetCountryId);

    if (orange_table_exists($pdo, 'orange_gl_account_settings')
        && orange_table_has_column($pdo, 'orange_gl_account_settings', 'country_id')) {
        $stSrc = $pdo->prepare(
            'SELECT g.setting_key, g.account_id, a.code AS account_code
             FROM orange_gl_account_settings g
             LEFT JOIN accounts a ON a.id = g.account_id
             WHERE g.country_id = ?'
        );
        $stSrc->execute([$sourceCountryId]);
        $stFind = $pdo->prepare(
            'SELECT account_id FROM orange_gl_account_settings WHERE setting_key = ? AND country_id = ? LIMIT 1'
        );
        $stIns = $pdo->prepare(
            'INSERT INTO orange_gl_account_settings (setting_key, account_id, country_id) VALUES (?, ?, ?)'
        );
        $stUpd = $pdo->prepare(
            'UPDATE orange_gl_account_settings SET account_id = ? WHERE setting_key = ? AND country_id = ?'
        );
        foreach ($stSrc->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if (!is_array($r)) {
                continue;
            }
            $key = trim((string) ($r['setting_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $srcAcct = (int) ($r['account_id'] ?? 0);
            $tgtAcct = 0;
            if ($srcAcct > 0) {
                if (isset($accountMap[$srcAcct])) {
                    $tgtAcct = (int) $accountMap[$srcAcct];
                } else {
                    ++$out['settings_skipped'];
                    continue;
                }
            }
            if ($tgtAcct <= 0) {
                ++$out['settings_skipped'];
                continue;
            }
            $stFind->execute([$key, $targetCountryId]);
            $exists = $stFind->fetchColumn() !== false;
            if ($exists) {
                $stUpd->execute([$tgtAcct, $key, $targetCountryId]);
            } else {
                $stIns->execute([$key, $tgtAcct, $targetCountryId]);
            }
            ++$out['settings_copied'];
        }
    }

    if (orange_table_exists($pdo, 'orange_gl_setting_alloc')
        && orange_table_has_column($pdo, 'orange_gl_setting_alloc', 'country_id')) {
        $stSrcA = $pdo->prepare('SELECT setting_key, percent_value FROM orange_gl_setting_alloc WHERE country_id = ?');
        $stSrcA->execute([$sourceCountryId]);
        $stInsA = $pdo->prepare(
            'INSERT INTO orange_gl_setting_alloc (setting_key, percent_value, country_id) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE percent_value = VALUES(percent_value)'
        );
        foreach ($stSrcA->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $key = trim((string) ($r['setting_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            try {
                $stInsA->execute([$key, $r['percent_value'] ?? 0, $targetCountryId]);
            } catch (Throwable $e) {
                if (function_exists('error_log')) {
                    error_log('[orange] copy gl alloc ' . $key . ': ' . $e->getMessage());
                }
            }
        }
    }

    if (orange_table_exists($pdo, 'orange_gl_journal_type_rules')
        && orange_table_has_column($pdo, 'orange_gl_journal_type_rules', 'country_id')
        && orange_table_exists($pdo, 'journal_types')
        && orange_table_has_column($pdo, 'journal_types', 'country_id')) {
        $stSrcR = $pdo->prepare(
            'SELECT jt.code, r.payment_terms, r.debit_setting_key, r.credit_setting_key
             FROM orange_gl_journal_type_rules r
             INNER JOIN journal_types jt ON jt.id = r.journal_type_id
             WHERE r.country_id = ?'
        );
        $stSrcR->execute([$sourceCountryId]);
        $stTgtJt = $pdo->prepare('SELECT id FROM journal_types WHERE country_id = ? AND code = ? LIMIT 1');
        $stFindR = $pdo->prepare(
            'SELECT id FROM orange_gl_journal_type_rules
             WHERE country_id = ? AND journal_type_id = ? AND payment_terms = ? LIMIT 1'
        );
        $stInsR = $pdo->prepare(
            'INSERT INTO orange_gl_journal_type_rules
                (country_id, journal_type_id, payment_terms, debit_setting_key, credit_setting_key)
             VALUES (?,?,?,?,?)'
        );
        $stUpdR = $pdo->prepare(
            'UPDATE orange_gl_journal_type_rules
             SET debit_setting_key = ?, credit_setting_key = ?
             WHERE id = ?'
        );
        foreach ($stSrcR->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string) ($row['code'] ?? ''));
            if ($code === '') {
                ++$out['rules_skipped'];
                continue;
            }
            $stTgtJt->execute([$targetCountryId, $code]);
            $tgtJtId = (int) ($stTgtJt->fetchColumn() ?: 0);
            if ($tgtJtId <= 0) {
                ++$out['rules_skipped'];
                continue;
            }
            $pt = (string) ($row['payment_terms'] ?? '');
            $dk = (string) ($row['debit_setting_key'] ?? '');
            $ck = (string) ($row['credit_setting_key'] ?? '');
            $stFindR->execute([$targetCountryId, $tgtJtId, $pt]);
            $rid = (int) ($stFindR->fetchColumn() ?: 0);
            try {
                if ($rid > 0) {
                    $stUpdR->execute([$dk, $ck, $rid]);
                } else {
                    $stInsR->execute([$targetCountryId, $tgtJtId, $pt, $dk, $ck]);
                }
                ++$out['rules_copied'];
            } catch (Throwable $e) {
                ++$out['rules_skipped'];
                if (function_exists('error_log')) {
                    error_log('[orange] copy gl rule ' . $code . ': ' . $e->getMessage());
                }
            }
        }
    }

    $out['success'] = $out['settings_copied'] > 0 || $out['rules_copied'] > 0;
    $out['message'] = $out['success']
        ? ('ربط GL: ' . $out['settings_copied'] . ' بند'
            . ($out['settings_skipped'] > 0 ? ('، تُخطّى ' . $out['settings_skipped']) : '')
            . '؛ قواعد: ' . $out['rules_copied']
            . ($out['rules_skipped'] > 0 ? ('، تُخطّى ' . $out['rules_skipped']) : '') . '.')
        : 'لم يُنسخ ربط GL — تحقق من الدليل وأنواع اليوميات في الهدف.';

    return $out;
}

/**
 * @return array{success:bool, message:string, inserted:int, updated:int, skipped:int}
 */
function orange_country_copy_invoice_line_presets_selective(
    PDO $pdo,
    int $sourceCountryId,
    int $targetCountryId
): array {
    $out = [
        'success' => false,
        'message' => '',
        'inserted' => 0,
        'updated' => 0,
        'skipped' => 0,
    ];
    if ($sourceCountryId <= 0 || $targetCountryId <= 0 || $sourceCountryId === $targetCountryId) {
        $out['message'] = 'معرّف الدولة غير صالح.';

        return $out;
    }
    if (!orange_invoice_ancillary_tables_ready($pdo)) {
        $out['message'] = 'جدول بنود الفاتورة غير جاهز.';

        return $out;
    }
    $accountMap = orange_country_build_account_id_map_by_code($pdo, $sourceCountryId, $targetCountryId);
    $hasSystemKey = orange_table_has_column($pdo, 'orange_invoice_line_presets', 'system_key');
    $stSrc = $pdo->prepare('SELECT * FROM orange_invoice_line_presets WHERE country_id = ? ORDER BY sort_order ASC, id ASC');
    $stSrc->execute([$sourceCountryId]);
    $rows = $stSrc->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        $out['message'] = 'لا توجد بنود في الدولة المصدر.';

        return $out;
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $srcAcct = (int) ($row['account_id'] ?? 0);
        if ($srcAcct <= 0 || !isset($accountMap[$srcAcct])) {
            ++$out['skipped'];
            continue;
        }
        $tgtAcct = (int) $accountMap[$srcAcct];
        $systemKey = $hasSystemKey ? trim((string) ($row['system_key'] ?? '')) : '';
        $lineKind = trim((string) ($row['line_kind'] ?? ''));
        $ctx = trim((string) ($row['invoice_context'] ?? 'both'));
        $labelAr = trim((string) ($row['label_ar'] ?? ''));
        $labelEn = trim((string) ($row['label_en'] ?? ''));
        $sort = (int) ($row['sort_order'] ?? 0);
        $active = (int) ($row['is_active'] ?? 1) === 1 ? 1 : 0;
        $show = (int) ($row['default_show_on_print'] ?? 0) === 1 ? 1 : 0;
        try {
            if ($hasSystemKey && $systemKey !== '') {
                $stFind = $pdo->prepare(
                    'SELECT id FROM orange_invoice_line_presets WHERE country_id = ? AND system_key = ? LIMIT 1'
                );
                $stFind->execute([$targetCountryId, $systemKey]);
                $existingId = (int) ($stFind->fetchColumn() ?: 0);
                if ($existingId > 0) {
                    $pdo->prepare(
                        'UPDATE orange_invoice_line_presets
                         SET account_id = ?, label_ar = ?, label_en = ?, invoice_context = ?, line_kind = ?,
                             default_show_on_print = ?, sort_order = ?, is_active = ?
                         WHERE id = ? AND country_id = ?'
                    )->execute([
                        $tgtAcct, $labelAr, $labelEn, $ctx, $lineKind, $show, $sort, $active,
                        $existingId, $targetCountryId,
                    ]);
                    ++$out['updated'];
                } else {
                    $pdo->prepare(
                        'INSERT INTO orange_invoice_line_presets
                            (country_id, account_id, label_ar, label_en, invoice_context, line_kind, system_key,
                             default_show_on_print, sort_order, is_active)
                         VALUES (?,?,?,?,?,?,?,?,?,?)'
                    )->execute([
                        $targetCountryId, $tgtAcct, $labelAr, $labelEn, $ctx, $lineKind, $systemKey,
                        $show, $sort, $active,
                    ]);
                    ++$out['inserted'];
                }
            } else {
                $stFind = $pdo->prepare(
                    'SELECT id FROM orange_invoice_line_presets
                     WHERE country_id = ? AND line_kind = ? AND invoice_context = ? AND label_ar = ? LIMIT 1'
                );
                $stFind->execute([$targetCountryId, $lineKind, $ctx, $labelAr]);
                $existingId = (int) ($stFind->fetchColumn() ?: 0);
                if ($existingId > 0) {
                    $pdo->prepare(
                        'UPDATE orange_invoice_line_presets
                         SET account_id = ?, label_en = ?, default_show_on_print = ?, sort_order = ?, is_active = ?
                         WHERE id = ? AND country_id = ?'
                    )->execute([$tgtAcct, $labelEn, $show, $sort, $active, $existingId, $targetCountryId]);
                    ++$out['updated'];
                } else {
                    $insSql = 'INSERT INTO orange_invoice_line_presets
                        (country_id, account_id, label_ar, label_en, invoice_context, line_kind,
                         default_show_on_print, sort_order, is_active';
                    $vals = [$targetCountryId, $tgtAcct, $labelAr, $labelEn, $ctx, $lineKind, $show, $sort, $active];
                    if ($hasSystemKey) {
                        $insSql .= ', system_key) VALUES (?,?,?,?,?,?,?,?,?,NULL)';
                    } else {
                        $insSql .= ') VALUES (?,?,?,?,?,?,?,?)';
                    }
                    $pdo->prepare($insSql)->execute($vals);
                    ++$out['inserted'];
                }
            }
        } catch (Throwable $e) {
            ++$out['skipped'];
            if (function_exists('error_log')) {
                error_log('[orange] copy invoice preset: ' . $e->getMessage());
            }
        }
    }
    $total = $out['inserted'] + $out['updated'];
    $out['success'] = $total > 0;
    $out['message'] = $out['success']
        ? ('بنود الفاتورة: ' . $out['inserted'] . ' جديد، ' . $out['updated'] . ' محدَّث'
            . ($out['skipped'] > 0 ? ('، ' . $out['skipped'] . ' تُخطّى') : '') . '.')
        : ('لم يُنسخ أي بند.' . ($out['skipped'] > 0 ? ' (لا حساب مطابق في الهدف)' : ''));

    return $out;
}

/**
 * @return array{success:bool, message:string, dimensions:int, values_inserted:int, values_updated:int, skipped:int}
 */
function orange_country_copy_analytical_dimensions_selective(
    PDO $pdo,
    int $sourceCountryId,
    int $targetCountryId
): array {
    $out = [
        'success' => false,
        'message' => '',
        'dimensions' => 0,
        'values_inserted' => 0,
        'values_updated' => 0,
        'skipped' => 0,
    ];
    if ($sourceCountryId <= 0 || $targetCountryId <= 0 || $sourceCountryId === $targetCountryId) {
        $out['message'] = 'معرّف الدولة غير صالح.';

        return $out;
    }
    if (!orange_analytical_dimensions_ready($pdo)) {
        $out['message'] = 'جداول الأبعاد غير جاهزة.';

        return $out;
    }
    $dims = orange_analytical_dimensions_list($pdo, $sourceCountryId, false);
    if ($dims === []) {
        $out['message'] = 'لا توجد أبعاد في الدولة المصدر.';

        return $out;
    }
    foreach ($dims as $dim) {
        if (!is_array($dim)) {
            continue;
        }
        $code = trim((string) ($dim['code'] ?? ''));
        if ($code === '') {
            continue;
        }
        $labelAr = trim((string) ($dim['label_ar'] ?? ''));
        $labelEn = trim((string) ($dim['label_en'] ?? ''));
        $sort = (int) ($dim['sort_order'] ?? 0);
        $active = (int) ($dim['is_active'] ?? 1) === 1 ? 1 : 0;
        $stFindD = $pdo->prepare(
            'SELECT id FROM analytical_dimension WHERE country_id = ? AND code = ? LIMIT 1'
        );
        $stFindD->execute([$targetCountryId, $code]);
        $tgtDimId = (int) ($stFindD->fetchColumn() ?: 0);
        if ($tgtDimId <= 0) {
            $pdo->prepare(
                'INSERT INTO analytical_dimension (code, label_ar, label_en, is_active, sort_order, country_id)
                 VALUES (?,?,?,?,?,?)'
            )->execute([$code, $labelAr, $labelEn !== '' ? $labelEn : null, $active, $sort, $targetCountryId]);
            $tgtDimId = (int) $pdo->lastInsertId();
        } else {
            $pdo->prepare(
                'UPDATE analytical_dimension SET label_ar = ?, label_en = ?, is_active = ?, sort_order = ?
                 WHERE id = ? AND country_id = ?'
            )->execute([
                $labelAr,
                $labelEn !== '' ? $labelEn : null,
                $active,
                $sort,
                $tgtDimId,
                $targetCountryId,
            ]);
        }
        if ($tgtDimId <= 0) {
            ++$out['skipped'];
            continue;
        }
        ++$out['dimensions'];
        $srcDimId = (int) ($dim['id'] ?? 0);
        $values = orange_analytical_dimension_values_list($pdo, $srcDimId, false);
        foreach ($values as $val) {
            if (!is_array($val)) {
                continue;
            }
            $vCode = trim((string) ($val['code'] ?? ''));
            if ($vCode === '') {
                ++$out['skipped'];
                continue;
            }
            $vAr = trim((string) ($val['label_ar'] ?? ''));
            $vEn = trim((string) ($val['label_en'] ?? ''));
            $vSort = (int) ($val['sort_order'] ?? 0);
            $vActive = (int) ($val['is_active'] ?? 1) === 1 ? 1 : 0;
            $stFindV = $pdo->prepare(
                'SELECT id FROM analytical_dimension_value WHERE dimension_id = ? AND code = ? LIMIT 1'
            );
            $stFindV->execute([$tgtDimId, $vCode]);
            $existingVid = (int) ($stFindV->fetchColumn() ?: 0);
            try {
                if ($existingVid > 0) {
                    $pdo->prepare(
                        'UPDATE analytical_dimension_value
                         SET label_ar = ?, label_en = ?, is_active = ?, sort_order = ?
                         WHERE id = ?'
                    )->execute([
                        $vAr,
                        $vEn !== '' ? $vEn : null,
                        $vActive,
                        $vSort,
                        $existingVid,
                    ]);
                    ++$out['values_updated'];
                } else {
                    $pdo->prepare(
                        'INSERT INTO analytical_dimension_value
                            (dimension_id, code, label_ar, label_en, is_active, sort_order)
                         VALUES (?,?,?,?,?,?)'
                    )->execute([
                        $tgtDimId,
                        $vCode,
                        $vAr,
                        $vEn !== '' ? $vEn : null,
                        $vActive,
                        $vSort,
                    ]);
                    ++$out['values_inserted'];
                }
            } catch (Throwable $e) {
                ++$out['skipped'];
                if (function_exists('error_log')) {
                    error_log('[orange] copy analytical value ' . $vCode . ': ' . $e->getMessage());
                }
            }
        }
    }
    $out['success'] = $out['dimensions'] > 0;
    $out['message'] = $out['success']
        ? ('أبعاد: ' . $out['dimensions']
            . '؛ قيم: ' . $out['values_inserted'] . ' جديد، ' . $out['values_updated'] . ' محدَّث'
            . ($out['skipped'] > 0 ? ('، ' . $out['skipped'] . ' تُخطّى') : '') . '.')
        : 'لم يُنسخ أي بُعد.';

    return $out;
}

/**
 * @param array<string, mixed> $payload
 * @return array{success:bool, message:string}
 */
function orange_country_screen_copy_run(
    PDO $pdo,
    string $screenKey,
    int $sourceCountryId,
    int $targetCountryId,
    array $payload,
    ?int $adminId = null
): array {
    $mods = orange_country_screen_copy_modules();
    $screenKey = trim($screenKey);
    if (!isset($mods[$screenKey])) {
        return ['success' => false, 'message' => 'شاشة غير معتمدة للنسخ.'];
    }
    if ($sourceCountryId <= 0 || $targetCountryId <= 0 || $sourceCountryId === $targetCountryId) {
        return ['success' => false, 'message' => 'اختر دولة هدف مختلفة عن المصدر.'];
    }

    $result = ['success' => false, 'message' => ''];
    switch ($screenKey) {
        case 'chart_of_accounts':
            $ids = $payload['account_ids'] ?? [];
            if (!is_array($ids)) {
                return ['success' => false, 'message' => 'قائمة الحسابات غير صالحة.'];
            }
            $result = orange_country_copy_accounts_selective($pdo, $sourceCountryId, $targetCountryId, $ids);
            break;
        case 'journal_types':
            $ids = $payload['journal_type_ids'] ?? [];
            if (!is_array($ids)) {
                return ['success' => false, 'message' => 'قائمة أنواع اليوميات غير صالحة.'];
            }
            $result = orange_country_copy_journal_types_selective($pdo, $sourceCountryId, $targetCountryId, $ids);
            break;
        case 'gl_account_settings':
            $result = orange_country_copy_gl_account_settings_selective($pdo, $sourceCountryId, $targetCountryId);
            break;
        case 'invoice_line_presets':
            $result = orange_country_copy_invoice_line_presets_selective($pdo, $sourceCountryId, $targetCountryId);
            break;
        case 'analytical_dimensions':
            $result = orange_country_copy_analytical_dimensions_selective($pdo, $sourceCountryId, $targetCountryId);
            break;
        default:
            return ['success' => false, 'message' => 'شاشة غير مدعومة.'];
    }

    if (!empty($result['success'])) {
        orange_country_screen_copy_log_append(
            $pdo,
            $screenKey,
            $sourceCountryId,
            $targetCountryId,
            (string) ($result['message'] ?? 'تم'),
            $adminId
        );
    }

    return [
        'success' => !empty($result['success']),
        'message' => (string) ($result['message'] ?? ''),
    ];
}

/**
 * بيانات معاينة الشاشة المختارة (المصدر = سياق الأدmin الحالي).
 *
 * @return array<string, mixed>
 */
function orange_country_screen_copy_preview(PDO $pdo, string $screenKey, int $sourceCountryId): array
{
    $mods = orange_country_screen_copy_modules();
    $screenKey = trim($screenKey);
    if (!isset($mods[$screenKey]) || $sourceCountryId <= 0) {
        return ['success' => false, 'message' => 'طلب غير صالح.'];
    }
    require_once __DIR__ . '/account_tree.php';
    require_once __DIR__ . '/journal_types.php';

    $meta = $mods[$screenKey];
    $out = [
        'success' => true,
        'screen_key' => $screenKey,
        'label_ar' => $meta['label_ar'],
        'hint_ar' => $meta['hint_ar'],
        'selection' => $meta['selection'],
    ];

    switch ($screenKey) {
        case 'chart_of_accounts':
            if (!orange_table_has_column($pdo, 'accounts', 'country_id')) {
                return ['success' => false, 'message' => 'عمود country_id غير مفعّل على الحسابات.'];
            }
            $flat = orange_accounts_flat($pdo);
            $tree = orange_accounts_build_tree($flat);
            $out['coa_tree'] = orange_coa_copy_tree_payload($tree);
            $flatAll = [];
            $walk = static function (array $nodes) use (&$walk, &$flatAll): void {
                foreach ($nodes as $n) {
                    if (!is_array($n)) {
                        continue;
                    }
                    $flatAll[] = $n;
                    if (!empty($n['children'])) {
                        $walk($n['children']);
                    }
                }
            };
            $walk($out['coa_tree']);
            $mandatory = [];
            foreach ($flatAll as $n) {
                if (!is_array($n)) {
                    continue;
                }
                $lvl = (int) ($n['level'] ?? 99);
                $id = (int) ($n['id'] ?? 0);
                if ($id > 0 && $lvl > 0 && $lvl <= 4) {
                    $mandatory[] = $id;
                }
            }
            $out['mandatory_account_ids'] = array_values(array_unique($mandatory));
            break;
        case 'journal_types':
            $rows = orange_journal_types_list($pdo, $sourceCountryId);
            $out['items'] = array_map(static function (array $t): array {
                return [
                    'id' => (int) ($t['id'] ?? 0),
                    'code' => (string) ($t['code'] ?? ''),
                    'name_ar' => (string) ($t['name_ar'] ?? ''),
                    'name_en' => (string) ($t['name_en'] ?? ''),
                ];
            }, $rows);
            break;
        case 'gl_account_settings':
            orange_catalog_ensure_gl_account_settings_alloc_tables($pdo);
            $bindings = orange_gl_settings_bindings_map($pdo, $sourceCountryId);
            $rules = orange_gl_journal_type_rules_list($pdo, $sourceCountryId);
            $out['stats'] = [
                'bindings_count' => count(array_filter($bindings, static fn (int $v): bool => $v > 0)),
                'rules_count' => count($rules),
            ];
            break;
        case 'invoice_line_presets':
            if (!orange_invoice_ancillary_tables_ready($pdo)) {
                return ['success' => false, 'message' => 'جدول البنود غير جاهز.'];
            }
            $presets = orange_invoice_ancillary_presets_list($pdo, $sourceCountryId, null, null, false);
            $out['stats'] = ['presets_count' => count($presets)];
            $out['items'] = array_map(static function (array $p): array {
                return [
                    'label_ar' => (string) ($p['label_ar'] ?? ''),
                    'line_kind' => (string) ($p['line_kind'] ?? ''),
                    'system_key' => (string) ($p['system_key'] ?? ''),
                    'account_code' => (string) ($p['account_code'] ?? ''),
                ];
            }, array_slice($presets, 0, 50));
            break;
        case 'analytical_dimensions':
            if (!orange_analytical_dimensions_ready($pdo)) {
                return ['success' => false, 'message' => 'جداول الأبعاد غير جاهزة.'];
            }
            require_once __DIR__ . '/analytical_dimensions.php';
            $dims = orange_analytical_dimensions_list_with_value_counts($pdo, $sourceCountryId, false);
            $out['items'] = array_map(static function (array $d): array {
                return [
                    'code' => (string) ($d['code'] ?? ''),
                    'label_ar' => (string) ($d['label_ar'] ?? ''),
                    'value_count' => (int) ($d['value_count'] ?? 0),
                ];
            }, $dims);
            break;
    }

    return $out;
}
