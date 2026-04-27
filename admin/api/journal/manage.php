<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/gl_pending_movements.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/date_format.php';
require_admin_api();

/**
 * أنواع السندات من شاشات القيد اليدوي الموحّدة (سند قيد / قبض / صرف).
 *
 * @return list<string>
 */
function orange_journal_manage_ui_entry_types(): array
{
    return ['manual', 'receipt_voucher', 'payment_voucher'];
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
 * سند القبض: أول سطر يجب أن يكون حساب النقدية (الخزينة) من الإعدادات، مديناً بلا دائن.
 *
 * @param list<array{account_id:int,debit:float,credit:float,memo:string}> $postLines
 */
function orange_journal_manage_assert_receipt_cash_first_line(PDO $pdo, string $entryTypeNorm, array $postLines): void
{
    if ($entryTypeNorm !== 'receipt_voucher' || $postLines === []) {
        return;
    }
    $cashId = orange_gl_account_id_optional($pdo, 'cash');
    if ($cashId === null || $cashId <= 0) {
        json_response(['success' => false, 'message' => 'اربط حساب النقدية (الخزينة) في إعدادات القيود التلقائية لاستخدام سند القبض.'], 422);
    }
    $first = $postLines[0];
    if ((int) $first['account_id'] !== (int) $cashId) {
        json_response(['success' => false, 'message' => 'سند القبض يجب أن يبدأ بسطر حساب الخزينة (النقدية) كما في الشاشة.'], 422);
    }
    if ((float) $first['debit'] <= 0.0 || (float) $first['credit'] > 0.0) {
        json_response(['success' => false, 'message' => 'السطر الأول في سند القبض (الخزينة) يجب أن يكون مديناً بمبلغ القبض دون دائن.'], 422);
    }
}

/**
 * سند الصرف: آخر سطر يجب أن يكون حساب النقدية (الخزينة) من الإعدادات، دائناً بلا مدين.
 *
 * @param list<array{account_id:int,debit:float,credit:float,memo:string}> $postLines
 */
function orange_journal_manage_assert_payment_cash_last_line(PDO $pdo, string $entryTypeNorm, array $postLines): void
{
    if ($entryTypeNorm !== 'payment_voucher' || $postLines === []) {
        return;
    }
    $cashId = orange_gl_account_id_optional($pdo, 'cash');
    if ($cashId === null || $cashId <= 0) {
        json_response(['success' => false, 'message' => 'اربط حساب النقدية (الخزينة) في إعدادات القيود التلقائية لاستخدام سند الصرف.'], 422);
    }
    $last = $postLines[count($postLines) - 1];
    if ((int) $last['account_id'] !== (int) $cashId) {
        json_response(['success' => false, 'message' => 'سند الصرف يجب أن ينتهي بسطر حساب الخزينة (النقدية) كما في الشاشة.'], 422);
    }
    if ((float) $last['credit'] <= 0.0 || (float) $last['debit'] > 0.0) {
        json_response(['success' => false, 'message' => 'السطر الأخير في سند الصرف (الخزينة) يجب أن يكون دائناً بمبلغ الصرف دون مدين.'], 422);
    }
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $action = trim((string)($data['action'] ?? 'update'));

    if ($action === 'create') {
        $description = trim((string)($data['description'] ?? ''));
        $reference = trim((string)($data['reference'] ?? ''));
        $entryTypeNorm = orange_journal_manage_resolve_ui_entry_type($data, 'manual');
        $dateRaw = trim((string)($data['date'] ?? ''));
        $date = orange_normalize_admin_posted_datetime($dateRaw);
        if ($date === null) {
            json_response(['success' => false, 'message' => 'تاريخ السند غير صالح'], 422);
        }
        if ($description === '') {
            json_response(['success' => false, 'message' => 'بيان السند مطلوب'], 422);
        }

        $linesIn = $data['lines'] ?? null;
        if (is_array($linesIn) && count($linesIn) >= 2) {
            $norm = [];
            foreach ($linesIn as $ln) {
                if (!is_array($ln)) {
                    continue;
                }
                $norm[] = [
                    'account_id' => (int)($ln['account_id'] ?? 0),
                    'debit' => (float)($ln['debit'] ?? 0),
                    'credit' => (float)($ln['credit'] ?? 0),
                    'memo' => trim((string)($ln['memo'] ?? '')),
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
                if (($ln['memo'] ?? '') === '') {
                    json_response(['success' => false, 'message' => 'بيان كل سطر مطلوب'], 422);
                }
                $postLines[] = $ln;
            }
            if (count($postLines) < 2) {
                json_response(['success' => false, 'message' => 'يُشترط سطران صالحان على الأقل في السند'], 422);
            }
            orange_journal_manage_assert_receipt_cash_first_line($pdo, $entryTypeNorm, $postLines);
            orange_journal_manage_assert_payment_cash_last_line($pdo, $entryTypeNorm, $postLines);
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
            if (orange_gl_use_pending_queue($pdo)) {
                $refOut = $reference !== '' ? $reference : ('JM-' . str_replace(['.', ' '], '', uniqid('', true)));
                try {
                    $pendingId = orange_gl_pending_enqueue_multi(
                        $pdo,
                        $postLines,
                        $refOut,
                        $refOut,
                        $date,
                        $date,
                        $description,
                        $entryTypeNorm
                    );
                } catch (Throwable $e) {
                    orange_admin_api_catch($e, 'تعذر إضافة السند', 422);
                }
                if ($pendingId <= 0 && $reference !== '') {
                    json_response(['success' => false, 'message' => 'المرجع مسجّل مسبقاً في طابور الترحيل أو غير صالح'], 422);
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
                $vid = orange_voucher_post($pdo, [
                    'voucher_date' => $date,
                    'document_entered_at' => date('Y-m-d H:i:s'),
                    'reference' => $reference !== '' ? $reference : null,
                    'description' => $description,
                    'entry_type' => $entryTypeNorm,
                ], $postLines);
            } catch (Throwable $e) {
                orange_admin_api_catch($e, 'تعذر إضافة السند', 422);
            }
            audit_log('journal_create', 'تم إنشاء سند محاسبي رقم: ' . $vid, 'journal_vouchers', $vid);
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
            $refOut = $reference !== '' ? $reference : ('JM-' . str_replace(['.', ' '], '', uniqid('', true)));
            try {
                $pendingId = orange_gl_pending_enqueue_simple($pdo, [
                    'reference' => $refOut,
                    'source_label' => $refOut,
                    'movement_at' => $date,
                    'voucher_date' => $date,
                    'account_debit' => $accountDebit,
                    'account_credit' => $accountCredit,
                    'amount' => $amount,
                    'description' => $description,
                    'entry_type' => $entryTypeNorm,
                ]);
            } catch (Throwable $e) {
                orange_admin_api_catch($e, 'تعذر إضافة السند', 422);
            }
            if ($pendingId <= 0 && $reference !== '') {
                json_response(['success' => false, 'message' => 'المرجع مسجّل مسبقاً في طابور الترحيل أو غير صالح'], 422);
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
            $vid = orange_voucher_post($pdo, [
                'voucher_date' => $date,
                'document_entered_at' => date('Y-m-d H:i:s'),
                'reference' => $reference !== '' ? $reference : null,
                'description' => $description,
                'entry_type' => $entryTypeNorm,
            ], [
                ['account_id' => $accountDebit, 'debit' => $amount, 'credit' => 0, 'memo' => $description],
                ['account_id' => $accountCredit, 'debit' => 0, 'credit' => $amount, 'memo' => $description],
            ]);
        } catch (Throwable $e) {
            orange_admin_api_catch($e, 'تعذر إضافة السند', 422);
        }
        audit_log('journal_create', 'تم إنشاء سند محاسبي رقم: ' . $vid, 'journal_vouchers', $vid);
        json_response(['success' => true, 'message' => 'تم إضافة السند', 'id' => $vid]);

        return;
    }

    if ($action === 'get') {
        $etReq = orange_journal_manage_resolve_ui_entry_type($data, 'manual');
        $gid = (int)($data['id'] ?? 0);
        if ($gid <= 0) {
            json_response(['success' => false, 'message' => 'معرف السند مطلوب'], 422);
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
        if ((string)($v['entry_type'] ?? '') !== $etReq) {
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
        $lst = $pdo->prepare(
            'SELECT jl.account_id, jl.debit, jl.credit, jl.memo, a.code, a.name
             FROM journal_lines jl
             INNER JOIN accounts a ON a.id = jl.account_id
             WHERE jl.voucher_id = ?
             ORDER BY jl.line_no ASC'
        );
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
            ];
        }
        json_response([
            'success' => true,
            'voucher' => [
                'id' => (int) $v['id'],
                'date' => $dateForInput,
                'reference' => (string)($v['reference'] ?? ''),
                'description' => (string)($v['description'] ?? ''),
                'document_entered_display' => $docDisplay,
            ],
            'lines' => $lines,
        ]);

        return;
    }

    if ($action === 'nav_manual') {
        $et = orange_journal_manage_resolve_ui_entry_type($data, 'manual');
        $where = trim((string)($data['where'] ?? ''));
        $currentId = (int)($data['current_id'] ?? 0);
        if (!orange_journal_vouchers_ready($pdo)) {
            json_response(['success' => false, 'message' => 'جداول السندات غير جاهزة'], 422);
        }
        $cSt = $pdo->prepare('SELECT COUNT(*) FROM journal_vouchers WHERE entry_type = ?');
        $cSt->execute([$et]);
        if ((int) $cSt->fetchColumn() === 0) {
            json_response(['success' => false, 'message' => 'لا توجد سندات من هذا النوع بعد']);
        }
        $target = 0;
        if ($where === 'first') {
            $q = $pdo->prepare('SELECT COALESCE(MIN(id), 0) FROM journal_vouchers WHERE entry_type = ?');
            $q->execute([$et]);
            $target = (int) $q->fetchColumn();
        } elseif ($where === 'last') {
            $q = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM journal_vouchers WHERE entry_type = ?');
            $q->execute([$et]);
            $target = (int) $q->fetchColumn();
        } elseif ($where === 'prev') {
            if ($currentId <= 0) {
                $q = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM journal_vouchers WHERE entry_type = ?');
                $q->execute([$et]);
                $target = (int) $q->fetchColumn();
            } else {
                $q = $pdo->prepare('SELECT id FROM journal_vouchers WHERE entry_type = ? AND id < ? ORDER BY id DESC LIMIT 1');
                $q->execute([$et, $currentId]);
                $row = $q->fetch(PDO::FETCH_ASSOC);
                $target = $row ? (int) $row['id'] : 0;
            }
        } elseif ($where === 'next') {
            if ($currentId <= 0) {
                $q = $pdo->prepare('SELECT COALESCE(MIN(id), 0) FROM journal_vouchers WHERE entry_type = ?');
                $q->execute([$et]);
                $target = (int) $q->fetchColumn();
            } else {
                $q = $pdo->prepare('SELECT id FROM journal_vouchers WHERE entry_type = ? AND id > ? ORDER BY id ASC LIMIT 1');
                $q->execute([$et, $currentId]);
                $row = $q->fetch(PDO::FETCH_ASSOC);
                $target = $row ? (int) $row['id'] : 0;
            }
        } else {
            json_response(['success' => false, 'message' => 'أمر تنقل غير معروف'], 422);
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
        $et = orange_journal_manage_resolve_ui_entry_type($data, 'manual');
        $parts = ['entry_type = ?'];
        $params = [$et];
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
            json_response(['success' => false, 'message' => 'حدّد معيار بحث واحد على الأقل (رقم، تاريخ، مرجع، أو بيان)'], 422);
        }

        $sql = 'SELECT jv.id, jv.voucher_date, jv.reference, jv.description,
            (SELECT COALESCE(SUM(jl.debit), 0) FROM journal_lines jl WHERE jl.voucher_id = jv.id) AS voucher_total
            FROM journal_vouchers jv
            WHERE ' . implode(' AND ', $parts)
            . ' ORDER BY jv.id DESC LIMIT 300';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $vd = (string) ($row['voucher_date'] ?? '');
            $dateDisp = strlen($vd) >= 10 ? orange_format_date_dmY(substr($vd, 0, 10)) : '';
            $rows[] = [
                'id' => (int) $row['id'],
                'voucher_date' => $dateDisp,
                'reference' => (string) ($row['reference'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'amount' => round((float) ($row['voucher_total'] ?? 0), 3),
            ];
        }
        json_response(['success' => true, 'rows' => $rows]);

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
