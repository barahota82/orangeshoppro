<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/account_tree.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'accounts')) {
        json_response(['success' => false, 'message' => 'جدول الحسابات غير متوفر'], 500);
    }

    $data = get_json_input();
    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرّف الحساب غير صالح'], 422);
    }

    $st = $pdo->prepare('SELECT id, name, code, parent_id FROM accounts WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_response(['success' => false, 'message' => 'الحساب غير موجود'], 404);
    }

    $label = trim((string) ($row['code'] ?? '')) !== ''
        ? $row['code'] . ' — ' . $row['name']
        : (string) $row['name'];

    if (orange_table_has_column($pdo, 'accounts', 'parent_id')) {
        $pid = isset($row['parent_id']) ? (int) $row['parent_id'] : 0;
        if ($pid <= 0) {
            $rk = orange_accounts_root_rank($pdo, $id);
            if ($rk >= 1 && $rk <= 4) {
                json_response([
                    'success' => false,
                    'message' => 'أول أربعة حسابات في الدليل (الحد الأدنى) لا يمكن حذفها.',
                ], 422);
            }
        }
        $ch = $pdo->prepare('SELECT COUNT(*) FROM accounts WHERE parent_id = ?');
        $ch->execute([$id]);
        if ((int) $ch->fetchColumn() > 0) {
            json_response([
                'success' => false,
                'message' => 'لا يمكن الحذف: يوجد حسابات فرعية تحت هذا الحساب. انقلها أو احذفها أولاً.',
            ], 409);
        }
    }

    if (orange_table_exists($pdo, 'orange_gl_account_settings')) {
        $gk = $pdo->prepare('SELECT setting_key FROM orange_gl_account_settings WHERE account_id = ?');
        $gk->execute([$id]);
        $keys = $gk->fetchAll(PDO::FETCH_COLUMN);
        if (is_array($keys) && $keys !== []) {
            $labMap = orange_gl_setting_key_labels();
            $parts = [];
            foreach ($keys as $k) {
                $k = (string) $k;
                $parts[] = $labMap[$k] ?? $k;
            }
            json_response([
                'success' => false,
                'message' => 'الحساب مربوط بإعدادات القيود التلقائية: ' . implode('، ', $parts)
                    . ' — غيّر الربط من «حسابات القيود التلقائية» ثم أعد المحاولة.',
            ], 409);
        }
    }

    if (orange_table_exists($pdo, 'journal_lines')) {
        $jl = $pdo->prepare('SELECT COUNT(*) FROM journal_lines WHERE account_id = ?');
        $jl->execute([$id]);
        if ((int) $jl->fetchColumn() > 0) {
            json_response([
                'success' => false,
                'message' => 'لا يمكن الحذف: يوجد حركات في سندات محاسبية على هذا الحساب.',
            ], 409);
        }
    }

    if (orange_table_exists($pdo, 'journal_entries')) {
        $je = $pdo->prepare(
            'SELECT COUNT(*) FROM journal_entries WHERE account_debit = ? OR account_credit = ?'
        );
        $je->execute([$id, $id]);
        if ((int) $je->fetchColumn() > 0) {
            json_response([
                'success' => false,
                'message' => 'لا يمكن الحذف: يوجد قيود قديمة (journal_entries) تستخدم هذا الحساب.',
            ], 409);
        }
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (function_exists('error_log')) {
            error_log('[orange] account delete: ' . $e->getMessage());
        }
        json_response([
            'success' => false,
            'message' => 'تعذر الحذف — قد يكون الحساب مرتبطاً ببيانات أخرى في القاعدة.',
        ], 409);
    }

    audit_log('account_delete', 'حذف حساب: ' . $label, 'accounts', $id);
    json_response(['success' => true, 'message' => 'تم حذف الحساب']);
} catch (Throwable $e) {
    api_error($e, 'تعذر حذف الحساب');
}
