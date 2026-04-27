<?php

declare(strict_types=1);

if (!isset($ppvKind) || !in_array($ppvKind, ['customer_receipt', 'supplier_payment'], true)) {
    throw new RuntimeException('partner_party_voucher_ui: set $ppvKind to customer_receipt or supplier_payment.');
}

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/party_subledger.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/supplier_payable_account.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$ppvIsReceipt = $ppvKind === 'customer_receipt';
$ppvTitle = $ppvIsReceipt ? 'سداد فواتير مبيعات آجلة' : 'سداد فواتير مشتريات آجلة';
$ppvCardTitle = $ppvIsReceipt ? 'سداد فواتير مبيعات آجلة (خزينة ↔ عملاء آجل)' : 'سداد فواتير مشتريات آجلة (ذمة مورد ↔ خزينة)';
$ppvApiUrl = $ppvIsReceipt
    ? '/admin/api/partners/customer-receipt.php'
    : '/admin/api/partners/supplier-payment.php';
$ppvOpenItemsKind = $ppvIsReceipt ? 'customer' : 'supplier';

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

$ppvPartyDefaultAcc = null;
if ($ppvIsReceipt) {
    $arId = orange_gl_account_id_optional($pdo, 'ar_credit');
    if ($arId !== null && $arId > 0) {
        $st = $pdo->prepare('SELECT id, code, name FROM accounts WHERE id = ? LIMIT 1');
        $st->execute([(int) $arId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $ppvPartyDefaultAcc = [
                'id' => (int) $r['id'],
                'code' => (string) ($r['code'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
            ];
        }
    }
}

$customers = [];
$suppliers = [];
$supplierPayableMap = [];

if ($ppvIsReceipt && orange_table_exists($pdo, 'customers')) {
    $customers = $pdo->query('SELECT id, name_ar, phone FROM customers ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
} elseif (!$ppvIsReceipt && orange_table_exists($pdo, 'suppliers')) {
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

$custBal = [];
foreach ($customers as $c) {
    $custBal[(int) $c['id']] = orange_party_balance_customer($pdo, (int) $c['id']);
}
$supBal = [];
foreach ($suppliers as $s) {
    $supBal[(int) $s['id']] = orange_party_balance_supplier($pdo, (int) $s['id']);
}

$prefillStmtKind = in_array((string) ($_GET['stmt_party_kind'] ?? ''), ['customer', 'supplier'], true)
    ? (string) $_GET['stmt_party_kind']
    : '';
$prefillStmtId = (int) ($_GET['stmt_party_id'] ?? 0);

$ppvPrefill = ['party_id' => 0];
if ($ppvIsReceipt && $prefillStmtKind === 'customer' && $prefillStmtId > 0) {
    $ppvPrefill['party_id'] = $prefillStmtId;
} elseif (!$ppvIsReceipt && $prefillStmtKind === 'supplier' && $prefillStmtId > 0) {
    $ppvPrefill['party_id'] = $prefillStmtId;
}

$jvGlSettingsUrl = storefront_public_path('/admin/index.php?page=gl_account_settings');
$ppvHeaderLineClass = 'jv-voucher-header-line jv-voucher-header-line--nav';
$ppvReady = $ppvCashLock !== null && (!$ppvIsReceipt || $ppvPartyDefaultAcc !== null);
?>
<div class="page-title page-title--stacked ppv-print-hide">
    <div>
        <h1><?php echo htmlspecialchars($ppvTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
    </div>
</div>

<div class="card ppv-print-area">
    <h3 class="card-title"><?php echo htmlspecialchars($ppvCardTitle, ENT_QUOTES, 'UTF-8'); ?></h3>
    <?php if ($ppvCashLock === null): ?>
    <p class="card-hint ppv-print-hide" style="margin:0 0 12px;">اربط حساب <strong>الخزينة / النقدية</strong> من <a href="<?php echo htmlspecialchars($jvGlSettingsUrl, ENT_QUOTES, 'UTF-8'); ?>">حسابات القيود التلقائية</a>.</p>
    <?php endif; ?>
    <?php if ($ppvIsReceipt && $ppvPartyDefaultAcc === null): ?>
    <p class="card-hint ppv-print-hide" style="margin:0 0 12px;">اربط حساب <strong>عملاء آجل</strong> (<code>ar_credit</code>) من <a href="<?php echo htmlspecialchars($jvGlSettingsUrl, ENT_QUOTES, 'UTF-8'); ?>">حسابات القيود التلقائية</a>.</p>
    <?php endif; ?>

    <div class="form-grid">
        <div style="grid-column:1/-1;">
            <label for="ppv_party"><?php echo $ppvIsReceipt ? 'العميل' : 'المورد'; ?></label>
            <select id="ppv_party" class="admin-inp"<?php echo !$ppvReady ? ' disabled' : ''; ?>>
                <?php if ($ppvIsReceipt): ?>
                    <?php if (!$customers): ?>
                        <option value="0">— لا يوجد عملاء —</option>
                    <?php endif; ?>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>">
                            <?php echo htmlspecialchars($c['name_ar'] . ' — ' . $c['phone'], ENT_QUOTES, 'UTF-8'); ?>
                            (رصيد <?php echo number_format($custBal[(int) $c['id']] ?? 0, 3); ?>)
                        </option>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php if (!$suppliers): ?>
                        <option value="0">— لا يوجد موردون —</option>
                    <?php endif; ?>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>">
                            <?php echo htmlspecialchars($s['name'] . ($s['phone'] ? ' — ' . $s['phone'] : ''), ENT_QUOTES, 'UTF-8'); ?>
                            (ذمة <?php echo number_format($supBal[(int) $s['id']] ?? 0, 3); ?>)
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>

        <div class="<?php echo htmlspecialchars($ppvHeaderLineClass, ENT_QUOTES, 'UTF-8'); ?>" style="grid-column:1/-1;">
            <div>
                <label for="ppv_number_preview">رقم القيد</label>
                <input type="text" id="ppv_number_preview" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;text-align:center;"
                    value="—"
                    title="يُخصَّص بعد الحفظ">
            </div>
            <div>
                <label for="ppv_date">تاريخ السند</label>
                <input type="text" id="ppv_date" class="admin-inp orange-inp-dmy"
                    value="<?php echo htmlspecialchars($partnerUiTodayDmy, ENT_QUOTES, 'UTF-8'); ?>"
                    dir="ltr" lang="en" autocomplete="off"<?php echo !$ppvReady ? ' disabled' : ''; ?>>
            </div>
            <div>
                <label for="ppv_ref">المرجع</label>
                <input type="text" id="ppv_ref" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;"
                    value=""
                    autocomplete="off">
            </div>
            <div>
                <label for="ppv_document_entered">تاريخ المستند</label>
                <input type="text" id="ppv_document_entered" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;"
                    value="<?php echo htmlspecialchars($ppvFormDocumentEnteredDisplay, ENT_QUOTES, 'UTF-8'); ?>"
                    dir="ltr" lang="en">
            </div>
            <div>
                <label for="ppv_tot_debit">مجموع المدين</label>
                <input type="text" id="ppv_tot_debit" readonly class="admin-inp-readonly jv-tot-readonly" value="0.000" dir="ltr" lang="en">
            </div>
            <div>
                <label for="ppv_tot_credit">مجموع الدائن</label>
                <input type="text" id="ppv_tot_credit" readonly class="admin-inp-readonly jv-tot-readonly" value="0.000" dir="ltr" lang="en">
            </div>
            <div class="jv-voucher-nav-cell jv-print-hide">
                <div class="jv-voucher-nav-btns ppv-voucher-action-btns" role="group" aria-label="إجراءات السند">
                    <button type="button" id="ppv_btn_save"<?php echo !$ppvReady ? ' disabled' : ''; ?>>حفظ السند</button>
                    <button type="button" class="btn-secondary jv-nav-search" id="ppv_btn_print" title="طباعة">طباعة السند</button>
                    <button type="button" class="btn-secondary jv-nav-search" id="ppv_btn_new" title="سند جديد">سند جديد</button>
                </div>
            </div>
        </div>

        <div style="grid-column:1/-1;">
            <label for="ppv_desc">البيان</label>
            <input type="text" id="ppv_desc" placeholder="<?php echo $ppvIsReceipt ? 'بيان السداد — مبيعات آجل' : 'بيان السداد — مشتريات آجل'; ?>"<?php echo !$ppvReady ? ' disabled' : ''; ?>>
        </div>

        <div style="grid-column:1/-1;" class="form-check ppv-print-hide">
            <label><input type="checkbox" id="ppv_allow_excess"<?php echo !$ppvReady ? ' disabled' : ''; ?>>
                <?php echo $ppvIsReceipt ? 'السماح بقبض يزيد عن رصيد الذمة (سلفة / دفعة مقدمة)' : 'السماح بدفع يزيد عن الذمة (دفعة مقدمة للمورد)'; ?>
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
                    <col class="jv-col-act">
                </colgroup>
                <thead>
                    <tr>
                        <th>كود الحساب</th>
                        <th>اسم الحساب</th>
                        <th>مدين</th>
                        <th>دائن</th>
                        <th class="admin-doc-col-actions" aria-label=""></th>
                    </tr>
                </thead>
                <tbody id="ppv_lines_body"></tbody>
            </table>
        </div>
    </div>

    <div style="grid-column:1/-1; margin-top:12px; padding-top:12px; border-top:1px solid #e4e4e7;" class="ppv-print-hide">
        <button type="button" class="btn-secondary" id="ppv_btn_load_alloc"<?php echo !$ppvReady ? ' disabled' : ''; ?>>تحميل المستندات ذات الرصيد</button>
        <div class="table-wrap" style="margin-top:8px;">
            <table class="admin-table">
                <thead><tr><th>مستند</th><th>متبقي</th><th>تخصيص</th></tr></thead>
                <tbody id="ppv_alloc_tbody"></tbody>
            </table>
        </div>
    </div>

</div>

<script>
var PPV_IS_RECEIPT = <?php echo $ppvIsReceipt ? 'true' : 'false'; ?>;
var PPV_API = <?php echo json_encode($ppvApiUrl, JSON_UNESCAPED_UNICODE); ?>;
var PPV_OPEN_KIND = <?php echo json_encode($ppvOpenItemsKind, JSON_UNESCAPED_UNICODE); ?>;
var PPV_CASH = <?php echo json_encode($ppvCashLock, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;
var PPV_READY = <?php echo $ppvReady ? 'true' : 'false'; ?>;
var PPV_PARTY_DEFAULT = <?php echo json_encode($ppvPartyDefaultAcc, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;
var PPV_SUPPLIER_PAYABLE = <?php echo json_encode($supplierPayableMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;
var PPV_PREFILL = <?php echo json_encode($ppvPrefill, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;

function ppvEscapeHtml(s) {
    return String(s == null ? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function ppvPairSeqRef() {
    window._ppvPairSeq = (window._ppvPairSeq || 0) + 1;
    return 'ppv' + String(window._ppvPairSeq);
}

function ppvMemoRow(mainTr) {
    if (!mainTr) return null;
    var pair = mainTr.getAttribute('data-jv-pair');
    var n = mainTr.nextElementSibling;
    if (n && n.classList.contains('jv-line-memo') && n.getAttribute('data-jv-pair') === pair) {
        return n;
    }
    return null;
}

function ppvPartyMainRow() {
    return document.querySelector('#ppv_lines_body tr.jv-line-main[data-ppv-party="1"]');
}

function ppvCashMainRow() {
    return document.querySelector('#ppv_lines_body tr.jv-line-main[data-jv-cash-locked="1"]');
}

function ppvSyncTreasury() {
    if (!PPV_CASH || !PPV_CASH.id) return;
    var cashTr = ppvCashMainRow();
    var partyTr = ppvPartyMainRow();
    if (!cashTr || !partyTr) return;
    var dEl = cashTr.querySelector('.jv-d');
    var cEl = cashTr.querySelector('.jv-c');
    var pd = partyTr.querySelector('.jv-d');
    var pc = partyTr.querySelector('.jv-c');
    if (!dEl || !cEl || !pd || !pc) return;
    if (PPV_IS_RECEIPT) {
        var cre = parseFloat(String(pc.value || '0').replace(',', '.')) || 0;
        dEl.value = cre > 0 ? cre.toFixed(3) : '';
        cEl.value = '0.000';
    } else {
        var deb = parseFloat(String(pd.value || '0').replace(',', '.')) || 0;
        cEl.value = deb > 0 ? deb.toFixed(3) : '';
        dEl.value = '0.000';
    }
}

function ppvRecalc() {
    ppvSyncTreasury();
    var sd = 0, sc = 0;
    document.querySelectorAll('#ppv_lines_body tr.jv-line-main').forEach(function (tr) {
        var d = parseFloat(String(tr.querySelector('.jv-d').value || '0').replace(',', '.')) || 0;
        var c = parseFloat(String(tr.querySelector('.jv-c').value || '0').replace(',', '.')) || 0;
        sd += d; sc += c;
    });
    var elD = document.getElementById('ppv_tot_debit');
    var elC = document.getElementById('ppv_tot_credit');
    if (elD) elD.value = sd.toFixed(3);
    if (elC) elC.value = sc.toFixed(3);
}

function ppvApplySupplierAccount() {
    if (PPV_IS_RECEIPT) return;
    var sid = parseInt(String(document.getElementById('ppv_party').value || '0'), 10) || 0;
    var partyTr = ppvPartyMainRow();
    if (!partyTr || sid <= 0) return;
    var m = PPV_SUPPLIER_PAYABLE[sid];
    if (!m) return;
    partyTr.querySelector('.jv-acc-id').value = String(m.id);
    partyTr.querySelector('.jv-acc-code').value = m.code || '';
    partyTr.querySelector('.jv-acc-name').value = m.name || '';
}

function ppvBuildLines() {
    var tb = document.getElementById('ppv_lines_body');
    if (!tb || !PPV_READY || !PPV_CASH || !PPV_CASH.id) return;
    tb.innerHTML = '';
    window._ppvPairSeq = 0;

    function addCashPair() {
        var pair = ppvPairSeqRef();
        var trMain = document.createElement('tr');
        trMain.className = 'jv-line-main jv-line-cash-locked';
        trMain.setAttribute('data-jv-pair', pair);
        trMain.setAttribute('data-jv-cash-locked', '1');
        var amtCells;
        if (PPV_IS_RECEIPT) {
            amtCells = '<td><input type="number" class="jv-d admin-inp-money" step="any" min="0" value="" placeholder="تلقائي" inputmode="decimal" lang="en" dir="ltr" readonly tabindex="-1" title="من مبلغ ذمة العميل"></td>' +
                '<td><input type="number" class="jv-c admin-inp-money" step="any" min="0" value="0.000" inputmode="decimal" lang="en" dir="ltr" readonly tabindex="-1"></td>';
        } else {
            amtCells = '<td><input type="number" class="jv-d admin-inp-money" step="any" min="0" value="0.000" inputmode="decimal" lang="en" dir="ltr" readonly tabindex="-1"></td>' +
                '<td><input type="number" class="jv-c admin-inp-money" step="any" min="0" value="" placeholder="تلقائي" inputmode="decimal" lang="en" dir="ltr" readonly tabindex="-1" title="من مبلغ ذمة المورد"></td>';
        }
        trMain.innerHTML = '<td class="jv-acc-code-cell">' +
            '<input type="hidden" class="jv-acc-id" value="' + String(PPV_CASH.id) + '">' +
            '<input type="text" class="jv-acc-code admin-inp admin-inp-readonly" value="' + ppvEscapeHtml(PPV_CASH.code || '') + '" readonly tabindex="-1">' +
            '</td>' +
            '<td><input type="text" class="jv-acc-name admin-inp admin-inp-readonly" value="' + ppvEscapeHtml(PPV_CASH.name || '') + '" readonly tabindex="-1"></td>' +
            amtCells +
            '<td><span class="muted" style="display:inline-block;padding:8px 0;">—</span></td>';
        var trMemo = document.createElement('tr');
        trMemo.className = 'jv-line-memo';
        trMemo.setAttribute('data-jv-pair', pair);
        trMemo.innerHTML = '<td colspan="5"><input type="text" class="jv-m admin-inp admin-inp-readonly" value="" readonly tabindex="-1" placeholder="بيان سطر الخزينة"></td>';
        tb.appendChild(trMain);
        tb.appendChild(trMemo);
    }

    function addPartyPair() {
        var pair = ppvPairSeqRef();
        var trMain = document.createElement('tr');
        trMain.className = 'jv-line-main';
        trMain.setAttribute('data-jv-pair', pair);
        trMain.setAttribute('data-ppv-party', '1');
        var pid = '';
        var pcode = '';
        var pname = '';
        if (PPV_IS_RECEIPT && PPV_PARTY_DEFAULT) {
            pid = String(PPV_PARTY_DEFAULT.id);
            pcode = PPV_PARTY_DEFAULT.code || '';
            pname = PPV_PARTY_DEFAULT.name || '';
        }
        var amtCells;
        if (PPV_IS_RECEIPT) {
            amtCells = '<td><input type="number" class="jv-d admin-inp-money" step="any" min="0" value="0.000" inputmode="decimal" lang="en" dir="ltr" readonly tabindex="-1"></td>' +
                '<td><input type="number" class="jv-c admin-inp-money" step="any" min="0" value="" placeholder="مبلغ القبض" inputmode="decimal" lang="en" dir="ltr"></td>';
        } else {
            amtCells = '<td><input type="number" class="jv-d admin-inp-money" step="any" min="0" value="" placeholder="مبلغ الصرف" inputmode="decimal" lang="en" dir="ltr"></td>' +
                '<td><input type="number" class="jv-c admin-inp-money" step="any" min="0" value="0.000" inputmode="decimal" lang="en" dir="ltr" readonly tabindex="-1"></td>';
        }
        trMain.innerHTML = '<td class="jv-acc-code-cell">' +
            '<input type="hidden" class="jv-acc-id" value="' + pid + '">' +
            '<input type="text" class="jv-acc-code admin-inp admin-inp-readonly" value="' + ppvEscapeHtml(pcode) + '" readonly tabindex="-1">' +
            '</td>' +
            '<td><input type="text" class="jv-acc-name admin-inp admin-inp-readonly" value="' + ppvEscapeHtml(pname) + '" readonly tabindex="-1"></td>' +
            amtCells +
            '<td><span class="muted" style="display:inline-block;padding:8px 0;">—</span></td>';
        var trMemo = document.createElement('tr');
        trMemo.className = 'jv-line-memo';
        trMemo.setAttribute('data-jv-pair', pair);
        trMemo.innerHTML = '<td colspan="5"><input type="text" class="jv-m admin-inp admin-inp-readonly" value="" readonly tabindex="-1" placeholder="بيان الذمة"></td>';
        tb.appendChild(trMain);
        tb.appendChild(trMemo);

        var amtInp = PPV_IS_RECEIPT ? trMain.querySelector('.jv-c') : trMain.querySelector('.jv-d');
        if (amtInp) {
            amtInp.addEventListener('input', ppvRecalc);
        }
    }

    if (PPV_IS_RECEIPT) {
        addCashPair();
        addPartyPair();
    } else {
        addPartyPair();
        addCashPair();
        ppvApplySupplierAccount();
    }

    var descEl = document.getElementById('ppv_desc');
    function syncMemos() {
        var t = descEl ? descEl.value.trim() : '';
        document.querySelectorAll('#ppv_lines_body .jv-m').forEach(function (inp) {
            inp.value = t;
        });
    }
    if (descEl) {
        descEl.addEventListener('input', function () { syncMemos(); ppvRecalc(); });
    }
    syncMemos();
    ppvRecalc();
}

function ppvCollectAlloc() {
    var tb = document.getElementById('ppv_alloc_tbody');
    if (!tb) return [];
    var out = [];
    tb.querySelectorAll('tr[data-ref-type]').forEach(function (tr) {
        var inp = tr.querySelector('.alloc-amt');
        var amt = parseFloat(inp && inp.value ? inp.value : '0');
        if (amt <= 0) return;
        out.push({
            ref_type: tr.getAttribute('data-ref-type'),
            ref_id: parseInt(tr.getAttribute('data-ref-id'), 10),
            amount: amt
        });
    });
    return out;
}

function ppvLoadAlloc() {
    var id = parseInt(String(document.getElementById('ppv_party').value || '0'), 10) || 0;
    var tb = document.getElementById('ppv_alloc_tbody');
    if (id <= 0) { alert(PPV_IS_RECEIPT ? 'اختر عميلاً' : 'اختر مورداً'); return; }
    tb.innerHTML = '<tr><td colspan="3">جاري التحميل…</td></tr>';
    postJSON('/admin/api/partners/open-items.php', { party_kind: PPV_OPEN_KIND, party_id: id }).then(function (r) {
        if (!r.success) {
            tb.innerHTML = '<tr><td colspan="3">' + (r.message || 'فشل') + '</td></tr>';
            return;
        }
        tb.innerHTML = '';
        var items = r.items || [];
        items.forEach(function (it) {
            var tr = document.createElement('tr');
            tr.setAttribute('data-ref-type', it.ref_type);
            tr.setAttribute('data-ref-id', String(it.ref_id));
            tr.innerHTML = '<td>' + ppvEscapeHtml(it.label) + '</td><td>' + Number(it.open).toFixed(3) + '</td><td><input type="number" class="alloc-amt admin-inp-money" step="any" min="0" placeholder="0.000" inputmode="decimal" lang="en" dir="ltr"></td>';
            tb.appendChild(tr);
        });
        if (!items.length) {
            tb.innerHTML = '<tr><td colspan="3" class="muted">لا توجد مستندات مفتوحة.</td></tr>';
        }
    }).catch(function (e) {
        tb.innerHTML = '<tr><td colspan="3">' + (e.message || String(e)) + '</td></tr>';
    });
}

function ppvGetAmount() {
    var partyTr = ppvPartyMainRow();
    if (!partyTr) return 0;
    if (PPV_IS_RECEIPT) {
        return parseFloat(String(partyTr.querySelector('.jv-c').value || '0').replace(',', '.')) || 0;
    }
    return parseFloat(String(partyTr.querySelector('.jv-d').value || '0').replace(',', '.')) || 0;
}

function ppvSave() {
    if (!PPV_CASH || !PPV_CASH.id) return;
    var partyId = parseInt(String(document.getElementById('ppv_party').value || '0'), 10) || 0;
    var amt = ppvGetAmount();
    var dIso = orangeGetDmyValueAsIso(document.getElementById('ppv_date'));
    var desc = document.getElementById('ppv_desc').value.trim();
    if (partyId <= 0 || amt <= 0 || !dIso) {
        alert('أكمل ' + (PPV_IS_RECEIPT ? 'العميل' : 'المورد') + ' والمبلغ والتاريخ (يوم/شهر/سنة)');
        return;
    }
    var allocs = ppvCollectAlloc();
    var sumA = allocs.reduce(function (a, x) { return a + x.amount; }, 0);
    if (allocs.length && sumA > amt + 0.02) {
        alert('مجموع التخصيصات (' + sumA.toFixed(3) + ') يتجاوز مبلغ السند (' + amt.toFixed(3) + ')');
        return;
    }
    var payload;
    if (PPV_IS_RECEIPT) {
        payload = {
            customer_id: partyId,
            amount: amt,
            date: dIso,
            description: desc || 'سداد فواتير مبيعات آجلة',
            allow_excess: document.getElementById('ppv_allow_excess').checked,
            allocations: allocs
        };
    } else {
        payload = {
            supplier_id: partyId,
            amount: amt,
            date: dIso,
            description: desc || 'سداد فواتير مشتريات آجلة',
            allow_excess: document.getElementById('ppv_allow_excess').checked,
            allocations: allocs
        };
    }
    postJSON(PPV_API, payload).then(function (r) {
        if (r.success) {
            alert(r.message || 'تم');
            location.reload();
            return;
        }
        if (!orangeAdminOfferSuggestOnFailure(r, 'فشل')) {
            alert(r.message || 'فشل');
        }
    }).catch(function (e) { alert(e.message || String(e)); });
}

function ppvBind() {
    var partySel = document.getElementById('ppv_party');
    if (partySel) {
        partySel.addEventListener('change', function () {
            if (!PPV_IS_RECEIPT) {
                ppvApplySupplierAccount();
            }
            document.getElementById('ppv_alloc_tbody').innerHTML = '';
            ppvRecalc();
        });
    }
    var bLoad = document.getElementById('ppv_btn_load_alloc');
    if (bLoad) bLoad.addEventListener('click', ppvLoadAlloc);
    var bSave = document.getElementById('ppv_btn_save');
    if (bSave) bSave.addEventListener('click', ppvSave);
    var bNew = document.getElementById('ppv_btn_new');
    if (bNew) bNew.addEventListener('click', function () { location.reload(); });
    var bPr = document.getElementById('ppv_btn_print');
    if (bPr) bPr.addEventListener('click', function () { window.print(); });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
        ppvBuildLines();
        ppvBind();
        if (PPV_PREFILL && PPV_PREFILL.party_id > 0) {
            var sel = document.getElementById('ppv_party');
            if (sel && sel.querySelector('option[value="' + PPV_PREFILL.party_id + '"]')) {
                sel.value = String(PPV_PREFILL.party_id);
                sel.dispatchEvent(new Event('change'));
            }
        }
    });
} else {
    ppvBuildLines();
    ppvBind();
    if (PPV_PREFILL && PPV_PREFILL.party_id > 0) {
        var sel2 = document.getElementById('ppv_party');
        if (sel2 && sel2.querySelector('option[value="' + PPV_PREFILL.party_id + '"]')) {
            sel2.value = String(PPV_PREFILL.party_id);
            sel2.dispatchEvent(new Event('change'));
        }
    }
}
</script>
