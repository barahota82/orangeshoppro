<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/**
 * ربط الحسابات الأساسية (مفاتيح ثابتة) بحسابات الدليل — يُضبط من لوحة الإدارة.
 *
 * في البرمجة يُعتمد على account_id المحفوظ في orange_gl_account_settings؛ الأسماء عربية للعرض فقط.
 * أي احتياط قديم يطابق الحساب بـ accounts.code (وليس بالاسم).
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
        'ar_cash' => 'عملاء نقدي — ذمم أو وسيط تحصيل للمبيعات النقدية (حسب هيكل الدليل)',
        'ar_credit' => 'عملاء آجل — مدين عند تسليم طلب آجل',
        'sales_returns_cash' => 'مردود مبيعات نقدي — يُستخدم عند تسجيل مرتجعات المبيعات النقدية',
        'sales_returns_credit' => 'مردود مبيعات آجل — يُستخدم عند تسجيل مرتجعات المبيعات الآجلة',
        'cogs_cash' => 'تكلفة مبيعات نقدي — مدين عند التسليم',
        'cogs_credit' => 'تكلفة مبيعات آجل — مدين عند التسليم',
        'cogs_returns_cash' => 'تكلفة مردود مبيعات نقدي — دائن عند إثبات تكلفة المرتجع النقدي',
        'cogs_returns_credit' => 'تكلفة مردود مبيعات آجل — دائن عند إثبات تكلفة المرتجع الآجل',
        'income_summary' => 'ملخص الدخل (مؤقت) — يُستخدم في قيود إقفال السنة فقط',
        'retained_earnings' => 'الأرباح المحتجزة / حقوق ملكية — يُنقل إليه صافي الدخل عند الإقفال',
    ];
}

/**
 * تسمية الصف القصيرة في جدول «حسابات القيود التلقائية» (عمود يمين).
 *
 * @return array<string, string>
 */
function orange_gl_setting_row_short_labels(): array
{
    return [
        'cash' => 'الخزينة',
        'inventory' => 'المخزن',
        'ar_cash' => 'العملاء النقدي',
        'ar_credit' => 'العملاء الاجل',
        'sales_revenue_cash' => 'المبيعات النقدية',
        'sales_revenue_credit' => 'المبيعات الآجل',
        'sales_returns_cash' => 'مردود المبيعات النقدي',
        'sales_returns_credit' => 'مردود المبيعات الاجل',
        'cogs_cash' => 'تكلفة المبيعات النقدي',
        'cogs_credit' => 'تكلفة المبيعات الآجلة',
        'cogs_returns_cash' => 'تكلفة مردود المبيعات النقدي',
        'cogs_returns_credit' => 'تكلفة مردود المبيعات الآجلة',
        'accounts_payable' => 'ذمم الموردين',
        'income_summary' => 'ملخص الدخل (إقفال)',
        'retained_earnings' => 'الأرباح المحتجزة (إقفال)',
    ];
}

/**
 * ترتيب الصفوف في الشاشة (مطابق المرجع ثم باقي البنود).
 *
 * @return list<string>
 */
function orange_gl_settings_form_key_order(): array
{
    return [
        'cash',
        'inventory',
        'ar_cash',
        'ar_credit',
        'sales_revenue_cash',
        'sales_revenue_credit',
        'sales_returns_cash',
        'sales_returns_credit',
        'cogs_cash',
        'cogs_credit',
        'cogs_returns_cash',
        'cogs_returns_credit',
        'accounts_payable',
        'income_summary',
        'retained_earnings',
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
 * احتياط اختياري: مفتاح الإعداد => كود الحساب في الدليل (يطابق accounts.code).
 * افتراضياً فارغ — الربط الصحيح من شاشة «حسابات القيود التلقائية» (account_id).
 *
 * @return array<string, string>
 */
function orange_gl_legacy_code_fallbacks(): array
{
    return [];
}

/**
 * @deprecated استخدم orange_gl_legacy_code_fallbacks()
 * @return array<string, string>
 */
function orange_gl_legacy_name_fallbacks(): array
{
    return orange_gl_legacy_code_fallbacks();
}

function orange_gl_resolve_legacy_account_id(PDO $pdo, string $key): int
{
    if (!orange_table_exists($pdo, 'accounts') || !orange_table_has_column($pdo, 'accounts', 'code')) {
        return 0;
    }
    $fb = orange_gl_legacy_code_fallbacks();
    if (!isset($fb[$key])) {
        return 0;
    }
    $code = trim((string) $fb[$key]);
    if ($code === '') {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT id FROM accounts WHERE code = ? LIMIT 1');
    $stmt->execute([$code]);
    $id = (int) $stmt->fetchColumn();

    return $id > 0 ? $id : 0;
}

/**
 * معرف الحساب لقيد تلقائي: من الجدول أولاً، ثم احتياط بالكود إن وُجد في orange_gl_legacy_code_fallbacks.
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
