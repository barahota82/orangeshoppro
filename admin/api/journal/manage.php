<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/edit_lock.php';
require_once __DIR__ . '/../../../includes/gl_pending_movements.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/date_format.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

/**
 * أنواع السندات من شاشات القيد اليدوي الموحّدة (سند قيد / قبض / صرف).
 *
 * @return list<string>
 */
function orange_journal_manage_ui_entry_types(): array
{
    return ['manual', 'other_voucher', 'receipt_voucher', 'payment_voucher'];
}

/**
 * @return list<string>
 */
function orange_journal_manage_partner_entry_types(): array
{
    return ['supplier_payment', 'customer_receipt'];
}

function orange_journal_manage_resolve_browse_entry_type(array $data, string $fallback = 'manual'): string
{
    $t = trim((string) ($data['entry_type'] ?? ''));
    if ($t === 'year_end_close') {
        return 'year_end_close';
    }
    if ($t !== '' && in_array($t, orange_journal_manage_partner_entry_types(), true)) {
        return $t;
    }

    return orange_journal_manage_resolve_ui_entry_type($data, $fallback);
}

function orange_journal_manage_resolve_ui_entry_type(array $data, string $fallback): string
{
    $t = trim((string) ($data['entry_type'] ?? $fallback));
    if (!in_array($t, orange_journal_manage_ui_entry_types(), true)) {
        return $fallback;
    }

    return $t;
}

/**
 * شاشة «سندات أخرى»: مراجعة القيود المرتبطة بنوع يومية محدد فقط — لا يُقبل journal_type_id = 0 (لا «عرض الكل»).
 */
function orange_journal_manage_other_voucher_require_journal_type_id(array $data): int
{
    $jid = (int) ($data['journal_type_id'] ?? 0);
    if ($jid <= 0) {
        json_response([
            'success' => false,
            'message' => 'اختر نوع اليومية من الفلتر لعرض القيود أو البحث أو التنقل. لا يُسمح بعرض كل القيود دفعة واحدة.',
        ], 422);
    }

    return $jid;
}

/**
 * شاشة «سندات أخرى»: أنواع القيود المعروضة حسب فلتر نوع اليومية من إعدادات القيود التلقائية (قسم ٢).
 *
 * @return list<string>
 */
function orange_journal_manage_other_voucher_browse_entry_types(PDO $pdo, int $journalTypeFilterId): array
{
    if ($journalTypeFilterId <= 0) {
        return [];
    }
    $types = orange_gl_entry_types_for_journal_type_id($pdo, $journalTypeFilterId);

    return array_values(array_unique(array_filter($types, static function ($t) {
        return trim((string) $t) !== '';
    })));
}

/**
 * @param list<string> $types
 * @return array{0:string,1:list<string>}
 */
function orange_journal_manage_entry_type_sql_fragment(array $types): array
{
    if ($types === []) {
        return ['1=0', []];
    }
    if (count($types) === 1) {
        return ['entry_type = ?', $types];
    }
    $ph = implode(', ', array_fill(0, count($types), '?'));

    return ['entry_type IN (' . $ph . ')', $types];
}

/**
 * سند يُنشأ من شاشة «سندات أخرى»: يُخزَّن بنوع قيد ضمن مجموعة نوع اليومية المختار في الفلتر ليظهر مع البحث/الفلتر نفسه.
 * يُفضَّل manual / general / other_voucher إن وُجدت ضمن المجموعة؛ وإلا أول نوع غير مقفّل حذفه من شاشة القيود؛ وإلا أول نوع في القائمة.
 */
function orange_journal_manage_store_entry_type_for_other_voucher_screen(PDO $pdo, int $journalTypeId): string
{
    $allowed = orange_journal_manage_other_voucher_browse_entry_types($pdo, $journalTypeId);
    if ($allowed === []) {
        json_response(['success' => false, 'message' => 'نوع اليومية المحدد غير مرتبط بأنواع قيود في الإعدادات'], 422);
    }
    foreach (['manual', 'general', 'other_voucher'] as $pref) {
        if (in_array($pref, $allowed, true)) {
            return $pref;
        }
    }
    $lockedFlip = array_flip(orange_gl_entry_types_delete_locked_from_journal_ui());
    foreach ($allowed as $t) {
        if (!isset($lockedFlip[$t])) {
            return $t;
        }
    }

    return (string) $allowed[0];
}

/**
 * @param list<mixed> $linesIn
 * @return list<array{account_id:int,debit:float,credit:float,memo:string}>
 */
function orange_journal_manage_normalize_multiline_body(PDO $pdo, array $linesIn): array
{
    $norm = [];
    foreach ($linesIn as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $norm[] = [
            'account_id' => (int) ($ln['account_id'] ?? 0),
            'debit' => (float) ($ln['debit'] ?? 0),
            'credit' => (float) ($ln['credit'] ?? 0),
            'memo' => trim((string) ($ln['memo'] ?? '')),
            'dimension_value_id' => (int) ($ln['dimension_value_id'] ?? 0),
        ];
    }
    $postLines = [];
    foreach ($norm as $ln) {
        $aid = (int) ($ln['account_id'] ?? 0);
        $d = (float) ($ln['debit'] ?? 0);
        $c = (float) ($ln['credit'] ?? 0);
        if ($aid <= 0 || ($d <= 0 && $c <= 0)) {
            continue;
        }
        $accSt = $pdo->prepare('SELECT code, name FROM accounts WHERE id = ? LIMIT 1');
        $accSt->execute([$aid]);
        $accRow = $accSt->fetch(PDO::FETCH_ASSOC);
        if (!$accRow) {
            continue;
        }
        $accLabel = trim((string) ($accRow['name'] ?? ''));
        if ($accLabel === '') {
            $accLabel = trim((string) ($accRow['code'] ?? ''));
        }
        if ($accLabel === '') {
            continue;
        }
        if (($ln['memo'] ?? '') === '') {
            json_response(['success' => false, 'message' => 'بيان كل سطر مطلوب'], 422);
        }
        $postLines[] = $ln;
    }
    if (count($postLines) < 2) {
        json_response(['success' => false, 'message' => 'يُشترط سطران صالحان على الأقل في السند'], 422);
    }
    $totalDebit = 0.0;
    $totalCredit = 0.0;
    foreach ($postLines as $ln) {
        $totalDebit += (float) ($ln['debit'] ?? 0);
        $totalCredit += (float) ($ln['credit'] ?? 0);
    }
    require_once __DIR__ . '/../../../includes/currency.php';
    $cc = orange_gl_functional_currency_code($pdo, orange_admin_context_country_id($pdo));
    if (!orange_gl_money_is_balanced($totalDebit, $totalCredit, $cc)) {
        json_response([
            'success' => false,
            'message' => 'السند غير متوازن: مجموع المدين يجب أن يساوي مجموع الدائن',
        ], 422);
    }
    if (orange_table_has_column($pdo, 'accounts', 'is_group')) {
        foreach ($postLines as $ln) {
            $aid = (int) $ln['account_id'];
            $chk = $pdo->prepare('SELECT is_group FROM accounts WHERE id = ? LIMIT 1');
            $chk->execute([$aid]);
            if ((int) $chk->fetchColumn() === 1) {
                json_response(['success' => false, 'message' => 'لا يُسجَّل على حساب رئيسي — اختر حساباً فرعياً من الدليل'], 422);
            }
        }
    }

    return $postLines;
}

/**
 * @return int|null معرف حساب الخزينة للدولة الحالية (إعدادات GL + احتياط الربط).
 */
function orange_journal_manage_resolve_cash_account_id(PDO $pdo): ?int
{
    $acc = orange_journal_voucher_resolve_cash_account($pdo, orange_admin_context_country_id($pdo));
    if ($acc === null || (int) ($acc['id'] ?? 0) <= 0) {
        return null;
    }

    return (int) $acc['id'];
}

/**
 * سند القبض: آخر سطر يجب أن يكون حساب النقدية (الخزينة) من الإعدادات، مديناً بلا دائن.
 *
 * @param list<array{account_id:int,debit:float,credit:float,memo:string}> $postLines
 */
function orange_journal_manage_assert_receipt_cash_last_line(PDO $pdo, string $entryTypeNorm, array $postLines): void
{
    if ($entryTypeNorm !== 'receipt_voucher' || $postLines === []) {
        return;
    }
    $cashId = orange_journal_manage_resolve_cash_account_id($pdo);
    if ($cashId === null || $cashId <= 0) {
        json_response(['success' => false, 'message' => 'اربط حساب النقدية (الخزينة) في إعدادات القيود التلقائية لاستخدام سند القبض.'], 422);
    }
    $last = $postLines[count($postLines) - 1];
    if ((int) $last['account_id'] !== (int) $cashId) {
        json_response(['success' => false, 'message' => 'سند القبض يجب أن ينتهي بسطر حساب الخزينة (النقدية) كما في الشاشة.'], 422);
    }
    if ((float) $last['debit'] <= 0.0 || (float) $last['credit'] > 0.0) {
        json_response(['success' => false, 'message' => 'السطر الأخير في سند القبض (الخزينة) يجب أن يكون مديناً بمبلغ القبض دون دائن.'], 422);
    }
}

/**
 * سند الصرف: أول سطر يجب أن يكون حساب النقدية (الخزينة) من الإعدادات، دائناً بلا مدين.
 *
 * @param list<array{account_id:int,debit:float,credit:float,memo:string}> $postLines
 */
function orange_journal_manage_assert_payment_cash_first_line(PDO $pdo, string $entryTypeNorm, array $postLines): void
{
    if ($entryTypeNorm !== 'payment_voucher' || $postLines === []) {
        return;
    }
    $cashId = orange_journal_manage_resolve_cash_account_id($pdo);
    if ($cashId === null || $cashId <= 0) {
        json_response(['success' => false, 'message' => 'اربط حساب النقدية (الخزينة) في إعدادات القيود التلقائية لاستخدام سند الصرف.'], 422);
    }
    $first = $postLines[0];
    if ((int) $first['account_id'] !== (int) $cashId) {
        json_response(['success' => false, 'message' => 'سند الصرف يجب أن يبدأ بسطر حساب الخزينة (النقدية) كما في الشاشة.'], 422);
    }
    if ((float) $first['credit'] <= 0.0 || (float) $first['debit'] > 0.0) {
        json_response(['success' => false, 'message' => 'السطر الأول في سند الصرف (الخزينة) يجب أن يكون دائناً بمبلغ الصرف دون مدين.'], 422);
    }
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $action = trim((string)($data['action'] ?? 'update'));
    if ($action === 'search') {
        $action = 'search_manual';
    }

    if ($action === 'reference_preview') {
        $dateRaw = trim((string) ($data['date'] ?? ''));
        $date = orange_normalize_admin_posted_datetime($dateRaw !== '' ? $dateRaw : date('Y-m-d'));
        if ($date === null) {
            json_response(['success' => false, 'message' => 'تاريخ السند غير صالح'], 422);
        }
        $entryTypeRaw = trim((string) ($data['entry_type'] ?? 'manual'));
        $jtId = (int) ($data['journal_type_id'] ?? 0);
        if (in_array($entryTypeRaw, orange_journal_manage_partner_entry_types(), true)) {
            $entryTypeForPreview = $entryTypeRaw;
        } else {
            $entryTypeNorm = orange_journal_manage_resolve_ui_entry_type($data, 'manual');
            if ($entryTypeNorm === 'other_voucher' && $jtId <= 0) {
                json_response(['success' => true, 'reference' => '', 'voucher_serial' => 0]);

                return;
            }
            $entryTypeForPreview = $entryTypeNorm;
            if ($entryTypeNorm === 'other_voucher') {
                $entryTypeForPreview = orange_journal_manage_store_entry_type_for_other_voucher_screen($pdo, $jtId);
            }
        }
        $countryId = orange_admin_context_country_id($pdo);
        try {
            $fyId = orange_fiscal_require_open_for_posting($pdo, $date, $countryId > 0 ? $countryId : null);
        } catch (Throwable $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 422);
        }
        $meta = orange_journal_voucher_resolve_serial_meta(
            $pdo,
            $entryTypeForPreview,
            $jtId > 0 ? $jtId : null,
            $countryId > 0 ? $countryId : null
        );
        $serial = orange_journal_voucher_next_serial($pdo, $fyId, $meta['journal_serial_bucket']);
        $jid = isset($meta['journal_type_id']) && $meta['journal_type_id'] !== null
            ? (int) $meta['journal_type_id']
            : ($jtId > 0 ? $jtId : null);
        $ref = orange_voucher_auto_reference(
            $pdo,
            $entryTypeForPreview,
            $serial,
            $countryId > 0 ? $countryId : null,
            $jid
        );
        json_response(['success' => true, 'reference' => $ref, 'voucher_serial' => $serial]);

        return;
    }

    if ($action === 'create') {
        $description = trim((string)($data['description'] ?? ''));
        $entryTypeNorm = orange_journal_manage_resolve_ui_entry_type($data, 'manual');
        $dateRaw = trim((string)($data['date'] ?? ''));
        $date = orange_normalize_admin_posted_datetime($dateRaw);
        if ($date === null) {
            json_response(['success' => false, 'message' => 'تاريخ السند غير صالح'], 422);
        }
        if ($description === '') {
            json_response(['success' => false, 'message' => 'بيان السند مطلوب'], 422);
        }

        $jtCreate = 0;
        $entryTypeForPost = $entryTypeNorm;
        if ($entryTypeNorm === 'other_voucher') {
            $jtCreate = (int) ($data['journal_type_id'] ?? 0);
            if ($jtCreate <= 0) {
                json_response([
                    'success' => false,
                    'message' => 'اختر نوع اليومية من الفلتر قبل حفظ السند ليُصنَّف مع القيود المعروضة تحت ذلك النوع.',
                ], 422);
            }
            $entryTypeForPost = orange_journal_manage_store_entry_type_for_other_voucher_screen($pdo, $jtCreate);
        }

        $headerExtra = [];
        if ($jtCreate > 0) {
            $headerExtra['journal_type_id'] = $jtCreate;
        }

        $linesIn = $data['lines'] ?? null;
        if (is_array($linesIn) && count($linesIn) >= 2) {
            $postLines = orange_journal_manage_normalize_multiline_body($pdo, $linesIn);
            orange_journal_manage_assert_receipt_cash_last_line($pdo, $entryTypeNorm, $postLines);
            orange_journal_manage_assert_payment_cash_first_line($pdo, $entryTypeNorm, $postLines);
            if (orange_gl_use_pending_queue($pdo)) {
                $refOut = 'src:journal:' . str_replace(['.', ' '], '', uniqid('', true));
                try {
                    $pendingId = orange_gl_pending_enqueue_multi(
                        $pdo,
                        $postLines,
                        $refOut,
                        mb_substr($description, 0, 120),
                        $date,
                        $date,
                        $description,
                        $entryTypeForPost,
                        null,
                        $jtCreate > 0 ? $jtCreate : null
                    );
                } catch (Throwable $e) {
                    orange_admin_api_catch($e, 'تعذر إضافة السند', 422);
                }
                if ($pendingId <= 0) {
                    json_response(['success' => false, 'message' => 'تعذر إدراج السند في طابور الترحيل'], 422);
                }
                audit_log('journal_create', 'سند يدوي متعدد الأسطر (معلّق) مرجع: ' . $refOut, 'orange_gl_pending_movements', $pendingId);
                json_response([
                    'success' => true,
                    'message' => 'تم إضافة السند إلى طابور الترحيل — أكمل من «إقفال الحركات»',
                    'id' => null,
                    'pending_movement_id' => $pendingId,
                ]);

                return;
            }
            try {
                $vid = orange_voucher_post($pdo, array_merge($headerExtra, [
                    'voucher_date' => $date,
                    'document_entered_at' => date('Y-m-d H:i:s'),
                    'description' => $description,
                    'entry_type' => $entryTypeForPost,
                ]), $postLines);
            } catch (Throwable $e) {
                orange_admin_api_catch($e, 'تعذر إضافة السند', 422);
            }
            audit_log('journal_create', 'تم إنشاء سند محاسبي رقم: ' . $vid, 'journal_vouchers', $vid);
            $vNew = $pdo->prepare('SELECT * FROM journal_vouchers WHERE id = ? LIMIT 1');
            $vNew->execute([$vid]);
            $vRowNew = $vNew->fetch(PDO::FETCH_ASSOC);
            if ($vRowNew) {
                orange_edit_lock_register_voucher($pdo, $vRowNew);
            }
            json_response(['success' => true, 'message' => 'تم إضافة السند', 'id' => $vid]);

            return;
        }

        $accountDebit = (int)($data['account_debit'] ?? 0);
        $accountCredit = (int)($data['account_credit'] ?? 0);
        $amount = (float)($data['amount'] ?? 0);
        if ($accountDebit <= 0 || $accountCredit <= 0 || $amount <= 0) {
            json_response(['success' => false, 'message' => 'بيانات القيد اليدوي غير مكتملة'], 422);
        }
        if (orange_table_has_column($pdo, 'accounts', 'is_group')) {
            foreach ([$accountDebit, $accountCredit] as $aid) {
                $chk = $pdo->prepare('SELECT is_group FROM accounts WHERE id = ? LIMIT 1');
                $chk->execute([$aid]);
                if ((int) $chk->fetchColumn() === 1) {
                    json_response(['success' => false, 'message' => 'لا يُسجَّل على حساب رئيسي — اختر حساباً فرعياً'], 422);
                }
            }
        }
        if (orange_gl_use_pending_queue($pdo)) {
            $refOut = 'src:journal:' . str_replace(['.', ' '], '', uniqid('', true));
            try {
                $simpleRow = [
                    'reference' => $refOut,
                    'source_label' => mb_substr($description, 0, 120),
                    'movement_at' => $date,
                    'voucher_date' => $date,
                    'account_debit' => $accountDebit,
                    'account_credit' => $accountCredit,
                    'amount' => $amount,
                    'description' => $description,
                    'entry_type' => $entryTypeForPost,
                ];
                if ($jtCreate > 0) {
                    $simpleRow['journal_type_id'] = $jtCreate;
                }
                $pendingId = orange_gl_pending_enqueue_simple($pdo, $simpleRow);
            } catch (Throwable $e) {
                orange_admin_api_catch($e, 'تعذر إضافة السند', 422);
            }
            if ($pendingId <= 0) {
                json_response(['success' => false, 'message' => 'تعذر إدراج السند في طابور الترحيل'], 422);
            }
            audit_log('journal_create', 'سند يدوي (معلّق) مرجع: ' . $refOut, 'orange_gl_pending_movements', $pendingId);
            json_response([
                'success' => true,
                'message' => 'تم إضافة السند إلى طابور الترحيل — أكمل من «إقفال الحركات»',
                'id' => null,
                'pending_movement_id' => $pendingId,
            ]);

            return;
        }
        try {
            $vid = orange_voucher_post($pdo, array_merge($headerExtra, [
                'voucher_date' => $date,
                'document_entered_at' => date('Y-m-d H:i:s'),
                'description' => $description,
                'entry_type' => $entryTypeForPost,
            ]), [
                ['account_id' => $accountDebit, 'debit' => $amount, 'credit' => 0, 'memo' => $description],
                ['account_id' => $accountCredit, 'debit' => 0, 'credit' => $amount, 'memo' => $description],
            ]);
        } catch (Throwable $e) {
            orange_admin_api_catch($e, 'تعذر إضافة السند', 422);
        }
        audit_log('journal_create', 'تم إنشاء سند محاسبي رقم: ' . $vid, 'journal_vouchers', $vid);
        $vNew2 = $pdo->prepare('SELECT * FROM journal_vouchers WHERE id = ? LIMIT 1');
        $vNew2->execute([$vid]);
        $vRowNew2 = $vNew2->fetch(PDO::FETCH_ASSOC);
        if ($vRowNew2) {
            orange_edit_lock_register_voucher($pdo, $vRowNew2);
        }
        json_response(['success' => true, 'message' => 'تم إضافة السند', 'id' => $vid]);

        return;
    }

    if ($action === 'get') {
        $etReq = orange_journal_manage_resolve_browse_entry_type($data, 'manual');
        $gid = (int)($data['id'] ?? 0);
        if ($gid <= 0) {
            json_response(['success' => false, 'message' => 'معرف السند مطلوب'], 422);
        }
        $jtFilterGet = 0;
        if ($etReq === 'other_voucher') {
            $jtFilterGet = orange_journal_manage_other_voucher_require_journal_type_id($data);
        }
        if (!orange_journal_vouchers_ready($pdo)) {
            json_response(['success' => false, 'message' => 'جداول السندات غير جاهزة'], 422);
        }
        $st = $pdo->prepare('SELECT * FROM journal_vouchers WHERE id = ? LIMIT 1');
        $st->execute([$gid]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
        if (!$v) {
            json_response(['success' => false, 'message' => 'السند غير موجود'], 404);
        }
        try {
            orange_journal_voucher_assert_admin_context($pdo, $gid);
        } catch (RuntimeException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 403);
        }
        if ($etReq === 'other_voucher') {
            $allowedGet = orange_journal_manage_other_voucher_browse_entry_types($pdo, $jtFilterGet);
            if ($allowedGet === []) {
                json_response(['success' => false, 'message' => 'نوع اليومية المحدد غير مرتبط بأنواع قيود في الإعدادات'], 422);
            }
            $vEtGet = (string) ($v['entry_type'] ?? '');
            if (!in_array($vEtGet, $allowedGet, true)) {
                json_response(['success' => false, 'message' => 'لا يُعرَض هذا السند مع فلتر النوع الحالي'], 422);
            }
        } elseif ((string)($v['entry_type'] ?? '') !== $etReq) {
            json_response(['success' => false, 'message' => 'لا يُعرَض هذا السند من هذه الشاشة'], 422);
        }
        $vd = (string)($v['voucher_date'] ?? '');
        $dateForInput = strlen($vd) >= 10 ? substr($vd, 0, 10) : '';
        $docRaw = '';
        if (!empty($v['document_entered_at'])) {
            $docRaw = (string) $v['document_entered_at'];
        } else {
            $docRaw = $vd;
        }
        $docDisplay = orange_format_datetime_dmY_hi($docRaw !== '' ? $docRaw : date('Y-m-d H:i:s'));
        $hasDimJoin = orange_table_has_column($pdo, 'journal_lines', 'dimension_value_id')
            && orange_table_exists($pdo, 'analytical_dimension_value');
        if ($hasDimJoin) {
            $lst = $pdo->prepare(
                'SELECT jl.account_id, jl.debit, jl.credit, jl.memo, jl.dimension_value_id, a.code, a.name,
                        adv.label_ar AS dim_label_ar, adv.code AS dim_value_code,
                        ad.label_ar AS dim_name_ar, ad.code AS dim_code
                 FROM journal_lines jl
                 INNER JOIN accounts a ON a.id = jl.account_id
                 LEFT JOIN analytical_dimension_value adv ON adv.id = jl.dimension_value_id
                 LEFT JOIN analytical_dimension ad ON ad.id = adv.dimension_id
                 WHERE jl.voucher_id = ?
                 ORDER BY jl.line_no ASC'
            );
        } else {
            $lst = $pdo->prepare(
                'SELECT jl.account_id, jl.debit, jl.credit, jl.memo, a.code, a.name
                 FROM journal_lines jl
                 INNER JOIN accounts a ON a.id = jl.account_id
                 WHERE jl.voucher_id = ?
                 ORDER BY jl.line_no ASC'
            );
        }
        $lst->execute([$gid]);
        $lines = [];
        while ($row = $lst->fetch(PDO::FETCH_ASSOC)) {
            $lines[] = [
                'account_id' => (int) $row['account_id'],
                'code' => (string)($row['code'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'debit' => (float) $row['debit'],
                'credit' => (float) $row['credit'],
                'memo' => (string)($row['memo'] ?? ''),
                'dimension_value_id' => (int) ($row['dimension_value_id'] ?? 0),
                'dimension_label' => trim((string) ($row['dim_name_ar'] ?? '')) !== ''
                    ? trim((string) ($row['dim_name_ar'] ?? '')) . ': ' . trim((string) ($row['dim_label_ar'] ?? ''))
                    : '',
            ];
        }
        $vEtLock = (string) ($v['entry_type'] ?? '');
        $editableV = !in_array($vEtLock, orange_gl_entry_types_delete_locked_from_journal_ui(), true);
        $partyCustomerId = 0;
        $partySupplierId = 0;
        if (orange_table_exists($pdo, 'party_subledger')) {
            $ps = $pdo->prepare(
                'SELECT party_kind, party_id FROM party_subledger WHERE voucher_id = ? ORDER BY id ASC LIMIT 1'
            );
            $ps->execute([$gid]);
            $psRow = $ps->fetch(PDO::FETCH_ASSOC);
            if (is_array($psRow)) {
                $pk = (string) ($psRow['party_kind'] ?? '');
                $pid = (int) ($psRow['party_id'] ?? 0);
                if ($pk === 'customer' && $pid > 0) {
                    $partyCustomerId = $pid;
                } elseif ($pk === 'supplier' && $pid > 0) {
                    $partySupplierId = $pid;
                }
            }
        }
        json_response([
            'success' => true,
            'voucher' => [
                'id' => (int) $v['id'],
                'voucher_serial' => (int) ($v['voucher_serial'] ?? 0),
                'display_voucher_no' => orange_journal_voucher_display_number($v),
                'voucher_date' => strlen($vd) >= 10 ? orange_format_date_dmY(substr($vd, 0, 10)) : '',
                'voucher_date_dmy' => strlen($vd) >= 10 ? orange_format_date_dmY(substr($vd, 0, 10)) : '',
                'date' => $dateForInput,
                'reference' => (string)($v['reference'] ?? ''),
                'description' => (string)($v['description'] ?? ''),
                'document_entered_display' => $docDisplay,
                'entry_type' => (string) ($v['entry_type'] ?? ''),
                'editable' => $editableV,
            ],
            'party_customer_id' => $partyCustomerId,
            'party_supplier_id' => $partySupplierId,
            'lines' => $lines,
        ]);

        return;
    }

    if ($action === 'nav_manual') {
        require_once __DIR__ . '/../../../includes/countries.php';
        $et = orange_journal_manage_resolve_browse_entry_type($data, 'manual');
        $jtNav = 0;
        $navTypes = [$et];
        $etFrag = 'entry_type = ?';
        if ($et === 'other_voucher') {
            $jtNav = orange_journal_manage_other_voucher_require_journal_type_id($data);
            $navTypes = orange_journal_manage_other_voucher_browse_entry_types($pdo, $jtNav);
            if ($navTypes === []) {
                json_response(['success' => false, 'message' => 'نوع اليومية المحدد غير مرتبط بأنواع قيود في الإعدادات'], 422);
            }
            [$etFrag, $navTypes] = orange_journal_manage_entry_type_sql_fragment($navTypes);
        }
        $etFragJv = preg_replace('/\bentry_type\b/', 'jv.entry_type', $etFrag);
        $countryBindPlain = orange_gl_voucher_country_bind($pdo, '');
        $countryBindJv = orange_gl_voucher_country_bind($pdo, 'jv');
        if ($countryBindPlain['sql'] !== '') {
            $etFrag .= ltrim($countryBindPlain['sql'], ' AND ');
            $etFragJv .= $countryBindJv['sql'];
            $navTypes = array_merge($navTypes, $countryBindPlain['params']);
        }
        $where = trim((string)($data['where'] ?? ''));
        $currentId = (int) ($data['current_id'] ?? 0);
        if (!orange_journal_vouchers_ready($pdo)) {
            json_response(['success' => false, 'message' => 'جداول السندات غير جاهزة'], 422);
        }

        $useSerialNav = orange_table_has_column($pdo, 'journal_vouchers', 'voucher_serial')
            && orange_table_has_column($pdo, 'journal_vouchers', 'journal_serial_bucket');

        $serialNav = false;
        $fyScope = 0;
        $buckScope = '';
        $vsCur = 0;
        if ($et !== 'year_end_close' && $useSerialNav && $currentId > 0) {
            $stCur = $pdo->prepare(
                'SELECT id, fiscal_year_id, voucher_serial, journal_serial_bucket, entry_type FROM journal_vouchers WHERE id = ? LIMIT 1'
            );
            $stCur->execute([$currentId]);
            $curRow = $stCur->fetch(PDO::FETCH_ASSOC);
            if ($curRow) {
                $fyScope = (int) ($curRow['fiscal_year_id'] ?? 0);
                $vsCur = (int) ($curRow['voucher_serial'] ?? 0);
                $buckStored = trim((string) ($curRow['journal_serial_bucket'] ?? ''));
                $buckScope = $et === 'other_voucher' ? ('JT' . $jtNav) : $buckStored;
                if ($fyScope > 0 && $buckScope !== '' && $vsCur > 0) {
                    $serialNav = true;
                }
            }
        }

        if ($serialNav) {
            $cSt = $pdo->prepare(
                'SELECT COUNT(*) FROM journal_vouchers jv WHERE ' . $etFragJv . ' AND jv.fiscal_year_id = ? AND jv.journal_serial_bucket = ?'
            );
            $cSt->execute(array_merge($navTypes, [$fyScope, $buckScope]));
            if ((int) $cSt->fetchColumn() === 0) {
                json_response(['success' => false, 'message' => 'لا توجد سندات من هذا النوع بعد']);
            }
            $target = 0;
            if ($where === 'first') {
                $q = $pdo->prepare(
                    'SELECT jv.id FROM journal_vouchers jv WHERE ' . $etFragJv
                    . ' AND jv.fiscal_year_id = ? AND jv.journal_serial_bucket = ? ORDER BY jv.voucher_serial ASC, jv.id ASC LIMIT 1'
                );
                $q->execute(array_merge($navTypes, [$fyScope, $buckScope]));
                $target = (int) $q->fetchColumn();
            } elseif ($where === 'last') {
                $q = $pdo->prepare(
                    'SELECT jv.id FROM journal_vouchers jv WHERE ' . $etFragJv
                    . ' AND jv.fiscal_year_id = ? AND jv.journal_serial_bucket = ? ORDER BY jv.voucher_serial DESC, jv.id DESC LIMIT 1'
                );
                $q->execute(array_merge($navTypes, [$fyScope, $buckScope]));
                $target = (int) $q->fetchColumn();
            } elseif ($where === 'prev') {
                $q = $pdo->prepare(
                    'SELECT jv.id FROM journal_vouchers jv WHERE ' . $etFragJv
                    . ' AND jv.fiscal_year_id = ? AND jv.journal_serial_bucket = ? '
                    . 'AND (jv.voucher_serial < ? OR (jv.voucher_serial = ? AND jv.id < ?)) '
                    . 'ORDER BY jv.voucher_serial DESC, jv.id DESC LIMIT 1'
                );
                $q->execute(array_merge($navTypes, [$fyScope, $buckScope, $vsCur, $vsCur, $currentId]));
                $row = $q->fetch(PDO::FETCH_ASSOC);
                $target = $row ? (int) $row['id'] : 0;
            } elseif ($where === 'next') {
                $q = $pdo->prepare(
                    'SELECT jv.id FROM journal_vouchers jv WHERE ' . $etFragJv
                    . ' AND jv.fiscal_year_id = ? AND jv.journal_serial_bucket = ? '
                    . 'AND (jv.voucher_serial > ? OR (jv.voucher_serial = ? AND jv.id > ?)) '
                    . 'ORDER BY jv.voucher_serial ASC, jv.id ASC LIMIT 1'
                );
                $q->execute(array_merge($navTypes, [$fyScope, $buckScope, $vsCur, $vsCur, $currentId]));
                $row = $q->fetch(PDO::FETCH_ASSOC);
                $target = $row ? (int) $row['id'] : 0;
            } else {
                json_response(['success' => false, 'message' => 'أمر تنقل غير معروف'], 422);
            }
        } else {
            $cSt = $pdo->prepare('SELECT COUNT(*) FROM journal_vouchers WHERE ' . $etFrag);
            $cSt->execute($navTypes);
            if ((int) $cSt->fetchColumn() === 0) {
                json_response(['success' => false, 'message' => 'لا توجد سندات من هذا النوع بعد']);
            }
            $target = 0;
            if ($where === 'first') {
                $q = $pdo->prepare('SELECT COALESCE(MIN(id), 0) FROM journal_vouchers WHERE ' . $etFrag);
                $q->execute($navTypes);
                $target = (int) $q->fetchColumn();
            } elseif ($where === 'last') {
                $q = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM journal_vouchers WHERE ' . $etFrag);
                $q->execute($navTypes);
                $target = (int) $q->fetchColumn();
            } elseif ($where === 'prev') {
                if ($currentId <= 0) {
                    $q = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM journal_vouchers WHERE ' . $etFrag);
                    $q->execute($navTypes);
                    $target = (int) $q->fetchColumn();
                } else {
                    $q = $pdo->prepare('SELECT id FROM journal_vouchers WHERE ' . $etFrag . ' AND id < ? ORDER BY id DESC LIMIT 1');
                    $q->execute(array_merge($navTypes, [$currentId]));
                    $row = $q->fetch(PDO::FETCH_ASSOC);
                    $target = $row ? (int) $row['id'] : 0;
                }
            } elseif ($where === 'next') {
                if ($currentId <= 0) {
                    $q = $pdo->prepare('SELECT COALESCE(MIN(id), 0) FROM journal_vouchers WHERE ' . $etFrag);
                    $q->execute($navTypes);
                    $target = (int) $q->fetchColumn();
                } else {
                    $q = $pdo->prepare('SELECT id FROM journal_vouchers WHERE ' . $etFrag . ' AND id > ? ORDER BY id ASC LIMIT 1');
                    $q->execute(array_merge($navTypes, [$currentId]));
                    $row = $q->fetch(PDO::FETCH_ASSOC);
                    $target = $row ? (int) $row['id'] : 0;
                }
            } else {
                json_response(['success' => false, 'message' => 'أمر تنقل غير معروف'], 422);
            }
        }
        if ($target <= 0) {
            $msg = 'لا توجد سندات من هذا النوع بعد';
            if ($where === 'prev') {
                $msg = 'لا يوجد سند أسبق';
            } elseif ($where === 'next') {
                $msg = 'لا يوجد سند لاحق';
            }
            json_response(['success' => false, 'message' => $msg]);
        }
        json_response(['success' => true, 'id' => $target]);

        return;
    }

    if ($action === 'search_manual') {
        if (!orange_journal_vouchers_ready($pdo)) {
            json_response(['success' => false, 'message' => 'جداول السندات غير جاهزة'], 422);
        }
        $et = orange_journal_manage_resolve_browse_entry_type($data, 'manual');
        $jtSearch = 0;
        $searchTypes = [$et];
        $etSearchFrag = 'entry_type = ?';
        if ($et === 'other_voucher') {
            $jtSearch = orange_journal_manage_other_voucher_require_journal_type_id($data);
            $searchTypes = orange_journal_manage_other_voucher_browse_entry_types($pdo, $jtSearch);
            if ($searchTypes === []) {
                json_response(['success' => false, 'message' => 'نوع اليومية المحدد غير مرتبط بأنواع قيود في الإعدادات'], 422);
            }
            [$etSearchFrag, $searchTypes] = orange_journal_manage_entry_type_sql_fragment($searchTypes);
        }
        $parts = [$etSearchFrag];
        $params = $searchTypes;
        $hasCriterion = false;

        $idFrom = (int) ($data['id_from'] ?? 0);
        $idTo = (int) ($data['id_to'] ?? 0);
        if ($idFrom > 0 && $idTo > 0) {
            if ($idFrom > $idTo) {
                $tmp = $idFrom;
                $idFrom = $idTo;
                $idTo = $tmp;
            }
            $parts[] = 'id BETWEEN ? AND ?';
            $params[] = $idFrom;
            $params[] = $idTo;
            $hasCriterion = true;
        } elseif ($idFrom > 0) {
            $parts[] = 'id = ?';
            $params[] = $idFrom;
            $hasCriterion = true;
        } elseif ($idTo > 0) {
            $parts[] = 'id = ?';
            $params[] = $idTo;
            $hasCriterion = true;
        }

        $dateFromRaw = trim((string) ($data['date_from'] ?? ''));
        $dateToRaw = trim((string) ($data['date_to'] ?? ''));
        $dfNorm = $dateFromRaw !== '' ? orange_parse_admin_date_to_ymd($dateFromRaw) : '';
        $dtNorm = $dateToRaw !== '' ? orange_parse_admin_date_to_ymd($dateToRaw) : '';
        $dfOk = $dfNorm !== '';
        $dtOk = $dtNorm !== '';
        if ($dfOk && $dtOk) {
            if ($dfNorm > $dtNorm) {
                $tmp = $dfNorm;
                $dfNorm = $dtNorm;
                $dtNorm = $tmp;
            }
            $parts[] = 'DATE(voucher_date) BETWEEN ? AND ?';
            $params[] = $dfNorm;
            $params[] = $dtNorm;
            $hasCriterion = true;
        } elseif ($dfOk) {
            $parts[] = 'DATE(voucher_date) = ?';
            $params[] = $dfNorm;
            $hasCriterion = true;
        } elseif ($dtOk) {
            $parts[] = 'DATE(voucher_date) = ?';
            $params[] = $dtNorm;
            $hasCriterion = true;
        }

        $ref = trim((string) ($data['reference'] ?? ''));
        if ($ref !== '') {
            $refEsc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $ref);
            $parts[] = 'reference LIKE ?';
            $params[] = '%' . $refEsc . '%';
            $hasCriterion = true;
        }

        $desc = trim((string) ($data['description'] ?? ''));
        if ($desc !== '') {
            $descEsc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $desc);
            $parts[] = 'description LIKE ?';
            $params[] = '%' . $descEsc . '%';
            $hasCriterion = true;
        }

        if (!$hasCriterion) {
            if ($et !== 'year_end_close') {
                json_response(['success' => false, 'message' => 'حدّد معيار بحث واحد على الأقل (رقم، تاريخ، مرجع، أو بيان)'], 422);
            }
        }

        $countryBind = orange_gl_voucher_country_bind($pdo, 'jv');
        $sql = 'SELECT jv.*,
            (SELECT COALESCE(SUM(jl.debit), 0) FROM journal_lines jl WHERE jl.voucher_id = jv.id) AS voucher_total
            FROM journal_vouchers jv
            WHERE ' . implode(' AND ', $parts) . $countryBind['sql']
            . (orange_table_has_column($pdo, 'journal_vouchers', 'voucher_serial')
                ? ' ORDER BY COALESCE(jv.voucher_serial, jv.id) DESC, jv.id DESC LIMIT 300'
                : ' ORDER BY jv.id DESC LIMIT 300');
        $st = $pdo->prepare($sql);
        $st->execute(array_merge($params, $countryBind['params']));
        $rows = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $vd = (string) ($row['voucher_date'] ?? '');
            $dateDisp = strlen($vd) >= 10 ? orange_format_date_dmY(substr($vd, 0, 10)) : '';
            $etRow = (string) ($row['entry_type'] ?? '');
            $rows[] = [
                'id' => (int) $row['id'],
                'display_no' => orange_journal_voucher_display_number($row),
                'voucher_date' => $dateDisp,
                'reference' => (string) ($row['reference'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'amount' => round((float) ($row['voucher_total'] ?? 0), 3),
                'entry_type' => $etRow,
                'entry_type_label' => orange_gl_entry_type_label_ar($etRow),
            ];
        }
        json_response(['success' => true, 'rows' => $rows, 'results' => $rows]);

        return;
    }

    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف السند مطلوب'], 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM journal_vouchers WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $v = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$v) {
        json_response(['success' => false, 'message' => 'السند غير موجود'], 404);
    }

    try {
        orange_journal_voucher_assert_admin_context($pdo, $id);
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }

    if (orange_fiscal_is_closed_for_voucher($pdo, $v)) {
        json_response([
            'success' => false,
            'message' => 'لا يمكن تعديل أو حذف سند ضمن سنة مالية مغلقة',
            'suggest_admin' => orange_gl_suggest_admin_fiscal_years_screen(),
        ], 422);
    }

    $lockTypes = orange_gl_entry_types_delete_locked_from_journal_ui();
    $entryTypeV = (string) ($v['entry_type'] ?? '');
    if (in_array($entryTypeV, $lockTypes, true)) {
        $blocked = [
            'success' => false,
            'message' => orange_gl_journal_delete_blocked_message_ar($entryTypeV),
        ];
        $suggest = orange_gl_journal_delete_blocked_admin_link($entryTypeV);
        if ($suggest !== null) {
            $blocked['suggest_admin'] = $suggest;
        }
        json_response($blocked, 422);
    }

    $jvKind = orange_edit_lock_kind_for_entry_type($entryTypeV);
    $adminJv = current_admin();
    if ($adminJv) {
        try {
            orange_edit_lock_assert_may_mutate(
                $pdo,
                $adminJv,
                $jvKind,
                $id,
                $action === 'delete' ? 'delete' : 'edit',
                isset($v['country_id']) ? (int) $v['country_id'] : null
            );
        } catch (RuntimeException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    if ($action === 'update') {
        $etReqUp = orange_journal_manage_resolve_ui_entry_type($data, 'manual');
        $linesInUp = $data['lines'] ?? null;
        if (!is_array($linesInUp) || count($linesInUp) < 2) {
            json_response(['success' => false, 'message' => 'يُشترط سطران صالحان على الأقل في السند'], 422);
        }
        $postLinesUp = orange_journal_manage_normalize_multiline_body($pdo, $linesInUp);
        orange_journal_manage_assert_receipt_cash_last_line($pdo, $entryTypeV, $postLinesUp);
        orange_journal_manage_assert_payment_cash_first_line($pdo, $entryTypeV, $postLinesUp);

        $descriptionUp = trim((string) ($data['description'] ?? ''));
        $dateRawUp = trim((string) ($data['date'] ?? ''));
        $dateUp = orange_normalize_admin_posted_datetime($dateRawUp);
        if ($dateUp === null) {
            json_response(['success' => false, 'message' => 'تاريخ السند غير صالح'], 422);
        }
        if ($descriptionUp === '') {
            json_response(['success' => false, 'message' => 'بيان السند مطلوب'], 422);
        }

        if ($etReqUp === 'other_voucher') {
            $jtUp = orange_journal_manage_other_voucher_require_journal_type_id($data);
            $allowedUp = orange_journal_manage_other_voucher_browse_entry_types($pdo, $jtUp);
            if (!in_array($entryTypeV, $allowedUp, true)) {
                json_response(['success' => false, 'message' => 'لا يمكن تعديل هذا السند مع فلتر نوع اليومية الحالي'], 422);
            }
        } elseif ($entryTypeV !== $etReqUp) {
            json_response(['success' => false, 'message' => 'نوع السند لا يطابق هذه الشاشة'], 422);
        }

        try {
            orange_voucher_update_multiline($pdo, $id, [
                'voucher_date' => $dateUp,
                'description' => $descriptionUp,
            ], $postLinesUp);
        } catch (InvalidArgumentException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            orange_admin_api_catch($e, 'تعذر تحديث السند', 422);
        }
        audit_log('journal_update', 'تم تحديث سند محاسبي رقم: ' . $id, 'journal_vouchers', $id);
        $vFresh = $pdo->prepare('SELECT * FROM journal_vouchers WHERE id = ? LIMIT 1');
        $vFresh->execute([$id]);
        $vRow = $vFresh->fetch(PDO::FETCH_ASSOC);
        if ($vRow) {
            orange_edit_lock_register_voucher($pdo, $vRow);
            orange_edit_lock_log_mutation($pdo, $jvKind, $id, 'edit');
        }
        json_response(['success' => true, 'message' => 'تم تحديث السند', 'id' => $id]);

        return;
    }

    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM journal_vouchers WHERE id = ?')->execute([$id]);
        audit_log('journal_delete', 'تم حذف سند محاسبي رقم: ' . $id, 'journal_vouchers', $id);
        json_response(['success' => true, 'message' => 'تم حذف السند']);

        return;
    }

    json_response(['success' => false, 'message' => 'التعديل غير مدعوم — احذف السند وأعد إدخاله'], 422);
} catch (Throwable $e) {
    orange_gl_api_catch_json($e, 'تعذر معالجة السند');
}
