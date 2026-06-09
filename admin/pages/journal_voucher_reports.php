<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/journal_types.php';
require_once __DIR__ . '/../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/company_settings.php';
require_once __DIR__ . '/../../includes/sales_doc_print.php';
require_once __DIR__ . '/../../includes/accounting_report_money.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

$pdo = orange_admin_page_pdo();
$jvrCountryLabel = orange_admin_page_country_label($pdo);
$reportMoney = orange_accounting_report_money($pdo, isset($orangeAdminMoney) ? $orangeAdminMoney : null);
orange_catalog_ensure_schema($pdo);
orange_journal_types_sync_canonical_defaults($pdo);

$dateToRaw = trim((string) ($_GET['date_to'] ?? ''));
$dateFromRaw = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = orange_parse_admin_date_to_ymd($dateToRaw);
$dateFrom = orange_parse_admin_date_to_ymd($dateFromRaw);
if ($dateTo === '') {
    $dateTo = date('Y-m-d');
}
if ($dateFrom === '') {
    $dateFrom = date('Y-m-01');
}
if ($dateFrom > $dateTo) {
    $tmp = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $tmp;
}
$dateFromDisp = orange_format_date_dmY($dateFrom);
$dateToDisp = orange_format_date_dmY($dateTo);

$jvrCountryId = orange_admin_settings_effective_country_id($pdo);
$jvrJournalTypes = orange_journal_types_list($pdo, $jvrCountryId);

$journalTypeFilterId = isset($_GET['journal_type_id']) ? (int) $_GET['journal_type_id'] : 0;
$entryTypeFilter = trim((string) ($_GET['entry_type'] ?? ''));
if ($entryTypeFilter !== '' && !preg_match('/^[a-zA-Z0-9_\-]+$/', $entryTypeFilter)) {
    $entryTypeFilter = '';
}

/* توافق قديم: entry_type في الرابط → نوع اليومية المطابق إن وُجد. */
if ($journalTypeFilterId <= 0 && $entryTypeFilter !== '') {
    $mappedCode = orange_journal_type_code_from_entry_type($entryTypeFilter);
    if ($mappedCode !== '') {
        $mappedId = orange_journal_type_id_by_code($pdo, $mappedCode, $jvrCountryId);
        if ($mappedId > 0) {
            $journalTypeFilterId = $mappedId;
            $entryTypeFilter = '';
        }
    }
}

$jvrJournalTypeFilterValid = false;
if ($journalTypeFilterId > 0) {
    foreach ($jvrJournalTypes as $jtRow) {
        if ((int) ($jtRow['id'] ?? 0) === $journalTypeFilterId) {
            $jvrJournalTypeFilterValid = true;
            break;
        }
    }
    if (!$jvrJournalTypeFilterValid) {
        $journalTypeFilterId = 0;
    }
}

$submitted = isset($_GET['run']) && (string) $_GET['run'] === '1';
$jvrTypeSelected = $journalTypeFilterId > 0 || $entryTypeFilter !== '';

$jvrPostingLeafCt = 0;
if (orange_journal_vouchers_ready($pdo)) {
    $jvrPostingLeafCt = orange_accounts_count_posting_leaves($pdo);
}

$vouchers = [];
$jvrRows = [];
$accNameMap = [];
$jvrVouchersReady = orange_journal_vouchers_ready($pdo);
$jvrReportDisplayed = $submitted && $jvrTypeSelected && $jvrVouchersReady;

if ($jvrReportDisplayed) {
    $accounts = orange_accounts_fetch(
        $pdo,
        'SELECT a.id, a.name, a.code FROM accounts a WHERE 1=1 ORDER BY COALESCE(a.code, \'\'), a.name',
        [],
        'a'
    );
    foreach ($accounts as $a) {
        $accNameMap[(int) $a['id']] = trim((string) ($a['name'] ?? ''));
    }

    $jvCountryBind = orange_gl_voucher_country_bind($pdo, 'jv');
    $sql = 'SELECT * FROM journal_vouchers jv
            WHERE DATE(jv.voucher_date) >= ? AND DATE(jv.voucher_date) <= ?';
    $params = [$dateFrom, $dateTo];
    $sql .= $jvCountryBind['sql'];
    foreach ($jvCountryBind['params'] as $cp) {
        $params[] = $cp;
    }
    if ($journalTypeFilterId > 0) {
        $mappedEntryTypes = orange_gl_entry_types_for_journal_type_id($pdo, $journalTypeFilterId);
        $hasJtCol = orange_table_has_column($pdo, 'journal_vouchers', 'journal_type_id');
        if ($hasJtCol) {
            $sql .= ' AND (jv.journal_type_id = ?';
            $params[] = $journalTypeFilterId;
            if ($mappedEntryTypes !== []) {
                $ph = implode(',', array_fill(0, count($mappedEntryTypes), '?'));
                $sql .= " OR (jv.journal_type_id IS NULL AND jv.entry_type IN ($ph))";
                foreach ($mappedEntryTypes as $etMap) {
                    $params[] = $etMap;
                }
            }
            $sql .= ')';
        } elseif ($mappedEntryTypes !== []) {
            $ph = implode(',', array_fill(0, count($mappedEntryTypes), '?'));
            $sql .= " AND jv.entry_type IN ($ph)";
            foreach ($mappedEntryTypes as $etMap) {
                $params[] = $etMap;
            }
        } else {
            $sql .= ' AND 1=0';
        }
    } elseif ($entryTypeFilter !== '') {
        $sql .= ' AND jv.entry_type = ?';
        $params[] = $entryTypeFilter;
    }
    $sql .= ' ORDER BY jv.voucher_date ASC, jv.id ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $vouchers = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($vouchers !== []) {
        $ids = array_map(static fn ($v) => (int) $v['id'], $vouchers);
        $in = implode(',', $ids);
        if ($in !== '') {
            $linesByVid = [];
            $jl = $pdo->query(
                'SELECT * FROM journal_lines WHERE voucher_id IN (' . $in . ') ORDER BY voucher_id ASC, line_no ASC'
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($jl as $ln) {
                $vid = (int) $ln['voucher_id'];
                if (!isset($linesByVid[$vid])) {
                    $linesByVid[$vid] = [];
                }
                $linesByVid[$vid][] = $ln;
            }
            foreach ($vouchers as $v) {
                $vid = (int) $v['id'];
                $vRef = trim((string) ($v['reference'] ?? ''));
                $voucherNo = $vRef !== '' ? $vRef : (string) orange_journal_voucher_display_number($v);
                $vDateRaw = (string) ($v['voucher_date'] ?? '');
                $vDateDisp = strlen($vDateRaw) >= 10
                    ? orange_format_date_dmY(substr($vDateRaw, 0, 10))
                    : '';
                $vDesc = trim((string) ($v['description'] ?? ''));
                foreach ($linesByVid[$vid] ?? [] as $ln) {
                    $aid = (int) ($ln['account_id'] ?? 0);
                    $lineMemo = trim((string) ($ln['memo'] ?? ''));
                    if ($lineMemo === '') {
                        $lineMemo = $vDesc;
                    }
                    $jvrRows[] = [
                        'voucher_no' => $voucherNo,
                        'voucher_date' => $vDateDisp,
                        'account_name' => $accNameMap[$aid] ?? ('#' . $aid),
                        'debit' => (float) ($ln['debit'] ?? 0),
                        'credit' => (float) ($ln['credit'] ?? 0),
                        'memo' => $lineMemo,
                    ];
                }
            }
        }
    }
}

$resetUrl = htmlspecialchars(storefront_public_path('/admin/index.php?page=journal_voucher_reports'), ENT_QUOTES, 'UTF-8');

$companyNameAr = orange_company_settings_name_ar($pdo);
$jvrPrintDatetime = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));
$jvrCompany = orange_sales_doc_print_company($pdo, (int) (function_exists('orange_admin_context_country_id') ? orange_admin_context_country_id($pdo) : 0));
$jvrLogo = (string) ($jvrCompany['logo_url'] ?? '');

$jvrFilterTypeLabel = '—';
if ($journalTypeFilterId > 0) {
    foreach ($jvrJournalTypes as $jtRow) {
        if ((int) ($jtRow['id'] ?? 0) === $journalTypeFilterId) {
            $jtName = trim((string) ($jtRow['name_ar'] ?? ''));
            if ($jtName === '') {
                $jtName = trim((string) ($jtRow['name_en'] ?? ''));
            }
            if ($jtName !== '') {
                $jvrFilterTypeLabel = $jtName;
            }
            break;
        }
    }
} elseif ($entryTypeFilter !== '') {
    $jvrFilterTypeLabel = orange_gl_entry_type_label_ar($entryTypeFilter);
}

$jvrExportSubtitle = 'من ' . $dateFromDisp . ' إلى ' . $dateToDisp . ' — نوع القيد: ' . $jvrFilterTypeLabel;
$jvrVoucherCount = count($vouchers);
$jvrLineCount = count($jvrRows);
$jvrPrintAlert = $jvrVouchersReady
    ? "alert('اختر نوع القيد ثم اضغط عرض أولاً')"
    : "alert('جداول السندات غير جاهزة بعد')";
$jvrPrintOnclick = $jvrReportDisplayed ? 'window.print()' : $jvrPrintAlert;
?>
<style>
/*
 * عرض تواريخ مساوٍ لمربعات «بحث السند» بسند القيد (~11rem): jv-search-field--date
 */
.jvr-filter-tools {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 12px;
    width: 100%;
    box-sizing: border-box;
}
.jvr-filter-tools__entry {
    flex: 1 1 16rem;
    min-width: 12rem;
}
.jvr-filter-tools__entry .admin-inp {
    width: 100%;
    box-sizing: border-box;
}
.jvr-filter-tools__date {
    flex: 0 0 11rem;
    min-width: 11rem;
    max-width: 11rem;
}
.jvr-filter-tools__date .admin-inp.orange-inp-dmy {
    width: 100%;
    box-sizing: border-box;
}
.jvr-filter-tools__actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-inline-start: auto;
}
.jvr-filter-tools--center .jvr-filter-tools__entry,
.jvr-filter-tools--center .jvr-filter-tools__date {
    text-align: center;
}
.jvr-filter-tools--center .jvr-filter-tools__entry label,
.jvr-filter-tools--center .jvr-filter-tools__date label {
    text-align: center;
}
.jvr-filter-tools--center .jvr-filter-tools__entry .admin-inp,
.jvr-filter-tools--center .jvr-filter-tools__date .admin-inp {
    text-align: center;
}
.jvr-lines-table {
    table-layout: fixed;
    width: 100%;
}
.jvr-lines-table col.jvr-col-vno,
.jvr-lines-table col.jvr-col-date { width: 8%; }
.jvr-lines-table col.jvr-col-acct { width: 18%; }
.jvr-lines-table col.jvr-col-amt { width: 9%; }
.jvr-lines-table col.jvr-col-memo { width: 48%; }
.jvr-lines-table th,
.jvr-lines-table td { text-align: center; vertical-align: top; }
.jvr-lines-table th:nth-child(3),
.jvr-lines-table td:nth-child(3),
.jvr-lines-table th:nth-child(6),
.jvr-lines-table td:nth-child(6) { text-align: right; }
.jvr-lines-table thead th:nth-child(1),
.jvr-lines-table thead th:nth-child(2) {
    white-space: normal;
    line-height: 1.3;
    padding-inline: 4px;
}
.jvr-lines-table tbody td:nth-child(1),
.jvr-lines-table tbody td:nth-child(2) {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 0;
}
.jvr-lines-table td:nth-child(6) {
    word-wrap: break-word;
    overflow-wrap: anywhere;
    line-height: 1.45;
}
.jvr-lines-table .gl-acc-stmt-col-num { white-space: nowrap; }
@media print {
    .jvr-lines-table col.jvr-col-vno,
    .jvr-lines-table col.jvr-col-date { width: 7%; }
    .jvr-lines-table col.jvr-col-memo { width: 46%; }
}
</style>

<div class="admin-fy-shell" dir="rtl">
    <div class="page-title gl-acc-stmt-no-print">
        <h1>تقارير السندات</h1>
        <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars($jvrCountryLabel, ENT_QUOTES, 'UTF-8'); ?></p>
    </div>

<?php if ($jvrVouchersReady && $jvrPostingLeafCt === 0): ?>
<div class="card admin-fy-card gl-acc-stmt-no-print" style="border:1px solid #fcd34d;background:#fffbeb;">
    <p class="card-hint" style="margin:0;line-height:1.55;"><strong>تنبيه:</strong> لا توجد حسابات ترحيل (أوراق) في الدليل بعد؛ تفسير أسطر السند والحسابات يعتمد على دليل كامل للأوراق. <strong>الفلاتر والشاشة تعملان</strong> — المتوقَّع أثناء الإعداد الأول في «الدليل المحاسبي».</p>
</div>
<?php endif; ?>

<div class="card admin-fy-card gl-acc-stmt-no-print gas-acc-stmt-search-card">
    <form method="get" action="" class="orange-doc-header-row">
        <input type="hidden" name="page" value="journal_voucher_reports">
        <input type="hidden" name="run" value="1">
        <div class="jvr-filter-tools jvr-filter-tools--center orange-doc-toolbar-fields">
            <div class="jvr-filter-tools__entry">
                <label for="jvr_journal_type_id">نوع القيد</label>
                <select id="jvr_journal_type_id" name="journal_type_id" class="admin-inp" required<?php echo $jvrJournalTypes === [] ? ' disabled' : ''; ?>>
                    <option value="" disabled<?php echo $journalTypeFilterId <= 0 && $entryTypeFilter === '' ? ' selected' : ''; ?>>— اختر نوع قيد —</option>
                    <?php foreach ($jvrJournalTypes as $jtRow):
                        $jtId = (int) ($jtRow['id'] ?? 0);
                        if ($jtId <= 0) {
                            continue;
                        }
                        $jtName = trim((string) ($jtRow['name_ar'] ?? ''));
                        if ($jtName === '') {
                            $jtName = trim((string) ($jtRow['name_en'] ?? ''));
                        }
                        if ($jtName === '') {
                            continue;
                        }
                        ?>
                        <option value="<?php echo $jtId; ?>"<?php echo $journalTypeFilterId === $jtId ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars($jtName, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($entryTypeFilter !== ''): ?>
                <input type="hidden" name="entry_type" value="<?php echo htmlspecialchars($entryTypeFilter, ENT_QUOTES, 'UTF-8'); ?>">
                <p class="muted" style="margin:6px 0 0;font-size:12px;">فلتر نوع قيد قديم: <?php echo htmlspecialchars(orange_gl_entry_type_label_ar($entryTypeFilter), ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
            </div>
            <div class="jvr-filter-tools__date">
                <label for="jvr_from">من تاريخ</label>
                <input type="text" id="jvr_from" name="date_from" class="admin-inp orange-inp-dmy"
                    value="<?php echo htmlspecialchars($dateFromDisp, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en" autocomplete="off">
            </div>
            <div class="jvr-filter-tools__date">
                <label for="jvr_to">إلى تاريخ</label>
                <input type="text" id="jvr_to" name="date_to" class="admin-inp orange-inp-dmy"
                    value="<?php echo htmlspecialchars($dateToDisp, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en" autocomplete="off">
            </div>
            <div class="jvr-filter-tools__actions gas-acc-stmt-actions" id="jvr_export_actions" data-export-host>
                <button type="submit">عرض</button>
                <button type="button" class="btn-secondary" onclick="<?php echo $jvrPrintOnclick; ?>">طباعة</button>
                <a class="btn-secondary" href="<?php echo $resetUrl; ?>">إعادة ضبط</a>
            </div>
        </div>
    </form>
</div>

<?php if (! $jvrVouchersReady): ?>
    <div class="card admin-fy-card gl-acc-stmt-no-print"><p class="muted" style="margin:0;">جداول السندات غير جاهزة بعد.</p></div>
<?php else: ?>
<div class="card admin-fy-card gl-acc-stmt-print">
    <div class="gl-acc-stmt-print-sheet ta-report-print-sheet">
        <div class="table-wrap admin-fy-table-wrap gl-acc-stmt-table-wrap">
            <table class="admin-fy-table gl-acc-stmt-table jvr-lines-table ta-report-print-table" dir="rtl"<?php if ($jvrReportDisplayed): ?>
                data-export-name="تقارير السندات"
                data-export-target="#jvr_export_actions"
                data-export-company="<?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?>"
                data-export-subtitle="<?php echo htmlspecialchars($jvrExportSubtitle, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
                <colgroup>
                    <col class="jvr-col-vno">
                    <col class="jvr-col-date">
                    <col class="jvr-col-acct">
                    <col class="jvr-col-amt">
                    <col class="jvr-col-amt">
                    <col class="jvr-col-memo">
                </colgroup>
                <thead class="ta-report-print-thead">
                    <tr class="ta-report-banner-row">
                        <td colspan="6" class="ta-report-banner-cell">
                            <header class="gl-acc-stmt-print-banner">
                                <div class="pl-month-brand-row">
                                    <div class="pl-month-brand">
                                        <?php if ($jvrLogo !== ''): ?>
                                            <img class="pl-month-print-logo" src="<?php echo htmlspecialchars($jvrLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                                        <?php endif; ?>
                                        <div class="pl-month-brand-text">
                                            <?php if ($companyNameAr !== ''): ?>
                                                <p class="gl-acc-stmt-print-company"><?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?></p>
                                            <?php endif; ?>
                                            <?php if (trim((string) ($jvrCompany['commercial_register'] ?? '')) !== ''): ?>
                                                <p class="pl-month-cr">سجل تجاري: <span dir="ltr"><?php echo htmlspecialchars((string) $jvrCompany['commercial_register'], ENT_QUOTES, 'UTF-8'); ?></span></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="pl-month-contact">
                                        <?php if (trim((string) ($jvrCompany['address'] ?? '')) !== ''): ?>
                                            <p class="pl-month-contact-line"><?php echo htmlspecialchars((string) $jvrCompany['address'], ENT_QUOTES, 'UTF-8'); ?></p>
                                        <?php endif; ?>
                                        <?php if (trim((string) ($jvrCompany['phones'] ?? '')) !== ''): ?>
                                            <p class="pl-month-contact-line"><span dir="ltr"><?php echo htmlspecialchars((string) $jvrCompany['phones'], ENT_QUOTES, 'UTF-8'); ?></span></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <h2 class="gl-acc-stmt-print-title ta-report-print-title">
                                    <span class="gl-acc-stmt-print-title-ar" lang="ar">تقرير السندات عن الفترة من&nbsp;<?php echo htmlspecialchars($dateFromDisp, ENT_QUOTES, 'UTF-8'); ?>&nbsp;إلى&nbsp;<?php echo htmlspecialchars($dateToDisp, ENT_QUOTES, 'UTF-8'); ?></span>
                                </h2>
                                <p class="muted" style="margin:8px 0 0;text-align:center;">نوع القيد: <?php echo htmlspecialchars($jvrFilterTypeLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                            </header>
                        </td>
                    </tr>
                    <tr class="ta-report-grid-row">
                        <td colspan="6" class="ta-report-grid-cell">
                            <div class="gl-acc-stmt-print-grid">
                                <div class="gl-acc-stmt-print-row gl-acc-stmt-print-row--dates">
                                    <span class="gl-acc-stmt-print-k">من تاريخ</span>
                                    <span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($dateFromDisp, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="gl-acc-stmt-print-k">إلى تاريخ</span>
                                    <span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($dateToDisp, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="gl-acc-stmt-print-k">نوع القيد</span>
                                    <span class="gl-acc-stmt-print-v"><?php echo htmlspecialchars($jvrFilterTypeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr class="ta-report-cols-row">
                        <th>رقم القيد</th>
                        <th>تاريخ السند</th>
                        <th>اسم الحساب</th>
                        <th class="gl-acc-stmt-col-num">مدين</th>
                        <th class="gl-acc-stmt-col-num">دائن</th>
                        <th>البيان</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (! $jvrReportDisplayed): ?>
                        <tr><td colspan="6" class="muted">اختر نوع القيد والفترة ثم اضغط «عرض».</td></tr>
                    <?php elseif ($submitted && ! $jvrTypeSelected): ?>
                        <tr><td colspan="6" class="muted">يجب اختيار نوع القيد قبل عرض التقرير.</td></tr>
                    <?php elseif ($jvrRows === []): ?>
                        <tr><td colspan="6" class="muted">لا توجد حركة قيود في هذه الفترة لنوع القيد المحدد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($jvrRows as $row): ?>
                            <tr>
                                <td dir="ltr" lang="en"><?php echo htmlspecialchars((string) $row['voucher_no'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td dir="ltr" lang="en"><?php echo htmlspecialchars((string) $row['voucher_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) $row['account_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo orange_accounting_report_format_amount((float) $row['debit'], $reportMoney); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo orange_accounting_report_format_amount((float) $row['credit'], $reportMoney); ?></td>
                                <td><?php echo htmlspecialchars((string) $row['memo'], ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($jvrReportDisplayed): ?>
        <p class="card-hint muted" style="margin-top:12px;">عدد السندات: <?php echo $jvrVoucherCount; ?> — عدد أسطر الحركة: <?php echo $jvrLineCount; ?></p>
        <?php endif; ?>
        <?php echo orange_accounting_report_print_metafoot_markup($jvrPrintDatetime); ?>
    </div>
</div>
<?php endif; ?>
</div>
