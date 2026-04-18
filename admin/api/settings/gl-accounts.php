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

    $data = get_json_input();
    $action = trim((string)($data['action'] ?? 'get'));

    if ($action === 'get') {
        $accounts = $pdo->query(
            'SELECT id, name, code FROM accounts ORDER BY COALESCE(code, \'\') ASC, name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $current = [];
        if (orange_table_exists($pdo, 'orange_gl_account_settings')) {
            $rows = $pdo->query('SELECT setting_key, account_id FROM orange_gl_account_settings')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $current[(string)$r['setting_key']] = (int)$r['account_id'];
            }
        }

        json_response([
            'success' => true,
            'keys' => orange_gl_setting_key_labels(),
            'accounts' => $accounts,
            'current' => $current,
        ]);
    }

    if ($action !== 'save') {
        json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
    }

    $allowedKeys = orange_gl_allowed_setting_keys();
    $settings = isset($data['settings']) && is_array($data['settings']) ? $data['settings'] : [];

    $pdo->beginTransaction();
    $up = $pdo->prepare(
        'INSERT INTO orange_gl_account_settings (setting_key, account_id) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE account_id = VALUES(account_id)'
    );
    $del = $pdo->prepare('DELETE FROM orange_gl_account_settings WHERE setting_key = ?');

    foreach ($allowedKeys as $key) {
        if (!array_key_exists($key, $settings)) {
            continue;
        }
        $aid = (int)$settings[$key];
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
        $up->execute([$key, $aid]);
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
