<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/account_tree.php';

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
        /** لا يُعرض في شاشة «حسابات القيود التلقائية»؛ يُستخدم في مشتريات آجل وسندات الموردين. */
        'accounts_payable' => 'ذمم الموردين — دائن شراء آجل',
        'sales_revenue_cash' => 'إيراد مبيعات نقدي — دائن عند تسليم طلب نقدي',
        'sales_revenue_credit' => 'إيراد مبيعات آجل — دائن عند تسليم طلب آجل',
        'sales_revenue_online' => 'إيراد مبيعات أونلاين — دائن عند تسليم طلب أونلاين',
        'ar_cash' => 'عملاء نقدي — ذمم أو وسيط تحصيل للمبيعات النقدية (حسب هيكل الدليل)',
        'ar_credit' => 'عملاء آجل — مدين عند تسليم طلب آجل',
        'sales_returns_cash' => 'مردود مبيعات نقدي — يُستخدم عند تسجيل مرتجعات المبيعات النقدية',
        'sales_returns_credit' => 'مردود مبيعات آجل — يُستخدم عند تسجيل مرتجعات المبيعات الآجلة',
        'sales_returns_online' => 'مردود مبيعات أونلاين',
        'cogs_cash' => 'تكلفة مبيعات نقدي — مدين عند التسليم',
        'cogs_credit' => 'تكلفة مبيعات آجل — مدين عند التسليم',
        'cogs_online' => 'تكلفة مبيعات أونلاين — مدين عند التسليم',
        'cogs_returns_cash' => 'تكلفة مردود مبيعات نقدي — دائن عند إثبات تكلفة المرتجع النقدي',
        'cogs_returns_credit' => 'تكلفة مردود مبيعات آجل — دائن عند إثبات تكلفة المرتجع الآجل',
        'cogs_returns_online' => 'تكلفة مردود مبيعات أونلاين',
        /** مفتاحان اختياريان: لا يُعرضان في «حسابات القيود التلقائية»؛ يُربطان عند الإقفال أو يُمرَّران في طلب الإقفال. */
        'income_summary' => 'ملخص الدخل (مؤقت) — قيود إقفال السنة',
        'retained_earnings' => 'الأرباح المحتجزة — صافي الدخل عند الإقفال',
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
        'cogs_cash' => 'تكلفة المبيعات النقدي',
        'sales_returns_cash' => 'مردود المبيعات النقدي',
        'cogs_returns_cash' => 'تكلفة مردود المبيعات النقدي',
        'sales_revenue_credit' => 'المبيعات الآجل',
        'cogs_credit' => 'تكلفة المبيعات الآجلة',
        'sales_returns_credit' => 'مردود المبيعات الاجل',
        'cogs_returns_credit' => 'تكلفة مردود المبيعات الآجلة',
        'sales_revenue_online' => 'المبيعات الاونلاين',
        'cogs_online' => 'تكلفة المبيعات الاونلاين',
        'sales_returns_online' => 'مردود المبيعات الاونلاين',
        'cogs_returns_online' => 'تكلفة مردود المبيعات الاونلاين',
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
        'cogs_cash',
        'sales_returns_cash',
        'cogs_returns_cash',
        'sales_revenue_credit',
        'cogs_credit',
        'sales_returns_credit',
        'cogs_returns_credit',
        'sales_revenue_online',
        'cogs_online',
        'sales_returns_online',
        'cogs_returns_online',
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
    $stmt = $pdo->prepare(
        'SELECT a.id FROM accounts a WHERE a.code = ? AND ' . orange_accounts_posting_leaf_where_sql($pdo, 'a') . ' LIMIT 1'
    );
    $stmt->execute([$code]);
    $id = (int) $stmt->fetchColumn();

    return $id > 0 ? $id : 0;
}

/**
 * معرف الحساب لقيد تلقائي: من الجدول أولاً، ثم احتياط بالكود إن وُجد في orange_gl_legacy_code_fallbacks.
 *
 * @throws RuntimeException إذا تعذر إيجاد حساب (بعد ضبط الشجرة اربط من شاشة «الحسابات الأساسية»)
 */
/**
 * نفس منطق orange_gl_account_id لكن بدون استثناء — إن لم يُربط المفتاح يُعاد null.
 */
function orange_gl_account_id_optional(PDO $pdo, string $key): ?int
{
    static $cache = [];
    $assertLeaf = static function (int $accountId) use ($pdo, $key): void {
        if ($accountId <= 0 || !orange_accounts_account_is_posting_leaf($pdo, $accountId)) {
            $labels = orange_gl_setting_key_labels();
            $lab = $labels[$key] ?? $key;
            throw new RuntimeException(
                'الحساب المربوط لـ ' . $lab . ' يجب أن يكون فرعياً (ورقة ترحيل). حدّث الربط من «حسابات القيود التلقائية».'
            );
        }
    };
    if (array_key_exists($key, $cache)) {
        $v = $cache[$key];
        if ($v !== null && $v > 0) {
            $assertLeaf((int) $v);
        }

        return $cache[$key];
    }

    if (!orange_table_exists($pdo, 'orange_gl_account_settings')) {
        $legacy = orange_gl_resolve_legacy_account_id($pdo, $key);
        if ($legacy > 0) {
            $assertLeaf($legacy);
            $cache[$key] = $legacy;

            return $legacy;
        }
        $cache[$key] = null;

        return null;
    }

    $stmt = $pdo->prepare('SELECT account_id FROM orange_gl_account_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $id = (int) $stmt->fetchColumn();
    if ($id > 0) {
        $assertLeaf($id);
        $cache[$key] = $id;

        return $id;
    }

    $legacy = orange_gl_resolve_legacy_account_id($pdo, $key);
    if ($legacy > 0) {
        $assertLeaf($legacy);
        $cache[$key] = $legacy;

        return $legacy;
    }

    $cache[$key] = null;

    return null;
}

function orange_gl_account_id(PDO $pdo, string $key): int
{
    static $cache = [];
    $assertLeaf = static function (int $accountId) use ($pdo, $key): void {
        if ($accountId <= 0 || !orange_accounts_account_is_posting_leaf($pdo, $accountId)) {
            $labels = orange_gl_setting_key_labels();
            $lab = $labels[$key] ?? $key;
            throw new RuntimeException(
                'الحساب المربوط لـ ' . $lab . ' يجب أن يكون فرعياً (ورقة ترحيل). حدّث الربط من «حسابات القيود التلقائية».'
            );
        }
    };
    if (isset($cache[$key])) {
        $assertLeaf($cache[$key]);

        return $cache[$key];
    }

    if (!orange_table_exists($pdo, 'orange_gl_account_settings')) {
        $legacy = orange_gl_resolve_legacy_account_id($pdo, $key);
        if ($legacy > 0) {
            $assertLeaf($legacy);
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
        $assertLeaf($id);
        $cache[$key] = $id;

        return $id;
    }

    $legacy = orange_gl_resolve_legacy_account_id($pdo, $key);
    if ($legacy > 0) {
        $assertLeaf($legacy);
        $cache[$key] = $legacy;

        return $legacy;
    }

    $labels = orange_gl_setting_key_labels();
    $lab = $labels[$key] ?? $key;
    throw new RuntimeException(
        'حساب أساسي غير مربوط: ' . $lab . ' — افتح «الحسابات الأساسية للقيود التلقائية» واختر الحساب من الشجرة.'
    );
}
