<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/payments/payment_core.php';
require_once __DIR__ . '/../../includes/upload_paths.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

$pdo = db();
orange_payments_ensure_schema($pdo);
$prCountryLabel = orange_admin_page_country_label($pdo);
?>
<div class="page-title">
    <h1>مراجعة الدفعات (تحويل بنكي)</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars($prCountryLabel, ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<div class="card">
    <div class="form-grid">
        <div>
            <label>الحالة</label>
            <select id="pr_status">
                <option value="pending_review">بانتظار التأكيد</option>
                <option value="unpaid">غير مدفوع</option>
                <option value="paid">مدفوع</option>
                <option value="failed">فشل</option>
            </select>
        </div>
        <div><label>بحث (رقم طلب / اسم / هاتف)</label><input type="text" id="pr_q" autocomplete="off"></div>
        <div style="display:flex;align-items:flex-end;"><button type="button" onclick="prSearch()">بحث</button></div>
    </div>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>الطلب</th><th>العميل</th><th>الإجمالي</th><th>مدفوع</th><th>الحالة</th><th>وقت الدفع</th><th>وقت الحركة</th><th>مرجع/إثبات</th><th>إجراءات</th></tr></thead>
            <tbody id="pr_tbody"><tr><td colspan="9" class="muted">اضغط «بحث».</td></tr></tbody>
        </table>
    </div>
</div>

<script>
function prApi(payload) { return postJSON('/admin/api/payments/review.php', payload); }
function prEsc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'); }

function prSearch() {
    var status = document.getElementById('pr_status').value;
    var q = document.getElementById('pr_q').value.trim();
    var tb = document.getElementById('pr_tbody');
    tb.innerHTML = '<tr><td colspan="9">جاري البحث…</td></tr>';
    prApi({ action: 'search', status: status, q: q }).then(function (r) {
        tb.innerHTML = '';
        var rows = (r && r.results) ? r.results : [];
        if (!rows.length) { tb.innerHTML = '<tr><td colspan="9" class="muted">لا نتائج</td></tr>'; return; }
        rows.forEach(function (o) {
            var tr = document.createElement('tr');
            var proof = o.proof_url ? '<a href="' + prEsc(o.proof_url) + '" target="_blank" rel="noopener">عرض الإثبات</a>' : '';
            var ref = o.last_reference ? prEsc(o.last_reference) : '';
            // Server-formatted country IANA display — do not parse UTC with browser TZ.
            var paidDisp = prEsc(o.paid_at_display || '—');
            var txnDisp = prEsc(o.last_txn_created_at_display || '—');
            tr.innerHTML =
                '<td dir="ltr">' + prEsc(o.order_number || ('#' + o.id)) + '</td>'
                + '<td>' + prEsc(o.customer_name || '') + '</td>'
                + '<td dir="ltr">' + prEsc(o.total) + '</td>'
                + '<td dir="ltr">' + prEsc(o.amount_paid) + '</td>'
                + '<td>' + prEsc(o.payment_status_label || o.payment_status) + '</td>'
                + '<td dir="ltr" style="white-space:nowrap;">' + paidDisp + '</td>'
                + '<td dir="ltr" style="white-space:nowrap;">' + txnDisp + '</td>'
                + '<td>' + ref + (ref && proof ? ' · ' : '') + proof + '</td>'
                + '<td class="stock-actions">'
                + '<button type="button" class="btn btn-secondary" onclick="prConfirm(' + parseInt(o.id,10) + ',' + (parseFloat(o.total)||0) + ')">تأكيد الدفع</button> '
                + '<button type="button" class="btn btn-outline" onclick="prReject(' + parseInt(o.id,10) + ')">رفض</button> '
                + '<button type="button" class="btn btn-outline" onclick="prUploadProof(' + parseInt(o.id,10) + ')">رفع إثبات</button>'
                + '</td>';
            document.getElementById('pr_tbody').appendChild(tr);
        });
    }).catch(function (e) { tb.innerHTML = '<tr><td colspan="9">' + prEsc(e.message || String(e)) + '</td></tr>'; });
}

function prConfirm(orderId, total) {
    var ref = prompt('مرجع التحويل (اختياري) ومبلغ مؤكد:', '');
    if (ref === null) return;
    var amt = parseFloat(prompt('المبلغ المدفوع:', String(total || 0)) || '0') || 0;
    prApi({ action: 'set_status', order_id: orderId, status: 'paid', reference: ref, amount: amt }).then(function (r) {
        alert((r && r.message) || 'تم'); prSearch();
    }).catch(function (e) { alert(e.message || String(e)); });
}
function prReject(orderId) {
    if (!confirm('وضع الطلب كـ «فشل الدفع»؟')) return;
    prApi({ action: 'set_status', order_id: orderId, status: 'failed' }).then(function (r) {
        alert((r && r.message) || 'تم'); prSearch();
    }).catch(function (e) { alert(e.message || String(e)); });
}
function prUploadProof(orderId) {
    var inp = document.createElement('input');
    inp.type = 'file';
    inp.accept = 'image/*,application/pdf';
    inp.onchange = function () {
        if (!inp.files || !inp.files[0]) return;
        var ref = prompt('مرجع التحويل (اختياري):', '') || '';
        var amt = parseFloat(prompt('المبلغ (اختياري):', '0') || '0') || 0;
        var fd = new FormData();
        fd.append('action', 'upload_proof');
        fd.append('order_id', String(orderId));
        fd.append('reference', ref);
        fd.append('amount', String(amt));
        fd.append('proof', inp.files[0]);
        fetch('/admin/api/payments/review.php', { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) { alert((res && res.message) || 'تم'); prSearch(); })
            .catch(function (e) { alert(e.message || String(e)); });
    };
    inp.click();
}
</script>
