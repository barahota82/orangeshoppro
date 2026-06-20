<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/loyalty.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$loyCountryId = orange_admin_context_country_id($pdo);
$loyReady = orange_table_exists($pdo, 'loyalty_settings') && orange_table_exists($pdo, 'loyalty_ledger');
$loySettings = $loyReady ? orange_loyalty_settings($pdo, $loyCountryId) : null;
if ($loySettings === null || (int) ($loySettings['country_id'] ?? -1) !== (int) $loyCountryId) {
    $loySettings = [
        'is_active' => 0,
        'earn_rate' => 0,
        'point_value' => 0,
        'min_redeem_points' => 0,
        'max_redeem_pct' => 0,
        'expiry_months' => 0,
    ];
}
?>
<div class="page-title">
    <h1>نظام ولاء العميل (النقاط)</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php if (!$loyReady): ?>
<div class="admin-card"><p class="card-hint">جداول الولاء غير متوفرة بعد — سيتم إنشاؤها تلقائياً عند تحديث المخطط (catalog_schema).</p></div>
<?php else: ?>

<div class="admin-card" style="margin-bottom:1rem;">
    <h2 style="margin:0 0 0.75rem;">إعدادات الكسب والاستبدال</h2>
    <p class="card-hint" style="margin:0 0 1rem;">
        نموذج محاسبي: التزام مؤجّل. عند الكسب: <strong>مدين «مصروفات برنامج الولاء» / دائن «التزامات نقاط الولاء»</strong>؛
        عند الاستبدال يُخصم من الالتزام كبند فاتورة؛ عند الانتهاء يُعكَس. اربط الحسابات من شاشة
        <strong>إعدادات قيود GL</strong> (المفاتيح: <code>loyalty_program_expense</code> و<code>loyalty_points_liability</code>)
        وبند الاستبدال <code>loyalty_points_redemption</code> من <strong>بنود الفاتورة الإضافية</strong>. لا يوجد تثبيت لأي حساب في الكود.
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.85rem;">
        <label style="display:flex;flex-direction:column;gap:0.3rem;">
            <span>مُفعَّل</span>
            <select id="loy_is_active" class="admin-inp">
                <option value="0">غير مُفعَّل</option>
                <option value="1">مُفعَّل</option>
            </select>
        </label>
        <label style="display:flex;flex-direction:column;gap:0.3rem;">
            <span>نقاط مكتسبة لكل وحدة عملة (على صافي مبيعات البضاعة)</span>
            <input type="number" id="loy_earn_rate" class="admin-inp" step="any" min="0" inputmode="decimal" lang="en" dir="ltr">
        </label>
        <label style="display:flex;flex-direction:column;gap:0.3rem;">
            <span>قيمة النقطة (بالعملة) عند الاستبدال</span>
            <input type="number" id="loy_point_value" class="admin-inp" step="any" min="0" inputmode="decimal" lang="en" dir="ltr">
        </label>
        <label style="display:flex;flex-direction:column;gap:0.3rem;">
            <span>أدنى نقاط للاستبدال</span>
            <input type="number" id="loy_min_redeem" class="admin-inp" step="1" min="0" inputmode="numeric" lang="en" dir="ltr">
        </label>
        <label style="display:flex;flex-direction:column;gap:0.3rem;">
            <span>أقصى نسبة من المبلغ المستحق تُدفع بالنقاط (%)</span>
            <input type="number" id="loy_max_pct" class="admin-inp" step="any" min="0" max="100" inputmode="decimal" lang="en" dir="ltr">
        </label>
        <label style="display:flex;flex-direction:column;gap:0.3rem;">
            <span>مدة صلاحية النقاط (شهور — 0 = بلا انتهاء)</span>
            <input type="number" id="loy_expiry_months" class="admin-inp" step="1" min="0" inputmode="numeric" lang="en" dir="ltr">
        </label>
    </div>
    <div style="margin-top:1rem;display:flex;gap:0.6rem;flex-wrap:wrap;">
        <button type="button" class="btn btn-primary" id="loySaveBtn">حفظ الإعدادات</button>
        <button type="button" class="btn" id="loyExpireBtn">تشغيل انتهاء النقاط المستحقة الآن</button>
    </div>
    <p id="loyMsg" class="card-hint" style="margin-top:0.6rem;"></p>
</div>

<div class="admin-card">
    <h2 style="margin:0 0 0.75rem;">رصيد وحركة نقاط عميل</h2>
    <div style="display:flex;gap:0.6rem;align-items:flex-end;flex-wrap:wrap;">
        <label style="display:flex;flex-direction:column;gap:0.3rem;">
            <span>رقم العميل (ID)</span>
            <input type="number" id="loy_customer_id" class="admin-inp" step="1" min="0" inputmode="numeric" lang="en" dir="ltr">
        </label>
        <button type="button" class="btn" id="loyLookupBtn">عرض الرصيد والحركة</button>
        <span id="loyBalance" style="font-weight:700;"></span>
    </div>
    <div style="overflow:auto;margin-top:0.85rem;">
        <table class="admin-table" id="loyLedgerTable" style="display:none;">
            <thead>
                <tr>
                    <th>#</th><th>النوع</th><th>النقاط</th><th>المتبقي</th><th>قيمة النقطة</th>
                    <th>الانتهاء</th><th>المرجع</th><th>ملاحظة</th><th>التاريخ</th>
                </tr>
            </thead>
            <tbody id="loyLedgerBody"></tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var S = <?php echo json_encode($loySettings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
    function $(id){ return document.getElementById(id); }
    function setMsg(t, ok){ var m = $('loyMsg'); m.textContent = t || ''; m.style.color = ok ? '#15803d' : '#b91c1c'; }
    $('loy_is_active').value = String(parseInt(S.is_active, 10) === 1 ? 1 : 0);
    $('loy_earn_rate').value = S.earn_rate != null ? String(S.earn_rate) : '';
    $('loy_point_value').value = S.point_value != null ? String(S.point_value) : '';
    $('loy_min_redeem').value = S.min_redeem_points != null ? String(S.min_redeem_points) : '';
    $('loy_max_pct').value = S.max_redeem_pct != null ? String(S.max_redeem_pct) : '';
    $('loy_expiry_months').value = S.expiry_months != null ? String(S.expiry_months) : '';

    async function postJSON(payload){
        var res = await fetch('/admin/api/loyalty/manage.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        return res.json();
    }

    $('loySaveBtn').addEventListener('click', async function () {
        setMsg('جارٍ الحفظ…', true);
        try {
            var r = await postJSON({
                action: 'save',
                is_active: parseInt($('loy_is_active').value, 10) || 0,
                earn_rate: parseFloat($('loy_earn_rate').value || '0'),
                point_value: parseFloat($('loy_point_value').value || '0'),
                min_redeem_points: parseInt($('loy_min_redeem').value || '0', 10),
                max_redeem_pct: parseFloat($('loy_max_pct').value || '0'),
                expiry_months: parseInt($('loy_expiry_months').value || '0', 10)
            });
            setMsg(r.message || (r.success ? 'تم' : 'تعذّر الحفظ'), !!r.success);
        } catch (e) { setMsg('خطأ في الاتصال', false); }
    });

    $('loyExpireBtn').addEventListener('click', async function () {
        if (!confirm('تشغيل انتهاء النقاط المستحقة الآن لهذه الدولة؟')) return;
        setMsg('جارٍ التنفيذ…', true);
        try {
            var r = await postJSON({ action: 'expire_run' });
            setMsg(r.message || (r.success ? 'تم' : 'تعذّر التنفيذ'), !!r.success);
        } catch (e) { setMsg('خطأ في الاتصال', false); }
    });

    $('loyLookupBtn').addEventListener('click', async function () {
        var cid = parseInt($('loy_customer_id').value || '0', 10);
        if (cid <= 0) { $('loyBalance').textContent = 'حدّد رقم العميل'; return; }
        $('loyBalance').textContent = '…';
        try {
            var r = await postJSON({ action: 'customer_ledger', customer_id: cid });
            if (!r.success) { $('loyBalance').textContent = r.message || 'تعذّر'; return; }
            $('loyBalance').textContent = 'الرصيد القابل للاستخدام: ' + r.data.balance + ' نقطة';
            var body = $('loyLedgerBody');
            body.innerHTML = '';
            (r.data.rows || []).forEach(function (row) {
                var tr = document.createElement('tr');
                function td(v){ var c = document.createElement('td'); c.textContent = v == null ? '' : String(v); return c; }
                tr.appendChild(td(row.id));
                tr.appendChild(td(row.kind));
                tr.appendChild(td(row.points));
                tr.appendChild(td(row.points_remaining));
                tr.appendChild(td(row.point_value));
                tr.appendChild(td(row.expires_at));
                tr.appendChild(td((row.ref_type || '') + ' ' + (row.ref_id || '')));
                tr.appendChild(td(row.memo));
                tr.appendChild(td(row.created_at));
                body.appendChild(tr);
            });
            $('loyLedgerTable').style.display = (r.data.rows || []).length ? '' : 'none';
        } catch (e) { $('loyBalance').textContent = 'خطأ في الاتصال'; }
    });
})();
</script>
<?php endif; ?>
