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
    $action = trim((string) ($data['action'] ?? 'get'));

    if ($action === 'get') {
        $accounts = $pdo->query(
            'SELECT id, name, code FROM accounts ORDER BY COALESCE(code, \'\') ASC, name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $current = [];
        if (orange_table_exists($pdo, 'orange_gl_account_settings')) {
            $sql = orange_table_has_column($pdo, 'orange_gl_account_settings', 'journal_type_id')
                ? 'SELECT setting_key, account_id, journal_type_id FROM orange_gl_account_settings'
                : 'SELECT setting_key, account_id FROM orange_gl_account_settings';
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $current[(string) $r['setting_key']] = (int) $r['account_id'];
            }
        }

        json_response([
            'success' => true,
            'keys' => orange_gl_setting_key_labels(),
            'ui_key_order' => orange_gl_settings_ui_key_order(),
            'accounts' => $accounts,
            'current' => $current,
            'journal_rules' => orange_gl_journal_type_rules_list($pdo),
            'journal_types' => orange_journal_types_list($pdo),
        ]);
    }

    if ($action !== 'save') {
        json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
    }

    $allowedKeys = orange_gl_allowed_setting_keys();
    $uiKeys = orange_gl_settings_ui_key_order();
    $settings = isset($data['settings']) && is_array($data['settings']) ? $data['settings'] : [];
    $hasJtCol = orange_table_has_column($pdo, 'orange_gl_account_settings', 'journal_type_id');
    $hasRulesTable = orange_table_exists($pdo, 'orange_gl_journal_type_rules');

    $pdo->beginTransaction();
    $up = $hasJtCol
        ? $pdo->prepare(
            'INSERT INTO orange_gl_account_settings (setting_key, account_id, journal_type_id) VALUES (?, ?, NULL)
             ON DUPLICATE KEY UPDATE account_id = VALUES(account_id), journal_type_id = NULL'
        )
        : $pdo->prepare(
            'INSERT INTO orange_gl_account_settings (setting_key, account_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE account_id = VALUES(account_id)'
        );
    $del = $pdo->prepare('DELETE FROM orange_gl_account_settings WHERE setting_key = ?');

    foreach ($allowedKeys as $key) {
        if (!array_key_exists($key, $settings)) {
            continue;
        }
        $aid = (int) $settings[$key];
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
        $up->execute($hasJtCol ? [$key, $aid] : [$key, $aid]);
    }

    $resolved = [];
    if (orange_table_exists($pdo, 'orange_gl_account_settings')) {
        $placeholders = implode(',', array_fill(0, count($allowedKeys), '?'));
        $resStmt = $pdo->prepare("SELECT setting_key, account_id FROM orange_gl_account_settings WHERE setting_key IN ($placeholders)");
        $resStmt->execute($allowedKeys);
        while ($row = $resStmt->fetch(PDO::FETCH_ASSOC)) {
            $resolved[(string) $row['setting_key']] = (int) $row['account_id'];
        }
    }

    if ($hasRulesTable && array_key_exists('journal_rules', $data)) {
        $rawRules = $data['journal_rules'];
        if (!is_array($rawRules)) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'قواعد أنواع اليومية غير صالحة'], 422);
        }
        $chkJt = orange_table_exists($pdo, 'journal_types')
            ? $pdo->prepare('SELECT id FROM journal_types WHERE id = ? LIMIT 1')
            : null;
        $pdo->exec('DELETE FROM orange_gl_journal_type_rules');
        $insRule = $pdo->prepare(
            'INSERT INTO orange_gl_journal_type_rules (journal_type_id, debit_setting_key, credit_setting_key) VALUES (?,?,?)'
        );
        $seenJt = [];
        foreach ($rawRules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $jt = (int) ($rule['journal_type_id'] ?? 0);
            $dk = trim((string) ($rule['debit_setting_key'] ?? ''));
            $ck = trim((string) ($rule['credit_setting_key'] ?? ''));
            if ($jt <= 0 && $dk === '' && $ck === '') {
                continue;
            }
            if ($jt <= 0 || $dk === '' || $ck === '') {
                $pdo->rollBack();
                json_response([
                    'success' => false,
                    'message' => 'كل قاعدة تحتاج نوع يومية وبند مدين وبند دائن.',
                ], 422);
            }
            if ($dk === $ck) {
                $pdo->rollBack();
                json_response(['success' => false, 'message' => 'بند المدين والدائن يجب أن يختلفان في نفس القاعدة.'], 422);
            }
            if (!in_array($dk, $uiKeys, true) || !in_array($ck, $uiKeys, true)) {
                $pdo->rollBack();
                json_response([
                    'success' => false,
                    'message' => 'بند المدين/الدائن يجب أن يكون من البنود المعروضة في الجدول العلوي.',
                ], 422);
            }
            if (!$chkJt) {
                $pdo->rollBack();
                json_response(['success' => false, 'message' => 'جدول أنواع اليوميات غير متوفر'], 422);
            }
            $chkJt->execute([$jt]);
            if (!$chkJt->fetch()) {
                $pdo->rollBack();
                json_response(['success' => false, 'message' => 'نوع يومية غير صالح في قواعد الربط.'], 422);
            }
            if (isset($seenJt[$jt])) {
                $pdo->rollBack();
                json_response(['success' => false, 'message' => 'لا يُكرر نفس نوع اليومية في أكثر من سطر.'], 422);
            }
            $seenJt[$jt] = true;
            $aidD = (int) ($resolved[$dk] ?? 0);
            $aidC = (int) ($resolved[$ck] ?? 0);
            if ($aidD <= 0 || $aidC <= 0) {
                $pdo->rollBack();
                json_response([
                    'success' => false,
                    'message' => 'اربط حساباً للبندين في الجدول العلوي قبل حفظ قاعدة المدين/الدائن.',
                ], 422);
            }
            $insRule->execute([$jt, $dk, $ck]);
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
