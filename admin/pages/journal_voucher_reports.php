<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/date_format.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

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

$entryTypeFilter = trim((string) ($_GET['entry_type'] ?? ''));
if ($entryTypeFilter !== '' && !preg_match('/^[a-zA-Z0-9_\-]+$/', $entryTypeFilter)) {
    $entryTypeFilter = '';
}

$typeLabels = orange_gl_entry_type_labels_map();
try {
    if (orange_journal_vouchers_ready($pdo)) {
        $dist = $pdo->query('SELECT DISTINCT entry_type FROM journal_vouchers ORDER BY entry_type')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($dist as $t) {
            $t = (string) $t;
            if ($t !== '' && !array_key_exists($t, $typeLabels)) {
                $typeLabels[$t] = $t;
            }
        }
    }
} catch (Throwable $e) {
    /* ignore */
}
asort($typeLabels, SORT_STRING);

$vouchers = [];
$linesByVid = [];
$accMap = [];

if (orange_journal_vouchers_ready($pdo)) {
    $hasGrp = orange_table_has_column($pdo, 'accounts', 'is_group');
    $accCols = $hasGrp ? 'id, name, code, is_group' : 'id, name, code';
    $accounts = $pdo->query('SELECT ' . $accCols . ' FROM accounts ORDER BY COALESCE(code, \'\'), name')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($accounts as $a) {
        $accMap[(int) $a['id']] = trim((string) ($a['code'] ?? '')) !== '' ? $a['code'] . ' — ' . $a['name'] : $a['name'];
    }

    $sql = 'SELECT * FROM journal_vouchers
            WHERE DATE(voucher_date) >= ? AND DATE(voucher_date) <= ?';
    $params = [$dateFrom, $dateTo];
    if ($entryTypeFilter !== '') {
        $sql .= ' AND entry_type = ?';
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
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>تقارير السندات</h1>
    </div>
</div>

<div class="card">
    <h3 class="card-title">تصفية</h3>
    <form method="get" action="" class="form-grid" style="align-items:end;">
        <input type="hidden" name="page" value="journal_voucher_reports">
        <div>
            <label for="jvr_from">من تاريخ</label>
            <input type="text" id="jvr_from" name="date_from" class="admin-inp orange-inp-dmy"
                value="<?php echo htmlspecialchars($dateFromDisp, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en" autocomplete="off">
        </div>
        <div>
            <label for="jvr_to">إلى تاريخ</label>
            <input type="text" id="jvr_to" name="date_to" class="admin-inp orange-inp-dmy"
                value="<?php echo htmlspecialchars($dateToDisp, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en" autocomplete="off">
        </div>
        <div style="grid-column:1/-1; max-width:28rem;">
            <label for="jvr_entry_type">نوع القيد</label>
            <select id="jvr_entry_type" name="entry_type">
                <option value=""<?php echo $entryTypeFilter === '' ? ' selected' : ''; ?>>الكل</option>
                <?php foreach ($typeLabels as $code => $lab): ?>
                    <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $entryTypeFilter === $code ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars($lab . ' (' . $code . ')', ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="actions" style="grid-column:1/-1; margin:0;">
            <button type="submit">عرض التقرير</button>
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=journal_voucher_reports'), ENT_QUOTES, 'UTF-8'); ?>">إعادة ضبط الفلاتر</a>
        </div>
    </form>
</div>

<?php if (!orange_journal_vouchers_ready($pdo)): ?>
    <div class="card"><p class="page-subtitle" style="margin:0;">جداول السندات غير جاهزة بعد.</p></div>
<?php elseif ($vouchers === []): ?>
    <div class="card"><p class="page-subtitle" style="margin:0;">لا توجد سندات في هذه الفترة<?php echo $entryTypeFilter !== '' ? ' لنوع القيد المحدد' : ''; ?>.</p></div>
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
                    $etAr = orange_gl_entry_type_label_ar($et);
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
