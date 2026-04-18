<?php

declare(strict_types=1);

/**
 * ربط الحسابات الأساسية (مفاتيح ثابتة) بحسابات الدليل — يُضبط من لوحة الإدارة.
 * يدعم عمود accounts.code لمطابقة كود الشجرة في العرض فقط؛ القيود تستخدم account_id.
 */

/**
 * @return array<string, string> مفتاح إنجليزي ثابت => وصف عربي للشاشة
 */
function orange_gl_setting_key_labels(): array
{
    return [
        'cash' => 'الخزينة / النقدية — دائن شراء نقدي؛ مدين تحصيل مبيعات نقدي',
        'inventory' => 'المخزون — مدين شراء؛ دائن تكلفة البضاعة المباعة',
        'accounts_payable' => 'ذمم الموردين — دائن شراء آجل',
        'sales_revenue_cash' => 'إيراد مبيعات نقدي — دائن عند تسليم طلب نقدي',
        'sales_revenue_credit' => 'إيراد مبيعات آجل — دائن عند تسليم طلب آجل',
        'ar_cash' => 'عملاء نقدي (اختياري لاحقًا للتحصيل)',
        'ar_credit' => 'عملاء آجل — مدين عند تسليم طلب آجل',
        'cogs_cash' => 'تكلفة مبيعات نقدي — مدين عند التسليم',
        'cogs_credit' => 'تكلفة مبيعات آجل — مدين عند التسليم',
        'income_summary' => 'ملخص الدخل (مؤقت) — يُستخدم في قيود إقفال السنة فقط',
        'retained_earnings' => 'الأرباح المحتجزة / حقوق ملكية — يُنقل إليه صافي الدخل عند الإقفال',
    ];
}

/**
 * @return list<string>
 */
function orange_gl_allowed_setting_keys(): array
{
    return array_keys(orange_gl_setting_key_labels());
}

/**
 * أسماء الحسابات الإنجليزية القديمة في Orange (قبل شجرة كليك) كاحتياط.
 *
 * @return array<string, string>
 */
function orange_gl_legacy_name_fallbacks(): array
{
    return [
        'cash' => 'Cash',
        'inventory' => 'Inventory',
        'accounts_payable' => 'Accounts Payable',
        'sales_revenue_cash' => 'Sales',
        'sales_revenue_credit' => 'Sales',
        'ar_cash' => 'Cash',
        'ar_credit' => 'Sales',
        'cogs_cash' => 'COGS',
        'cogs_credit' => 'COGS',
        'income_summary' => 'Income Summary',
        'retained_earnings' => 'Retained Earnings',
    ];
}

function orange_gl_resolve_legacy_account_id(PDO $pdo, string $key): int
{
    $fb = orange_gl_legacy_name_fallbacks();
    if (!isset($fb[$key])) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT id FROM accounts WHERE name = ? LIMIT 1');
    $stmt->execute([$fb[$key]]);
    $id = (int)$stmt->fetchColumn();

    return $id > 0 ? $id : 0;
}

/**
 * معرف الحساب المرتبط بمفتاح القيد التلقائي، أو الاحتياط القديم بالاسم.
 *
 * @throws RuntimeException إذا تعذر إيجاد حساب (بعد ضبط الشجرة اربط من شاشة «الحسابات الأساسية»)
 */
function orange_gl_account_id(PDO $pdo, string $key): int
{
    static $cache = [];
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    if (!orange_table_exists($pdo, 'orange_gl_account_settings')) {
        $legacy = orange_gl_resolve_legacy_account_id($pdo, $key);
        if ($legacy > 0) {
            $cache[$key] = $legacy;

            return $legacy;
        }
        throw new RuntimeException(
            'لم يُضبط الدليل المحاسبي. من الأدمن: «الحسابات الأساسية للقيود التلقائية».'
        );
    }

    $stmt = $pdo->prepare('SELECT account_id FROM orange_gl_account_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $id = (int)$stmt->fetchColumn();
    if ($id > 0) {
        $cache[$key] = $id;

        return $id;
    }

    $legacy = orange_gl_resolve_legacy_account_id($pdo, $key);
    if ($legacy > 0) {
        $cache[$key] = $legacy;

        return $legacy;
    }

    $labels = orange_gl_setting_key_labels();
    $lab = $labels[$key] ?? $key;
    throw new RuntimeException(
        'حساب أساسي غير مربوط: ' . $lab . ' — افتح «الحسابات الأساسية للقيود التلقائية» واختر الحساب من الشجرة.'
    );
}
