<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/account_tree.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/journal_types.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $data = get_json_input();
    $action = trim((string)($data['action'] ?? 'get'));

    if ($action === 'get') {
        $accounts = $pdo->query(
            'SELECT id, name, code FROM accounts ORDER BY COALESCE(code, \'\') ASC, name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $current = [];
        $currentJournalTypes = [];
        if (orange_table_exists($pdo, 'orange_gl_account_settings')) {
            $hasJt = orange_table_has_column($pdo, 'orange_gl_account_settings', 'journal_type_id');
            $sql = $hasJt
                ? 'SELECT setting_key, account_id, journal_type_id FROM orange_gl_account_settings'
                : 'SELECT setting_key, account_id FROM orange_gl_account_settings';
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $k = (string) $r['setting_key'];
                $current[$k] = (int) $r['account_id'];
                $currentJournalTypes[$k] = $hasJt ? (int) ($r['journal_type_id'] ?? 0) : 0;
            }
        }

        json_response([
            'success' => true,
            'keys' => orange_gl_setting_key_labels(),
            'accounts' => $accounts,
            'current' => $current,
            'current_journal_type_ids' => $currentJournalTypes,
            'journal_types' => orange_journal_types_list($pdo),
        ]);
    }

    if ($action !== 'save') {
        json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
    }

    $allowedKeys = orange_gl_allowed_setting_keys();
    $settings = isset($data['settings']) && is_array($data['settings']) ? $data['settings'] : [];
    $jtMap = isset($data['journal_type_ids']) && is_array($data['journal_type_ids']) ? $data['journal_type_ids'] : [];
    $hasJtCol = orange_table_has_column($pdo, 'orange_gl_account_settings', 'journal_type_id');

    $pdo->beginTransaction();
    $up = $hasJtCol
        ? $pdo->prepare(
            'INSERT INTO orange_gl_account_settings (setting_key, account_id, journal_type_id) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE account_id = VALUES(account_id), journal_type_id = VALUES(journal_type_id)'
        )
        : $pdo->prepare(
            'INSERT INTO orange_gl_account_settings (setting_key, account_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE account_id = VALUES(account_id)'
        );
    $del = $pdo->prepare('DELETE FROM orange_gl_account_settings WHERE setting_key = ?');
    $chkJt = orange_table_exists($pdo, 'journal_types')
        ? $pdo->prepare('SELECT id FROM journal_types WHERE id = ? LIMIT 1')
        : null;

    foreach ($allowedKeys as $key) {
        if (!array_key_exists($key, $settings)) {
            continue;
        }
        $aid = (int) $settings[$key];
        $jtId = isset($jtMap[$key]) ? (int) $jtMap[$key] : 0;

        if ($hasJtCol) {
            if ($aid > 0 && $jtId <= 0) {
                $pdo->rollBack();
                json_response([
                    'success' => false,
                    'message' => 'لا يُحفظ حساب دون اختيار نوع يومية للبند: ' . $key,
                ], 422);
            }
            if ($aid <= 0 && $jtId > 0) {
                $pdo->rollBack();
                json_response([
                    'success' => false,
                    'message' => 'لا يُحفظ نوع يومية دون ربط حساب للبند: ' . $key,
                ], 422);
            }
        }

        if ($aid <= 0) {
            $del->execute([$key]);
            continue;
        }
        $chk = $pdo->prepare('SELECT id FROM accounts WHERE id = ? LIMIT 1');
        $chk->execute([$aid]);
        if (!$chk->fetch()) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'حساب غير صالح: ' . $key], 422);
        }
        if (!orange_accounts_account_is_posting_leaf($pdo, $aid)) {
            $pdo->rollBack();
            json_response([
                'success' => false,
                'message' => 'يُقبل ربط القيود التلقائية مع حساب فرعي (ورقة ترحيل) فقط — ليس جذراً أو مجلداً: ' . $key,
            ], 422);
        }
        if ($hasJtCol && $jtId > 0) {
            if (!$chkJt) {
                $pdo->rollBack();
                json_response(['success' => false, 'message' => 'جدول أنواع اليوميات غير متوفر'], 422);
            }
            $chkJt->execute([$jtId]);
            if (!$chkJt->fetch()) {
                $pdo->rollBack();
                json_response(['success' => false, 'message' => 'نوع يومية غير صالح للبند: ' . $key], 422);
            }
        }
        if ($hasJtCol) {
            $up->execute([$key, $aid, $jtId > 0 ? $jtId : null]);
        } else {
            $up->execute([$key, $aid]);
        }
    }

    $pdo->commit();
    audit_log('gl_settings_save', 'تم تحديث الحسابات الأساسية للقيود التلقائية', 'orange_gl_account_settings', 0);
    json_response(['success' => true, 'message' => 'تم حفظ الربط']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
