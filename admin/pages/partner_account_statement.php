<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/upload_paths.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/gl_account_aging.php';
require_once __DIR__ . '/../../includes/party_subledger.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/company_settings.php';
require_once __DIR__ . '/../../includes/accounting_report_money.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

$pdo = orange_admin_page_pdo();
$reportMoney = orange_accounting_report_money($pdo, isset($orangeAdminMoney) ? $orangeAdminMoney : null);

$pasCtxCountryId = orange_admin_context_country_id($pdo);
$pasJvCountryBind = orange_gl_voucher_country_bind($pdo, 'jv', $pasCtxCountryId);

/**
 * تمرير أسطر القيد لفلاتر مدين/دائن ونوع الترحيل (يدوي مقابل نظام التشغيل).
 *
 * «غير مرحل» يشمل أنواع السند اليدوية (manual / other_voucher / general والفراغ).
 *
 * @param array<string, mixed> $ln
 */
function orange_gas_stmt_line_matches(array $ln, string $filtDc, string $filtPost): bool
{
    $d = round((float) ($ln['debit'] ?? 0), 4);
    $c = round((float) ($ln['credit'] ?? 0), 4);

    if ($filtDc === 'debit') {
        if ($d <= 0.0001) {
            return false;
        }
    } elseif ($filtDc === 'credit') {
        if ($c <= 0.0001) {
            return false;
        }
    }

    $et = strtolower(trim((string) ($ln['entry_type'] ?? '')));
    $isManualBucket = ($et === '' || in_array($et, ['manual', 'other_voucher', 'general'], true));
    if ($filtPost === 'posted') {
        if ($isManualBucket) {
            return false;
        }
    } elseif ($filtPost === 'unposted') {
        if (! $isManualBucket) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string, mixed> $jv
 */
function orange_partner_account_stmt_gl_voucher_display(array $jv): string
{
    $ref = trim((string) ($jv['reference'] ?? ''));
    if ($ref !== '') {
        return $ref;
    }
    $vs = (int) ($jv['voucher_serial'] ?? 0);
    if ($vs > 0) {
        $buck = trim((string) ($jv['journal_serial_bucket'] ?? ''));

        return ($buck !== '' ? $buck . '-' : '') . (string) $vs;
    }

    return '#' . (int) ($jv['voucher_id'] ?? $jv['id'] ?? 0);
}

/**
 * @param array<string, mixed> $ln
 */
function orange_partner_account_stmt_gl_line_text(array $ln): string
{
    $d = trim((string) ($ln['description'] ?? ''));
    $m = trim((string) ($ln['line_memo'] ?? ''));
    if ($d !== '' && $m !== '' && $m !== $d) {
        return $d . ' — ' . $m;
    }

    return $d !== '' ? $d : $m;
}

$todayDmY = orange_format_date_dmY(date('Y-m-d'));
$printDatetime = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));

$leafWhere = orange_accounts_posting_leaf_where_sql($pdo, 'a');
$pasAcctSql = "SELECT a.id, a.name, a.code FROM accounts a WHERE $leafWhere";
$pasAcctParams = [];
$pasAcctFilter = orange_accounts_sql_country_filter($pdo, 'a');
if ($pasAcctFilter !== null) {
    $pasAcctSql .= $pasAcctFilter['sql'];
    $pasAcctParams = $pasAcctFilter['params'];
}
$pasAcctSql .= " ORDER BY COALESCE(a.code, ''), a.name";
if ($pasAcctParams !== []) {
    $pasAcctSt = $pdo->prepare($pasAcctSql);
    $pasAcctSt->execute($pasAcctParams);
    $accounts = $pasAcctSt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $accounts = $pdo->query($pasAcctSql)->fetchAll(PDO::FETCH_ASSOC);
}

$companyNameAr = orange_company_settings_name_ar($pdo);

$accountId = isset($_GET['account']) ? (int) $_GET['account'] : 0;
$customerId = isset($_GET['customer']) ? (int) $_GET['customer'] : 0;
$modeRaw = strtolower(trim((string) ($_GET['mode'] ?? '')));
$isCustomerMode = ($modeRaw === 'customer') || ($customerId > 0);
$dateFromRaw = trim((string) ($_GET['date_from'] ?? ''));
$dateToRaw = trim((string) ($_GET['date_to'] ?? ''));

$filtDc = strtolower(trim((string) ($_GET['filt_dc'] ?? 'all')));
if (! in_array($filtDc, ['all', 'debit', 'credit'], true)) {
    $filtDc = 'all';
}
$filtPost = strtolower(trim((string) ($_GET['filt_post'] ?? 'all')));
if (! in_array($filtPost, ['all', 'posted', 'unposted'], true)) {
    $filtPost = 'all';
}
$showAging = isset($_GET['show_aging']) && (string) $_GET['show_aging'] === '1';

if ($dateFromRaw === '') {
    $dateFromRaw = orange_format_date_dmY(date('Y-01-01'));
}
if ($dateToRaw === '') {
    $dateToRaw = $todayDmY;
}

$dateFromYmd = orange_parse_admin_date_to_ymd($dateFromRaw);
$dateToYmd = orange_parse_admin_date_to_ymd($dateToRaw);
if ($dateFromYmd === '' || $dateToYmd === '') {
    $dateFromYmd = date('Y-01-01');
    $dateToYmd = date('Y-m-d');
    $dateFromRaw = orange_format_date_dmY($dateFromYmd);
    $dateToRaw = orange_format_date_dmY($dateToYmd);
}

if ($dateFromYmd > $dateToYmd) {
    $tmp = $dateFromYmd;
    $dateFromYmd = $dateToYmd;
    $dateToYmd = $tmp;
    $dateFromRaw = orange_format_date_dmY($dateFromYmd);
    $dateToRaw = orange_format_date_dmY($dateToYmd);
}

$useVouchers = orange_journal_vouchers_ready($pdo);
$partySubledgerReady = orange_party_subledger_ready($pdo);

$openingBal = 0.0;
$rows = [];
$sumDebitPeriod = 0.0;
$sumCreditPeriod = 0.0;
$closingBal = 0.0;
$err = '';
$agingReport = null;
$stmtFilterNoMatch = false;

if ($isCustomerMode) {
    $accountId = 0; // وضع العميل: لا نستعمل الحساب
    if (!$partySubledgerReady) {
        $err = 'سجل الذمم غير جاهز بعد.';
    }
    if (!orange_table_exists($pdo, 'customers')) {
        $err = 'جدول العملاء غير متوفر.';
        $customerId = 0;
    }
} else {
    if ($accountId > 0 && ! orange_accounts_account_is_posting_leaf($pdo, $accountId)) {
        $err = 'يُعرض كشف الحساب للحسابات الفرعية (ورقة ترحيل) فقط.';
        $accountId = 0;
    }
}

$accCode = '';
$accNameOnly = '';
foreach ($accounts as $a) {
    if ((int) $a['id'] === $accountId) {
        $accCode = trim((string) ($a['code'] ?? ''));
        $accNameOnly = trim((string) ($a['name'] ?? ''));
        break;
    }
}

// س15 + بند 10: تحميل بيانات العميل عند وضع العميل لعرضها في النموذج والترويسة.
$custCodeDisp = '';
$custNameDisp = '';
$custPhoneDisp = '';
if ($isCustomerMode && $customerId > 0 && $err === '' && orange_table_exists($pdo, 'customers')) {
    $custCols = 'name_ar AS name, phone';
    if (orange_table_has_column($pdo, 'customers', 'code')) {
        $custCols .= ', code';
    }
    $custLoad = $pdo->prepare('SELECT ' . $custCols . ' FROM customers WHERE id = ? LIMIT 1');
    $custLoad->execute([$customerId]);
    $custRow = $custLoad->fetch(PDO::FETCH_ASSOC);
    if (!$custRow) {
        $err = 'العميل غير موجود.';
        $customerId = 0;
    } else {
        $custNameDisp = trim((string) ($custRow['name'] ?? ''));
        $custPhoneDisp = trim((string) ($custRow['phone'] ?? ''));
        $custCodeDisp = trim((string) ($custRow['code'] ?? ''));
    }
}

if ($isCustomerMode && $customerId > 0 && $err === '') {
    try {
        // الرصيد الافتتاحي قبل التاريخ من
        $stOpenCust = $pdo->prepare(
            'SELECT COALESCE(SUM(ps.debit - ps.credit), 0) AS bal
             FROM party_subledger ps
             INNER JOIN journal_vouchers jv ON jv.id = ps.voucher_id
             WHERE ps.party_kind = ? AND ps.party_id = ? AND DATE(jv.voucher_date) < ?' . $pasJvCountryBind['sql']
        );
        $stOpenCust->execute(array_merge(['customer', $customerId, $dateFromYmd], $pasJvCountryBind['params']));
        $openingBal = (float) $stOpenCust->fetchColumn();

        $hasSerial = orange_table_has_column($pdo, 'journal_vouchers', 'voucher_serial')
            && orange_table_has_column($pdo, 'journal_vouchers', 'journal_serial_bucket');
        $jvCols = 'jv.id AS voucher_id, jv.voucher_date, jv.reference, jv.description, jv.entry_type';
        if ($hasSerial) {
            $jvCols .= ', jv.voucher_serial, jv.journal_serial_bucket';
        }
        $stLcust = $pdo->prepare(
            "SELECT ps.debit, ps.credit, ps.memo AS line_memo, ps.id AS line_no, ps.ref_type, ps.ref_id, $jvCols
             FROM party_subledger ps
             INNER JOIN journal_vouchers jv ON jv.id = ps.voucher_id
             WHERE ps.party_kind = ? AND ps.party_id = ?
               AND DATE(jv.voucher_date) >= ?
               AND DATE(jv.voucher_date) <= ?" . $pasJvCountryBind['sql'] . '
             ORDER BY jv.voucher_date ASC, jv.id ASC, ps.id ASC'
        );
        $stLcust->execute(array_merge(['customer', $customerId, $dateFromYmd, $dateToYmd], $pasJvCountryBind['params']));
        $rawLinesCust = $stLcust->fetchAll(PDO::FETCH_ASSOC);
        $balCust = $openingBal;
        foreach ($rawLinesCust as $ln) {
            if (! orange_gas_stmt_line_matches($ln, $filtDc, $filtPost)) {
                continue;
            }
            $d = (float) $ln['debit'];
            $c = (float) $ln['credit'];
            $sumDebitPeriod += $d;
            $sumCreditPeriod += $c;
            $balCust += ($d - $c);
            $ln['balance'] = $balCust;
            $rows[] = $ln;
        }
        $closingBal = $rows === [] ? $openingBal : (float) ($rows[count($rows) - 1]['balance'] ?? $openingBal);
        $stmtFilterNoMatch = $rawLinesCust !== [] && $rows === [];
    } catch (Throwable $e) {
        $err = 'تعذر قراءة حركات العميل.';
    }
} elseif ($useVouchers && $accountId > 0 && $err === '') {
    try {
        $stOpen = $pdo->prepare(
            'SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS bal
             FROM journal_lines jl
             INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id
             WHERE jl.account_id = ? AND DATE(jv.voucher_date) < ?' . $pasJvCountryBind['sql']
        );
        $stOpen->execute(array_merge([$accountId, $dateFromYmd], $pasJvCountryBind['params']));
        $openingBal = (float) $stOpen->fetchColumn();

        $hasSerial = orange_table_has_column($pdo, 'journal_vouchers', 'voucher_serial')
            && orange_table_has_column($pdo, 'journal_vouchers', 'journal_serial_bucket');
        $jvCols = 'jv.id AS voucher_id, jv.voucher_date, jv.reference, jv.description, jv.entry_type';
        if ($hasSerial) {
            $jvCols .= ', jv.voucher_serial, jv.journal_serial_bucket';
        }
        $stL = $pdo->prepare(
            "SELECT jl.debit, jl.credit, jl.memo AS line_memo, jl.line_no, $jvCols
             FROM journal_lines jl
             INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id
             WHERE jl.account_id = ?
               AND DATE(jv.voucher_date) >= ?
               AND DATE(jv.voucher_date) <= ?" . $pasJvCountryBind['sql'] . '
             ORDER BY jv.voucher_date ASC, jv.id ASC, jl.line_no ASC'
        );
        $stL->execute(array_merge([$accountId, $dateFromYmd, $dateToYmd], $pasJvCountryBind['params']));
        $rawLines = $stL->fetchAll(PDO::FETCH_ASSOC);
        $bal = $openingBal;
        foreach ($rawLines as $ln) {
            if (! orange_gas_stmt_line_matches($ln, $filtDc, $filtPost)) {
                continue;
            }
            $d = (float) $ln['debit'];
            $c = (float) $ln['credit'];
            $sumDebitPeriod += $d;
            $sumCreditPeriod += $c;
            $bal += ($d - $c);
            $ln['balance'] = $bal;
            $rows[] = $ln;
        }
        $closingBal = $rows === [] ? $openingBal : (float) ($rows[count($rows) - 1]['balance'] ?? $openingBal);
        $stmtFilterNoMatch = $rawLines !== [] && $rows === [];

        if ($showAging) {
            $agingReport = orange_gl_account_statement_aging_buckets($pdo, $accountId, $dateToYmd, $pasCtxCountryId);
        }
    } catch (Throwable $e) {
        $err = 'تعذر قراءة الحركات.';
    }
} elseif (! $useVouchers) {
    $err = 'سندات اليومية غير جاهزة بعد.';
}

?>
<div class="admin-fy-shell" dir="rtl">
    <div class="gl-acc-stmt-no-print">
        <h1 class="admin-fy-shell__title">كشف حساب</h1>
    </div>

    <div class="card admin-fy-card gl-acc-stmt-no-print gas-acc-stmt-search-card">
        <form method="get" id="gas_acc_stmt_form" class="gas-acc-stmt-filter-form">
            <input type="hidden" name="page" value="partner_account_statement">
            <input type="hidden" name="account" id="gas_account_id" value="<?php echo (int) $accountId; ?>">
            <input type="hidden" name="customer" id="gas_customer_id" value="<?php echo (int) $customerId; ?>">
            <input type="hidden" name="mode" id="gas_mode" value="<?php echo $isCustomerMode ? 'customer' : 'account'; ?>">
            <?php
            $codeLabel = $isCustomerMode ? 'كود/هاتف العميل' : 'كود الحساب';
            $nameLabel = $isCustomerMode ? 'اسم العميل' : 'اسم الحساب';
            $codeValShown = $isCustomerMode ? ($custCodeDisp !== '' ? $custCodeDisp : $custPhoneDisp) : $accCode;
            $nameValShown = $isCustomerMode ? $custNameDisp : $accNameOnly;
            $codePlaceholder = 'نقرتان للاختيار';
            ?>
            <div class="gas-acc-stmt-toolbar-wrap">
                <div class="gas-acc-stmt-toolbar gas-acc-stmt-toolbar--main-center">
                    <div class="gas-acc-stmt-field gas-acc-stmt-field--code">
                        <label for="gas_acc_code"><?php echo htmlspecialchars($codeLabel, ENT_QUOTES, 'UTF-8'); ?></label>
                        <input type="text" id="gas_acc_code" name="_gas_acc_code_dummy" autocomplete="off" readonly
                            class="admin-inp jv-acc-code gas-acc-stmt-acc-code-input"
                            placeholder="<?php echo htmlspecialchars($codePlaceholder, ENT_QUOTES, 'UTF-8'); ?>"
                            title="<?php echo htmlspecialchars($codePlaceholder, ENT_QUOTES, 'UTF-8'); ?>"
                            value="<?php echo htmlspecialchars($codeValShown, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en">
                    </div>
                    <div class="gas-acc-stmt-field gas-acc-stmt-field--name">
                        <label for="gas_acc_name"><?php echo htmlspecialchars($nameLabel, ENT_QUOTES, 'UTF-8'); ?></label>
                        <input type="text" id="gas_acc_name" name="_gas_acc_name_dummy" tabindex="-1" readonly autocomplete="off"
                            class="admin-inp gas-acc-stmt-acc-name-input"
                            placeholder="—" title="يُعبأ بعد اختيار الحساب/العميل" value="<?php echo htmlspecialchars($nameValShown, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="gas-acc-stmt-field gas-acc-stmt-field--cust-toggle" style="display:flex;align-items:flex-end;">
                        <label class="gas-opt-chip gas-opt-chip--solo" style="margin:0;">
                            <input type="checkbox" id="gas_cust_mode_toggle"<?php echo $isCustomerMode ? ' checked' : ''; ?>>
                            حساب عميل
                        </label>
                    </div>
                    <div class="gas-acc-stmt-field gas-acc-stmt-field--date gas-acc-stmt-field--dmy">
                        <label for="gas_from">من تاريخ</label>
                        <input type="text" name="date_from" id="gas_from" class="admin-inp orange-inp-dmy" value="<?php echo htmlspecialchars($dateFromRaw, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en" autocomplete="off" required>
                    </div>
                    <div class="gas-acc-stmt-field gas-acc-stmt-field--date gas-acc-stmt-field--dmy">
                        <label for="gas_to">إلى تاريخ</label>
                        <input type="text" name="date_to" id="gas_to" class="admin-inp orange-inp-dmy" value="<?php echo htmlspecialchars($dateToRaw, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en" autocomplete="off" required>
                    </div>
                    <?php
                    $showStatement = $err === '' && (
                        ($isCustomerMode && $customerId > 0 && $partySubledgerReady) ||
                        (!$isCustomerMode && $accountId > 0 && $useVouchers)
                    );
                    ?>
                    <div class="gas-acc-stmt-actions">
                        <button type="submit">استخراج الكشف</button>
                        <?php if ($showStatement): ?>
                            <button type="button" class="btn-secondary" onclick="window.print()">طباعة</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="gas-acc-stmt-options-row-wrap">
                <div class="gas-acc-stmt-options-row" role="group" aria-label="خيارات الكشف">
                    <div class="gas-opt-unit">
                        <span class="gas-opt-unit-label">حركة السطر</span>
                        <div class="gas-opt-radio-group">
                            <label class="gas-opt-chip"><input type="radio" name="filt_dc" value="all"<?php echo $filtDc === 'all' ? ' checked' : ''; ?>> الكل</label>
                            <label class="gas-opt-chip"><input type="radio" name="filt_dc" value="debit"<?php echo $filtDc === 'debit' ? ' checked' : ''; ?>> مدين فقط</label>
                            <label class="gas-opt-chip"><input type="radio" name="filt_dc" value="credit"<?php echo $filtDc === 'credit' ? ' checked' : ''; ?>> دائن فقط</label>
                        </div>
                    </div>
                    <div class="gas-opt-unit">
                        <span class="gas-opt-unit-label">التصنيف</span>
                        <div class="gas-opt-radio-group">
                            <label class="gas-opt-chip"><input type="radio" name="filt_post" value="all"<?php echo $filtPost === 'all' ? ' checked' : ''; ?>> الكل</label>
                            <label class="gas-opt-chip"><input type="radio" name="filt_post" value="posted"<?php echo $filtPost === 'posted' ? ' checked' : ''; ?>> مرحّل (وحدات التشغيل)</label>
                            <label class="gas-opt-chip"><input type="radio" name="filt_post" value="unposted"<?php echo $filtPost === 'unposted' ? ' checked' : ''; ?>> غير مرحّل (يدوي)</label>
                        </div>
                    </div>
                    <div class="gas-opt-unit gas-opt-unit--aging">
                        <label class="gas-opt-chip gas-opt-chip--solo">
                            <input type="checkbox" name="show_aging" value="1"<?php echo $showAging ? ' checked' : ''; ?>>
                            إظهار أعمار الرصيد (توزيع زمني)
                        </label>
                    </div>
                </div>
            </div>
        </form>
        <?php if ($err !== ''): ?>
            <p class="card-hint gas-acc-stmt-err-msg" style="color:var(--danger,#b91c1c);margin-top:10px;"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
    </div>

<?php if ($useVouchers && $accounts === []): ?>
    <div class="card admin-fy-card gl-acc-stmt-no-print" style="border:1px solid #fcd34d;background:#fffbeb;">
        <p class="muted" style="margin:0;line-height:1.55;"><strong>تنبيه:</strong> لا توجد حسابات ترحيل (أوراق) في الدليل بعد؛ لا يمكن اختيار حساب للكشف حتى الإنشاء في «الدليل المحاسبي». <strong>النموذج والفترات تعملان</strong>.</p>
    </div>
<?php endif; ?>

    <div class="gl-pick-modal gl-acc-stmt-no-print" id="gas_pick_modal" hidden aria-hidden="true">
        <div class="gl-pick-modal__backdrop" id="gas_pick_backdrop"></div>
        <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="gas_pick_title">
            <h3 id="gas_pick_title" class="gl-pick-modal__title">اختيار حساب فرعي</h3>
            <p class="gl-pick-modal__hint muted" style="margin:0 0 8px;font-size:0.9rem;">نقرتان للاختيار</p>
            <input type="search" id="gas_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بالكود أو الاسم…" autocomplete="off" dir="rtl">
            <ul class="gl-pick-modal__list" id="gas_pick_list"></ul>
            <button type="button" class="btn-secondary" id="gas_pick_close">إغلاق</button>
        </div>
    </div>

    <div class="gl-pick-modal gl-acc-stmt-no-print" id="gas_cust_pick_modal" hidden aria-hidden="true">
        <div class="gl-pick-modal__backdrop" id="gas_cust_pick_backdrop"></div>
        <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="gas_cust_pick_title">
            <h3 id="gas_cust_pick_title" class="gl-pick-modal__title">اختيار عميل</h3>
            <p class="gl-pick-modal__hint muted" style="margin:0 0 8px;font-size:0.9rem;">ابحث بالاسم / الهاتف / الكود — نقرتان للاختيار</p>
            <input type="search" id="gas_cust_pick_q" class="gl-pick-modal__search admin-inp" placeholder="اسم العميل، هاتف، أو كود…" autocomplete="off" dir="rtl">
            <ul class="gl-pick-modal__list" id="gas_cust_pick_list"></ul>
            <button type="button" class="btn-secondary" id="gas_cust_pick_close">إغلاق</button>
        </div>
    </div>

    <script>
    (function () {
        var gasPickSeq = 0;
        var gasPickSearchTimer = null;

        function gasPickLoad(q) {
            var mySeq = ++gasPickSeq;
            var url = '/admin/api/accounts/search-leaves.php?q=' + encodeURIComponent(q || '');
            var pickList = document.getElementById('gas_pick_list');
            if (!pickList) {
                return;
            }
            fetch(url, { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (mySeq !== gasPickSeq) {
                        return;
                    }
                    if (!data.success) {
                        pickList.innerHTML = '<li class="gl-pick-empty">' + (data.message || 'تعذر التحميل') + '</li>';
                        return;
                    }
                    var accs = data.accounts || [];
                    if (accs.length === 0) {
                        pickList.innerHTML = '<li class="gl-pick-empty">لا نتائج</li>';
                        return;
                    }
                    pickList.innerHTML = '';
                    accs.forEach(function (a) {
                        var li = document.createElement('li');
                        li.className = 'gl-pick-item';
                        var code = a.code || '';
                        li.textContent = (code ? code + ' — ' : '') + (a.name || '');
                        li.setAttribute('role', 'button');
                        li.tabIndex = 0;
                        function gasPickChoose() {
                            var hid = document.getElementById('gas_account_id');
                            var cd = document.getElementById('gas_acc_code');
                            var nm = document.getElementById('gas_acc_name');
                            if (hid) { hid.value = String(a.id || '0'); }
                            if (cd) { cd.value = a.code || ''; }
                            if (nm) { nm.value = a.name || ''; }
                            gasPickClose();
                        }
                        li.addEventListener('click', gasPickChoose);
                        li.addEventListener('keydown', function (ev) {
                            if (ev.key === 'Enter' || ev.key === ' ') {
                                ev.preventDefault();
                                gasPickChoose();
                            }
                        });
                        pickList.appendChild(li);
                    });
                })
                .catch(function (e) {
                    pickList.innerHTML = '<li class="gl-pick-empty">' + (e.message || String(e)) + '</li>';
                });
        }

        function gasPickClose() {
            var pm = document.getElementById('gas_pick_modal');
            if (pm) {
                pm.hidden = true;
                pm.setAttribute('aria-hidden', 'true');
            }
            document.body.classList.remove('gl-pick-open');
        }

        function gasPickOpen() {
            var pm = document.getElementById('gas_pick_modal');
            var pickQ = document.getElementById('gas_pick_q');
            var pickList = document.getElementById('gas_pick_list');
            if (!pm || !pickQ || !pickList) {
                return;
            }
            pickQ.value = '';
            pickList.innerHTML = '';
            gasPickLoad('');
            pm.hidden = false;
            pm.setAttribute('aria-hidden', 'false');
            document.body.classList.add('gl-pick-open');
            pickQ.focus();
        }

        // س15 + بند 10: picker العملاء (وضع العميل).
        var gasCustSeq = 0;
        var gasCustSearchTimer = null;

        function gasCustomerModeActive() {
            var tg = document.getElementById('gas_cust_mode_toggle');
            return !!(tg && tg.checked);
        }

        function gasCustPickLoad(q) {
            var mySeq = ++gasCustSeq;
            var url = '/admin/api/customers/search.php?q=' + encodeURIComponent(q || '');
            var pickList = document.getElementById('gas_cust_pick_list');
            if (!pickList) {
                return;
            }
            fetch(url, { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (mySeq !== gasCustSeq) {
                        return;
                    }
                    if (!data.success) {
                        pickList.innerHTML = '<li class="gl-pick-empty">' + (data.message || 'تعذر التحميل') + '</li>';
                        return;
                    }
                    var custs = data.customers || [];
                    if (custs.length === 0) {
                        pickList.innerHTML = '<li class="gl-pick-empty">لا نتائج</li>';
                        return;
                    }
                    pickList.innerHTML = '';
                    custs.forEach(function (c) {
                        var li = document.createElement('li');
                        li.className = 'gl-pick-item';
                        var code = c.code || '';
                        var phone = c.phone || '';
                        var leftRef = code !== '' ? code : phone;
                        var nameDisp = c.name || '—';
                        var areaDisp = c.area ? (' — ' + c.area) : '';
                        li.textContent = (leftRef ? leftRef + ' — ' : '') + nameDisp + areaDisp;
                        li.setAttribute('role', 'button');
                        li.tabIndex = 0;
                        function gasCustChoose() {
                            var hid = document.getElementById('gas_customer_id');
                            var cd = document.getElementById('gas_acc_code');
                            var nm = document.getElementById('gas_acc_name');
                            var mode = document.getElementById('gas_mode');
                            var accHid = document.getElementById('gas_account_id');
                            if (hid) { hid.value = String(c.id || '0'); }
                            if (accHid) { accHid.value = '0'; }
                            if (cd) { cd.value = code !== '' ? code : phone; }
                            if (nm) { nm.value = nameDisp; }
                            if (mode) { mode.value = 'customer'; }
                            gasCustPickClose();
                        }
                        li.addEventListener('click', gasCustChoose);
                        li.addEventListener('keydown', function (ev) {
                            if (ev.key === 'Enter' || ev.key === ' ') {
                                ev.preventDefault();
                                gasCustChoose();
                            }
                        });
                        pickList.appendChild(li);
                    });
                })
                .catch(function (e) {
                    pickList.innerHTML = '<li class="gl-pick-empty">' + (e.message || String(e)) + '</li>';
                });
        }

        function gasCustPickClose() {
            var pm = document.getElementById('gas_cust_pick_modal');
            if (pm) {
                pm.hidden = true;
                pm.setAttribute('aria-hidden', 'true');
            }
            document.body.classList.remove('gl-pick-open');
        }

        function gasCustPickOpen() {
            var pm = document.getElementById('gas_cust_pick_modal');
            var pickQ = document.getElementById('gas_cust_pick_q');
            var pickList = document.getElementById('gas_cust_pick_list');
            if (!pm || !pickQ || !pickList) {
                return;
            }
            pickQ.value = '';
            pickList.innerHTML = '';
            gasCustPickLoad('');
            pm.hidden = false;
            pm.setAttribute('aria-hidden', 'false');
            document.body.classList.add('gl-pick-open');
            pickQ.focus();
        }

        function gasApplyToggleMode() {
            var on = gasCustomerModeActive();
            var modeEl = document.getElementById('gas_mode');
            if (modeEl) {
                modeEl.value = on ? 'customer' : 'account';
            }
            // عند التبديل من/إلى وضع العميل: تفريغ الاختيار السابق لتفادي خلط الأنواع.
            var accHid = document.getElementById('gas_account_id');
            var custHid = document.getElementById('gas_customer_id');
            var cd = document.getElementById('gas_acc_code');
            var nm = document.getElementById('gas_acc_name');
            if (on) {
                if (accHid) { accHid.value = '0'; }
            } else {
                if (custHid) { custHid.value = '0'; }
            }
            // تحديث labels الحقول البصرية (placeholder الرسالة) بدون تعديل HTML النص:
            if (cd) {
                cd.placeholder = on ? 'نقرتان لاختيار عميل' : 'نقرتان لاختيار حساب';
            }
            // إن كان للحقل قيمة قبل التبديل، أفرّغ الاسم/الكود لتجنب تضليل المستخدم.
            // (الاختيار الجديد سيُعبأ من الـ picker)
            var prevCode = cd ? cd.value : '';
            var prevName = nm ? nm.value : '';
            if (prevCode || prevName) {
                // فقط افرّغ لو لم يكن المعرف الحالي مطابقاً للوضع.
                var hidForMode = on ? (custHid ? custHid.value : '0') : (accHid ? accHid.value : '0');
                if (!hidForMode || hidForMode === '0') {
                    if (cd) { cd.value = ''; }
                    if (nm) { nm.value = ''; }
                }
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            var cd = document.getElementById('gas_acc_code');
            if (cd) {
                cd.addEventListener('dblclick', function (e) {
                    e.preventDefault();
                    if (gasCustomerModeActive()) {
                        gasCustPickOpen();
                    } else {
                        gasPickOpen();
                    }
                });
            }
            var pickQ = document.getElementById('gas_pick_q');
            if (pickQ && !pickQ.getAttribute('data-gas-bound')) {
                pickQ.setAttribute('data-gas-bound', '1');
                pickQ.addEventListener('input', function () {
                    if (gasPickSearchTimer) {
                        clearTimeout(gasPickSearchTimer);
                    }
                    gasPickSearchTimer = setTimeout(function () {
                        gasPickLoad(pickQ.value.trim());
                    }, 280);
                });
            }
            var custPickQ = document.getElementById('gas_cust_pick_q');
            if (custPickQ && !custPickQ.getAttribute('data-gas-bound')) {
                custPickQ.setAttribute('data-gas-bound', '1');
                custPickQ.addEventListener('input', function () {
                    if (gasCustSearchTimer) {
                        clearTimeout(gasCustSearchTimer);
                    }
                    gasCustSearchTimer = setTimeout(function () {
                        gasCustPickLoad(custPickQ.value.trim());
                    }, 280);
                });
            }
            var bd = document.getElementById('gas_pick_backdrop');
            var closer = document.getElementById('gas_pick_close');
            if (bd) {
                bd.addEventListener('click', gasPickClose);
            }
            if (closer) {
                closer.addEventListener('click', gasPickClose);
            }
            var bdC = document.getElementById('gas_cust_pick_backdrop');
            var closerC = document.getElementById('gas_cust_pick_close');
            if (bdC) {
                bdC.addEventListener('click', gasCustPickClose);
            }
            if (closerC) {
                closerC.addEventListener('click', gasCustPickClose);
            }
            var toggleEl = document.getElementById('gas_cust_mode_toggle');
            if (toggleEl) {
                toggleEl.addEventListener('change', gasApplyToggleMode);
            }
            document.addEventListener('keydown', function gasPickEsc(ev) {
                if (ev.key !== 'Escape') {
                    return;
                }
                var gm = document.getElementById('gas_pick_modal');
                if (gm && !gm.hidden) {
                    gasPickClose();
                }
                var gmC = document.getElementById('gas_cust_pick_modal');
                if (gmC && !gmC.hidden) {
                    gasCustPickClose();
                }
            }, true);
        });
    })();
    </script>

    <?php if ($showStatement): ?>

    <div class="card admin-fy-card gl-acc-stmt-print">
        <div class="gl-acc-stmt-print-sheet">
            <header class="gl-acc-stmt-print-banner">
                <?php if ($companyNameAr !== ''): ?>
                    <p class="gl-acc-stmt-print-company"><?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <h2 class="gl-acc-stmt-print-title">
                    <span class="gl-acc-stmt-print-title-ar" lang="ar">كــــــشـــــف&nbsp;حـــســـاب</span>
                    <span class="gl-acc-stmt-print-title-en" lang="en" dir="ltr">STATMENT OF ACCOUNT</span>
                </h2>
            </header>
            <?php
                $printCodeKey = $isCustomerMode ? 'كــود/هـاتـف الـعـمـيـل' : 'رقـــم الحســاب';
                $printNameKey = $isCustomerMode ? 'اســم الـعـمـيـل' : 'اسم الحســـــــــاب';
                $printCodeVal = $isCustomerMode
                    ? ($custCodeDisp !== '' ? $custCodeDisp : ($custPhoneDisp !== '' ? $custPhoneDisp : '—'))
                    : ($accCode !== '' ? $accCode : '—');
                $printNameVal = $isCustomerMode
                    ? ($custNameDisp !== '' ? $custNameDisp : '—')
                    : ($accNameOnly !== '' ? $accNameOnly : '—');
            ?>
            <div class="gl-acc-stmt-print-grid">
                <div class="gl-acc-stmt-print-row"><span class="gl-acc-stmt-print-k"><?php echo htmlspecialchars($printCodeKey, ENT_QUOTES, 'UTF-8'); ?></span><span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($printCodeVal, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div class="gl-acc-stmt-print-row"><span class="gl-acc-stmt-print-k"><?php echo htmlspecialchars($printNameKey, ENT_QUOTES, 'UTF-8'); ?></span><span class="gl-acc-stmt-print-v"><?php echo htmlspecialchars($printNameVal, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div class="gl-acc-stmt-print-row gl-acc-stmt-print-row--dates">
                    <span class="gl-acc-stmt-print-k">من تاريخ</span><span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($dateFromRaw, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="gl-acc-stmt-print-k">الى تاريخ</span><span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($dateToRaw, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="gl-acc-stmt-print-k">تاريخ الكشف</span><span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($todayDmY, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>
            <?php if ($filtDc !== 'all' || $filtPost !== 'all'): ?>
                <p class="gl-acc-stmt-filter-note muted">تصفية معروضة: <?php
                    $bits = [];
                    if ($filtDc === 'debit') {
                        $bits[] = 'مدين فقط';
                    } elseif ($filtDc === 'credit') {
                        $bits[] = 'دائن فقط';
                    }
                    if ($filtPost === 'posted') {
                        $bits[] = 'مرحّل (وحدات التشغيل) فقط';
                    } elseif ($filtPost === 'unposted') {
                        $bits[] = 'غير مرحّل (يدوي) فقط';
                    }
                    echo htmlspecialchars(implode(' — ', $bits), ENT_QUOTES, 'UTF-8');
                ?> — عمود الرصيد يُحسب من الرصيد الافتتاحي ثم الأسطر الظاهرة فقط.</p>
            <?php endif; ?>
            <div class="table-wrap admin-fy-table-wrap gl-acc-stmt-table-wrap">
                <table class="admin-fy-table gl-acc-stmt-table">
                    <thead>
                        <tr>
                            <th>التاريخ</th>
                            <th>نوع السند</th>
                            <th>رقم السند</th>
                            <th>بيــــــــان القيــــــــــــد</th>
                            <th class="gl-acc-stmt-col-num">مديـــــن</th>
                            <th class="gl-acc-stmt-col-num">دائــــــن</th>
                            <th class="gl-acc-stmt-col-num">الرصيد</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="gl-acc-stmt-row-opening">
                            <td dir="ltr"><?php echo htmlspecialchars($dateFromRaw, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>—</td>
                            <td dir="ltr">—</td>
                            <td>رصيد افتتاحى</td>
                            <td class="gl-acc-stmt-col-num"><?php echo orange_accounting_report_format_amount(0.0, $reportMoney); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo orange_accounting_report_format_amount(0.0, $reportMoney); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo orange_accounting_report_format_amount($openingBal, $reportMoney); ?></td>
                        </tr>
                        <?php if ($stmtFilterNoMatch): ?>
                            <tr><td colspan="7" class="muted">يوجد على الحساب حركات في هذه الفترة لكن لا يوجد سطر يطابق خيارات العرض (مدين/دائن أو مرحّل/غير مرحّل).</td></tr>
                        <?php elseif ($rows === []): ?>
                            <tr><td colspan="7" class="muted">لا حركة على هذا الحساب في هذه الفترة بعد الرصيد الافتتاحي.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $sr): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(orange_format_date_dmY((string) ($sr['voucher_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars(orange_gl_entry_type_label_ar((string) ($sr['entry_type'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td dir="ltr" class="gl-acc-stmt-col-ref"><?php echo htmlspecialchars(orange_partner_account_stmt_gl_voucher_display($sr), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars(orange_partner_account_stmt_gl_line_text($sr), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo orange_accounting_report_format_amount((float) ($sr['debit'] ?? 0), $reportMoney); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo orange_accounting_report_format_amount((float) ($sr['credit'] ?? 0), $reportMoney); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo orange_accounting_report_format_amount((float) ($sr['balance'] ?? 0), $reportMoney); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="gl-acc-stmt-foot-label">
                            <td colspan="4" class="gl-acc-stmt-foot-total-title">الإجمالى</td>
                            <td class="gl-acc-stmt-col-num"><?php echo orange_accounting_report_format_amount($sumDebitPeriod, $reportMoney); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo orange_accounting_report_format_amount($sumCreditPeriod, $reportMoney); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo orange_accounting_report_format_amount($closingBal, $reportMoney); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php if ($showAging): ?>
                <?php if ($agingReport !== null && is_array($agingReport)): ?>
                    <div class="gl-acc-stmt-aging-wrap">
                        <h3 class="gl-acc-stmt-aging-title">توزيع الرصيد زمنياً (أعمار الرصيد، FIFO) حتى <?php echo htmlspecialchars(orange_format_date_dmY((string) ($agingReport['as_of'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></h3>
                        <div class="table-wrap admin-fy-table-wrap">
                            <table class="admin-fy-table gl-acc-stmt-table gl-acc-stmt-aging-table">
                                <thead>
                                    <tr>
                                        <th>الفئة</th>
                                        <th class="gl-acc-stmt-col-num">المبلغ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $lbls = isset($agingReport['bucket_labels_ar']) && is_array($agingReport['bucket_labels_ar']) ? $agingReport['bucket_labels_ar'] : [];
                                    $bks = isset($agingReport['buckets']) && is_array($agingReport['buckets']) ? $agingReport['buckets'] : [];
                                    foreach ($bks as $k => $amt) {
                                        $lb = isset($lbls[$k]) ? (string) $lbls[$k] : (string) $k;
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($lb, ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="gl-acc-stmt-col-num"><?php echo orange_accounting_report_format_amount((float) $amt, $reportMoney); ?></td>
                                        </tr>
                                        <?php
                                    }
                                    ?>
                                </tbody>
                                <?php if (isset($agingReport['balance']) || isset($agingReport['prepayment'])): ?>
                                    <tfoot>
                                        <tr>
                                            <td>رصيد الحساب (مجموع مدين − دائن حتى تاريخ «إلى الفترة»)</td>
                                            <td class="gl-acc-stmt-col-num"><?php echo orange_accounting_report_format_amount((float) ($agingReport['balance'] ?? 0), $reportMoney); ?></td>
                                        </tr>
                                        <?php if ((float) ($agingReport['prepayment'] ?? 0) > 0.0001): ?>
                                            <tr>
                                                <td>رصيد دائن (لصالح الطرف الآخر) — عندما يكون الرصيد سالباً وفق الدفتر</td>
                                                <td class="gl-acc-stmt-col-num"><?php echo orange_accounting_report_format_amount((float) ($agingReport['prepayment'] ?? 0), $reportMoney); ?></td>
                                            </tr>
                                        <?php endif; ?>
                                    </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <div class="gl-acc-stmt-print-footer">
                <p class="gl-acc-stmt-print-disclaimer">يعتبر كشف الحساب هذا صحيحا ومقبولا ما لم يتم اخطارنا باى اختلافات خلال اسبوعين من تاريخ الاستلام</p>
                <div class="gl-acc-stmt-print-signatures">
                    <div class="gl-acc-stmt-print-sig"><span>المحاسب</span><span class="gl-acc-stmt-print-sig-line">...................................</span></div>
                    <div class="gl-acc-stmt-print-sig"><span>المدير المالى</span><span class="gl-acc-stmt-print-sig-line">...................................</span></div>
                    <div class="gl-acc-stmt-print-sig"><span>اسم المستلم</span><span class="gl-acc-stmt-print-sig-line">...................................</span></div>
                </div>
                <p class="gl-acc-stmt-print-metafoot" dir="ltr">تاريخ ووقت الطباعة: <?php echo htmlspecialchars($printDatetime, ENT_QUOTES, 'UTF-8'); ?> — صفحة 1 من 1</p>
            </div>
        </div>
    </div>
    <?php elseif ($err === '' && (($isCustomerMode && $customerId <= 0) || (!$isCustomerMode && $accountId <= 0 && $useVouchers))): ?>
        <div class="card admin-fy-card gl-acc-stmt-no-print">
            <p class="card-hint"><?php echo $isCustomerMode ? 'اختر العميل ونطاق التواريخ ثم «استخراج الكشف».' : 'اختر الحساب ونطاق التواريخ ثم «استخراج الكشف».'; ?></p>
        </div>
    <?php endif; ?>
</div>
