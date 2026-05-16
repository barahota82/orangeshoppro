<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/account_tree.php';
require_once __DIR__ . '/journal_types.php';
require_once __DIR__ . '/upload_paths.php';

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
        /** توافق قديم — لا يُعرض في قوائم ربط نوع اليومية. */
        'general_expense' => 'مصروف عام',
        'inventory' => 'المخزون — مدين شراء؛ دائن تكلفة البضاعة المباعة',
        /** توافق قديم — شراء آجل يُرحَّل على حساب ذمة كل مورد. */
        'accounts_payable' => 'ذمم الموردين المجمّعة',
        'sales_revenue_cash' => 'إيراد مبيعات نقدي — دائن عند تسليم طلب نقدي',
        'sales_revenue_credit' => 'إيراد مبيعات آجل — دائن عند تسليم طلب آجل',
        'sales_revenue_online' => 'إيراد مبيعات أونلاين — دائن عند تسليم طلب أونلاين',
        'ar_cash' => 'عملاء نقدي — وسيط تسليم: تسجيل على هذا الحساب ثم التحصيل إلى الخزينة (نقد عند التسليم COD لطلب الواجهة، أو مسار أونلاين للفواتير غير الواجهة حسب نوع البيع)؛ حساب وسيط واحد دون تكرار في الميزانية',
        'ar_credit' => 'عملاء آجل — مدين عند تسليم طلب آجل',
        'sales_returns_cash' => 'مردود مبيعات نقدي — يُستخدم عند تسجيل مرتجعات المبيعات النقدية',
        'sales_returns_credit' => 'مردود مبيعات آجل — يُستخدم عند تسجيل مرتجعات المبيعات الآجلة',
        'sales_returns_online' => 'مردود مبيعات أونلاين',
        'cogs' => 'تكلفة المبيعات — مدين عند تسليم الطلب (نقدي / آجل / أونلاين)',
        'cogs_returns' => 'تكلفة مردودات المبيعات — دائن عند إلغاء التسليم وإرجاع التكلفة للمخزون',
        'sales_discount' => 'خصم المبيعات — يُستخدم عند قيود خصم مسموح على المبيعات (حسب سياسة الدليل)',
        'purchase_discount' => 'خصم مكتسب على المشتريات — يُستخدم عند خصم من المورد أو إثبات خصم مشتريات (حسب سياسة الدليل)',
        /** توافق قديم — يُستبدل بمفتاح cogs */
        'cogs_cash' => 'تكلفة مبيعات نقدي',
        'cogs_credit' => 'تكلفة مبيعات آجل',
        'cogs_online' => 'تكلفة مبيعات أونلاين',
        /** توافق قديم — يُستبدل بمفتاح cogs_returns */
        'cogs_returns_cash' => 'تكلفة مردود مبيعات نقدي',
        'cogs_returns_credit' => 'تكلفة مردود مبيعات آجل',
        'cogs_returns_online' => 'تكلفة مردود مبيعات أونلاين',
        'income_summary' => 'أرباح / خسائر السنة الحالية (وسيط إقفال) — قيود إقفال القائمة ثم الترحيل للمحتجز',
        'retained_earnings' => 'الأرباح المحتجزة — صافي الدخل أو الخسارة المرحّلة بعد الإقفال',
        'legal_reserve' => 'الاحتياطي القانوني — حقوق ملكية؛ النسبة % تُخصَّم من أرباح السنة الحالية (بعد إقفال القائمة) لاستخدامها في قيود الإقفال لاحقاً',
        'accounts_payable_parent' => 'اختر الحساب الأب للموردين',
    ];
}

/**
 * تسمية الصف القصيرة في جدول «حسابات القيود التلقائية» (عمود يمين).
 *
 * @return array<string, string>
 */
function orange_gl_setting_row_short_labels(): array
{
    $p = 'حـ / ';

    return [
        'cash' => $p . 'الخزينة',
        'inventory' => $p . 'المخزن',
        'ar_cash' => $p . 'العملاء النقدي',
        'ar_credit' => $p . 'العملاء الاجل',
        'sales_revenue_cash' => $p . 'المبيعات النقدية',
        'sales_returns_cash' => $p . 'مردودات المبيعات النقدي',
        'sales_revenue_credit' => $p . 'المبيعات الآجل',
        'sales_returns_credit' => $p . 'مردودات المبيعات الاجل',
        'sales_revenue_online' => $p . 'المبيعات الاونلاين',
        'sales_returns_online' => $p . 'مردودات المبيعات الاونلاين',
        'cogs' => $p . 'تكلفة المبيعات',
        'cogs_returns' => $p . 'تكلفة مردودات المبيعات',
        'sales_discount' => $p . 'خصم المبيعات',
        'purchase_discount' => $p . 'خصم مكتسب على المشتريات',
        'income_summary' => $p . 'أرباح / خسائر السنة الحالية',
        'retained_earnings' => $p . 'الأرباح المحتجزة',
        'legal_reserve' => $p . 'الاحتياطي القانوني',
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
        'sales_returns_cash',
        'sales_revenue_credit',
        'sales_returns_credit',
        'sales_revenue_online',
        'sales_returns_online',
        'cogs',
        'cogs_returns',
        'sales_discount',
        'purchase_discount',
        'income_summary',
        'retained_earnings',
        'legal_reserve',
        'accounts_payable_parent',
    ];
}

/**
 * قواعد ربط نوع اليومية ببند مدين وبند دائن (مفاتيح من القسم العلوي).
 *
 * @return list<array<string, mixed>>
 */
function orange_gl_journal_type_rules_list(PDO $pdo): array
{
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'orange_gl_journal_type_rules')) {
        return [];
    }
    try {
        $st = $pdo->query(
            'SELECT journal_type_id, payment_terms, debit_setting_key, credit_setting_key
             FROM orange_gl_journal_type_rules
             ORDER BY journal_type_id ASC, payment_terms ASC'
        );

        return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_gl_journal_type_rules_list: ' . $e->getMessage());
        }

        return [];
    }
}

/**
 * صف قاعدة ربط نوع اليومية (مدين/دائن) لنقدي أو آجل — يُستخدم عند الترحيل.
 *
 * @param 'cash'|'credit'|'' $paymentTerms للمشتريات: cash أو credit؛ للأنواع الأخرى يُمرَّر '' فقط.
 *
 * @return array{debit_setting_key: string, credit_setting_key: string}|null
 */
function orange_gl_journal_type_rule_for_terms(PDO $pdo, int $journalTypeId, string $paymentTerms): ?array
{
    orange_catalog_ensure_schema($pdo);
    if ($journalTypeId <= 0 || !orange_table_exists($pdo, 'orange_gl_journal_type_rules')) {
        return null;
    }
    $pt = trim($paymentTerms);
    $code = orange_journal_type_code_by_id($pdo, $journalTypeId);
    if ($code !== 'PIN' && $code !== 'PDN') {
        $pt = '';
    } elseif ($pt !== 'cash' && $pt !== 'credit') {
        return null;
    }
    try {
        $st = $pdo->prepare(
            'SELECT debit_setting_key, credit_setting_key FROM orange_gl_journal_type_rules
             WHERE journal_type_id = ? AND payment_terms = ? LIMIT 1'
        );
        $st->execute([$journalTypeId, $pt]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_gl_journal_type_rule_for_terms: ' . $e->getMessage());
        }

        return null;
    }
}

/**
 * قاعدة «مدين/دائن» من شاشة ربط أنواع اليومية (payment_terms فارغ) لتسليم الطلب ومردود المبيعات.
 *
 * - إيراد آجل: SIN — مدين: عملاء آجل، دائن: إيراد مبيعات آجل (أو ما يعادلهما في القاعدة).
 * - إيراد نقدي: CSI — مدين: وسيط عملاء نقدي، دائن: إيراد مبيعات نقدي؛ الخزينة تُؤخذ من بند cash (قيد أربعة أسطر).
 * - إيراد أونلاين: OSI — نفس منطق النقدي مع إيراد أونلاين.
 * - تكلفة آجل/نقدي/أونلاين: CGT / CGC / CGO — مدين: تكلفة، دائن: مخزون (عادة).
 * - مردود إيراد نقدي/آجل/أونلاين: SCR / SRR / OSR (مستند مردود من الأدمن).
 * - مردود تكلفة نقدي/آجل/أونلاين: CSR / CGR / COR.
 *
 * @param 'SIN'|'CSI'|'OSI'|'CGT'|'CGC'|'CGO'|'SCR'|'SRR'|'OSR'|'CSR'|'CGR'|'COR' $journalCode كود journal_types
 *
 * @return array{debit_key: string, credit_key: string}|null إن لم توجد قاعدة أو ناقصة
 */
function orange_gl_order_delivery_setting_keys_from_rule(PDO $pdo, string $journalCode): ?array
{
    $code = orange_journal_type_normalize_code($journalCode);
    $allowed = [
        'SIN', 'CSI', 'OSI', 'CGT', 'CGC', 'CGO',
        'SCR', 'SRR', 'OSR', 'CSR', 'CGR', 'COR',
    ];
    if ($code === '' || !in_array($code, $allowed, true)) {
        return null;
    }
    $jtId = orange_journal_type_id_by_code($pdo, $code);
    if ($jtId <= 0) {
        return null;
    }
    $rule = orange_gl_journal_type_rule_for_terms($pdo, $jtId, '');
    if ($rule === null) {
        return null;
    }
    $dk = trim((string) ($rule['debit_setting_key'] ?? ''));
    $ck = trim((string) ($rule['credit_setting_key'] ?? ''));
    if ($dk === '' || $ck === '' || $dk === $ck) {
        return null;
    }

    return ['debit_key' => $dk, 'credit_key' => $ck];
}

/**
 * أنواع اليومية لشاشة الترحيل: من جدول القواعد إن وُجد، وإلا من الربط القديم في orange_gl_account_settings.
 *
 * @return list<array<string, mixed>>
 */
function orange_gl_posting_linked_journal_types(PDO $pdo): array
{
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'journal_types')) {
        return [];
    }
    try {
        if (orange_table_exists($pdo, 'orange_gl_journal_type_rules')) {
            $st = $pdo->query(
                'SELECT DISTINCT jt.id, jt.code, jt.name_ar, jt.sort_order
                 FROM orange_gl_journal_type_rules r
                 INNER JOIN journal_types jt ON jt.id = r.journal_type_id
                 ORDER BY jt.sort_order ASC, jt.id ASC'
            );
            $fromRules = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
            if ($fromRules !== []) {
                return $fromRules;
            }
        }
        if (!orange_table_exists($pdo, 'orange_gl_account_settings')
            || !orange_table_has_column($pdo, 'orange_gl_account_settings', 'journal_type_id')) {
            return [];
        }
        $st = $pdo->query(
            'SELECT DISTINCT jt.id, jt.code, jt.name_ar, jt.sort_order
             FROM orange_gl_account_settings g
             INNER JOIN journal_types jt ON jt.id = g.journal_type_id
             WHERE g.journal_type_id IS NOT NULL AND g.journal_type_id > 0
             ORDER BY jt.sort_order ASC, jt.id ASC'
        );

        return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_gl_posting_linked_journal_types: ' . $e->getMessage());
        }

        return [];
    }
}

/**
 * مفاتيح البنود الظاهرة في نموذج «حسابات القيود التلقائية» (علوي وسفلي).
 *
 * @return list<string>
 */
function orange_gl_settings_ui_key_order(): array
{
    $labelsKeys = array_keys(orange_gl_setting_key_labels());
    $ordered = orange_gl_settings_form_key_order();

    return array_values(array_filter($ordered, static function ($k) use ($labelsKeys) {
        return in_array($k, $labelsKeys, true);
    }));
}

/**
 * @return list<string>
 */
function orange_gl_allowed_setting_keys(): array
{
    return array_keys(orange_gl_setting_key_labels());
}

/**
 * مفاتيح لا تُعرض في قوائم «ربط نوع اليومية» — احتياطات قديمة أو غير مستخدمة في مسار الشاشة.
 *
 * @return list<string>
 */
function orange_gl_journal_rule_dropdown_excluded_keys(): array
{
    return [
        'general_expense',
        'accounts_payable',
        'cogs_cash',
        'cogs_credit',
        'cogs_online',
        'cogs_returns_cash',
        'cogs_returns_credit',
        'cogs_returns_online',
    ];
}

/**
 * ترتيب مفاتيح قوائم المدين/الدائن (القسم ٢): بند يظهر فقط إذا وُجد له حساب مربوط في القسم ١ (ومستبعدة مفاتيح التوافق القديمة).
 * قواعد محفوظة قد تشير لمفتاح خارج القائمة؛ الواجهة تُضيف خياراً مؤقتاً لذلك المفتاح في صف القاعدة فقط.
 *
 * @param array<string, int> $current setting_key => account_id من orange_gl_account_settings
 *
 * @return list<string>
 */
function orange_gl_journal_rule_dropdown_key_order(array $current): array
{
    $excluded = orange_gl_journal_rule_dropdown_excluded_keys();
    $formOrder = orange_gl_settings_form_key_order();
    $out = [];
    foreach ($formOrder as $k) {
        if (in_array($k, $excluded, true)) {
            continue;
        }
        if ((int) ($current[$k] ?? 0) > 0) {
            $out[] = $k;
        }
    }

    return $out;
}

/**
 * نسبة مئوية (0–100) مربوطة ببند إعداد — تُخزَّن في orange_gl_setting_alloc (مثل نسبة الاحتياطي من أرباح السنة الحالية).
 */
function orange_gl_setting_alloc_percent(PDO $pdo, string $settingKey): float
{
    orange_catalog_ensure_schema($pdo);
    orange_catalog_ensure_gl_account_settings_alloc_tables($pdo);
    if (trim($settingKey) === '' || ! orange_table_exists($pdo, 'orange_gl_setting_alloc')) {
        return 0.0;
    }
    $st = $pdo->prepare('SELECT percent_value FROM orange_gl_setting_alloc WHERE setting_key = ? LIMIT 1');
    $st->execute([$settingKey]);
    $v = $st->fetchColumn();
    if ($v === false || $v === null) {
        return 0.0;
    }

    return max(0.0, min(100.0, round((float) $v, 4)));
}

/**
 * نسبة الاحتياطي القانوني من أرباح السنة الحالية (للاستخدام في منطق الإقفال أو القيود التلقائية).
 */
function orange_gl_legal_reserve_percent_of_current_year_profit(PDO $pdo): float
{
    return orange_gl_setting_alloc_percent($pdo, 'legal_reserve');
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

/**
 * حساب تكلفة المبيعات عند التسليم (بند مدين) — يفضّل المفتاح cogs ثم احتياط المفاتيح القديمة حسب قناة الدفع.
 *
 * @throws RuntimeException
 */
function orange_gl_cogs_delivery_account_id(PDO $pdo): int
{
    foreach (['cogs', 'cogs_cash', 'cogs_credit', 'cogs_online'] as $key) {
        $id = orange_gl_account_id_optional($pdo, $key);
        if ($id !== null && $id > 0) {
            return $id;
        }
    }

    return orange_gl_account_id($pdo, 'cogs');
}

/**
 * حساب تكلفة مردود المبيعات عند إلغاء التسليم (بند دائن) — يفضّل cogs_returns ثم احتياط قديم، ثم نفس حساب تكلفة المبيعات.
 *
 * @throws RuntimeException
 */
function orange_gl_cogs_return_account_id(PDO $pdo): int
{
    foreach (['cogs_returns', 'cogs_returns_cash', 'cogs_returns_credit', 'cogs_returns_online'] as $key) {
        $id = orange_gl_account_id_optional($pdo, $key);
        if ($id !== null && $id > 0) {
            return $id;
        }
    }

    return orange_gl_cogs_delivery_account_id($pdo);
}

/**
 * معرف الحساب الأب لذمم الموردين (لا يُشترط أن يكون ورقة ترحيل — هو حساب أب).
 * يُقرأ من إعداد accounts_payable_parent في orange_gl_account_settings.
 *
 * @return int|null null إذا لم يُربط بعد
 */
function orange_gl_supplier_parent_account_id(PDO $pdo): ?int
{
    if (!orange_table_exists($pdo, 'orange_gl_account_settings')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT account_id FROM orange_gl_account_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute(['accounts_payable_parent']);
    $id = (int) $stmt->fetchColumn();

    return $id > 0 ? $id : null;
}

/**
 * بند مدين قيد مردود الإيراد عند إلغاء تسليم طلب مكتمل.
 * إن وُجد ربط لـ sales_returns_* يُستخدم (مطابقة دليل منفصل لمردود المبيعات)؛ وإلا يُعكس على حساب الإيراد نفسه
 * (نفس التوازن مع شاشة الربط الحالية دون إجبار صفوف إضافية).
 *
 * @param 'cash'|'online'|'credit' $channel
 *
 * @throws RuntimeException
 */
function orange_gl_order_return_sale_debit_account_id(PDO $pdo, string $channel): int
{
    $channel = trim($channel);
    if ($channel === 'credit') {
        return orange_gl_account_id($pdo, 'sales_revenue_credit');
    }
    if ($channel === 'online') {
        $id = orange_gl_account_id_optional($pdo, 'sales_returns_online');
        if ($id !== null && $id > 0) {
            return $id;
        }

        return orange_gl_account_id($pdo, 'sales_revenue_online');
    }
    if ($channel === 'cash') {
        $id = orange_gl_account_id_optional($pdo, 'sales_returns_cash');
        if ($id !== null && $id > 0) {
            return $id;
        }

        return orange_gl_account_id($pdo, 'sales_revenue_cash');
    }

    throw new RuntimeException('قناة دفع غير صالحة لمردود تسليم الطلب.');
}

/**
 * بند مدين قيد إيراد مردود مبيعات (مستند مستقل): يفضّل sales_returns_credit للآجل إن وُجد ربط؛ وإلا sales_revenue_credit.
 * النقدي والأونلاين: نفس منطق إلغاء التسليم (مردود منفصل أو إيراد).
 *
 * @param 'cash'|'online'|'credit' $channel
 *
 * @throws RuntimeException
 */
function orange_gl_sales_return_revenue_debit_account_id(PDO $pdo, string $channel): int
{
    $channel = trim($channel);
    if ($channel === 'credit') {
        $id = orange_gl_account_id_optional($pdo, 'sales_returns_credit');
        if ($id !== null && $id > 0) {
            return $id;
        }

        return orange_gl_account_id($pdo, 'sales_revenue_credit');
    }

    return orange_gl_order_return_sale_debit_account_id($pdo, $channel);
}

/**
 * تسميات عربية لحقل journal_vouchers.entry_type / طابور المحاسبة (للعرض فقط).
 *
 * @return array<string, string>
 */
function orange_gl_entry_type_labels_map(): array
{
    return [
        'manual' => 'سند يدوي',
        'other_voucher' => 'سندات أخرى',
        'receipt_voucher' => 'سند قبض',
        'payment_voucher' => 'سند صرف',
        'general' => 'قيد عام',
        'opening_balance' => 'قيد رصيد افتتاحي',
        'year_end_close' => 'إقفال سنة مالية',
        'customer_receipt' => 'سداد فواتير مبيعات آجلة',
        'supplier_payment' => 'سداد فواتير مشتريات آجلة',
        'purchase' => 'شراء',
        'purchase_receive' => 'استلام مخزون — شراء',
        'purchase_return' => 'مردود مشتريات',
        'expense' => 'مصروف',
        'expense_adjustment' => 'تعديل مصروف',
        'expense_reversal' => 'عكس مصروف',
        'order_delivery_sale' => 'تسليم طلب — إيراد',
        'order_delivery_cogs' => 'تسليم طلب — تكلفة',
        'order_return_sale' => 'إلغاء تسليم — مردود إيراد',
        'order_return_cogs' => 'إلغاء تسليم — مردود تكلفة',
        'migrated' => 'مرحّل من نظام سابق',
    ];
}

function orange_gl_entry_type_label_ar(string $entryType): string
{
    $entryType = trim($entryType);
    if ($entryType === '') {
        return '';
    }
    $map = orange_gl_entry_type_labels_map();

    return $map[$entryType] ?? $entryType;
}

/**
 * أنواع سندات لا تُحذف من شاشة «سندات القيود» اليدوية — تُدار من مسارات الطلبات/الترحيل أو شاشات مخصصة.
 *
 * @return list<string>
 */
function orange_gl_entry_types_delete_locked_from_journal_ui(): array
{
    return [
        'year_end_close',
        'opening_balance',
        'order_delivery_sale',
        'order_delivery_cogs',
        'order_return_sale',
        'order_return_cogs',
        'purchase',
        'purchase_receive',
        'purchase_return',
        'customer_receipt',
        'supplier_payment',
        'expense',
        'expense_adjustment',
        'expense_reversal',
    ];
}

/**
 * رسالة للمستخدم عند محاولة حذف سند نظامي من شاشة القيود اليدوية.
 */
function orange_gl_journal_delete_blocked_message_ar(string $entryType): string
{
    $et = trim($entryType);
    $label = orange_gl_entry_type_label_ar($et);
    if ($label === '') {
        $label = $et !== '' ? $et : 'سند نظامي';
    }
    $hints = [
        'year_end_close' => 'يُدار من مسار إقفال السنة المالية.',
        'opening_balance' => 'عدّل من شاشة «الأرصدة الافتتاحية».',
        'order_delivery_sale' => 'للتصحيح: «ترحيل الحركات» (فك ترحيل) أو مسار الطلب.',
        'order_delivery_cogs' => 'كما سبق — تكلفة التسليم مرتبطة بالطابور أو الطلب.',
        'order_return_sale' => 'من شاشة «مردود المبيعات» أو إلغاء تسليم طلب — راجع «ترحيل الحركات» إن لزم.',
        'order_return_cogs' => 'من شاشة «مردود المبيعات» أو إلغاء تسليم طلب — راجع «ترحيل الحركات» إن لزم.',
        'purchase' => 'عدّل أو ألغِ من شاشة المشتريات.',
        'purchase_receive' => 'قديم: استلام منفصل أُلغي — عالج من شاشة المشتريات أو «ترحيل الحركات» إن بقي سند قديم.',
        'purchase_return' => 'عدّل أو ألغِ من شاشة مردود المشتريات.',
        'customer_receipt' => 'عدّل من شاشة سداد فواتير مبيعات آجلة أو من الذمم.',
        'supplier_payment' => 'عدّل من شاشة سداد فواتير مشتريات آجلة أو من الذمم.',
        'expense' => 'قيود قديمة من شاشة مصروفات أُزيلت — للتصحيح: سند صرف أو قيد يدوي أو ترحيل الحركات.',
        'expense_adjustment' => 'كما سبق — راجع سند الصرف أو القيود اليدوية.',
        'expense_reversal' => 'كما سبق — راجع الترحيل أو القيود اليدوية.',
    ];
    $hint = $hints[$et] ?? 'استخدم الشاشة أو المسار الذي أنشأ السند، أو فك الترحيل من «ترحيل الحركات» إن وُجد في الطابور.';

    return 'لا يمكن حذف «' . $label . '» من هنا. ' . $hint;
}

/**
 * رابط لوحة إدارة مقترح لتصحيح السند (عرض واجهة فقط — الـ API يبقى برسالة نصية).
 *
 * @return array{href:string,label:string}|null
 */
function orange_gl_journal_delete_blocked_admin_link(string $entryType): ?array
{
    $et = trim($entryType);
    $map = [
        'opening_balance' => ['page' => 'opening_balances', 'label' => 'الأرصدة الافتتاحية'],
        'year_end_close' => ['page' => 'fiscal_years', 'label' => 'السنوات المالية / الإقفال'],
        'purchase' => ['page' => 'purchases', 'label' => 'المشتريات'],
        'purchase_receive' => ['page' => 'purchases', 'label' => 'المشتريات'],
        'purchase_return' => ['page' => 'purchase_returns', 'label' => 'مردود المشتريات'],
        'customer_receipt' => ['page' => 'partner_customer_receipt', 'label' => 'سداد فواتير مبيعات آجلة'],
        'supplier_payment' => ['page' => 'partner_supplier_payment', 'label' => 'سداد فواتير مشتريات آجلة'],
        'expense' => ['page' => 'payment_voucher', 'label' => 'سند صرف'],
        'expense_adjustment' => ['page' => 'payment_voucher', 'label' => 'سند صرف'],
        'expense_reversal' => ['page' => 'payment_voucher', 'label' => 'سند صرف'],
        'order_delivery_sale' => ['page' => 'gl_posting', 'label' => 'ترحيل الحركات'],
        'order_delivery_cogs' => ['page' => 'gl_posting', 'label' => 'ترحيل الحركات'],
        'order_return_sale' => ['page' => 'sales_returns', 'label' => 'مردود المبيعات'],
        'order_return_cogs' => ['page' => 'sales_returns', 'label' => 'مردود المبيعات'],
    ];
    if (!isset($map[$et])) {
        return null;
    }
    $page = $map[$et]['page'];

    return [
        'href' => storefront_public_path('/admin/index.php?page=' . rawurlencode($page)),
        'label' => $map[$et]['label'],
    ];
}

/**
 * عند فشل فك الترحيل من الطابور: اقتراح شاشة إدارية من نصوص الأخطاء المعروفة.
 *
 * @param list<string> $errors
 * @return array{href:string,label:string}|null
 */
function orange_gl_unpost_errors_suggest_admin_link(array $errors): ?array
{
    foreach ($errors as $err) {
        $e = (string) $err;
        if (str_contains($e, 'أرصدة افتتاحية')) {
            return [
                'href' => storefront_public_path('/admin/index.php?page=opening_balances'),
                'label' => 'الأرصدة الافتتاحية',
            ];
        }
        if (str_contains($e, 'إقفال سنوي')) {
            return [
                'href' => storefront_public_path('/admin/index.php?page=fiscal_years'),
                'label' => 'السنوات المالية / الإقفال',
            ];
        }
        if (str_contains($e, 'سنة مالية مغلقة')) {
            return orange_gl_suggest_admin_fiscal_years_screen();
        }
    }

    return null;
}

/**
 * عند فشل ترحيل الطابور: اقتراح شاشة من نصوص الأخطاء (إعدادات GL أو السنة المالية).
 *
 * @param list<string> $errors
 * @return array{href:string,label:string}|null
 */
function orange_gl_post_errors_suggest_admin_link(array $errors): ?array
{
    foreach ($errors as $err) {
        $e = (string) $err;
        if (
            str_contains($e, 'حسابات القيود التلقائية')
            || str_contains($e, 'حساب أساسي غير مربوط')
            || str_contains($e, 'لم يُضبط الدليل المحاسبي')
        ) {
            return [
                'href' => storefront_public_path('/admin/index.php?page=gl_account_settings'),
                'label' => 'حسابات القيود التلقائية',
            ];
        }
    }
    foreach ($errors as $err) {
        $e = (string) $err;
        if (str_contains($e, 'سنة مالية')) {
            return [
                'href' => storefront_public_path('/admin/index.php?page=fiscal_years'),
                'label' => 'السنوات المالية',
            ];
        }
    }

    return null;
}

/**
 * شاشة السنوات المالية — للإرشاد عند رفض عملية بسبب إقفال السنة.
 *
 * @return array{href:string,label:string}
 */
function orange_gl_suggest_admin_fiscal_years_screen(): array
{
    return [
        'href' => storefront_public_path('/admin/index.php?page=fiscal_years'),
        'label' => 'السنوات المالية',
    ];
}

/**
 * اقتراح شاشة إدارية من نص استثناء (مساعد قبض/شراء/قيد عند الفشل).
 *
 * @return array{href:string,label:string}|null
 */
function orange_gl_exception_suggest_admin(Throwable $e): ?array
{
    $m = $e->getMessage();
    if ($m === '') {
        return null;
    }
    if (str_contains($m, 'سنة مالية')) {
        return orange_gl_suggest_admin_fiscal_years_screen();
    }
    if (
        str_contains($m, 'حسابات القيود التلقائية')
        || str_contains($m, 'حساب أساسي غير مربوط')
        || str_contains($m, 'لم يُضبط الدليل المحاسبي')
        || str_contains($m, 'الحساب المربوط لـ')
    ) {
        return [
            'href' => storefront_public_path('/admin/index.php?page=gl_account_settings'),
            'label' => 'حسابات القيود التلقائية',
        ];
    }

    return null;
}

/**
 * رد JSON عند خطأ API مع نفس أسلوب api_error + suggest عند امتثال نص الاستثناء.
 */
function orange_gl_api_catch_json(Throwable $e, string $userMessage): void
{
    if (function_exists('error_log')) {
        error_log(
            '[orange] API: ' . $userMessage . ' | ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine()
        );
    }
    $payload = [
        'success' => false,
        'message' => $userMessage,
    ];
    $s = orange_gl_exception_suggest_admin($e);
    if ($s !== null) {
        $payload['suggest_admin'] = $s;
    }
    $debug = getenv('ORANGE_API_DEBUG');
    if ($debug === '1' || $debug === 'true') {
        $payload['debug'] = $e->getMessage();
    }
    json_response($payload, 500);
}
