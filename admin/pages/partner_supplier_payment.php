<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/party_subledger.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/supplier_payable_account.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$ppvTitle = 'سداد فواتير مشتريات آجلة';
$ppvApiUrl = '/admin/api/partners/supplier-payment.php';

$partnerUiTodayDmy = orange_format_date_dmY(date('Y-m-d'));
$ppvFormDocumentEnteredDisplay = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));

$cashAccId = orange_gl_account_id_optional($pdo, 'cash');
$ppvCashLock = null;
if ($cashAccId !== null && $cashAccId > 0) {
    $stCash = $pdo->prepare('SELECT id, code, name FROM accounts WHERE id = ? LIMIT 1');
    $stCash->execute([(int) $cashAccId]);
    $cashRow = $stCash->fetch(PDO::FETCH_ASSOC);
    if ($cashRow) {
        $ppvCashLock = [
            'id' => (int) $cashRow['id'],
            'code' => (string) ($cashRow['code'] ?? ''),
            'name' => (string) ($cashRow['name'] ?? ''),
        ];
    }
}

$suppliers = [];
$supplierPayableMap = [];
$ppvSupplierPickRows = [];
if (orange_table_exists($pdo, 'suppliers')) {
    $suppliers = $pdo->query('SELECT id, name, phone FROM suppliers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($suppliers as $s) {
        $sid = (int) $s['id'];
        $aid = orange_supplier_payable_account_id($pdo, $sid);
        $st = $pdo->prepare('SELECT id, code, name FROM accounts WHERE id = ? LIMIT 1');
        $st->execute([$aid]);
        $arow = $st->fetch(PDO::FETCH_ASSOC);
        $supplierPayableMap[$sid] = [
            'id' => $arow ? (int) $arow['id'] : $aid,
            'code' => $arow ? (string) ($arow['code'] ?? '') : '',
            'name' => $arow ? (string) ($arow['name'] ?? '') : ('#' . $aid),
        ];
    }
}

$supBal = [];
foreach ($suppliers as $s) {
    $supBal[(int) $s['id']] = orange_party_balance_supplier($pdo, (int) $s['id']);
}

$ppvApParentId = orange_gl_supplier_parent_account_id($pdo);
$ppvApDescendantSet = [];
if ($ppvApParentId !== null && $ppvApParentId > 0 && orange_table_has_column($pdo, 'accounts', 'parent_id')) {
    $ppvApDescIds = [$ppvApParentId];
    for ($depth = 0; $depth < 10; ++$depth) {
        $ph = implode(',', array_fill(0, count($ppvApDescIds), '?'));
        $chSt = $pdo->prepare("SELECT id FROM accounts WHERE parent_id IN ($ph) AND id NOT IN ($ph)");
        $chSt->execute(array_merge($ppvApDescIds, $ppvApDescIds));
        $newIds = $chSt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($newIds === []) {
            break;
        }
        foreach ($newIds as $nid) {
            $ppvApDescIds[] = (int) $nid;
        }
    }
    $ppvApDescendantSet = array_flip($ppvApDescIds);
}

foreach ($suppliers as $s) {
    $sid = (int) ($s['id'] ?? 0);
    if ($sid <= 0) {
        continue;
    }
    $map = $supplierPayableMap[$sid] ?? ['id' => 0, 'code' => '', 'name' => ''];
    $mapAccountId = (int) ($map['id'] ?? 0);
    if ($ppvApDescendantSet !== [] && ($mapAccountId <= 0 || !isset($ppvApDescendantSet[$mapAccountId]))) {
        continue;
    }
    $supplierName = trim((string) ($s['name'] ?? ''));
    $supplierPhone = trim((string) ($s['phone'] ?? ''));
    $accountCode = trim((string) ($map['code'] ?? ''));
    $accountName = trim((string) ($map['name'] ?? ''));
    $balance = (float) ($supBal[$sid] ?? 0.0);
    $ppvSupplierPickRows[] = [
        'id' => $sid,
        'name' => $supplierName,
        'phone' => $supplierPhone,
        'balance' => round($balance, 3),
        'account_id' => $mapAccountId,
        'account_code' => $accountCode,
        'account_name' => $accountName,
    ];
}

$prefillStmtId = (int) ($_GET['stmt_party_id'] ?? 0);
$prefillStmtKind = trim((string) ($_GET['stmt_party_kind'] ?? ''));
$ppvPrefillSupplierId = ($prefillStmtKind === 'supplier' && $prefillStmtId > 0) ? $prefillStmtId : 0;

$nextVoucherNo = 1;
if (orange_journal_vouchers_ready($pdo)) {
    $nextVoucherNo = (int) $pdo->query('SELECT COALESCE(MAX(id),0) + 1 FROM journal_vouchers')->fetchColumn();
}

$jvGlSettingsUrl = storefront_public_path('/admin/index.php?page=gl_account_settings');
$ppvReady = $ppvCashLock !== null;
?>
<div class="page-title page-title--stacked jv-print-hide">
    <div><h1><?php echo htmlspecialchars($ppvTitle, ENT_QUOTES, 'UTF-8'); ?></h1></div>
</div>

<?php if ($ppvCashLock === null): ?>
<div class="card jv-print-hide" style="border:1px solid #fcd34d;background:#fffbeb;margin-bottom:12px;">
    <p class="card-hint" style="margin:0;line-height:1.55;">اربط حساب <strong>الخزينة / النقدية</strong> من <a href="<?php echo htmlspecialchars($jvGlSettingsUrl, ENT_QUOTES, 'UTF-8'); ?>">حسابات القيود التلقائية</a>.</p>
</div>
<?php endif; ?>

<div class="card jv-print-area">
    <h3 class="card-title"><?php echo htmlspecialchars($ppvTitle, ENT_QUOTES, 'UTF-8'); ?></h3>
    <div class="form-grid">
        <div style="grid-column:1/-1;">
            <label for="spay_supplier_code">المورد</label>
            <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,2fr);gap:10px 14px;">
                <input type="text" id="spay_supplier_code" autocomplete="off" dir="ltr" lang="en" readonly placeholder="نقرتان للاختيار" title="نقرتان للاختيار" style="cursor:pointer;"<?php echo !$ppvReady ? ' disabled' : ''; ?>>
                <input type="text" id="spay_supplier_name" class="admin-inp-readonly" readonly disabled tabindex="-1" placeholder="يُعبأ تلقائياً">
            </div>
            <input type="hidden" id="spay_supplier_id" value="0">
        </div>

        <div class="jv-voucher-header-line jv-voucher-header-line--nav" style="grid-column:1/-1;">
            <div>
                <label for="spay_number_preview">رقم القيد</label>
                <input type="text" id="spay_number_preview" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;text-align:center;" value="<?php echo (int) $nextVoucherNo; ?>">
            </div>
            <div>
                <label for="spay_date">تاريخ السند</label>
                <input type="text" id="spay_date" class="admin-inp orange-inp-dmy" value="<?php echo htmlspecialchars($partnerUiTodayDmy, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en" autocomplete="off"<?php echo !$ppvReady ? ' disabled' : ''; ?>>
            </div>
            <div>
                <label for="spay_ref">المرجع</label>
                <input type="text" id="spay_ref" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;" value="—" title="يُخصَّص تلقائياً عند الحفظ">
            </div>
            <div>
                <label for="spay_document_entered">تاريخ المستند</label>
                <input type="text" id="spay_document_entered" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;" value="<?php echo htmlspecialchars($ppvFormDocumentEnteredDisplay, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en">
            </div>
            <div>
                <label for="spay_tot_debit">مجموع المدين</label>
                <input type="text" id="spay_tot_debit" readonly class="admin-inp-readonly jv-tot-readonly" value="0.000" dir="ltr" lang="en">
            </div>
            <div>
                <label for="spay_tot_credit">مجموع الدائن</label>
                <input type="text" id="spay_tot_credit" readonly class="admin-inp-readonly jv-tot-readonly" value="0.000" dir="ltr" lang="en">
            </div>
            <div class="jv-voucher-nav-cell jv-print-hide">
                <div class="jv-voucher-nav-btns" role="group" aria-label="إجراءات السند">
                    <button type="button" id="spay_btn_save"<?php echo !$ppvReady ? ' disabled' : ''; ?>>حفظ السند</button>
                    <button type="button" class="btn-secondary" id="spay_btn_new">سند جديد</button>
                    <button type="button" class="btn-secondary" id="spay_btn_print">طباعة</button>
                </div>
            </div>
        </div>

        <div style="grid-column:1/-1;">
            <label for="spay_desc">البيان</label>
            <input type="text" id="spay_desc" placeholder="بيان السداد"<?php echo !$ppvReady ? ' disabled' : ''; ?>>
        </div>

        <div style="grid-column:1/-1;" class="form-check jv-print-hide">
            <label><input type="checkbox" id="spay_advance_mode"<?php echo !$ppvReady ? ' disabled' : ''; ?>>
                السماح بدفع يزيد عن الذمة (دفعة مقدمة للمورد)
            </label>
        </div>
    </div>

    <div class="admin-doc-frame">
        <div class="table-wrap">
            <table class="admin-table admin-doc-lines-table jv-lines-table">
                <colgroup>
                    <col class="jv-col-code">
                    <col class="jv-col-name">
                    <col class="jv-col-amt">
                    <col class="jv-col-amt">
                </colgroup>
                <thead>
                    <tr>
                        <th>كود الحساب</th>
                        <th>اسم الحساب</th>
                        <th>مدين</th>
                        <th>دائن</th>
                    </tr>
                </thead>
                <tbody id="spay_lines_body"></tbody>
            </table>
        </div>
    </div>
</div>

<div class="gl-pick-modal jv-print-hide" id="spay_supplier_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="spay_supplier_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="spay_supplier_pick_title">
        <h3 id="spay_supplier_pick_title" class="gl-pick-modal__title">اختيار المورد</h3>
        <p class="gl-pick-modal__hint muted" style="margin:0 0 8px;font-size:0.9rem;">نقرتان للاختيار</p>
        <input type="search" id="spay_supplier_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بكود الحساب أو اسم المورد…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="spay_supplier_pick_list"></ul>
        <button type="button" class="btn-secondary" id="spay_supplier_pick_close">إغلاق</button>
    </div>
</div>

<script>
(function () {
    var SPAY_API = <?php echo json_encode($ppvApiUrl, JSON_UNESCAPED_UNICODE); ?>;
    var SPAY_CASH = <?php echo json_encode($ppvCashLock, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var SPAY_READY = <?php echo $ppvReady ? 'true' : 'false'; ?>;
    var SPAY_SUPPLIER_PAYABLE = <?php echo json_encode($supplierPayableMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var SPAY_SUPPLIER_PICK_ROWS = <?php echo json_encode($ppvSupplierPickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var SPAY_PREFILL_SUPPLIER = <?php echo (int) $ppvPrefillSupplierId; ?>;

    var currentSupplierId = 0;
    var currentInvoiceLines = [];

    function esc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    function supplierById(id) {
        id = parseInt(String(id || '0'), 10) || 0;
        for (var i = 0; i < SPAY_SUPPLIER_PICK_ROWS.length; i++) {
            if ((parseInt(String(SPAY_SUPPLIER_PICK_ROWS[i].id || '0'), 10) || 0) === id) return SPAY_SUPPLIER_PICK_ROWS[i];
        }
        return null;
    }

    function selectSupplier(id) {
        var row = supplierById(id);
        var codeEl = document.getElementById('spay_supplier_code');
        var nameEl = document.getElementById('spay_supplier_name');
        var idEl = document.getElementById('spay_supplier_id');
        if (!row) {
            currentSupplierId = 0;
            if (codeEl) codeEl.value = '';
            if (nameEl) nameEl.value = '';
            if (idEl) idEl.value = '0';
            renderLines();
            return;
        }
        currentSupplierId = parseInt(String(row.id), 10) || 0;
        if (codeEl) codeEl.value = row.account_code || '';
        if (nameEl) nameEl.value = row.name || '';
        if (idEl) idEl.value = String(currentSupplierId);
        loadInvoices();
    }

    function isAdvanceMode() {
        var cb = document.getElementById('spay_advance_mode');
        return cb && cb.checked;
    }

    function loadInvoices() {
        if (currentSupplierId <= 0 || isAdvanceMode()) {
            currentInvoiceLines = [];
            renderLines();
            return;
        }
        postJSON('/admin/api/partners/open-items.php', { party_kind: 'supplier', party_id: currentSupplierId }).then(function (r) {
            if (!r.success) {
                currentInvoiceLines = [];
                renderLines();
                return;
            }
            currentInvoiceLines = (r.items || []).map(function (it) {
                return { ref_type: it.ref_type, ref_id: it.ref_id, label: it.label, open: parseFloat(String(it.open || '0')) || 0, amount: 0 };
            });
            renderLines();
        }).catch(function () {
            currentInvoiceLines = [];
            renderLines();
        });
    }

    function renderLines() {
        var tb = document.getElementById('spay_lines_body');
        if (!tb || !SPAY_READY || !SPAY_CASH) return;
        tb.innerHTML = '';

        var map = SPAY_SUPPLIER_PAYABLE[String(currentSupplierId)] || { id: 0, code: '', name: '' };
        var accCode = map.code || '';
        var accName = map.name || '';

        if (isAdvanceMode()) {
            var tr = document.createElement('tr');
            tr.className = 'jv-line-main';
            tr.setAttribute('data-spay-advance', '1');
            tr.innerHTML = '<td><input type="text" class="jv-acc-code admin-inp admin-inp-readonly" value="' + esc(accCode) + '" readonly tabindex="-1"></td>' +
                '<td><input type="text" class="jv-acc-name admin-inp admin-inp-readonly" value="' + esc(accName) + '" readonly tabindex="-1"></td>' +
                '<td><input type="number" class="spay-amt admin-inp-money" step="any" min="0" placeholder="مبلغ الدفعة" inputmode="decimal" lang="en" dir="ltr"></td>' +
                '<td><input type="number" class="admin-inp-money" value="0.000" readonly tabindex="-1" dir="ltr" lang="en"></td>';
            tb.appendChild(tr);
            tr.querySelector('.spay-amt').addEventListener('input', recalc);
        } else {
            currentInvoiceLines.forEach(function (inv, idx) {
                var tr = document.createElement('tr');
                tr.className = 'jv-line-main';
                tr.setAttribute('data-ref-type', inv.ref_type);
                tr.setAttribute('data-ref-id', String(inv.ref_id));
                tr.innerHTML = '<td><input type="text" class="jv-acc-code admin-inp admin-inp-readonly" value="' + esc(accCode) + '" readonly tabindex="-1"></td>' +
                    '<td><input type="text" class="jv-acc-name admin-inp admin-inp-readonly" value="' + esc(accName + ' — ' + inv.label + ' (متبقي ' + inv.open.toFixed(3) + ')') + '" readonly tabindex="-1"></td>' +
                    '<td><input type="number" class="spay-amt admin-inp-money" step="any" min="0" max="' + inv.open.toFixed(3) + '" placeholder="0.000" inputmode="decimal" lang="en" dir="ltr" data-idx="' + idx + '"></td>' +
                    '<td><input type="number" class="admin-inp-money" value="0.000" readonly tabindex="-1" dir="ltr" lang="en"></td>';
                tb.appendChild(tr);
                tr.querySelector('.spay-amt').addEventListener('input', function () {
                    var v = parseFloat(this.value) || 0;
                    currentInvoiceLines[idx].amount = v;
                    recalc();
                });
            });
            if (currentInvoiceLines.length === 0 && currentSupplierId > 0) {
                var emptyTr = document.createElement('tr');
                emptyTr.innerHTML = '<td colspan="4" class="muted" style="text-align:center;padding:12px;">لا توجد فواتير آجلة مفتوحة لهذا المورد</td>';
                tb.appendChild(emptyTr);
            }
        }

        var cashTr = document.createElement('tr');
        cashTr.className = 'jv-line-main jv-line-cash-locked';
        cashTr.innerHTML = '<td><input type="text" class="jv-acc-code admin-inp admin-inp-readonly" value="' + esc(SPAY_CASH.code || '') + '" readonly tabindex="-1"></td>' +
            '<td><input type="text" class="jv-acc-name admin-inp admin-inp-readonly" value="' + esc(SPAY_CASH.name || '') + '" readonly tabindex="-1"></td>' +
            '<td><input type="number" class="admin-inp-money" value="0.000" readonly tabindex="-1" dir="ltr" lang="en"></td>' +
            '<td><input type="number" class="spay-cash-credit admin-inp-money" value="0.000" readonly tabindex="-1" dir="ltr" lang="en"></td>';
        tb.appendChild(cashTr);
        recalc();
    }

    function recalc() {
        var total = 0;
        document.querySelectorAll('#spay_lines_body .spay-amt').forEach(function (inp) {
            total += parseFloat(inp.value) || 0;
        });
        var cashEl = document.querySelector('#spay_lines_body .spay-cash-credit');
        if (cashEl) cashEl.value = total.toFixed(3);
        var dEl = document.getElementById('spay_tot_debit');
        var cEl = document.getElementById('spay_tot_credit');
        if (dEl) dEl.value = total.toFixed(3);
        if (cEl) cEl.value = total.toFixed(3);
    }

    function pickerOpen() {
        var modal = document.getElementById('spay_supplier_pick_modal');
        var qEl = document.getElementById('spay_supplier_pick_q');
        if (!modal || !qEl) return;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('gl-pick-open');
        qEl.value = '';
        pickerRender('');
        qEl.focus();
    }
    function pickerClose() {
        var modal = document.getElementById('spay_supplier_pick_modal');
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('gl-pick-open');
    }
    function pickerRender(q) {
        var listEl = document.getElementById('spay_supplier_pick_list');
        if (!listEl) return;
        var query = String(q || '').trim().toLowerCase();
        var rows = SPAY_SUPPLIER_PICK_ROWS.filter(function (r) {
            if (!query) return true;
            var hay = (r.account_code + ' ' + r.account_name + ' ' + r.name + ' ' + r.phone).toLowerCase();
            return hay.indexOf(query) !== -1;
        });
        listEl.innerHTML = '';
        if (!rows.length) { listEl.innerHTML = '<li class="gl-pick-empty">لا نتائج</li>'; return; }
        rows.forEach(function (r) {
            var li = document.createElement('li');
            li.className = 'gl-pick-item';
            li.setAttribute('role', 'button');
            li.tabIndex = 0;
            li.textContent = (r.account_code ? r.account_code + ' — ' : '') + r.name + (r.phone ? ' (' + r.phone + ')' : '') + ' [رصيد ' + r.balance.toFixed(3) + ']';
            li.addEventListener('dblclick', function () { selectSupplier(r.id); pickerClose(); });
            li.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { selectSupplier(r.id); pickerClose(); } });
            listEl.appendChild(li);
        });
    }

    function save() {
        if (!SPAY_CASH || !SPAY_CASH.id) return;
        var sid = currentSupplierId;
        if (sid <= 0) { alert('اختر المورد أولاً'); return; }
        var dIso = orangeGetDmyValueAsIso(document.getElementById('spay_date'));
        if (!dIso) { alert('أدخل تاريخ السند (يوم/شهر/سنة)'); return; }
        var desc = (document.getElementById('spay_desc').value || '').trim() || 'سداد فواتير مشتريات آجلة';
        var advance = isAdvanceMode();
        var totalAmt = 0;
        var allocations = [];

        if (advance) {
            var advInp = document.querySelector('#spay_lines_body [data-spay-advance="1"] .spay-amt');
            totalAmt = parseFloat(advInp ? advInp.value : '0') || 0;
            if (totalAmt <= 0) { alert('أدخل مبلغ الدفعة المقدمة'); return; }
        } else {
            currentInvoiceLines.forEach(function (inv) {
                if (inv.amount > 0) {
                    totalAmt += inv.amount;
                    allocations.push({ ref_type: inv.ref_type, ref_id: inv.ref_id, amount: inv.amount });
                }
            });
            if (totalAmt <= 0) { alert('حدد مبلغ السداد لفاتورة واحدة على الأقل'); return; }
        }

        var payload = {
            supplier_id: sid,
            amount: totalAmt,
            date: dIso,
            description: desc,
            allow_excess: advance,
            allocations: allocations
        };
        postJSON(SPAY_API, payload).then(function (r) {
            if (r.success) {
                alert(r.message || 'تم');
                location.reload();
            } else {
                alert(r.message || 'فشل');
            }
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function init() {
        var codeEl = document.getElementById('spay_supplier_code');
        if (codeEl) {
            codeEl.addEventListener('dblclick', function (e) { e.preventDefault(); pickerOpen(); });
            codeEl.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pickerOpen(); } });
        }
        document.getElementById('spay_supplier_pick_backdrop').addEventListener('click', pickerClose);
        document.getElementById('spay_supplier_pick_close').addEventListener('click', pickerClose);
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') pickerClose(); });

        var pickQ = document.getElementById('spay_supplier_pick_q');
        var pickTimer = null;
        if (pickQ) {
            pickQ.addEventListener('input', function () {
                if (pickTimer) clearTimeout(pickTimer);
                pickTimer = setTimeout(function () { pickerRender(pickQ.value || ''); }, 180);
            });
        }

        document.getElementById('spay_advance_mode').addEventListener('change', function () {
            renderLines();
        });

        document.getElementById('spay_btn_save').addEventListener('click', save);
        document.getElementById('spay_btn_new').addEventListener('click', function () { location.reload(); });
        document.getElementById('spay_btn_print').addEventListener('click', function () { window.print(); });

        if (SPAY_PREFILL_SUPPLIER > 0) {
            selectSupplier(SPAY_PREFILL_SUPPLIER);
        } else {
            renderLines();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
