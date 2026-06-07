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
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

$pdo = orange_admin_page_pdo();
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

$jvrPostingLeafCt = 0;
if (orange_journal_vouchers_ready($pdo)) {
    $jvrPostingLeafCt = orange_accounts_count_posting_leaves($pdo);
}

$vouchers = [];
$linesByVid = [];
$accMap = [];

if (orange_journal_vouchers_ready($pdo)) {
    $hasGrp = orange_table_has_column($pdo, 'accounts', 'is_group');
    $accCols = $hasGrp ? 'a.id, a.name, a.code, a.is_group' : 'a.id, a.name, a.code';
    $accounts = orange_accounts_fetch(
        $pdo,
        'SELECT ' . $accCols . ' FROM accounts a WHERE 1=1 ORDER BY COALESCE(a.code, \'\'), a.name',
        [],
        'a'
    );
    foreach ($accounts as $a) {
        $accMap[(int) $a['id']] = trim((string) ($a['code'] ?? '')) !== '' ? $a['code'] . ' — ' . $a['name'] : $a['name'];
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
    $sql .= ' ORDER BY voucher_date DESC, id DESC LIMIT 500';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $vouchers = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($vouchers !== []) {
        $ids = array_map(static fn ($v) => (int) $v['id'], $vouchers);
        $in = implode(',', $ids);
        if ($in !== '') {
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
        }
    }
}

$resetUrl = htmlspecialchars(storefront_public_path('/admin/index.php?page=journal_voucher_reports'), ENT_QUOTES, 'UTF-8');

$companyNameAr = orange_company_settings_name_ar($pdo);
$jvrPrintDatetime = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));
$jvrCompany = orange_sales_doc_print_company($pdo, (int) (function_exists('orange_admin_context_country_id') ? orange_admin_context_country_id($pdo) : 0));
$jvrLogo = (string) ($jvrCompany['logo_url'] ?? '');

$jvrFilterTypeLabel = 'جميع الأنواع';
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
$jvrVouchersReady = orange_journal_vouchers_ready($pdo);
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
</style>

<div class="admin-fy-shell" dir="rtl">
    <h1 class="admin-fy-shell__title gl-acc-stmt-no-print">تقارير السندات</h1>

<?php if ($jvrVouchersReady && $jvrPostingLeafCt === 0): ?>
<div class="card admin-fy-card gl-acc-stmt-no-print" style="border:1px solid #fcd34d;background:#fffbeb;">
    <p class="card-hint" style="margin:0;line-height:1.55;"><strong>تنبيه:</strong> لا توجد حسابات ترحيل (أوراق) في الدليل بعد؛ تفسير أسطر السند والحسابات يعتمد على دليل كامل للأوراق. <strong>الفلاتر والشاشة تعملان</strong> — المتوقَّع أثناء الإعداد الأول في «الدليل المحاسبي».</p>
</div>
<?php endif; ?>

<div class="card admin-fy-card gl-acc-stmt-no-print gas-acc-stmt-search-card">
    <form method="get" action="" class="orange-doc-header-row">
        <input type="hidden" name="page" value="journal_voucher_reports">
        <div class="jvr-filter-tools jvr-filter-tools--center orange-doc-toolbar-fields">
            <div class="jvr-filter-tools__entry">
                <label for="jvr_journal_type_id">نوع القيد</label>
                <select id="jvr_journal_type_id" name="journal_type_id" class="admin-inp"<?php echo $jvrJournalTypes === [] ? ' disabled' : ''; ?>>
                    <option value=""<?php echo $journalTypeFilterId <= 0 && $entryTypeFilter === '' ? ' selected' : ''; ?>>الكل</option>
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
                <button type="button" class="btn-secondary" onclick="<?php echo $jvrVouchersReady ? 'window.print()' : "alert('جداول السندات غير جاهزة بعد')"; ?>">طباعة</button>
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
        <div class="table-wrap admin-fy-table-wrap gl-acc-stmt-table-wrap">
            <table class="admin-fy-table gl-acc-stmt-table" dir="rtl"
                data-export-name="تقارير السندات"
                data-export-target="#jvr_export_actions"
                data-export-company="<?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?>"
                data-export-subtitle="<?php echo htmlspecialchars($jvrExportSubtitle, ENT_QUOTES, 'UTF-8'); ?>">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>تاريخ السند</th>
                        <th>تاريخ المستند</th>
                        <th>نوع القيد</th>
                        <th>مرجع</th>
                        <th>البيان</th>
                        <th>التفاصيل</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($vouchers === []): ?>
                        <tr><td colspan="7" class="muted">لا توجد سندات في هذه الفترة<?php echo ($journalTypeFilterId > 0 || $entryTypeFilter !== '') ? ' لنوع القيد المحدد' : ''; ?>.</td></tr>
                    <?php else: ?>
                        <?php foreach ($vouchers as $v): ?>
                            <?php
                            $vid = (int) $v['id'];
                            $lines = $linesByVid[$vid] ?? [];
                            $det = [];
                            foreach ($lines as $ln) {
                                $aid = (int) $ln['account_id'];
                                $det[] = htmlspecialchars($accMap[$aid] ?? ('#' . $aid), ENT_QUOTES, 'UTF-8')
                                    . ' م:' . $ln['debit'] . ' د:' . $ln['credit'];
                            }
                            $et = (string) ($v['entry_type'] ?? '');
                            $etAr = orange_gl_voucher_type_label_ar($pdo, $v);
                            ?>
                            <tr>
                                <td><?php echo $vid; ?></td>
                                <td><?php echo htmlspecialchars(orange_format_datetime_dmY_hi((string) ($v['voucher_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php
                                    $docIn = (string) ($v['document_entered_at'] ?? '');
                                    if ($docIn === '') {
                                        $docIn = (string) ($v['created_at'] ?? '');
                                    }
                                    echo htmlspecialchars(orange_format_datetime_dmY_hi($docIn), ENT_QUOTES, 'UTF-8');
                                    ?></td>
                                <td title="<?php echo htmlspecialchars($et, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($etAr, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($v['reference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($v['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="font-size:12px;max-width:22rem;"><?php echo implode(' | ', $det); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="card-hint muted" style="margin-top:12px;">عدد السندات المعروضة: <?php echo $jvrVoucherCount; ?> (حد أقصى 500)</p>
        <div class="gl-acc-stmt-print-footer ta-report-print-footer">
            <p class="gl-acc-stmt-print-metafoot" dir="ltr">تاريخ ووقت الطباعة: <?php echo htmlspecialchars($jvrPrintDatetime, ENT_QUOTES, 'UTF-8'); ?> — صفحة 1 من 1</p>
        </div>
    </div>
</div>
<?php endif; ?>
</div>
