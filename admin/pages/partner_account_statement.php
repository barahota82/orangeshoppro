<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/upload_paths.php';
require_once __DIR__ . '/../../includes/account_tree.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

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
$accounts = $pdo->query(
    "SELECT a.id, a.name, a.code FROM accounts a WHERE $leafWhere ORDER BY COALESCE(a.code, ''), a.name"
)->fetchAll(PDO::FETCH_ASSOC);

$gasAccountsLeafArr = [];
foreach ($accounts as $ga) {
    $gasAccountsLeafArr[] = [
        'id' => (int) ($ga['id'] ?? 0),
        'code' => (string) ($ga['code'] ?? ''),
        'name' => (string) ($ga['name'] ?? ''),
    ];
}
$gasAccountsLeafJson = json_encode($gasAccountsLeafArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($gasAccountsLeafJson === false) {
    $gasAccountsLeafJson = '[]';
}

$companyNameAr = '';
if (orange_table_exists($pdo, 'company_settings')) {
    $cs = $pdo->query('SELECT company_name_ar FROM company_settings ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (is_array($cs)) {
        $companyNameAr = trim((string) ($cs['company_name_ar'] ?? ''));
    }
}

$accountId = isset($_GET['account']) ? (int) $_GET['account'] : 0;
$dateFromRaw = trim((string) ($_GET['date_from'] ?? ''));
$dateToRaw = trim((string) ($_GET['date_to'] ?? ''));

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

$openingBal = 0.0;
$rows = [];
$sumDebitPeriod = 0.0;
$sumCreditPeriod = 0.0;
$closingBal = 0.0;
$err = '';

if ($accountId > 0 && ! orange_accounts_account_is_posting_leaf($pdo, $accountId)) {
    $err = 'يُعرض كشف الحساب للحسابات الفرعية (ورقة ترحيل) فقط.';
    $accountId = 0;
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

if ($useVouchers && $accountId > 0 && $err === '') {
    try {
        $stOpen = $pdo->prepare(
            'SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS bal
             FROM journal_lines jl
             INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id
             WHERE jl.account_id = ? AND DATE(jv.voucher_date) < ?'
        );
        $stOpen->execute([$accountId, $dateFromYmd]);
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
               AND DATE(jv.voucher_date) <= ?
             ORDER BY jv.voucher_date ASC, jv.id ASC, jl.line_no ASC"
        );
        $stL->execute([$accountId, $dateFromYmd, $dateToYmd]);
        $bal = $openingBal;
        foreach ($stL->fetchAll(PDO::FETCH_ASSOC) as $ln) {
            $d = (float) $ln['debit'];
            $c = (float) $ln['credit'];
            $sumDebitPeriod += $d;
            $sumCreditPeriod += $c;
            $bal += ($d - $c);
            $ln['balance'] = $bal;
            $rows[] = $ln;
        }
        $closingBal = $rows === [] ? $openingBal : (float) ($rows[count($rows) - 1]['balance'] ?? $openingBal);
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
            <div class="gas-acc-stmt-toolbar-wrap">
                <div class="gas-acc-stmt-toolbar">
                    <div class="gas-acc-stmt-field gas-acc-stmt-field--code">
                        <label for="gas_acc_code">كود الحساب</label>
                        <input type="text" id="gas_acc_code" name="_gas_acc_code_dummy" autocomplete="off" readonly
                            class="admin-inp jv-acc-code gas-acc-stmt-acc-code-input"
                            placeholder="انقر نقرتين للاختيار"
                            title="انقر نقرتين لفتح قائمة الحسابات الفرعية"
                            value="<?php echo htmlspecialchars($accCode, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en">
                    </div>
                    <div class="gas-acc-stmt-field gas-acc-stmt-field--name">
                        <label for="gas_acc_name">اسم الحساب</label>
                        <input type="text" id="gas_acc_name" name="_gas_acc_name_dummy" tabindex="-1" readonly autocomplete="off"
                            class="admin-inp gas-acc-stmt-acc-name-input"
                            placeholder="—" title="يُعبأ بعد اختيار الحساب" value="<?php echo htmlspecialchars($accNameOnly, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="gas-acc-stmt-field gas-acc-stmt-field--date gas-acc-stmt-field--dmy">
                        <label for="gas_from">من تاريخ</label>
                        <input type="text" name="date_from" id="gas_from" class="admin-inp orange-inp-dmy" value="<?php echo htmlspecialchars($dateFromRaw, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en" autocomplete="off" required>
                    </div>
                    <div class="gas-acc-stmt-field gas-acc-stmt-field--date gas-acc-stmt-field--dmy">
                        <label for="gas_to">إلى تاريخ</label>
                        <input type="text" name="date_to" id="gas_to" class="admin-inp orange-inp-dmy" value="<?php echo htmlspecialchars($dateToRaw, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en" autocomplete="off" required>
                    </div>
                    <div class="gas-acc-stmt-actions">
                        <button type="submit">استخراج الكشف</button>
                        <?php if ($accountId > 0 && $err === '' && $useVouchers): ?>
                            <button type="button" class="btn-secondary" onclick="window.print()">طباعة</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
        <?php if ($err !== ''): ?>
            <p class="card-hint gas-acc-stmt-err-msg" style="color:var(--danger,#b91c1c);margin-top:10px;"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
    </div>

    <div id="gas_acct_picker" class="jv-acct-picker jv-print-hide gl-acc-stmt-no-print" style="display:none;" aria-hidden="true">
        <label class="jv-acct-picker-label" for="gas_acct_picker_search">بحث</label>
        <input type="search" id="gas_acct_picker_search" class="jv-acct-picker-search admin-inp" placeholder="اكتب كلمات من الاسم أو الكود…" autocomplete="off" dir="auto">
        <div class="jv-acct-picker-scroll">
            <table class="admin-table jv-acct-picker-table">
                <thead>
                    <tr>
                        <th>كود الحساب</th>
                        <th>اسم الحساب</th>
                    </tr>
                </thead>
                <tbody id="gas_acct_picker_tbody"></tbody>
            </table>
        </div>
        <p class="jv-acct-picker-hint muted">نقرتان على صف لاختيار الحساب — Esc للإغلاق</p>
    </div>

    <script>
    (function () {
        var GAS_ACCOUNTS = <?php echo $gasAccountsLeafJson; ?>;
        var gasAcctPickerAnchor = null;

        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }
        function gasAcctTokens(q) {
            return String(q || '').trim().toLowerCase().split(/\s+/).filter(Boolean);
        }
        function gasAcctFilterAccounts(q) {
            var tokens = gasAcctTokens(q);
            var rows = !tokens.length ? GAS_ACCOUNTS.slice() : GAS_ACCOUNTS.filter(function (a) {
                var hay = ((a.code || '') + ' ' + (a.name || '')).toLowerCase();
                return tokens.every(function (t) { return hay.indexOf(t) !== -1; });
            });
            return rows;
        }
        function gasAcctPickerPosition(anchorEl) {
            var box = document.getElementById('gas_acct_picker');
            if (!box || !anchorEl) { return; }
            var r = anchorEl.getBoundingClientRect();
            var margin = 8;
            var w = box.offsetWidth || 320;
            box.style.left = Math.max(margin, Math.min(r.left, window.innerWidth - w - margin)) + 'px';
            box.style.top = (r.top - margin) + 'px';
            box.style.transform = 'translateY(-100%)';
        }
        function gasAcctPickerRender() {
            var searchEl = document.getElementById('gas_acct_picker_search');
            var tb = document.getElementById('gas_acct_picker_tbody');
            if (!tb) { return; }
            var q = searchEl ? searchEl.value : '';
            var rows = gasAcctFilterAccounts(q);
            tb.innerHTML = '';
            rows.forEach(function (a) {
                var tr = document.createElement('tr');
                tr.innerHTML = '<td>' + escapeHtml(a.code) + '</td><td>' + escapeHtml(a.name) + '</td>';
                tr.addEventListener('dblclick', function () { gasAcctPickerApply(a); });
                tb.appendChild(tr);
            });
        }
        function gasAcctPickerClose() {
            var box = document.getElementById('gas_acct_picker');
            if (box) {
                box.style.display = 'none';
                box.setAttribute('aria-hidden', 'true');
            }
            gasAcctPickerAnchor = null;
        }
        function gasAcctPickerApply(a) {
            if (!a) {
                gasAcctPickerClose();
                return;
            }
            var hid = document.getElementById('gas_account_id');
            var cd = document.getElementById('gas_acc_code');
            var nm = document.getElementById('gas_acc_name');
            if (hid) { hid.value = String(a.id || '0'); }
            if (cd) { cd.value = a.code || ''; }
            if (nm) { nm.value = a.name || ''; }
            gasAcctPickerClose();
        }
        function gasAcctPickerOpen(anchorEl) {
            var box = document.getElementById('gas_acct_picker');
            var searchEl = document.getElementById('gas_acct_picker_search');
            if (!box || !searchEl || !anchorEl) { return; }
            gasAcctPickerAnchor = anchorEl;
            searchEl.value = '';
            gasAcctPickerRender();
            box.style.display = 'block';
            box.setAttribute('aria-hidden', 'false');
            box.style.transform = '';
            gasAcctPickerPosition(anchorEl);
            requestAnimationFrame(function () {
                gasAcctPickerPosition(anchorEl);
                searchEl.focus();
                searchEl.select();
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            var cd = document.getElementById('gas_acc_code');
            if (cd) {
                cd.addEventListener('dblclick', function (e) {
                    e.preventDefault();
                    gasAcctPickerOpen(cd);
                });
            }
            var searchEl = document.getElementById('gas_acct_picker_search');
            if (searchEl && !searchEl.getAttribute('data-gas-bound')) {
                searchEl.setAttribute('data-gas-bound', '1');
                searchEl.addEventListener('input', gasAcctPickerRender);
                searchEl.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Enter') { ev.preventDefault(); }
                });
            }

            document.addEventListener('mousedown', function gasAcctPickDoc(ev) {
                var box = document.getElementById('gas_acct_picker');
                if (!box || box.style.display === 'none') { return; }
                var t = ev.target;
                if (box.contains(t)) { return; }
                if (gasAcctPickerAnchor && (t === gasAcctPickerAnchor || (gasAcctPickerAnchor.contains && gasAcctPickerAnchor.contains(t)))) { return; }
                gasAcctPickerClose();
            }, true);

            document.addEventListener('keydown', function gasAcctPickEsc(ev) {
                if (ev.key === 'Escape') {
                    gasAcctPickerClose();
                }
            }, true);
        });
    })();
    </script>

    <?php if ($accountId > 0 && $err === '' && $useVouchers): ?>

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
            <div class="gl-acc-stmt-print-grid">
                <div class="gl-acc-stmt-print-row"><span class="gl-acc-stmt-print-k">رقـــم الحســاب</span><span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($accCode !== '' ? $accCode : '—', ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div class="gl-acc-stmt-print-row"><span class="gl-acc-stmt-print-k">اسم الحســـــــــاب</span><span class="gl-acc-stmt-print-v"><?php echo htmlspecialchars($accNameOnly !== '' ? $accNameOnly : '—', ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div class="gl-acc-stmt-print-row gl-acc-stmt-print-row--dates">
                    <span class="gl-acc-stmt-print-k">من تاريخ</span><span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($dateFromRaw, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="gl-acc-stmt-print-k">الى تاريخ</span><span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($dateToRaw, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="gl-acc-stmt-print-k">تاريخ الكشف</span><span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($todayDmY, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>
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
                            <td class="gl-acc-stmt-col-num"><?php echo number_format(0.0, 4); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo number_format(0.0, 4); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo number_format($openingBal, 4); ?></td>
                        </tr>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="7" class="muted">لا حركة على هذا الحساب في هذه الفترة بعد الرصيد الافتتاحي.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $sr): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(orange_format_date_dmY((string) ($sr['voucher_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars(orange_gl_entry_type_label_ar((string) ($sr['entry_type'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td dir="ltr" class="gl-acc-stmt-col-ref"><?php echo htmlspecialchars(orange_partner_account_stmt_gl_voucher_display($sr), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars(orange_partner_account_stmt_gl_line_text($sr), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo number_format((float) ($sr['debit'] ?? 0), 4); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo number_format((float) ($sr['credit'] ?? 0), 4); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo number_format((float) ($sr['balance'] ?? 0), 4); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="gl-acc-stmt-foot-label">
                            <td colspan="4" class="gl-acc-stmt-foot-total-title">الإجمالى</td>
                            <td class="gl-acc-stmt-col-num"><?php echo number_format($sumDebitPeriod, 4); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo number_format($sumCreditPeriod, 4); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo number_format($closingBal, 4); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
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
    <?php elseif ($accountId <= 0 && $useVouchers): ?>
        <div class="card admin-fy-card gl-acc-stmt-no-print">
            <p class="card-hint">اختر الحساب ونطاق التواريخ ثم «استخراج الكشف».</p>
        </div>
    <?php endif; ?>
</div>
