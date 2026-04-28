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
    $dateFromRaw = orange_format_date_dmY(date('Y-m-01'));
}
if ($dateToRaw === '') {
    $dateToRaw = $todayDmY;
}

$dateFromYmd = orange_parse_admin_date_to_ymd($dateFromRaw);
$dateToYmd = orange_parse_admin_date_to_ymd($dateToRaw);
if ($dateFromYmd === '' || $dateToYmd === '') {
    $dateFromYmd = date('Y-m-01');
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

$accCode = '';
$accNameOnly = '';
foreach ($accounts as $a) {
    if ((int) $a['id'] === $accountId) {
        $accCode = trim((string) ($a['code'] ?? ''));
        $accNameOnly = trim((string) ($a['name'] ?? ''));
        break;
    }
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
} elseif (!$useVouchers) {
    $err = 'سندات اليومية غير جاهزة بعد.';
}

?>
<div class="admin-fy-shell" dir="rtl">
    <div class="gl-acc-stmt-no-print">
        <h1 class="admin-fy-shell__title">كشف حساب</h1>
        <p class="admin-fy-shell__lead">اختر حساباً فرعياً (ورقة ترحيل) من الدليل وحدد الفترة — يعتمد على سندات اليومية.</p>
    </div>

    <div class="card admin-fy-card gl-acc-stmt-no-print">
        <h3 class="card-title">بحث</h3>
        <form method="get" class="form-grid" style="max-width:720px;">
            <input type="hidden" name="page" value="partner_account_statement">
            <div>
                <label for="gas_account">الحساب (فرعي — ورقة ترحيل)</label>
                <select name="account" id="gas_account" required>
                    <option value="">— اختر حساباً —</option>
                    <?php foreach ($accounts as $a): ?>
                        <option value="<?php echo (int) $a['id']; ?>"<?php echo (int) $a['id'] === $accountId ? ' selected' : ''; ?>>
                            <?php
                            echo htmlspecialchars(
                                (trim((string) ($a['code'] ?? '')) !== '' ? $a['code'] . ' — ' : '') . $a['name'],
                                ENT_QUOTES,
                                'UTF-8'
                            );
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="gas_from">من تاريخ</label>
                <input type="text" name="date_from" id="gas_from" class="admin-inp orange-inp-dmy" value="<?php echo htmlspecialchars($dateFromRaw, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en" autocomplete="off" required>
            </div>
            <div>
                <label for="gas_to">إلى تاريخ</label>
                <input type="text" name="date_to" id="gas_to" class="admin-inp orange-inp-dmy" value="<?php echo htmlspecialchars($dateToRaw, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en" autocomplete="off" required>
            </div>
            <div class="actions" style="align-self:end;">
                <button type="submit">استخراج الكشف</button>
                <?php if ($accountId > 0 && $err === '' && $useVouchers): ?>
                    <button type="button" class="btn-secondary" onclick="window.print()">طباعة</button>
                <?php endif; ?>
            </div>
        </form>
        <?php if ($err !== ''): ?>
            <p class="card-hint" style="color:var(--danger,#b91c1c);margin-top:10px;"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
    </div>

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
