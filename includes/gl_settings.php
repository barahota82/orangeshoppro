<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/account_tree.php';
require_once __DIR__ . '/journal_types.php';
require_once __DIR__ . '/upload_paths.php';
require_once __DIR__ . '/countries.php';

function orange_gl_settings_effective_country_id(PDO $pdo, ?int $countryId = null): int
{
    if ($countryId !== null && $countryId > 0) {
        return $countryId;
    }
    $ctx = orange_admin_context_country_id($pdo);

    return $ctx > 0 ? $ctx : orange_countries_default_id($pdo);
}

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
        'delivery_expense' => 'مصروف توصيل — مدين عند تسليم الطلب بقيمة تكلفة التوصيل',
        'delivery_payable_default' => 'ذمة شركة التوصيل أو (مستحقات التوصيل تصرف من الخزينة لاحقاً) — دائن عند تسليم الطلب',
        'loyalty_program_expense' => 'مصروفات برنامج الولاء والمكافآت — مدين عند كسب العميل نقاطاً (والدائن: التزامات نقاط الولاء)؛ ويُعكَس عليه المنتهي',
        'loyalty_points_liability' => 'التزامات نقاط الولاء — دائن عند الكسب؛ مدين عند الاستبدال أو الانتهاء',
        'stock_adjustment_gain' => 'أرباح جرد (تسوية مخزون — زيادة) — دائن مقابل زيادة المخزون؛ يُقترَح في الكارت السفلي ويمكن تغييره (مثلاً إلى ذمة موظف)',
        'stock_adjustment_loss' => 'خسائر جرد (تسوية مخزون — نقص) — مدين مقابل نقص المخزون؛ يُقترَح في الكارت السفلي ويمكن تغييره (مثلاً إلى ذمة موظف)',
        'vat_output' => 'ضريبة القيمة المضافة المستحقة (مبيعات) — التزام؛ تُحسب تلقائياً بنسبة الدولة على فواتير البيع (الكويت 0% = لا أثر)',
        'vat_input' => 'ضريبة القيمة المضافة على المشتريات (مدخلات) — أصل/قابلة للخصم؛ تُحسب تلقائياً بنسبة الدولة على فواتير الشراء',
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
        'delivery_expense' => $p . 'مصروف توصيل',
        'delivery_payable_default' => $p . 'مستحقات توصيل افتراضي',
        'loyalty_program_expense' => $p . 'مصروفات برنامج الولاء',
        'loyalty_points_liability' => $p . 'التزامات نقاط الولاء',
        'stock_adjustment_gain' => $p . 'أرباح جرد (تسوية)',
        'stock_adjustment_loss' => $p . 'خسائر جرد (تسوية)',
        'vat_output' => $p . 'ضريبة القيمة المضافة (مبيعات)',
        'vat_input' => $p . 'ضريبة القيمة المضافة (مشتريات)',
        'income_summary' => $p . 'أرباح / خسائر السنة الحالية',
        'retained_earnings' => $p . 'الأرباح المحتجزة',
        'legal_reserve' => $p . 'الاحتياطي القانوني',
        'accounts_payable_parent' => 'اختر الحساب الأب للموردين',
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
        'delivery_expense',
        'delivery_payable_default',
        'loyalty_program_expense',
        'loyalty_points_liability',
        'stock_adjustment_gain',
        'stock_adjustment_loss',
        'vat_output',
        'vat_input',
        'income_summary',
        'retained_earnings',
        'legal_reserve',
        'accounts_payable_parent',
    ];
}

function orange_gl_journal_type_rules_has_country_column(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'orange_gl_journal_type_rules')
        && orange_table_has_column($pdo, 'orange_gl_journal_type_rules', 'country_id');
}

/**
 * قواعد ربط نوع اليومية ببند مدين وبند دائن (مفاتيح من القسم العلوي).
 *
 * @return list<array<string, mixed>>
 */
function orange_gl_journal_type_rules_list(PDO $pdo, ?int $countryId = null): array
{
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'orange_gl_journal_type_rules')) {
        return [];
    }
    $cid = orange_gl_settings_effective_country_id($pdo, $countryId);
    try {
        if (orange_gl_journal_type_rules_has_country_column($pdo) && $cid > 0) {
            $st = $pdo->prepare(
                'SELECT journal_type_id, payment_terms, debit_setting_key, credit_setting_key
                 FROM orange_gl_journal_type_rules
                 WHERE country_id = ?
                 ORDER BY journal_type_id ASC, payment_terms ASC'
            );
            $st->execute([$cid]);
        } else {
            $st = $pdo->query(
                'SELECT journal_type_id, payment_terms, debit_setting_key, credit_setting_key
                 FROM orange_gl_journal_type_rules
                 ORDER BY journal_type_id ASC, payment_terms ASC'
            );
        }

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
    if ($code === 'SAJ') {
        if ($pt !== 'gain' && $pt !== 'loss') {
            return null;
        }
    } elseif ($code !== 'PIN' && $code !== 'PDN') {
        $pt = '';
    } elseif ($pt !== 'cash' && $pt !== 'credit') {
        return null;
    }
    try {
        if (orange_gl_journal_type_rules_has_country_column($pdo)
            && orange_table_exists($pdo, 'journal_types')
            && orange_table_has_column($pdo, 'journal_types', 'country_id')) {
            $st = $pdo->prepare(
                'SELECT r.debit_setting_key, r.credit_setting_key
                 FROM orange_gl_journal_type_rules r
                 INNER JOIN journal_types jt ON jt.id = r.journal_type_id AND jt.id = ?
                 WHERE r.journal_type_id = ? AND r.payment_terms = ? AND r.country_id = jt.country_id
                 LIMIT 1'
            );
            $st->execute([$journalTypeId, $journalTypeId, $pt]);
        } else {
            $st = $pdo->prepare(
                'SELECT debit_setting_key, credit_setting_key FROM orange_gl_journal_type_rules
                 WHERE journal_type_id = ? AND payment_terms = ? LIMIT 1'
            );
            $st->execute([$journalTypeId, $pt]);
        }
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
 * خريطة افتراضية لقسم «ربط نوع اليومية» — تُمرَّر للواجهة لملء المدين/الدائن عند اختيار النوع.
 *
 * مفاتيح الصف: payment_terms (فارغ = قياسي). الحقول الاختيارية:
 * - debit_key / credit_key — مفتاح بند القسم ١ (يُختار من قائمة القسم ١)
 * - debit_from_supplier / credit_from_supplier — ذمة المورد من المستند (بدون قائمة؛ لا بند في القسم ١)
 *
 * @return array<string, array<string, array<string, bool|string>>>
 */
function orange_gl_journal_type_rule_ui_defaults(): array
{
    return [
        'PIN' => [
            'cash' => ['debit_key' => 'inventory', 'credit_key' => 'cash'],
            'credit' => ['debit_key' => 'inventory', 'credit_from_supplier' => true],
        ],
        'PDN' => [
            'cash' => ['debit_key' => 'cash', 'credit_key' => 'inventory'],
            'credit' => ['debit_from_supplier' => true, 'credit_key' => 'inventory'],
        ],
        'SAJ' => [
            'gain' => ['debit_key' => 'inventory', 'credit_key' => 'stock_adjustment_gain'],
            'loss' => ['debit_key' => 'stock_adjustment_loss', 'credit_key' => 'inventory'],
        ],
        'CSI' => ['' => ['debit_key' => 'ar_cash', 'credit_key' => 'sales_revenue_cash']],
        'SIN' => ['' => ['debit_key' => 'ar_credit', 'credit_key' => 'sales_revenue_credit']],
        'OSI' => ['' => ['debit_key' => 'ar_cash', 'credit_key' => 'sales_revenue_online']],
        'CGT' => ['' => ['debit_key' => 'cogs', 'credit_key' => 'inventory']],
        'CGC' => ['' => ['debit_key' => 'cogs', 'credit_key' => 'inventory']],
        'CGO' => ['' => ['debit_key' => 'cogs', 'credit_key' => 'inventory']],
        'CSR' => ['' => ['debit_key' => 'inventory', 'credit_key' => 'cogs_returns']],
        'CGR' => ['' => ['debit_key' => 'inventory', 'credit_key' => 'cogs_returns']],
        'COR' => ['' => ['debit_key' => 'inventory', 'credit_key' => 'cogs_returns']],
        'SCR' => ['' => ['debit_key' => 'sales_returns_cash', 'credit_key' => 'cash']],
        'SRR' => ['' => ['debit_key' => 'sales_returns_credit', 'credit_key' => 'ar_credit']],
        'OSR' => ['' => ['debit_key' => 'sales_returns_online', 'credit_key' => 'cash']],
        'LYE' => ['' => ['debit_key' => 'loyalty_program_expense', 'credit_key' => 'loyalty_points_liability']],
        'LYX' => ['' => ['debit_key' => 'loyalty_points_liability', 'credit_key' => 'loyalty_program_expense']],
    ];
}

/**
 * صفوف جاهزة لزر «إنشاء القيود التلقائية» — كل تركيبة نوع يومية + نقدي/آجل/… مع مفاتيح القسم ١.
 *
 * @return list<array{journal_type_id:int,payment_terms:string,debit_setting_key:string,credit_setting_key:string,code:string}>
 */
function orange_gl_journal_type_rule_ui_seed_rows(PDO $pdo, ?int $countryId = null): array
{
    $cid = orange_gl_settings_effective_country_id($pdo, $countryId);
    $out = [];
    foreach (orange_gl_journal_type_rule_ui_defaults() as $code => $byPt) {
        $jtId = orange_journal_type_id_by_code($pdo, (string) $code, $cid);
        if ($jtId <= 0) {
            continue;
        }
        foreach ($byPt as $pt => $def) {
            if (!is_array($def)) {
                continue;
            }
            $dk = trim((string) ($def['debit_key'] ?? ''));
            $ck = trim((string) ($def['credit_key'] ?? ''));
            if (!empty($def['debit_from_supplier'])) {
                $dk = '';
            }
            if (!empty($def['credit_from_supplier'])) {
                $ck = '';
            }
            $out[] = [
                'journal_type_id' => $jtId,
                'payment_terms' => (string) $pt,
                'debit_setting_key' => $dk,
                'credit_setting_key' => $ck,
                'code' => (string) $code,
            ];
        }
    }

    return $out;
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
    $jtId = orange_journal_type_id_by_code($pdo, $code, orange_gl_settings_effective_country_id($pdo));
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
 * يحلّ معرّفات حسابي المدين/الدائن من قاعدة القسم ٢ «ربط نوع اليومية» لأي كود نوع يومية
 * (مثل LYE / LYX) في سياق دولة محدّدة. يُستعمل للقيود التلقائية التي لها زوج مدين/دائن ثابت
 * (الولاء) بحيث يُحدِّد المحاسب الحسابين من شاشة واحدة بدل تثبيتهما في الكود.
 *
 * يُعيد null إن لم تُضبط القاعدة أو كانت ناقصة/غير صالحة — فيقع المستدعي على بديل آمن.
 *
 * @return array{debit:int, credit:int, debit_key:string, credit_key:string}|null
 */
function orange_gl_rule_accounts_for_code(PDO $pdo, string $journalCode, ?int $countryId = null): ?array
{
    $code = orange_journal_type_normalize_code($journalCode);
    if ($code === '') {
        return null;
    }
    $cid = orange_gl_settings_effective_country_id($pdo, $countryId);
    $jtId = orange_journal_type_id_by_code($pdo, $code, $cid);
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
    $deb = (int) (orange_gl_account_id_optional($pdo, $dk, $cid) ?? 0);
    $cred = (int) (orange_gl_account_id_optional($pdo, $ck, $cid) ?? 0);
    if ($deb <= 0 || $cred <= 0 || $deb === $cred) {
        return null;
    }

    return ['debit' => $deb, 'credit' => $cred, 'debit_key' => $dk, 'credit_key' => $ck];
}

/**
 * حساب الطرف المقابل لقيد تسوية المخزون (الكارت السفلي) حسب نوع التسوية.
 *
 * - gain (ربح/زيادة): دائن — من قاعدة SAJ+gain أو مفتاح stock_adjustment_gain.
 * - loss (خسارة/نقص): مدين — من قاعدة SAJ+loss أو مفتاح stock_adjustment_loss.
 *
 * @param 'gain'|'loss' $kind
 *
 * @return array{account_id:int, side:string, setting_key:string, code:string, name:string}
 */
function orange_gl_stock_adjustment_contra_meta(PDO $pdo, string $kind, ?int $countryId = null): array
{
    $kind = $kind === 'loss' ? 'loss' : 'gain';
    $cid = orange_gl_settings_effective_country_id($pdo, $countryId);
    $out = ['account_id' => 0, 'side' => $kind === 'gain' ? 'credit' : 'debit', 'setting_key' => '', 'code' => '', 'name' => ''];
    $fallbackKey = $kind === 'gain' ? 'stock_adjustment_gain' : 'stock_adjustment_loss';
    $settingKey = $fallbackKey;

    $jtId = orange_journal_type_id_by_code($pdo, 'SAJ', $cid);
    if ($jtId > 0) {
        $rule = orange_gl_journal_type_rule_for_terms($pdo, $jtId, $kind);
        if ($rule !== null) {
            $settingKey = $kind === 'gain'
                ? trim((string) ($rule['credit_setting_key'] ?? ''))
                : trim((string) ($rule['debit_setting_key'] ?? ''));
            if ($settingKey === '') {
                $settingKey = $fallbackKey;
            }
        }
    }

    $accId = (int) (orange_gl_account_id_optional($pdo, $settingKey, $cid) ?? 0);
    if ($accId <= 0 && $settingKey !== $fallbackKey) {
        $accId = (int) (orange_gl_account_id_optional($pdo, $fallbackKey, $cid) ?? 0);
        $settingKey = $fallbackKey;
    }
    // توافق قديم: مفتاح stock_adjustment_contra الواحد.
    if ($accId <= 0) {
        $accId = (int) (orange_gl_account_id_optional($pdo, 'stock_adjustment_contra', $cid) ?? 0);
        if ($accId > 0) {
            $settingKey = 'stock_adjustment_contra';
        }
    }

    $out['account_id'] = $accId;
    $out['setting_key'] = $settingKey;
    if ($accId > 0 && orange_table_exists($pdo, 'accounts')) {
        $st = $pdo->prepare('SELECT code, name FROM accounts WHERE id = ? LIMIT 1');
        $st->execute([$accId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $out['code'] = trim((string) ($row['code'] ?? ''));
            $out['name'] = trim((string) ($row['name'] ?? ''));
        }
    }

    return $out;
}

/**
 * أنواع اليومية لشاشة الترحيل: من جدول القواعد إن وُجد، وإلا من الربط القديم في orange_gl_account_settings.
 *
 * @return list<array<string, mixed>>
 */
function orange_gl_posting_linked_journal_types(PDO $pdo, ?int $countryId = null): array
{
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'journal_types')) {
        return [];
    }
    $cid = orange_gl_settings_effective_country_id($pdo, $countryId);
    $jtScoped = orange_table_has_column($pdo, 'journal_types', 'country_id');
    $rulesScoped = orange_gl_journal_type_rules_has_country_column($pdo);
    $glScoped = orange_table_has_column($pdo, 'orange_gl_account_settings', 'country_id');
    try {
        if (orange_table_exists($pdo, 'orange_gl_journal_type_rules')) {
            if ($rulesScoped && $cid > 0) {
                $sql = 'SELECT DISTINCT jt.id, jt.code, jt.name_ar, jt.sort_order
                        FROM orange_gl_journal_type_rules r
                        INNER JOIN journal_types jt ON jt.id = r.journal_type_id
                        WHERE r.country_id = ?';
                $params = [$cid];
                if ($jtScoped) {
                    $sql .= ' AND jt.country_id = ?';
                    $params[] = $cid;
                }
                $sql .= ' ORDER BY jt.sort_order ASC, jt.id ASC';
                $st = $pdo->prepare($sql);
                $st->execute($params);
            } else {
                $st = $pdo->query(
                    'SELECT DISTINCT jt.id, jt.code, jt.name_ar, jt.sort_order
                     FROM orange_gl_journal_type_rules r
                     INNER JOIN journal_types jt ON jt.id = r.journal_type_id
                     ORDER BY jt.sort_order ASC, jt.id ASC'
                );
            }
            $fromRules = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
            if ($fromRules !== []) {
                return $fromRules;
            }
        }
        if (!orange_table_exists($pdo, 'orange_gl_account_settings')
            || !orange_table_has_column($pdo, 'orange_gl_account_settings', 'journal_type_id')) {
            return [];
        }
        if ($glScoped && $cid > 0) {
            $sql = 'SELECT DISTINCT jt.id, jt.code, jt.name_ar, jt.sort_order
                    FROM orange_gl_account_settings g
                    INNER JOIN journal_types jt ON jt.id = g.journal_type_id
                    WHERE g.journal_type_id IS NOT NULL AND g.journal_type_id > 0 AND g.country_id = ?';
            $params = [$cid];
            if ($jtScoped) {
                $sql .= ' AND jt.country_id = ?';
                $params[] = $cid;
            }
            $sql .= ' ORDER BY jt.sort_order ASC, jt.id ASC';
            $st = $pdo->prepare($sql);
            $st->execute($params);
        } else {
            $st = $pdo->query(
                'SELECT DISTINCT jt.id, jt.code, jt.name_ar, jt.sort_order
                 FROM orange_gl_account_settings g
                 INNER JOIN journal_types jt ON jt.id = g.journal_type_id
                 WHERE g.journal_type_id IS NOT NULL AND g.journal_type_id > 0
                 ORDER BY jt.sort_order ASC, jt.id ASC'
            );
        }

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
        'delivery_expense',
        'delivery_payable_default',
        // stock_adjustment_gain / stock_adjustment_loss تظهر في قوائم القسم ٢ لقواعد SAJ.
        // stock_adjustment_contra — مفتاح قديم (يُنسخ تلقائياً إلى gain/loss عند الترحيل v97).
        'stock_adjustment_contra',
        // ملاحظة: مفاتيح الولاء (loyalty_program_expense / loyalty_points_liability) لم تَعُد مستبعدة
        // كي تظهر في قوائم «ربط نوع اليومية» لقيدَي LYE/LYX (الحل الأول للولاء).
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
 * @return array<string, int> setting_key => account_id لسياق الدولة الحالي (أو المحددة)
 */
function orange_gl_settings_bindings_map(PDO $pdo, ?int $countryId = null): array
{
    orange_catalog_ensure_gl_account_settings_alloc_tables($pdo);
    $current = [];
    if (!orange_table_exists($pdo, 'orange_gl_account_settings')) {
        return $current;
    }
    $cid = orange_gl_settings_effective_country_id($pdo, $countryId);
    if ($cid > 0 && orange_table_has_column($pdo, 'orange_gl_account_settings', 'country_id')) {
        $st = $pdo->prepare('SELECT setting_key, account_id FROM orange_gl_account_settings WHERE country_id = ?');
        $st->execute([$cid]);
    } else {
        $st = $pdo->query('SELECT setting_key, account_id FROM orange_gl_account_settings');
    }
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $key = trim((string) ($r['setting_key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $current[$key] = (int) ($r['account_id'] ?? 0);
    }

    return $current;
}

/**
 * account_id المربوط في orange_gl_account_settings دون التحقق من «ورقة ترحيل» — للعرض في الشاشات.
 */
function orange_gl_setting_bound_account_id_raw(PDO $pdo, string $key, ?int $countryId = null): int
{
    orange_catalog_ensure_gl_account_settings_alloc_tables($pdo);
    if (!orange_table_exists($pdo, 'orange_gl_account_settings')) {
        return 0;
    }
    $key = trim($key);
    if ($key === '') {
        return 0;
    }
    $cid = orange_gl_settings_effective_country_id($pdo, $countryId);
    if ($cid > 0 && orange_table_has_column($pdo, 'orange_gl_account_settings', 'country_id')) {
        $st = $pdo->prepare(
            'SELECT account_id FROM orange_gl_account_settings WHERE setting_key = ? AND country_id = ? LIMIT 1'
        );
        $st->execute([$key, $cid]);
        $id = (int) $st->fetchColumn();

        return $id > 0 ? $id : 0;
    }
    $st = $pdo->prepare('SELECT account_id FROM orange_gl_account_settings WHERE setting_key = ? LIMIT 1');
    $st->execute([$key]);
    $id = (int) $st->fetchColumn();

    return $id > 0 ? $id : 0;
}

/**
 * نسبة مئوية (0–100) مربوطة ببند إعداد — تُخزَّن في orange_gl_setting_alloc (مثل نسبة الاحتياطي من أرباح السنة الحالية).
 */
function orange_gl_setting_alloc_percent(PDO $pdo, string $settingKey, ?int $countryId = null): float
{
    orange_catalog_ensure_schema($pdo);
    orange_catalog_ensure_gl_account_settings_alloc_tables($pdo);
    if (trim($settingKey) === '' || ! orange_table_exists($pdo, 'orange_gl_setting_alloc')) {
        return 0.0;
    }
    $cid = orange_gl_settings_effective_country_id($pdo, $countryId);
    if ($cid > 0 && orange_table_has_column($pdo, 'orange_gl_setting_alloc', 'country_id')) {
        $st = $pdo->prepare('SELECT percent_value FROM orange_gl_setting_alloc WHERE setting_key = ? AND country_id = ? LIMIT 1');
        $st->execute([$settingKey, $cid]);
    } else {
        $st = $pdo->prepare('SELECT percent_value FROM orange_gl_setting_alloc WHERE setting_key = ? LIMIT 1');
        $st->execute([$settingKey]);
    }
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

function orange_gl_resolve_legacy_account_id(PDO $pdo, string $key, ?int $countryId = null): int
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
    $cid = orange_gl_settings_effective_country_id($pdo, $countryId);
    $sql = 'SELECT a.id FROM accounts a WHERE a.code = ? AND ' . orange_accounts_posting_leaf_where_sql($pdo, 'a');
    $params = [$code];
    if ($cid > 0 && orange_table_has_country_id($pdo, 'accounts')) {
        $sql .= ' AND a.country_id = ?';
        $params[] = $cid;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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
function orange_gl_account_id_optional(PDO $pdo, string $key, ?int $countryId = null): ?int
{
    static $cache = [];
    $cid = orange_gl_settings_effective_country_id($pdo, $countryId);
    $cacheKey = $cid . ':' . $key;
    $assertLeaf = static function (int $accountId) use ($pdo, $key): void {
        if ($accountId <= 0 || !orange_accounts_account_is_posting_leaf($pdo, $accountId)) {
            $labels = orange_gl_setting_key_labels();
            $lab = $labels[$key] ?? $key;
            throw new RuntimeException(
                'الحساب المربوط لـ ' . $lab . ' يجب أن يكون فرعياً (ورقة ترحيل). حدّث الربط من «حسابات القيود التلقائية».'
            );
        }
    };
    if (array_key_exists($cacheKey, $cache)) {
        $v = $cache[$cacheKey];
        if ($v !== null && $v > 0) {
            $assertLeaf((int) $v);
        }

        return $cache[$cacheKey];
    }

    if (!orange_table_exists($pdo, 'orange_gl_account_settings')) {
        $legacy = orange_gl_resolve_legacy_account_id($pdo, $key, $cid);
        if ($legacy > 0) {
            $assertLeaf($legacy);
            $cache[$cacheKey] = $legacy;

            return $legacy;
        }
        $cache[$cacheKey] = null;

        return null;
    }

    if (orange_table_has_column($pdo, 'orange_gl_account_settings', 'country_id')) {
        $stmt = $pdo->prepare(
            'SELECT account_id FROM orange_gl_account_settings WHERE setting_key = ? AND country_id = ? LIMIT 1'
        );
        $stmt->execute([$key, $cid]);
    } else {
        $stmt = $pdo->prepare('SELECT account_id FROM orange_gl_account_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
    }
    $id = (int) $stmt->fetchColumn();
    if ($id > 0) {
        $assertLeaf($id);
        $cache[$cacheKey] = $id;

        return $id;
    }

    $legacy = orange_gl_resolve_legacy_account_id($pdo, $key, $cid);
    if ($legacy > 0) {
        $assertLeaf($legacy);
        $cache[$cacheKey] = $legacy;

        return $legacy;
    }

    $cache[$cacheKey] = null;

    return null;
}

function orange_gl_account_id(PDO $pdo, string $key, ?int $countryId = null): int
{
    static $cache = [];
    $cid = orange_gl_settings_effective_country_id($pdo, $countryId);
    $cacheKey = $cid . ':' . $key;
    $assertLeaf = static function (int $accountId) use ($pdo, $key): void {
        if ($accountId <= 0 || !orange_accounts_account_is_posting_leaf($pdo, $accountId)) {
            $labels = orange_gl_setting_key_labels();
            $lab = $labels[$key] ?? $key;
            throw new RuntimeException(
                'الحساب المربوط لـ ' . $lab . ' يجب أن يكون فرعياً (ورقة ترحيل). حدّث الربط من «حسابات القيود التلقائية».'
            );
        }
    };
    if (isset($cache[$cacheKey])) {
        $assertLeaf($cache[$cacheKey]);

        return $cache[$cacheKey];
    }

    if (!orange_table_exists($pdo, 'orange_gl_account_settings')) {
        $legacy = orange_gl_resolve_legacy_account_id($pdo, $key, $cid);
        if ($legacy > 0) {
            $assertLeaf($legacy);
            $cache[$cacheKey] = $legacy;

            return $legacy;
        }
        throw new RuntimeException(
            'لم يُضبط الدليل المحاسبي. من الأدمن: «الحسابات الأساسية للقيود التلقائية».'
        );
    }

    if (orange_table_has_column($pdo, 'orange_gl_account_settings', 'country_id')) {
        $stmt = $pdo->prepare(
            'SELECT account_id FROM orange_gl_account_settings WHERE setting_key = ? AND country_id = ? LIMIT 1'
        );
        $stmt->execute([$key, $cid]);
    } else {
        $stmt = $pdo->prepare('SELECT account_id FROM orange_gl_account_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
    }
    $id = (int) $stmt->fetchColumn();
    if ($id > 0) {
        $assertLeaf($id);
        $cache[$cacheKey] = $id;

        return $id;
    }

    $legacy = orange_gl_resolve_legacy_account_id($pdo, $key, $cid);
    if ($legacy > 0) {
        $assertLeaf($legacy);
        $cache[$cacheKey] = $legacy;

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
function orange_gl_cogs_delivery_account_id(PDO $pdo, ?int $countryId = null): int
{
    foreach (['cogs', 'cogs_cash', 'cogs_credit', 'cogs_online'] as $key) {
        $id = orange_gl_account_id_optional($pdo, $key, $countryId);
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
function orange_gl_supplier_parent_account_id(PDO $pdo, ?int $countryId = null): ?int
{
    $id = orange_gl_setting_bound_account_id_raw($pdo, 'accounts_payable_parent', $countryId);

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
        'manual' => 'سند قيد',
        'other_voucher' => 'سندات أخرى',
        'receipt_voucher' => 'سند قبض',
        'payment_voucher' => 'سند صرف',
        'general' => 'سند قيد',
        'opening_balance' => 'سند رصيد افتتاحي',
        'year_end_close' => 'قيد الإقفال السنوي',
        'customer_receipt' => 'قبض عميل آجل',
        'supplier_payment' => 'صرف مورد آجل',
        'purchase' => 'فاتورة مشتريات',
        'purchase_receive' => 'فاتورة مشتريات',
        'purchase_return' => 'مردود مشتريات',
        'expense' => 'سند صرف',
        'expense_adjustment' => 'سند صرف',
        'expense_reversal' => 'سند صرف',
        'order_delivery_sale' => 'مبيعات',
        'order_delivery_cogs' => 'تكلفة مبيعات',
        'order_return_sale' => 'مردود مبيعات',
        'order_return_cogs' => 'تكلفة مردود مبيعات',
        'stock_adjustment' => 'قيد تسوية مخزون',
        'loyalty_earn' => 'قيد كسب نقاط ولاء',
        'loyalty_expire' => 'قيد انتهاء نقاط ولاء',
        'migrated' => 'مرحّل من نظام سابق',
    ];
}

function orange_gl_entry_type_label_ar(string $entryType): string
{
    $entryType = trim($entryType);
    if ($entryType === '') {
        return '';
    }
    $code = orange_journal_type_code_from_entry_type($entryType);
    if ($code !== '') {
        $canonical = orange_journal_type_canonical_name_ar($code);
        if ($canonical !== '' && !in_array($entryType, ['manual', 'general', 'other_voucher', 'migrated'], true)) {
            return $canonical;
        }
    }
    $map = orange_gl_entry_type_labels_map();

    return $map[$entryType] ?? $entryType;
}

/**
 * تسمية نوع القيد للعرض — تفضّل اسم نوع اليومية المرتبط بالسند ثم المرجع الثابت.
 *
 * @param array<string, mixed> $voucherRow صف journal_vouchers (يدعم journal_type_id و entry_type)
 */
function orange_gl_voucher_type_label_ar(PDO $pdo, array $voucherRow): string
{
    $jtId = (int) ($voucherRow['journal_type_id'] ?? 0);
    if ($jtId > 0) {
        $jtName = orange_journal_type_name_ar_by_id($pdo, $jtId);
        if ($jtName !== '') {
            return $jtName;
        }
    }
    $entryType = trim((string) ($voucherRow['entry_type'] ?? ''));

    return orange_gl_entry_type_label_ar($entryType);
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
        'year_end_close' => 'يُدار من شاشة «قيود الإقفال السنوية» — ابدأ من «السنوات المالية».',
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
        'year_end_close' => ['page' => 'year_end_close_vouchers', 'label' => 'قيود الإقفال السنوية'],
        'purchase' => ['page' => 'purchases', 'label' => 'المشتريات'],
        'purchase_receive' => ['page' => 'purchases', 'label' => 'المشتريات'],
        'purchase_return' => ['page' => 'purchase_returns', 'label' => 'مردود المشتريات'],
        'customer_receipt' => ['page' => 'partner_customer_receipt', 'label' => 'سداد فواتير مبيعات آجلة'],
        'supplier_payment' => ['page' => 'partner_supplier_payment', 'label' => 'سداد فواتير مشتريات آجلة'],
        'expense' => ['page' => 'payment_voucher', 'label' => 'سند صرف'],
        'expense_adjustment' => ['page' => 'payment_voucher', 'label' => 'سند صرف'],
        'expense_reversal' => ['page' => 'payment_voucher', 'label' => 'سند صرف'],
        'order_delivery_sale' => ['page' => 'edit_lock', 'label' => 'إقفال التعديلات'],
        'order_delivery_cogs' => ['page' => 'edit_lock', 'label' => 'إقفال التعديلات'],
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
