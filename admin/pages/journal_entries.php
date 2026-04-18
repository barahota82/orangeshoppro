<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$years = orange_fiscal_years_list($pdo);
$fyId = isset($_GET['fy']) ? (int)$_GET['fy'] : 0;
if ($fyId <= 0 && $years !== []) {
    $fyId = (int)$years[0]['id'];
}

$hasGrp = orange_table_has_column($pdo, 'accounts', 'is_group');
$accCols = $hasGrp ? 'id, name, code, is_group' : 'id, name, code';
$accounts = $pdo->query('SELECT ' . $accCols . ' FROM accounts ORDER BY COALESCE(code, \'\'), name')->fetchAll(PDO::FETCH_ASSOC);
$accMap = [];
foreach ($accounts as $a) {
    $accMap[(int)$a['id']] = trim((string)($a['code'] ?? '')) !== '' ? $a['code'] . ' — ' . $a['name'] : $a['name'];
}

$vouchers = [];
if (orange_journal_vouchers_ready($pdo) && $fyId > 0) {
    $st = $pdo->prepare('SELECT * FROM journal_vouchers WHERE fiscal_year_id = ? ORDER BY voucher_date DESC, id DESC LIMIT 120');
    $st->execute([$fyId]);
    $vouchers = $st->fetchAll(PDO::FETCH_ASSOC);
}

$linesByVid = [];
if ($vouchers !== []) {
    $ids = array_map(static fn ($v) => (int)$v['id'], $vouchers);
    $in = implode(',', $ids);
    if ($in !== '') {
        $jl = $pdo->query(
            'SELECT * FROM journal_lines WHERE voucher_id IN (' . $in . ') ORDER BY voucher_id ASC, line_no ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($jl as $ln) {
            $vid = (int)$ln['voucher_id'];
            if (!isset($linesByVid[$vid])) {
                $linesByVid[$vid] = [];
            }
            $linesByVid[$vid][] = $ln;
        }
    }
}

$acctOpts = '';
foreach ($accounts as $a) {
    if ($hasGrp && !empty($a['is_group'])) {
        continue;
    }
    $acctOpts .= '<option value="' . (int)$a['id'] . '">' . htmlspecialchars($accMap[(int)$a['id']], ENT_QUOTES, 'UTF-8') . '</option>';
}
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>القيود المحاسبية (سندات)</h1>
        <p class="page-subtitle">
            كل سند يضم عدة أسطر؛ مجموع المدين يجب أن يساوي مجموع الدائن.
            سندات <strong>الافتتاح</strong> و<strong>الإقفال</strong> تُدار من شاشات السنة المالية والإقفال.
        </p>
    </div>
</div>

<div class="card">
    <h3 class="card-title">تصفية</h3>
    <form method="get" action="" class="form-grid" style="align-items:end;">
        <input type="hidden" name="page" value="journal_entries">
        <div>
            <label for="fy_sel">السنة المالية</label>
            <select id="fy_sel" name="fy" onchange="this.form.submit()">
                <?php foreach ($years as $y): ?>
                    <option value="<?php echo (int)$y['id']; ?>" <?php echo ((int)$y['id'] === $fyId) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($y['label_ar'] . ' (' . $y['start_date'] . ' — ' . $y['end_date'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<div class="card">
    <h3 class="card-title">سند يدوي جديد (متعدد الأسطر)</h3>
    <div class="form-grid">
        <div>
            <label for="jv_date">تاريخ السند</label>
            <input type="date" id="jv_date" value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div>
            <label for="jv_ref">مرجع (اختياري)</label>
            <input type="text" id="jv_ref" placeholder="JV-...">
        </div>
        <div style="grid-column:1/-1;">
            <label for="jv_desc">البيان</label>
            <input type="text" id="jv_desc" placeholder="وصف السند">
        </div>
    </div>
    <p class="card-hint" id="jv_balance_hint">مجموع المدين: 0 — مجموع الدائن: 0</p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>الحساب</th>
                    <th>مدين</th>
                    <th>دائن</th>
                    <th>البيان</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="jv_lines_body"></tbody>
        </table>
    </div>
    <div class="actions" style="margin-top:10px;flex-wrap:wrap;gap:8px;">
        <button type="button" class="btn-secondary" onclick="jvAddRow()">+ سطر</button>
        <button type="button" onclick="jvSubmit()">حفظ السند</button>
    </div>
</div>

<div class="card">
    <h3 class="card-title">السندات المسجّلة</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>التاريخ</th>
                    <th>النوع</th>
                    <th>مرجع</th>
                    <th>البيان</th>
                    <th>التفاصيل</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vouchers as $v): ?>
                    <?php
                    $vid = (int)$v['id'];
                    $lines = $linesByVid[$vid] ?? [];
                    $det = [];
                    foreach ($lines as $ln) {
                        $aid = (int)$ln['account_id'];
                        $det[] = htmlspecialchars($accMap[$aid] ?? ('#' . $aid), ENT_QUOTES, 'UTF-8')
                            . ' م:' . $ln['debit'] . ' د:' . $ln['credit'];
                    }
                    $et = (string)($v['entry_type'] ?? '');
                    $lockDel = in_array($et, ['year_end_close', 'opening_balance'], true);
                    $etLabels = [
                        'manual' => 'سند يدوي',
                        'opening_balance' => 'قيد رصيد افتتاحي',
                        'year_end_close' => 'إقفال سنة مالية',
                        'customer_receipt' => 'قبض عميل',
                        'supplier_payment' => 'دفع مورد',
                        'purchase' => 'شراء',
                    ];
                    $etAr = $etLabels[$et] ?? $et;
                    ?>
                    <tr>
                        <td><?php echo $vid; ?></td>
                        <td><?php echo htmlspecialchars(substr((string)$v['voucher_date'], 0, 19), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($etAr, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($v['reference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($v['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="font-size:12px;max-width:22rem;"><?php echo implode(' | ', $det); ?></td>
                        <td>
                            <?php if (!$lockDel): ?>
                                <button type="button" class="btn-secondary" onclick="jvDelete(<?php echo $vid; ?>)">حذف</button>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($vouchers === []): ?>
        <p class="page-subtitle">لا سندات في هذه السنة أو لم تُهيّأ الجداول بعد.</p>
    <?php endif; ?>
</div>

<script>
var JV_ACCT_OPTS = <?php echo json_encode($acctOpts, JSON_UNESCAPED_UNICODE); ?>;

function jvAddRow() {
    var tb = document.getElementById('jv_lines_body');
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><select class="jv-acc">' + JV_ACCT_OPTS + '</select></td>' +
        '<td><input type="number" class="jv-d admin-inp-money" step="any" min="0" value="" placeholder="0.000" inputmode="decimal" lang="en" dir="ltr"></td>' +
        '<td><input type="number" class="jv-c admin-inp-money" step="any" min="0" value="" placeholder="0.000" inputmode="decimal" lang="en" dir="ltr"></td>' +
        '<td><input type="text" class="jv-m" value="" placeholder="البيان"></td>' +
        '<td><button type="button" class="btn-secondary" onclick="this.closest(\'tr\').remove();jvRecalc();">حذف</button></td>';
    tb.appendChild(tr);
    jvRecalc();
}

function jvRecalc() {
    var sd = 0, sc = 0;
    document.querySelectorAll('#jv_lines_body tr').forEach(function (tr) {
        var d = parseFloat(String(tr.querySelector('.jv-d').value || '0').replace(',', '.'));
        var c = parseFloat(String(tr.querySelector('.jv-c').value || '0').replace(',', '.'));
        sd += d; sc += c;
    });
    document.getElementById('jv_balance_hint').textContent = 'مجموع المدين: ' + sd.toFixed(3) + ' — مجموع الدائن: ' + sc.toFixed(3);
}

function jvSubmit() {
    var d = document.getElementById('jv_date').value;
    var ref = document.getElementById('jv_ref').value.trim();
    var desc = document.getElementById('jv_desc').value.trim();
    if (!d || !desc) {
        alert('التاريخ والبيان مطلوبان');
        return;
    }
    var lines = [];
    var memoAbort = false;
    document.querySelectorAll('#jv_lines_body tr').forEach(function (tr) {
        var acc = parseInt(tr.querySelector('.jv-acc').value, 10) || 0;
        var deb = parseFloat(String(tr.querySelector('.jv-d').value || '0').replace(',', '.'));
        var cre = parseFloat(String(tr.querySelector('.jv-c').value || '0').replace(',', '.'));
        var memo = tr.querySelector('.jv-m').value.trim();
        if (acc <= 0) return;
        if (deb > 0 && cre > 0) {
            cre = 0;
        }
        if (deb <= 0 && cre <= 0) return;
        if (memo === '') {
            alert('البيان مطلوب لكل سطر يحتوي مبلغاً');
            memoAbort = true;
            return;
        }
        lines.push({ account_id: acc, debit: deb, credit: cre, memo: memo });
    });
    if (memoAbort) {
        return;
    }
    if (lines.length < 2) {
        alert('أضف سطرين على الأقل بمبالغ صحيحة');
        return;
    }
    var sd = lines.reduce(function (a, x) { return a + x.debit; }, 0);
    var sc = lines.reduce(function (a, x) { return a + x.credit; }, 0);
    if (Math.abs(sd - sc) > 0.001) {
        alert('السند غير متوازن');
        return;
    }
    postJSON('/admin/api/journal/manage.php', {
        action: 'create',
        date: d,
        reference: ref,
        description: desc,
        entry_type: 'manual',
        lines: lines
    }).then(function (r) {
        alert(r.message || (r.success ? 'تم' : 'فشل'));
        if (r.success) location.reload();
    }).catch(function (e) { alert(e.message || String(e)); });
}

function jvDelete(id) {
    if (!confirm('حذف السند #' + id + '؟')) return;
    postJSON('/admin/api/journal/manage.php', { action: 'delete', id: id })
        .then(function (r) {
            alert(r.message || (r.success ? 'تم' : 'فشل'));
            if (r.success) location.reload();
        })
        .catch(function (e) { alert(e.message || String(e)); });
}

jvAddRow();
jvAddRow();
</script>
