<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/account_tree.php';
require_once __DIR__ . '/gl_settings.php';

/**
 * معرف حساب الذمة الدائنة الفعلي للمورد: عمود payable_account_id إن وُجد وصالح، وإلا حساب «ذمم الموردين» من الإعدادات.
 *
 * @deprecated للترحيل التلقائي يُفضّل orange_supplier_required_payable_account_id عند السياسة «ذمة لكل مورد».
 */
function orange_supplier_payable_account_id(PDO $pdo, int $supplierId): int
{
    if ($supplierId <= 0 || !orange_table_has_column($pdo, 'suppliers', 'payable_account_id')) {
        return orange_gl_account_id($pdo, 'accounts_payable');
    }
    $st = $pdo->prepare('SELECT payable_account_id FROM suppliers WHERE id = ? LIMIT 1');
    $st->execute([$supplierId]);
    $raw = $st->fetchColumn();
    if ($raw === false || $raw === null) {
        return orange_gl_account_id($pdo, 'accounts_payable');
    }
    $aid = (int) $raw;
    if ($aid <= 0 || !orange_accounts_account_is_posting_leaf($pdo, $aid)) {
        return orange_gl_account_id($pdo, 'accounts_payable');
    }

    return $aid;
}

/**
 * حساب ذمة المورد الإلزامي (ورقة دليل) — بدون الرجوع لحساب مجمع.
 *
 * @throws RuntimeException
 */
function orange_supplier_required_payable_account_id(PDO $pdo, int $supplierId): int
{
    if ($supplierId <= 0) {
        throw new RuntimeException('شراء آجل أو دفع مورد يتطلب اختيار مورد.');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'payable_account_id')) {
        throw new RuntimeException('قاعدة البيانات تحتاج عمود payable_account_id في جدول الموردين — حدّث المخطط.');
    }
    $st = $pdo->prepare('SELECT payable_account_id FROM suppliers WHERE id = ? LIMIT 1');
    $st->execute([$supplierId]);
    $raw = $st->fetchColumn();
    if ($raw === false || $raw === null) {
        throw new RuntimeException('المورد غير مربوط بحساب ذمة في الدليل. افتح «الموردين» واختر حساباً فرعياً تحت الخصوم.');
    }
    $aid = (int) $raw;
    if ($aid <= 0 || !orange_accounts_account_is_posting_leaf($pdo, $aid)) {
        throw new RuntimeException('حساب ذمة المورد غير صالح (يجب أن يكون حساباً فرعياً في الدليل). حدّث بيانات المورد.');
    }

    return $aid;
}

/**
 * يتحقق أن المورد موجود ونشط ليُستخدم في مستندات الشراء.
 *
 * @throws RuntimeException
 */
function orange_supplier_assert_active_for_purchase(PDO $pdo, int $supplierId): void
{
    if ($supplierId <= 0) {
        return;
    }
    if (!orange_table_exists($pdo, 'suppliers')) {
        throw new RuntimeException('جدول الموردين غير متوفر.');
    }
    $hasStatus = orange_table_has_column($pdo, 'suppliers', 'status');
    $hasLegacyActive = orange_table_has_column($pdo, 'suppliers', 'is_active');
    $hasLegacyBlocked = orange_table_has_column($pdo, 'suppliers', 'is_blocked');
    $hasBlockReason = orange_table_has_column($pdo, 'suppliers', 'block_reason');

    $cols = ['id'];
    if ($hasStatus) {
        $cols[] = 'status';
    } else {
        $cols[] = $hasLegacyActive ? 'is_active' : '1 AS is_active';
        $cols[] = $hasLegacyBlocked ? 'is_blocked' : '0 AS is_blocked';
    }
    $cols[] = $hasBlockReason ? 'block_reason' : 'NULL AS block_reason';
    $st = $pdo->prepare('SELECT ' . implode(', ', $cols) . ' FROM suppliers WHERE id = ? LIMIT 1');
    $st->execute([$supplierId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('المورد غير موجود.');
    }
    $status = 'active';
    if ($hasStatus) {
        $statusRaw = strtolower(trim((string) ($row['status'] ?? 'active')));
        if (in_array($statusRaw, ['active', 'inactive', 'blocked'], true)) {
            $status = $statusRaw;
        }
    } else {
        $legacyBlocked = (int) ($row['is_blocked'] ?? 0) === 1;
        $legacyActive = (int) ($row['is_active'] ?? 1) === 1;
        if ($legacyBlocked) {
            $status = 'blocked';
        } elseif (!$legacyActive) {
            $status = 'inactive';
        }
    }
    if ($status === 'inactive') {
        throw new RuntimeException('المورد غير نشط. فعّل المورد أولاً ثم احفظ الفاتورة.');
    }
    if ($status === 'blocked') {
        $reason = trim((string) ($row['block_reason'] ?? ''));
        if ($reason !== '') {
            throw new RuntimeException('المورد محظور مؤقتاً: ' . $reason);
        }
        throw new RuntimeException('المورد محظور مؤقتاً. ألغِ الحظر أولاً ثم احفظ الفاتورة.');
    }
}

/**
 * هل للمورد حساب ذمة مخصّص في الدليل (ورقة ترحيل)؟
 */
function orange_supplier_has_dedicated_payable_account(PDO $pdo, int $supplierId): bool
{
    if ($supplierId <= 0 || !orange_table_has_column($pdo, 'suppliers', 'payable_account_id')) {
        return false;
    }
    $st = $pdo->prepare('SELECT payable_account_id FROM suppliers WHERE id = ? LIMIT 1');
    $st->execute([$supplierId]);
    $raw = $st->fetchColumn();
    if ($raw === false || $raw === null) {
        return false;
    }
    $aid = (int) $raw;

    return $aid > 0 && orange_accounts_account_is_posting_leaf($pdo, $aid);
}

/**
 * حسابات الذمم في الأستاذ للمطابقة مع دفتر الموردين: المجمع من الإعدادات + أي حسابات مربوطة بموردين.
 *
 * @return list<int>
 */
function orange_supplier_payable_gl_account_ids_for_reconcile(PDO $pdo): array
{
    $ids = [orange_gl_account_id($pdo, 'accounts_payable')];
    if (!orange_table_has_column($pdo, 'suppliers', 'payable_account_id')) {
        return array_values(array_unique(array_filter($ids, static fn (int $i): bool => $i > 0)));
    }
    $st = $pdo->query(
        'SELECT DISTINCT payable_account_id FROM suppliers WHERE payable_account_id IS NOT NULL AND payable_account_id > 0'
    );
    if ($st) {
        while ($col = $st->fetchColumn()) {
            $i = (int) $col;
            if ($i > 0) {
                $ids[] = $i;
            }
        }
    }

    return array_values(array_unique(array_filter($ids, static fn (int $i): bool => $i > 0)));
}
