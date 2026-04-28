<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/upload_paths.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

/**
 * @param array<string, mixed> $jv
 */
function orange_gl_stmt_voucher_number(array $jv): string
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
function orange_gl_stmt_line_text(array $ln): string
{
    $d = trim((string) ($ln['description'] ?? ''));
    $m = trim((string) ($ln['line_memo'] ?? ''));
    if ($d !== '' && $m !== '' && $m !== $d) {
        return $d . ' — ' . $m;
    }

    return $d !== '' ? $d : $m;
}

$todayDmY = orange_format_date_dmY(date('Y-m-d'));

$accounts = $pdo->query('SELECT id, name, code FROM accounts ORDER BY COALESCE(code, \'\'), name')->fetchAll(PDO::FETCH_ASSOC);

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
    // إعادة تطبيع افتراضي
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
$err = '';

if ($useVouchers && $accountId > 0) {
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
            $bal += ($d - $c);
            $ln['balance'] = $bal;
            $rows[] = $ln;
        }
    } catch (Throwable $e) {
        $err = 'تعذر قراءة الحركات.';
    }
} elseif (!$useVouchers) {
    $err = 'سندات اليومية غير جاهزة بعد.';
}

<div class="admin-fy-shell" dir="rtl">
    <div class="gl-acc-stmt-no-print">
        <h1 class="admin-fy-shell__title">كشف حساب</h1>
        <p class="admin-fy-shell__lead">حركة حساب من الدليل المحاسبي لفترة تختارها — يعتمد على سندات اليومية المرحّلة.</p>
    </div>

    <div class="card admin-fy-card gl-acc-stmt-no-print">
        <h3 class="card-title">بحث</h3>
        <form method="get" class="form-grid" style="max-width:720px;">
            <input type="hidden" name="page" value="gl_account_statement">
            <div>
                <label for="gas_account">الحساب</label>
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
        <div class="gl-acc-stmt-print-head">
            <h2 class="gl-acc-stmt-print-title">كشف حساب</h2>
            <dl class="gl-acc-stmt-print-meta">
                <div><dt>رقم الحساب</dt><dd><?php echo htmlspecialchars($accCode !== '' ? $accCode : '—', ENT_QUOTES, 'UTF-8'); ?></dd></div>
                <div><dt>اسم الحساب</dt><dd><?php echo htmlspecialchars($accNameOnly !== '' ? $accNameOnly : '—', ENT_QUOTES, 'UTF-8'); ?></dd></div>
                <div><dt>تاريخ إصدار الكشف</dt><dd><?php echo htmlspecialchars($todayDmY, ENT_QUOTES, 'UTF-8'); ?></dd></div>
                <div><dt>مدة الكشف (البحث)</dt><dd>من <?php echo htmlspecialchars($dateFromRaw, ENT_QUOTES, 'UTF-8'); ?> إلى <?php echo htmlspecialchars($dateToRaw, ENT_QUOTES, 'UTF-8'); ?></dd></div>
            </dl>
            <p class="gl-acc-stmt-open-line"><strong>الرصيد قبل الفترة:</strong> <?php echo number_format($openingBal, 4); ?></p>
        </div>
        <div class="table-wrap admin-fy-table-wrap">
            <table class="admin-fy-table gl-acc-stmt-table">
                <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>نوع السند</th>
                        <th>رقم السند</th>
                        <th>البيان</th>
                        <th>مدين</th>
                        <th>دائن</th>
                        <th>رصيد</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="7" class="muted">لا حركة على هذا الحساب في هذه الفترة.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $sr): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(orange_format_date_dmY((string) ($sr['voucher_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(orange_gl_entry_type_label_ar((string) ($sr['entry_type'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td dir="ltr" style="text-align:right;"><?php echo htmlspecialchars(orange_gl_stmt_voucher_number($sr), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(orange_gl_stmt_line_text($sr), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo number_format((float) ($sr['debit'] ?? 0), 4); ?></td>
                                <td><?php echo number_format((float) ($sr['credit'] ?? 0), 4); ?></td>
                                <td><?php echo number_format((float) ($sr['balance'] ?? 0), 4); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php elseif ($accountId <= 0 && $useVouchers): ?>
        <div class="card admin-fy-card gl-acc-stmt-no-print">
            <p class="card-hint">اختر الحساب ونطاق التواريخ ثم «استخراج الكشف».</p>
        </div>
    <?php endif; ?>
</div>
