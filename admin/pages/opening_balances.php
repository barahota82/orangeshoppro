<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$years = array_values(array_filter(orange_fiscal_years_list($pdo), static fn ($y) => (int)($y['is_closed'] ?? 0) === 0));
$fyId = isset($_GET['fy']) ? (int)$_GET['fy'] : 0;
if ($fyId <= 0 && $years !== []) {
    $fyId = (int)$years[0]['id'];
}

$accounts = $pdo->query('SELECT id, name, code, account_class FROM accounts ORDER BY COALESCE(code, \'\'), name')->fetchAll(PDO::FETCH_ASSOC);
$accMap = [];
foreach ($accounts as $a) {
    $accMap[(int)$a['id']] = trim((string)($a['code'] ?? '')) !== '' ? $a['code'] . ' — ' . $a['name'] : $a['name'];
}

$acctOpts = '';
foreach ($accounts as $a) {
    $acctOpts .= '<option value="' . (int)$a['id'] . '">' . htmlspecialchars($accMap[(int)$a['id']], ENT_QUOTES, 'UTF-8') . '</option>';
}
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>أرصدة أول المدة</h1>
        <p class="page-subtitle">
            لكل <strong>سنة مالية مفتوحة</strong>، سجّل أرصدة الافتتاح كسند متوازن في أول يوم من السنة.
            يُستبدل السند السابق لنفس السنة عند كل حفظ. لا يُحسب في قائمة الدخل (يُستبعد مع قيود الإقفال).
        </p>
    </div>
</div>

<div class="card">
    <form method="get" action="" class="form-grid" style="align-items:end;">
        <input type="hidden" name="page" value="opening_balances">
        <div>
            <label for="ob_fy">السنة المالية (مفتوحة)</label>
            <select id="ob_fy" name="fy" onchange="this.form.submit()">
                <?php foreach ($years as $y): ?>
                    <option value="<?php echo (int)$y['id']; ?>" <?php echo ((int)$y['id'] === $fyId) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($y['label_ar'] . ' (' . $y['start_date'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <?php if ($years === []): ?>
        <p class="card-hint">لا توجد سنة مفتوحة — افتح سنة من <a href="/admin/index.php?page=fiscal_years">السنوات المالية</a>.</p>
    <?php endif; ?>
</div>

<?php if ($fyId > 0 && $years !== []): ?>
<div class="card">
    <h3 class="card-title">أسطر الأرصدة</h3>
    <p class="card-hint" id="ob_hint">مجموع المدين: 0 — مجموع الدائن: 0</p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>الحساب</th>
                    <th>مدين</th>
                    <th>دائن</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="ob_body"></tbody>
        </table>
    </div>
    <div class="actions" style="margin-top:10px;">
        <button type="button" class="btn-secondary" onclick="obAdd()">+ سطر</button>
        <button type="button" onclick="obSave()">حفظ أرصدة الافتتاح</button>
    </div>
</div>
<?php endif; ?>

<script>
var OB_FY = <?php echo (int)$fyId; ?>;
var OB_ACCT = <?php echo json_encode($acctOpts, JSON_UNESCAPED_UNICODE); ?>;

function obAdd() {
    var tb = document.getElementById('ob_body');
    if (!tb) return;
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><select class="ob-acc">' + OB_ACCT + '</select></td>' +
        '<td><input type="number" class="ob-d" step="0.0001" min="0" value=""></td>' +
        '<td><input type="number" class="ob-c" step="0.0001" min="0" value=""></td>' +
        '<td><button type="button" class="btn-secondary" onclick="this.closest(\'tr\').remove();obRecalc();">حذف</button></td>';
    tb.appendChild(tr);
    tr.querySelector('.ob-d').addEventListener('input', obRecalc);
    tr.querySelector('.ob-c').addEventListener('input', obRecalc);
    obRecalc();
}

function obRecalc() {
    var el = document.getElementById('ob_hint');
    if (!el) return;
    var sd = 0, sc = 0;
    document.querySelectorAll('#ob_body tr').forEach(function (tr) {
        sd += parseFloat(tr.querySelector('.ob-d').value || '0');
        sc += parseFloat(tr.querySelector('.ob-c').value || '0');
    });
    el.textContent = 'مجموع المدين: ' + sd.toFixed(4) + ' — مجموع الدائن: ' + sc.toFixed(4);
}

function obSave() {
    if (OB_FY <= 0) {
        alert('اختر سنة');
        return;
    }
    var lines = [];
    document.querySelectorAll('#ob_body tr').forEach(function (tr) {
        var acc = parseInt(tr.querySelector('.ob-acc').value, 10) || 0;
        var deb = parseFloat(tr.querySelector('.ob-d').value || '0');
        var cre = parseFloat(tr.querySelector('.ob-c').value || '0');
        if (acc <= 0) return;
        if (deb <= 0 && cre <= 0) return;
        lines.push({ account_id: acc, debit: deb, credit: cre, memo: '' });
    });
    if (lines.length < 2) {
        alert('سطران على الأقل');
        return;
    }
    var sd = lines.reduce(function (a, x) { return a + x.debit; }, 0);
    var sc = lines.reduce(function (a, x) { return a + x.credit; }, 0);
    if (Math.abs(sd - sc) > 0.001) {
        alert('السند غير متوازن');
        return;
    }
    postJSON('/admin/api/opening_balances/save.php', { fiscal_year_id: OB_FY, lines: lines })
        .then(function (r) {
            alert(r.message || (r.success ? 'تم' : 'فشل'));
            if (r.success) location.reload();
        })
        .catch(function (e) { alert(e.message || String(e)); });
}

if (document.getElementById('ob_body')) {
    obAdd(); obAdd();
}
</script>
