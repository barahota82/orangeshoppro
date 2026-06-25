<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/delivery_areas.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$hasTable = orange_table_exists($pdo, 'delivery_fee_promotions');
$dpMoney = isset($orangeAdminMoney) && is_array($orangeAdminMoney)
    ? $orangeAdminMoney
    : orange_admin_currency_context($pdo);
$dpMoneyDecimals = (int) ($dpMoney['decimals'] ?? 3);
$discountTypes = [];
foreach (orange_delivery_promotion_discount_type_values() as $key => $_) {
    $label = 'خصم مبلغ ثابت';
    if ($key === 'percent') {
        $label = 'خصم نسبة مئوية';
    } elseif ($key === 'free') {
        $label = 'توصيل مجاني كامل';
    }
    $discountTypes[] = ['key' => $key, 'label_ar' => $label];
}
?>

<div class="page-title">
    <h1>عروض ورسوم التوصيل</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>
<?php
require_once __DIR__ . '/../../includes/offer_gl_link_card.php';
echo orange_offer_gl_link_card_html(
    $pdo,
    ['delivery_fee_charge', 'delivery_fee_discount'],
    ['delivery_expense', 'delivery_payable_default'],
    'الربط المحاسبي للتوصيل (للقراءة فقط)'
);
?>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جداول عروض التوصيل غير جاهزة.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>ملخص قيم التوصيل المحفوظة (للقراءة فقط)</h3>
    <p class="card-hint" style="margin-top:0;">
        قيمة التوصيل وتكلفته تُضبطان من شاشة «محافظات ومناطق التوصيل». هذا العرض للمساعدة أثناء إنشاء العروض فقط.
        المناطق مجمّعة لكل محافظة حسب (قيمة التوصيل + التكلفة)، واضغط السهم لعرض أسماء المناطق.
    </p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:42px;"></th>
                    <th>المحافظة</th>
                    <th>عدد المناطق</th>
                    <th>قيمة التوصيل</th>
                    <th>التكلفة على الشركة</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody id="dp_fee_summary_tbody">
                <tr><td colspan="6" class="muted">جارٍ تحميل البيانات...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3>إضافة / تعديل</h3>
    <input type="hidden" id="dp_id" value="0">
    <div class="form-grid">
        <div>
            <label for="dp_name_ar">اسم العرض (عربي)</label>
            <input type="text" id="dp_name_ar" class="admin-inp" dir="auto" placeholder="مثال: خصم توصيل نهاية الأسبوع">
        </div>
        <div>
            <label for="dp_name_en">English</label>
            <input type="text" id="dp_name_en" class="admin-inp" dir="ltr" lang="en" placeholder="Weekend delivery promo">
        </div>
        <div>
            <label for="dp_discount_type">نوع الخصم</label>
            <select id="dp_discount_type" class="admin-inp"></select>
        </div>
        <div>
            <label for="dp_discount_value" id="dp_discount_value_label">قيمة الخصم</label>
            <input type="text" id="dp_discount_value" class="admin-inp-money" inputmode="decimal" lang="en" dir="ltr" placeholder="2">
        </div>
        <div>
            <label for="dp_sort">الترتيب</label>
            <input type="number" id="dp_sort" value="0" style="max-width:120px;">
        </div>
        <?php $ocpFieldPrefix = 'dp'; require __DIR__ . '/../partials/cart_promo_schedule_fields.inc.php'; ?>
        <div style="grid-column:1/-1;">
            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;max-width:52rem;line-height:1.45;">
                <input type="checkbox" id="dp_reg" style="margin-top:4px;flex-shrink:0;">
                <span><strong>للمسجّلين فقط</strong> — عند التفعيل يُطبّق العرض على حسابات المتجر المسجّلة فقط، وإلا فيشمل الزائر والعميل المسجّل.</span>
            </label>
        </div>
        <div>
            <label for="dp_target_governorates">المحافظات المستهدفة (اختياري)</label>
            <select id="dp_target_governorates" class="admin-inp" multiple size="6"></select>
            <span class="muted" style="display:block;margin-top:4px;">اتركه فارغاً لتطبيق العرض على كل المحافظات.</span>
        </div>
        <div>
            <label for="dp_target_areas">المناطق المستهدفة (اختياري)</label>
            <select id="dp_target_areas" class="admin-inp" multiple size="6"></select>
            <span class="muted" style="display:block;margin-top:4px;">يمكن تحديد مناطق محددة داخل المحافظة.</span>
        </div>
        <div style="display:flex;align-items:flex-end;gap:8px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" id="dp_active" checked> نشط
            </label>
        </div>
    </div>
    <div class="admin-form-actions">
        <button type="button" onclick="saveDeliveryPromotion()" <?php echo !$hasTable ? 'disabled' : ''; ?>>حفظ</button>
        <button type="button" class="btn-secondary" onclick="resetDeliveryPromotionForm()">جديد</button>
    </div>
</div>

<div class="card">
    <h3>القواعد</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>الاسم</th>
                    <th>الخصم</th>
                    <th>نطاق العرض</th>
                    <th>الاستهداف</th>
                    <th>الفترة</th>
                    <th>الحالة</th>
                    <th>ترتيب</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="dp_tbody"></tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3>سجل التفعيل الدائم</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>العرض</th>
                    <th>بداية التفعيل الدائم</th>
                    <th>نهاية التفعيل الدائم</th>
                    <th>بواسطة</th>
                    <th>إنهاء بواسطة</th>
                </tr>
            </thead>
            <tbody id="dp_history_tbody">
                <tr><td colspan="6" class="muted">جارٍ التحميل...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
<?php require __DIR__ . '/../partials/cart_promo_schedule_js.inc.php'; ?>
(function () {
    var DP_DISCOUNT_TYPES = <?php echo json_encode($discountTypes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var DP_READY = <?php echo $hasTable ? 'true' : 'false'; ?>;
    var DP_MONEY_DECIMALS = <?php echo (int) $dpMoneyDecimals; ?>;
    var DP_GOVS = [];
    var DP_AREAS = [];
    var DP_ROWS = [];
    var DP_SUM_SEQ = 0;

    function escDp(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function dpParseMoney(v) {
        var raw = String(v == null ? '' : v).trim().replace(',', '.');
        if (raw === '') return 0;
        var n = parseFloat(raw);
        if (!Number.isFinite(n) || n < 0) return NaN;
        return n;
    }

    function dpRoundMoney(v) {
        var n = Number(v);
        if (!Number.isFinite(n) || n < 0) return 0;
        var p = Math.pow(10, DP_MONEY_DECIMALS);
        return Math.round(n * p) / p;
    }

    function dpFormatMoney(v) {
        var n = dpRoundMoney(dpParseMoney(v));
        return n.toFixed(DP_MONEY_DECIMALS);
    }

    function dpSummaryGovernorateName(row) {
        return row && (row.governorate_name_ar || row.governorate_name_en)
            ? (row.governorate_name_ar || row.governorate_name_en)
            : 'بدون محافظة';
    }

    function dpRenderFeeSummaryEmpty(message) {
        var tb = document.getElementById('dp_fee_summary_tbody');
        if (!tb) return;
        tb.innerHTML = '<tr><td colspan="6" class="muted">' + escDp(message || 'لا توجد بيانات') + '</td></tr>';
    }

    function dpFeeStatusBadge(isDefault) {
        return isDefault
            ? '<span style="color:#166534;font-weight:600;">مربوط بالمحافظة</span>'
            : '<span style="color:#b45309;font-weight:600;">مخصّص</span>';
    }

    function dpAreaNamesHtml(areas) {
        var list = Array.isArray(areas) ? areas : [];
        if (!list.length) {
            return '—';
        }
        return list.map(function (a) {
            return escDp(a.name_ar || a.name_en || ('#' + (a.id || '')));
        }).join('، ');
    }

    function dpAppendSummaryRow(tb, opts) {
        DP_SUM_SEQ++;
        var detailId = 'dp_sum_detail_' + DP_SUM_SEQ;
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td><button type="button" class="btn-secondary dp-sum-toggle" data-dp-detail="' + detailId + '" style="padding:2px 9px;line-height:1;">&#9656;</button></td>' +
            '<td>' + escDp(opts.govName) + '</td>' +
            '<td>' + escDp(String(opts.count)) + '</td>' +
            '<td dir="ltr">' + opts.feeCell + '</td>' +
            '<td dir="ltr">' + opts.costCell + '</td>' +
            '<td>' + opts.statusCell + '</td>';
        tb.appendChild(tr);
        var trd = document.createElement('tr');
        trd.id = detailId;
        trd.style.display = 'none';
        trd.innerHTML = '<td></td><td colspan="5" class="muted">' + dpAreaNamesHtml(opts.areas) + '</td>';
        tb.appendChild(trd);
    }

    function dpBindFeeSummaryToggles() {
        var tb = document.getElementById('dp_fee_summary_tbody');
        if (!tb) return;
        tb.querySelectorAll('.dp-sum-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.getAttribute('data-dp-detail');
                var row = id ? document.getElementById(id) : null;
                if (!row) return;
                var open = row.style.display === 'none';
                row.style.display = open ? '' : 'none';
                btn.innerHTML = open ? '&#9662;' : '&#9656;';
            });
        });
    }

    function dpRenderFeeSummary(data) {
        var tb = document.getElementById('dp_fee_summary_tbody');
        if (!tb) return;
        var govs = Array.isArray(data && data.governorates) ? data.governorates : [];
        DP_SUM_SEQ = 0;
        tb.innerHTML = '';
        if (!govs.length) {
            dpRenderFeeSummaryEmpty('لا توجد بيانات محافظات/مناطق متاحة.');
            return;
        }
        govs.forEach(function (gov) {
            var govName = dpSummaryGovernorateName(gov);
            var groups = Array.isArray(gov.fee_groups) ? gov.fee_groups : [];
            groups.forEach(function (group) {
                dpAppendSummaryRow(tb, {
                    govName: govName,
                    count: parseInt(group.area_count, 10) || 0,
                    feeCell: escDp(dpFormatMoney(group.delivery_fee || 0)),
                    costCell: escDp(dpFormatMoney(group.company_delivery_cost || 0)),
                    statusCell: dpFeeStatusBadge(!!group.is_default),
                    areas: group.areas
                });
            });
            var inactive = Array.isArray(gov.inactive_areas) ? gov.inactive_areas : [];
            if (inactive.length) {
                dpAppendSummaryRow(tb, {
                    govName: govName,
                    count: inactive.length,
                    feeCell: '<span class="muted">—</span>',
                    costCell: '<span class="muted">—</span>',
                    statusCell: '<span style="color:#b91c1c;background:#fee2e2;padding:2px 8px;border-radius:6px;">غير متاحة للتوصيل</span>',
                    areas: inactive
                });
            }
            if (!groups.length && !inactive.length) {
                dpAppendSummaryRow(tb, {
                    govName: govName,
                    count: 0,
                    feeCell: '<span class="muted">—</span>',
                    costCell: '<span class="muted">—</span>',
                    statusCell: '<span class="muted">لا توجد مناطق</span>',
                    areas: []
                });
            }
        });
        dpBindFeeSummaryToggles();
    }

    async function loadDeliveryFeeSummary() {
        var res = await postJSON('/admin/api/delivery_promotions/manage.php', { action: 'get_fee_summary' });
        if (!res || !res.success) {
            dpRenderFeeSummaryEmpty((res && res.message) ? res.message : 'تعذر تحميل ملخص القيم المحفوظة.');
            return;
        }
        dpRenderFeeSummary(res.data || {});
    }

    function dpSelectedIds(selectId) {
        var el = document.getElementById(selectId);
        if (!el) return [];
        var out = [];
        Array.prototype.forEach.call(el.options, function (opt) {
            if (opt.selected) {
                var id = parseInt(opt.value, 10) || 0;
                if (id > 0) out.push(id);
            }
        });
        return out;
    }

    function dpRenderSelect(selectId, rows, selectedIds, getLabel) {
        var el = document.getElementById(selectId);
        if (!el) return;
        var picked = {};
        (selectedIds || []).forEach(function (id) { picked[String(id)] = true; });
        el.innerHTML = '';
        (rows || []).forEach(function (row) {
            var id = parseInt(row.id, 10) || 0;
            if (id <= 0) return;
            var opt = document.createElement('option');
            opt.value = String(id);
            opt.selected = !!picked[String(id)];
            opt.textContent = getLabel(row);
            if (parseInt(row.is_active, 10) !== 1) {
                opt.textContent += ' (غير نشط)';
            }
            el.appendChild(opt);
        });
    }

    function dpRenderTargets(govIds, areaIds) {
        dpRenderSelect('dp_target_governorates', DP_GOVS, govIds || [], function (row) {
            return row.name_ar || row.name_en || ('#' + row.id);
        });
        dpRenderSelect('dp_target_areas', DP_AREAS, areaIds || [], function (row) {
            var areaName = row.name_ar || row.name_en || ('#' + row.id);
            var govName = row.governorate_name_ar || '';
            return govName ? (govName + ' — ' + areaName) : areaName;
        });
    }

    function dpScopeLabel(row) {
        var govNames = Array.isArray(row.target_governorate_names) ? row.target_governorate_names : [];
        var areaNames = Array.isArray(row.target_area_names) ? row.target_area_names : [];
        if (!govNames.length && !areaNames.length) {
            return 'كل مناطق التوصيل';
        }
        var parts = [];
        if (govNames.length) {
            parts.push('محافظات: ' + govNames.join('، '));
        }
        if (areaNames.length) {
            parts.push('مناطق: ' + areaNames.join('، '));
        }
        return parts.join(' | ');
    }

    function dpDiscountLabel(row) {
        var t = String(row.discount_type || 'amount');
        var v = parseFloat(row.discount_value || 0) || 0;
        if (t === 'free') return 'توصيل مجاني';
        if (t === 'percent') return ('%' + v);
        return (v + ' خصم ثابت');
    }

    function dpStatusLabel(row) {
        if (parseInt(row.is_active, 10) !== 1) return 'غير نشط';
        if (parseInt(row.is_always_on, 10) === 1) return 'نشط (دائم)';
        return 'نشط';
    }

    function dpFillDiscountTypeOptions(selected) {
        var sel = document.getElementById('dp_discount_type');
        if (!sel) return;
        sel.innerHTML = '';
        DP_DISCOUNT_TYPES.forEach(function (row) {
            var opt = document.createElement('option');
            opt.value = row.key;
            opt.textContent = row.label_ar || row.key;
            sel.appendChild(opt);
        });
        sel.value = selected || 'amount';
        if (!sel.value) sel.value = 'amount';
    }

    function dpApplyDiscountTypeUi() {
        var typeEl = document.getElementById('dp_discount_type');
        var valEl = document.getElementById('dp_discount_value');
        var labelEl = document.getElementById('dp_discount_value_label');
        if (!typeEl || !valEl || !labelEl) return;
        var t = String(typeEl.value || 'amount');
        if (t === 'free') {
            labelEl.textContent = 'قيمة الخصم (تلقائي)';
            valEl.value = '0';
            valEl.disabled = true;
            valEl.placeholder = '0';
            return;
        }
        valEl.disabled = false;
        if (t === 'percent') {
            labelEl.textContent = 'نسبة الخصم (%)';
            valEl.placeholder = '10';
        } else {
            labelEl.textContent = 'قيمة الخصم';
            valEl.placeholder = '2';
        }
    }

    function resetDeliveryPromotionForm() {
        document.getElementById('dp_id').value = '0';
        document.getElementById('dp_name_ar').value = '';
        document.getElementById('dp_name_en').value = '';
        document.getElementById('dp_discount_type').value = 'amount';
        document.getElementById('dp_discount_value').value = '';
        document.getElementById('dp_sort').value = '0';
        document.getElementById('dp_reg').checked = false;
        document.getElementById('dp_active').checked = true;
        ocpSetAlwaysOn('dp', false);
        ocpDefaultScheduleDates('dp');
        dpRenderTargets([], []);
        dpApplyDiscountTypeUi();
    }

    function editDeliveryPromotion(row) {
        document.getElementById('dp_id').value = String(row.id != null ? row.id : 0);
        document.getElementById('dp_name_ar').value = row.name_ar || '';
        document.getElementById('dp_name_en').value = row.name_en || '';
        document.getElementById('dp_discount_type').value = row.discount_type || 'amount';
        document.getElementById('dp_discount_value').value = row.discount_value != null ? String(row.discount_value) : '';
        document.getElementById('dp_sort').value = String(row.sort_order != null ? row.sort_order : 0);
        document.getElementById('dp_reg').checked = parseInt(row.requires_registered_account, 10) === 1;
        document.getElementById('dp_active').checked = parseInt(row.is_active, 10) === 1;
        ocpSetAlwaysOn('dp', parseInt(row.is_always_on, 10) === 1);
        ocpSetDmyFromIso('dp_valid_from', row.valid_from);
        ocpSetDmyFromIso('dp_valid_to', row.valid_to);
        dpRenderTargets(row.target_governorate_ids || [], row.target_area_ids || []);
        dpApplyDiscountTypeUi();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    async function loadDeliveryPromotionTargets() {
        var res = await postJSON('/admin/api/delivery_promotions/manage.php', { action: 'list_targets' });
        if (!res || !res.success) {
            throw new Error((res && res.message) || 'تعذر تحميل المحافظات والمناطق');
        }
        DP_GOVS = Array.isArray(res.governorates) ? res.governorates : [];
        DP_AREAS = Array.isArray(res.areas) ? res.areas : [];
        dpRenderTargets(dpSelectedIds('dp_target_governorates'), dpSelectedIds('dp_target_areas'));
    }

    async function loadDeliveryPromotions() {
        var res = await postJSON('/admin/api/delivery_promotions/manage.php', { action: 'list' });
        if (!res || !res.success) {
            alert((res && res.message) || 'تعذر تحميل عروض التوصيل');
            return;
        }
        DP_ROWS = Array.isArray(res.data) ? res.data : [];
        var tb = document.getElementById('dp_tbody');
        tb.innerHTML = '';
        if (!DP_ROWS.length) {
            tb.innerHTML = '<tr><td colspan="9" class="muted">لا توجد عروض</td></tr>';
            return;
        }
        DP_ROWS.forEach(function (row) {
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td>' + escDp(String(row.id || '')) + '</td>' +
                '<td>' + escDp(row.name_ar || row.name_en || '') + '</td>' +
                '<td dir="ltr">' + escDp(dpDiscountLabel(row)) + '</td>' +
                '<td>' + (parseInt(row.requires_registered_account, 10) === 1 ? 'مسجّل فقط' : 'جميع الزوار') + '</td>' +
                '<td>' + escDp(dpScopeLabel(row)) + '</td>' +
                '<td dir="ltr">' + escDp(ocpScheduleLabel(row)) + '</td>' +
                '<td>' + escDp(dpStatusLabel(row)) + '</td>' +
                '<td>' + escDp(String(row.sort_order || 0)) + '</td>' +
                '<td><button type="button" class="btn-secondary" data-dp-edit="' + escDp(String(row.id || 0)) + '">تعديل</button></td>';
            tb.appendChild(tr);
        });
        tb.querySelectorAll('[data-dp-edit]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = parseInt(btn.getAttribute('data-dp-edit'), 10) || 0;
                var row = DP_ROWS.find(function (x) { return (parseInt(x.id, 10) || 0) === id; });
                if (row) editDeliveryPromotion(row);
            });
        });
    }

    async function saveDeliveryPromotion() {
        var payload = {
            action: 'save',
            id: parseInt(document.getElementById('dp_id').value, 10) || 0,
            name_ar: (document.getElementById('dp_name_ar').value || '').trim(),
            name_en: (document.getElementById('dp_name_en').value || '').trim(),
            discount_type: (document.getElementById('dp_discount_type').value || 'amount').trim(),
            discount_value: (document.getElementById('dp_discount_value').value || '').trim(),
            sort_order: parseInt(document.getElementById('dp_sort').value, 10) || 0,
            requires_registered_account: document.getElementById('dp_reg').checked ? 1 : 0,
            is_active: document.getElementById('dp_active').checked ? 1 : 0,
            is_always_on: ocpIsAlwaysOn('dp') ? 1 : 0,
            valid_from: ocpGetIso('dp_valid_from'),
            valid_to: ocpGetIso('dp_valid_to'),
            target_governorate_ids: dpSelectedIds('dp_target_governorates'),
            target_area_ids: dpSelectedIds('dp_target_areas')
        };
        var res = await postJSON('/admin/api/delivery_promotions/manage.php', payload);
        alert((res && res.message) || (res && res.success ? 'تم الحفظ' : 'فشل الحفظ'));
        if (res && res.success) {
            resetDeliveryPromotionForm();
            await loadDeliveryPromotions();
            await loadDeliveryPromotionAlwaysOnHistory();
        }
    }

    function dpAdminName(name) {
        var s = String(name || '').trim();
        return s !== '' ? s : '—';
    }

    async function loadDeliveryPromotionAlwaysOnHistory() {
        var tb = document.getElementById('dp_history_tbody');
        if (!tb) return;
        var res = await postJSON('/admin/api/delivery_promotions/manage.php', { action: 'always_on_history' });
        if (!res || !res.success || !Array.isArray(res.data)) {
            tb.innerHTML = '<tr><td colspan="6" class="muted">تعذر تحميل السجل.</td></tr>';
            return;
        }
        var rows = res.data;
        if (!rows.length) {
            tb.innerHTML = '<tr><td colspan="6" class="muted">لا توجد عمليات تفعيل دائم مسجلة بعد.</td></tr>';
            return;
        }
        tb.innerHTML = '';
        rows.forEach(function (row) {
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td>' + escDp(String(row.id || '')) + '</td>' +
                '<td>عرض #' + escDp(String(row.promotion_id || '')) + '</td>' +
                '<td dir="ltr">' + escDp(String(row.started_at || '')) + '</td>' +
                '<td dir="ltr">' + escDp(String(row.ended_at || '')) + '</td>' +
                '<td>' + escDp(dpAdminName(row.started_by_name)) + '</td>' +
                '<td>' + escDp(dpAdminName(row.ended_by_name)) + '</td>';
            tb.appendChild(tr);
        });
    }

    window.resetDeliveryPromotionForm = resetDeliveryPromotionForm;
    window.saveDeliveryPromotion = saveDeliveryPromotion;

    function initDeliveryPromotionsPage() {
        var discountTypeEl = document.getElementById('dp_discount_type');
        if (!discountTypeEl) {
            return;
        }
        ocpBindAlwaysOn('dp');
        discountTypeEl.addEventListener('change', dpApplyDiscountTypeUi);
        dpFillDiscountTypeOptions('amount');
        resetDeliveryPromotionForm();
        loadDeliveryFeeSummary().catch(function () {
            dpRenderFeeSummaryEmpty('تعذر تحميل الملخص.');
        });
        loadDeliveryPromotionAlwaysOnHistory().catch(function () {
            var tb = document.getElementById('dp_history_tbody');
            if (tb) tb.innerHTML = '<tr><td colspan="6" class="muted">تعذر تحميل السجل.</td></tr>';
        });
        if (!DP_READY) {
            return;
        }
        loadDeliveryPromotionTargets().catch(function (e) {
            alert(e.message || String(e));
        });
        loadDeliveryPromotions();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDeliveryPromotionsPage);
    } else {
        initDeliveryPromotionsPage();
    }
})();
</script>
