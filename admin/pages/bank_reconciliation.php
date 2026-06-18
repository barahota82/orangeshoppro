<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/bank_reconciliation.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../includes/accounting_report_money.php';
require_once __DIR__ . '/../../includes/upload_paths.php';
require_once __DIR__ . '/../../includes/date_format.php';

$pdo = orange_admin_page_pdo();
$reportMoney = orange_accounting_report_money($pdo, isset($orangeAdminMoney) ? $orangeAdminMoney : null);
$ctxCountryId = orange_admin_settings_effective_country_id($pdo);
$brCountryLabel = orange_admin_page_country_label($pdo);

$ready = orange_bank_reconciliation_ready($pdo);
$useVouchers = orange_journal_vouchers_ready($pdo);
$years = orange_fiscal_years_list($pdo, $ctxCountryId);
$bankAccounts = $ready ? orange_bank_reconciliation_bank_account_options($pdo) : [];
$allLeaves = orange_accounts_fetch($pdo, 'SELECT a.id, a.code, a.name FROM accounts a WHERE 1=1 ORDER BY COALESCE(a.code,\'\'), a.name', [], 'a');
$adjustAccounts = [];
foreach ($allLeaves as $al) {
    $aid = (int) ($al['id'] ?? 0);
    if ($aid > 0 && orange_accounts_account_is_posting_leaf($pdo, $aid)) {
        $code = trim((string) ($al['code'] ?? ''));
        $name = trim((string) ($al['name'] ?? ''));
        $adjustAccounts[] = [
            'id' => $aid,
            'label' => ($code !== '' ? $code . ' — ' : '') . $name,
        ];
    }
}

$list = $ready ? orange_bank_reconciliation_list($pdo, $ctxCountryId, 40) : [];

$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editRec = ($editId > 0 && $ready) ? orange_bank_reconciliation_get($pdo, $editId, $ctxCountryId) : null;

$apiBase = storefront_public_path('/admin/api/bank-reconciliation');
$fmtAmt = static fn (float $n): string => orange_accounting_report_format_amount($n, $reportMoney);

$initial = [
    'id' => 0,
    'account_id' => 0,
    'fiscal_year_id' => $years !== [] ? (int) ($years[0]['id'] ?? 0) : 0,
    'period_from' => '',
    'period_to' => date('Y-m-d'),
    'statement_balance' => 0,
    'notes' => '',
    'lines' => [],
    'gl_balance_live' => 0,
    'variance_live' => 0,
    'status' => 'draft',
];

if ($editRec !== null) {
    $h = $editRec['header'];
    $initial = [
        'id' => (int) ($h['id'] ?? 0),
        'account_id' => (int) ($h['account_id'] ?? 0),
        'fiscal_year_id' => (int) ($h['fiscal_year_id'] ?? 0),
        'period_from' => substr((string) ($h['period_from'] ?? ''), 0, 10),
        'period_to' => substr((string) ($h['period_to'] ?? ''), 0, 10),
        'statement_balance' => (float) ($h['statement_balance'] ?? 0),
        'notes' => (string) ($h['notes'] ?? ''),
        'lines' => $editRec['lines'],
        'gl_balance_live' => (float) ($editRec['gl_balance_live'] ?? 0),
        'variance_live' => (float) ($editRec['variance_live'] ?? 0),
        'status' => (string) ($h['status'] ?? 'draft'),
        'journal_voucher_id' => (int) ($h['journal_voucher_id'] ?? 0),
    ];
}

$initialJson = json_encode($initial, JSON_UNESCAPED_UNICODE);
if ($initialJson === false) {
    $initialJson = '{}';
}

?>
<div class="admin-fy-shell" dir="rtl" id="bank_recon_app">
    <div class="page-title">
        <h1>تسوية البنك</h1>
        <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars($brCountryLabel, ENT_QUOTES, 'UTF-8'); ?></p>
    </div>

    <?php if (! $ready || ! $useVouchers): ?>
        <div class="card" style="border:1px solid #fcd34d;background:#fffbeb;">
            <p style="margin:0;">جدول السندات أو تسوية البنك غير جاهز — حدّث المخطط (ACC-10 مرحلة 0).</p>
        </div>
    <?php else: ?>

    <p class="actions gl-acc-stmt-no-print" style="margin:0 0 16px;">
        <button type="button" class="btn-secondary" id="br_btn_new">جلسة جديدة</button>
        <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=edit_lock'), ENT_QUOTES, 'UTF-8'); ?>">إقفال التعديلات</a>
    </p>

    <div class="card" style="margin-bottom:16px;">
        <h3 class="card-title">جلسات التسوية</h3>
        <div class="table-wrap">
            <table class="admin-fy-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الحساب</th>
                        <th>حتى</th>
                        <th>GL</th>
                        <th>كشف</th>
                        <th>الحالة</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($list === []): ?>
                        <tr><td colspan="7" class="muted">لا جلسات بعد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($list as $row): ?>
                            <?php
                            $rid = (int) ($row['id'] ?? 0);
                            $st = (string) ($row['status'] ?? '');
                            ?>
                            <tr>
                                <td><?php echo $rid; ?></td>
                                <td><?php echo htmlspecialchars(trim((string) ($row['account_code'] ?? '')) . ' ' . (string) ($row['account_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td dir="ltr"><?php echo htmlspecialchars(substr((string) ($row['period_to'] ?? ''), 0, 10), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td dir="ltr"><?php echo htmlspecialchars($fmtAmt((float) ($row['gl_balance'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td dir="ltr"><?php echo htmlspecialchars($fmtAmt((float) ($row['statement_balance'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo $st === 'closed' ? 'مغلقة' : 'مسودة'; ?></td>
                                <td><a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=bank_reconciliation&id=' . $rid), ENT_QUOTES, 'UTF-8'); ?>">فتح</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" id="br_editor_card">
        <h3 class="card-title">تحرير الجلسة</h3>
        <p id="br_status_badge" class="card-hint" style="margin-top:0;"></p>

        <div class="admin-fy-form-grid" style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:16px;">
            <div>
                <label for="br_account">حساب البنك / النقد</label>
                <select id="br_account">
                    <option value="">— اختر —</option>
                    <?php foreach ($bankAccounts as $ba): ?>
                        <option value="<?php echo (int) $ba['id']; ?>"><?php echo htmlspecialchars($ba['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="br_fy">السنة المالية</label>
                <select id="br_fy">
                    <?php foreach ($years as $yr): ?>
                        <option value="<?php echo (int) ($yr['id'] ?? 0); ?>"><?php echo htmlspecialchars(trim((string) ($yr['label_ar'] ?? '')) !== '' ? (string) $yr['label_ar'] : ('#' . (int) $yr['id']), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="br_from">من تاريخ</label>
                <input type="text" id="br_from" class="orange-inp-dmy" dir="ltr" lang="en">
            </div>
            <div>
                <label for="br_to">حتى تاريخ (رصيد GL)</label>
                <input type="text" id="br_to" class="orange-inp-dmy" dir="ltr" lang="en" required>
            </div>
            <div>
                <label for="br_stmt">رصيد كشف البنك</label>
                <input type="number" id="br_stmt" step="<?php echo htmlspecialchars(orange_admin_money_input_step((int) $reportMoney['decimals']), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>

        <div class="card-hint" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;margin-bottom:16px;padding:12px;background:#f8fafc;border-radius:8px;">
            <div><strong>رصيد GL:</strong> <span id="br_gl_disp" dir="ltr">0</span></div>
            <div><strong>رصيد الكشف:</strong> <span id="br_stmt_disp" dir="ltr">0</span></div>
            <div><strong>الفرق:</strong> <span id="br_var_disp" dir="ltr">0</span></div>
        </div>

        <div style="margin-bottom:12px;">
            <label for="br_notes">ملاحظات</label>
            <input type="text" id="br_notes" style="width:100%;max-width:640px;">
        </div>

        <h4>أسطر الكشف (يدوي + استيراد)</h4>
        <p class="card-hint">استيراد CSV: <code>date,description,amount</code> — Excel: «حفظ باسم» CSV UTF-8.</p>
        <p class="gl-acc-stmt-no-print">
            <input type="file" id="br_csv_file" accept=".csv,text/csv">
            <button type="button" class="btn-secondary" id="br_import_btn">استيراد CSV</button>
            <button type="button" class="btn-secondary" id="br_line_add">سطر يدوي</button>
        </p>

        <div class="table-wrap">
            <table class="admin-fy-table" id="br_lines_table">
                <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>الوصف</th>
                        <th>المبلغ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="br_lines_body"></tbody>
            </table>
        </div>

        <p class="actions gl-acc-stmt-no-print" style="margin-top:16px;">
            <button type="button" id="br_save_btn">حفظ المسودة</button>
            <button type="button" class="btn-danger" id="br_delete_btn">حذف المسودة</button>
        </p>

        <div id="br_close_panel" class="gl-acc-stmt-no-print" style="margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb;">
            <h4>إقفال التسوية</h4>
            <p class="card-hint">عند وجود فرق: يُنشأ قيد تسوية (أو يُدرَج في طابور الترحيل).</p>
            <div style="display:grid;gap:12px;grid-template-columns:1fr auto;max-width:720px;align-items:end;">
                <div>
                    <label for="br_adj_account">حساب طرف التسوية (مصروف/إيراد/…)</label>
                    <select id="br_adj_account">
                        <option value="">— اختر عند وجود فرق —</option>
                        <?php foreach ($adjustAccounts as $aa): ?>
                            <option value="<?php echo (int) $aa['id']; ?>"><?php echo htmlspecialchars($aa['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" id="br_close_btn">إقفال</button>
            </div>
        </div>

        <p id="br_msg" class="card-hint" style="margin-top:12px;color:#166534;display:none;"></p>
        <p id="br_err" class="card-hint" style="margin-top:12px;color:#b91c1c;display:none;"></p>
    </div>

    <?php endif; ?>
</div>
<script>
(function () {
    var API = <?php echo json_encode([
        'save' => $apiBase . '/save.php',
        'get' => $apiBase . '/get.php',
        'close' => $apiBase . '/close.php',
        'import' => $apiBase . '/import.php',
    ], JSON_UNESCAPED_UNICODE); ?>;
    var state = <?php echo $initialJson; ?>;
    var decimals = <?php echo (int) $reportMoney['decimals']; ?>;
    var lineMoneyStep = <?php echo json_encode(orange_admin_money_input_step((int) $reportMoney['decimals']), JSON_UNESCAPED_UNICODE); ?>;

    function fmt(n) {
        var x = Number(n) || 0;
        return x.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    }

    function el(id) { return document.getElementById(id); }

    function showErr(msg) {
        el('br_err').textContent = msg || '';
        el('br_err').style.display = msg ? 'block' : 'none';
        if (msg) el('br_msg').style.display = 'none';
    }
    function showOk(msg) {
        el('br_msg').textContent = msg || '';
        el('br_msg').style.display = msg ? 'block' : 'none';
        if (msg) el('br_err').style.display = 'none';
    }

    function syncFormFromState() {
        el('br_account').value = String(state.account_id || '');
        el('br_fy').value = String(state.fiscal_year_id || '');
        el('br_from').value = state.period_from ? orangeIsoDateToDmy(state.period_from) : '';
        el('br_to').value = state.period_to ? orangeIsoDateToDmy(state.period_to) : '';
        el('br_stmt').value = state.statement_balance != null ? String(state.statement_balance) : '0';
        el('br_notes').value = state.notes || '';
        el('br_gl_disp').textContent = fmt(state.gl_balance_live || 0);
        el('br_stmt_disp').textContent = fmt(state.statement_balance || 0);
        var v = (state.variance_live != null) ? state.variance_live : ((state.statement_balance || 0) - (state.gl_balance_live || 0));
        el('br_var_disp').textContent = fmt(v);
        var closed = state.status === 'closed';
        el('br_status_badge').textContent = closed ? ('مغلقة' + (state.journal_voucher_id ? (' — سند #' + state.journal_voucher_id) : '')) : 'مسودة';
        ['br_account','br_fy','br_from','br_to','br_stmt','br_notes','br_save_btn','br_delete_btn','br_close_panel','br_import_btn','br_line_add','br_csv_file'].forEach(function (id) {
            var node = el(id);
            if (!node) return;
            if (node.tagName === 'BUTTON' || node.type === 'file' || node.type === 'number' || node.type === 'date' || node.tagName === 'SELECT' || node.tagName === 'INPUT') {
                node.disabled = closed;
            }
        });
        renderLines();
    }

    function renderLines() {
        var tb = el('br_lines_body');
        tb.innerHTML = '';
        (state.lines || []).forEach(function (ln, idx) {
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td><input type="text" class="orange-inp-dmy" dir="ltr" lang="en" data-idx="' + idx + '" data-f="date" value="' + (ln.line_date ? orangeIsoDateToDmy(ln.line_date) : '') + '"' + (state.status === 'closed' ? ' disabled' : '') + '></td>' +
                '<td><input type="text" data-idx="' + idx + '" data-f="desc" value="' + (ln.description || '').replace(/"/g, '&quot;') + '" style="width:100%"' + (state.status === 'closed' ? ' disabled' : '') + '></td>' +
                '<td><input type="number" step="' + lineMoneyStep + '" data-idx="' + idx + '" data-f="amt" value="' + (ln.amount != null ? ln.amount : 0) + '" dir="ltr"' + (state.status === 'closed' ? ' disabled' : '') + '></td>' +
                '<td>' + (state.status === 'closed' ? '' : '<button type="button" class="btn-secondary" data-rm="' + idx + '">×</button>') + '</td>';
            tb.appendChild(tr);
        });
        tb.querySelectorAll('input').forEach(function (inp) {
            inp.addEventListener('change', onLineEdit);
        });
        if (typeof orangeInitDmyInputs === 'function') orangeInitDmyInputs(tb);
        tb.querySelectorAll('button[data-rm]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var i = parseInt(btn.getAttribute('data-rm'), 10);
                state.lines.splice(i, 1);
                renderLines();
            });
        });
    }

    function onLineEdit(ev) {
        var inp = ev.target;
        var i = parseInt(inp.getAttribute('data-idx'), 10);
        var f = inp.getAttribute('data-f');
        if (!state.lines[i]) return;
        if (f === 'date') state.lines[i].line_date = orangeGetDmyValueAsIso(inp) || null;
        if (f === 'desc') state.lines[i].description = inp.value;
        if (f === 'amt') state.lines[i].amount = parseFloat(inp.value) || 0;
    }

    function payloadFromForm() {
        return {
            id: state.id || 0,
            account_id: parseInt(el('br_account').value, 10) || 0,
            fiscal_year_id: parseInt(el('br_fy').value, 10) || 0,
            period_from: orangeGetDmyValueAsIso(el('br_from')) || '',
            period_to: orangeGetDmyValueAsIso(el('br_to')) || '',
            statement_balance: parseFloat(el('br_stmt').value) || 0,
            notes: el('br_notes').value || '',
            lines: (state.lines || []).map(function (ln) {
                return {
                    line_date: ln.line_date || '',
                    description: ln.description || '',
                    amount: parseFloat(ln.amount) || 0,
                    source: ln.source || 'manual'
                };
            })
        };
    }

    function applyRec(rec) {
        if (!rec || !rec.header) return;
        var h = rec.header;
        state.id = parseInt(h.id, 10) || 0;
        state.account_id = parseInt(h.account_id, 10) || 0;
        state.fiscal_year_id = parseInt(h.fiscal_year_id, 10) || 0;
        state.period_from = (h.period_from || '').substring(0, 10);
        state.period_to = (h.period_to || '').substring(0, 10);
        state.statement_balance = parseFloat(h.statement_balance) || 0;
        state.notes = h.notes || '';
        state.lines = rec.lines || [];
        state.gl_balance_live = parseFloat(rec.gl_balance_live) || 0;
        state.variance_live = parseFloat(rec.variance_live) || 0;
        state.status = h.status || 'draft';
        state.journal_voucher_id = parseInt(h.journal_voucher_id, 10) || 0;
        syncFormFromState();
    }

    async function postJson(url, body) {
        var res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body || {})
        });
        return res.json();
    }

    el('br_save_btn') && el('br_save_btn').addEventListener('click', async function () {
        showErr('');
        var data = await postJson(API.save, payloadFromForm());
        if (!data.success) { showErr(data.message || 'فشل الحفظ'); return; }
        showOk(data.message || 'تم الحفظ');
        applyRec(data.reconciliation);
        if (state.id && !window.location.search.includes('id=')) {
            history.replaceState(null, '', '?page=bank_reconciliation&id=' + state.id);
        }
    });

    el('br_delete_btn') && el('br_delete_btn').addEventListener('click', async function () {
        if (!state.id || !confirm('حذف المسودة؟')) return;
        var data = await postJson(API.save, { action: 'delete', id: state.id });
        if (!data.success) { showErr(data.message); return; }
        window.location.href = '?page=bank_reconciliation';
    });

    el('br_close_btn') && el('br_close_btn').addEventListener('click', async function () {
        if (!state.id) { showErr('احفظ المسودة أولاً'); return; }
        if (!confirm('إقفال التسوية؟')) return;
        var data = await postJson(API.close, {
            id: state.id,
            adjustment_account_id: parseInt(el('br_adj_account').value, 10) || 0
        });
        if (!data.success) { showErr(data.message); return; }
        showOk(data.message);
        applyRec(data.reconciliation);
    });

    el('br_line_add') && el('br_line_add').addEventListener('click', function () {
        state.lines = state.lines || [];
        state.lines.push({ line_date: orangeGetDmyValueAsIso(el('br_to')) || null, description: '', amount: 0, source: 'manual' });
        renderLines();
    });

    el('br_import_btn') && el('br_import_btn').addEventListener('click', async function () {
        var f = el('br_csv_file').files && el('br_csv_file').files[0];
        if (!f) { showErr('اختر ملف CSV'); return; }
        var fd = new FormData();
        fd.append('file', f);
        showErr('');
        var res = await fetch(API.import, { method: 'POST', credentials: 'same-origin', body: fd });
        var data = await res.json();
        if (!data.success) { showErr(data.message); return; }
        state.lines = (state.lines || []).concat(data.lines || []);
        showOk(data.message);
        renderLines();
    });

    el('br_btn_new') && el('br_btn_new').addEventListener('click', function () {
        window.location.href = '?page=bank_reconciliation';
    });

    el('br_stmt') && el('br_stmt').addEventListener('input', function () {
        state.statement_balance = parseFloat(el('br_stmt').value) || 0;
        el('br_stmt_disp').textContent = fmt(state.statement_balance);
        el('br_var_disp').textContent = fmt(state.statement_balance - (state.gl_balance_live || 0));
    });

    syncFormFromState();
})();
</script>
