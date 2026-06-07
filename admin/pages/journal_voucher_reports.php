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

$pdo = db();
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

<div class="page-title page-title--stacked">
    <div>
        <h1>تقارير السندات</h1>
    </div>
</div>

<?php if (orange_journal_vouchers_ready($pdo) && $jvrPostingLeafCt === 0): ?>
<div class="card" style="border:1px solid #fcd34d;background:#fffbeb;margin-bottom:12px;">
    <p class="card-hint" style="margin:0;line-height:1.55;"><strong>تنبيه:</strong> لا توجد حسابات ترحيل (أوراق) في الدليل بعد؛ تفسير أسطر السند والحسابات يعتمد على دليل كامل للأوراق. <strong>الفلاتر والشاشة تعملان</strong> — المتوقَّع أثناء الإعداد الأول في «الدليل المحاسبي».</p>
</div>
<?php endif; ?>

<div class="card">
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
            <div class="jvr-filter-tools__actions">
                <button type="submit">عرض التقرير</button>
                <a class="btn btn-secondary" href="<?php echo $resetUrl; ?>">إعادة ضبط الفلتر</a>
            </div>
        </div>
    </form>
</div>

<?php if (!orange_journal_vouchers_ready($pdo)): ?>
    <div class="card"><p class="page-subtitle" style="margin:0;">جداول السندات غير جاهزة بعد.</p></div>
<?php elseif ($vouchers === []): ?>
    <div class="card"><p class="page-subtitle" style="margin:0;">لا توجد سندات في هذه الفترة<?php echo ($journalTypeFilterId > 0 || $entryTypeFilter !== '') ? ' لنوع القيد المحدد' : ''; ?>.</p></div>
<?php else: ?>
<div class="card">
    <h3 class="card-title">النتائج (حتى 500 سنداً)</h3>
    <div class="table-wrap">
        <table>
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
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
