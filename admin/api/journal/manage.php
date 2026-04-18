<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $action = trim((string)($data['action'] ?? 'update'));

    if ($action === 'create') {
        $description = trim((string)($data['description'] ?? ''));
        $reference = trim((string)($data['reference'] ?? ''));
        $entryType = trim((string)($data['entry_type'] ?? 'manual'));
        $dateRaw = trim((string)($data['date'] ?? ''));
        $date = $dateRaw !== '' ? $dateRaw : date('Y-m-d H:i:s');
        if (strlen($date) === 10) {
            $date .= ' 12:00:00';
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
            }
            if (orange_table_has_column($pdo, 'accounts', 'is_group')) {
                foreach ($norm as $ln) {
                    $aid = (int) $ln['account_id'];
                    if ($aid <= 0) {
                        continue;
                    }
                    $chk = $pdo->prepare('SELECT is_group FROM accounts WHERE id = ? LIMIT 1');
                    $chk->execute([$aid]);
                    if ((int) $chk->fetchColumn() === 1) {
                        json_response(['success' => false, 'message' => 'لا يُسجَّل على حساب رئيسي — اختر حساباً فرعياً من الدليل'], 422);
                    }
                }
            }
            try {
                $vid = orange_voucher_post($pdo, [
                    'voucher_date' => $date,
                    'reference' => $reference !== '' ? $reference : null,
                    'description' => $description,
                    'entry_type' => $entryType !== '' ? $entryType : 'manual',
                ], $norm);
            } catch (Throwable $e) {
                json_response(['success' => false, 'message' => $e->getMessage()], 422);
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
        try {
            $vid = orange_voucher_post($pdo, [
                'voucher_date' => $date,
                'reference' => $reference !== '' ? $reference : null,
                'description' => $description,
                'entry_type' => $entryType !== '' ? $entryType : 'manual',
            ], [
                ['account_id' => $accountDebit, 'debit' => $amount, 'credit' => 0, 'memo' => $description],
                ['account_id' => $accountCredit, 'debit' => 0, 'credit' => $amount, 'memo' => $description],
            ]);
        } catch (Throwable $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 422);
        }
        audit_log('journal_create', 'تم إنشاء سند محاسبي رقم: ' . $vid, 'journal_vouchers', $vid);
        json_response(['success' => true, 'message' => 'تم إضافة السند', 'id' => $vid]);

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
        json_response(['success' => false, 'message' => 'لا يمكن تعديل أو حذف سند ضمن سنة مالية مغلقة'], 422);
    }

    $lockTypes = ['year_end_close', 'opening_balance'];
    if (in_array((string)($v['entry_type'] ?? ''), $lockTypes, true)) {
        json_response(['success' => false, 'message' => 'لا يمكن حذف سند إقفال أو أرصدة افتتاحية من هنا'], 422);
    }

    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM journal_vouchers WHERE id = ?')->execute([$id]);
        audit_log('journal_delete', 'تم حذف سند محاسبي رقم: ' . $id, 'journal_vouchers', $id);
        json_response(['success' => true, 'message' => 'تم حذف السند']);

        return;
    }

    json_response(['success' => false, 'message' => 'التعديل غير مدعوم — احذف السند وأعد إدخاله'], 422);
} catch (Throwable $e) {
    api_error($e, 'تعذر معالجة السند');
}
