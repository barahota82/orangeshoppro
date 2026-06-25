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

<style>
.dp-tree { border:1px solid #e5e7eb; border-radius:10px; padding:6px 8px; max-height:520px; overflow:auto; }
.dp-tree-all { display:flex; align-items:center; gap:8px; font-weight:700; cursor:pointer; margin:6px 2px 10px; }
.dp-node-row { display:flex; align-items:center; gap:8px; padding:5px 4px; border-radius:8px; }
.dp-node-row:hover { background:#f8fafc; }
.dp-gov > .dp-node-row { font-weight:700; }
.dp-toggle { background:#f1f5f9; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer; width:26px; height:24px; line-height:1; padding:0; color:#334155; flex:0 0 auto; }
.dp-toggle.is-leaf { visibility:hidden; }
.dp-node-label { display:flex; align-items:center; gap:8px; cursor:pointer; flex:1 1 auto; }
.dp-amounts { color:#64748b; font-weight:400; }
.dp-default-tag { color:#166534; font-size:.8em; }
.dp-custom-tag { color:#b45309; font-size:.8em; }
.dp-gov-body, .dp-grp-body, .dp-inactive-body { margin-inline-start:26px; border-inline-start:2px solid #eef2f7; padding-inline-start:8px; }
.dp-area-search { width:100%; max-width:320px; margin:6px 0; padding:5px 8px; border:1px solid #e2e8f0; border-radius:6px; }
.dp-area-row { display:flex; align-items:center; gap:8px; padding:3px 4px; }
.dp-inactive-badge { color:#b91c1c; background:#fee2e2; padding:2px 8px; border-radius:6px; font-size:.85em; }
.dp-tree-empty { color:#64748b; padding:10px; }
.dp-form-row { display:flex; flex-wrap:wrap; gap:18px; align-items:flex-end; }
.dp-form-row .dp-field { display:flex; flex-direction:column; }
.dp-form-row .dp-field > label { margin-bottom:4px; }
.dp-form-row .dp-field .admin-inp { height:var(--input-min-h,40px); box-sizing:border-box; }
.dp-form-row .dp-check { display:flex; align-items:center; gap:8px; cursor:pointer; height:var(--input-min-h,40px); white-space:nowrap; }
.admin-inp-dmy-with-picker.is-disabled { opacity:.55; }
.admin-inp-dmy-with-picker.is-disabled .admin-inp { background:#f1f5f9; color:#94a3b8; cursor:not-allowed; }
</style>

<div class="card">
    <h3>استهداف العرض — محافظات ومناطق التوصيل</h3>
    <label class="dp-tree-all">
        <input type="checkbox" id="dp_tree_all"> كل مناطق التوصيل
    </label>
    <div id="dp_tree" class="dp-tree">
        <div class="dp-tree-empty">جارٍ تحميل البيانات...</div>
    </div>
</div>

<div class="card">
    <h3>إضافة / تعديل</h3>
    <input type="hidden" id="dp_id" value="0">
    <div class="dp-form-row" style="margin-bottom:14px;">
        <div class="dp-field" style="max-width:120px;">
            <label for="dp_sort">الترتيب</label>
            <input type="number" id="dp_sort" class="admin-inp" value="0" readonly tabindex="-1" title="يُحدَّد تلقائياً" style="background:#f1f5f9;color:#64748b;cursor:not-allowed;text-align:center;">
        </div>
        <label class="dp-check">
            <input type="checkbox" id="dp_active" checked> نشط
        </label>
    </div>
    <div class="form-grid">
        <div>
            <label for="dp_name_ar">اسم العرض (عربي)</label>
            <input type="text" id="dp_name_ar" class="admin-inp" dir="auto" placeholder="مثال: خصم توصيل نهاية الأسبوع">
        </div>
        <div>
            <label for="dp_name_en">English</label>
            <input type="text" id="dp_name_en" class="admin-inp" dir="ltr" lang="en" placeholder="Weekend delivery promo">
        </div>
    </div>
    <div class="dp-form-row" style="margin-top:12px;">
        <div class="dp-field" style="min-width:160px;">
            <label for="dp_discount_type">نوع الخصم</label>
            <select id="dp_discount_type" class="admin-inp"></select>
        </div>
        <div class="dp-field" style="min-width:140px;">
            <label for="dp_discount_value" id="dp_discount_value_label">قيمة الخصم</label>
            <input type="text" id="dp_discount_value" class="admin-inp admin-inp-money" inputmode="decimal" lang="en" dir="ltr" placeholder="2">
        </div>
        <label class="dp-check">
            <input type="checkbox" id="dp_always_on"> <strong>التفعيل الدائم</strong>
        </label>
        <div class="dp-field" style="min-width:150px;">
            <label for="dp_valid_from">بداية العرض <span dir="ltr">*</span></label>
            <input type="text" id="dp_valid_from" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off" required>
        </div>
        <div class="dp-field" style="min-width:150px;">
            <label for="dp_valid_to">نهاية العرض <span dir="ltr">*</span></label>
            <input type="text" id="dp_valid_to" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off" required>
        </div>
        <label class="dp-check">
            <input type="checkbox" id="dp_reg"> <strong>للمسجّلين فقط</strong>
        </label>
        <label class="dp-check">
            <input type="checkbox" id="dp_first_delivered"> <strong>أول طلب مُسلَّم</strong>
        </label>
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
    var DP_ROWS = [];
    var DP_FEE_SUMMARY = { governorates: [] };
    var DP_TREE_SEQ = 0;

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

    function dpSummaryGovernorateName(gov) {
        return gov && (gov.governorate_name_ar || gov.governorate_name_en)
            ? (gov.governorate_name_ar || gov.governorate_name_en)
            : 'بدون محافظة';
    }

    function dpGovActiveAreaIds(gov) {
        var ids = [];
        (Array.isArray(gov.fee_groups) ? gov.fee_groups : []).forEach(function (g) {
            (Array.isArray(g.areas) ? g.areas : []).forEach(function (a) {
                var id = parseInt(a.id, 10) || 0;
                if (id > 0) ids.push(id);
            });
        });
        return ids;
    }

    function dpAllActiveAreaIds() {
        var ids = [];
        (DP_FEE_SUMMARY.governorates || []).forEach(function (gov) {
            ids = ids.concat(dpGovActiveAreaIds(gov));
        });
        return ids;
    }

    // ===== شجرة الاستهداف (مصدر الحقيقة = صناديق المناطق المختارة) =====

    function dpTreeRenderEmpty(message) {
        var box = document.getElementById('dp_tree');
        if (box) box.innerHTML = '<div class="dp-tree-empty">' + escDp(message || 'لا توجد بيانات') + '</div>';
    }

    function dpMakeToggle(targetEl) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dp-toggle';
        btn.innerHTML = '&#9656;';
        btn.addEventListener('click', function () {
            var open = targetEl.style.display === 'none';
            targetEl.style.display = open ? '' : 'none';
            btn.innerHTML = open ? '&#9662;' : '&#9656;';
        });
        return btn;
    }

    function dpBuildAreaRow(area) {
        var id = parseInt(area.id, 10) || 0;
        var row = document.createElement('label');
        row.className = 'dp-area-row';
        row.setAttribute('data-area-name', String(area.name_ar || area.name_en || '').toLowerCase());
        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'dp-area-cb';
        cb.value = String(id);
        cb.addEventListener('change', dpRecomputeTreeStates);
        var span = document.createElement('span');
        span.textContent = area.name_ar || area.name_en || ('#' + id);
        row.appendChild(cb);
        row.appendChild(span);
        return row;
    }

    function dpRenderTree() {
        var box = document.getElementById('dp_tree');
        if (!box) return;
        DP_TREE_SEQ = 0;
        box.innerHTML = '';
        var govs = DP_FEE_SUMMARY.governorates || [];
        if (!govs.length) {
            dpTreeRenderEmpty('لا توجد محافظات/مناطق متاحة. أضِفها من شاشة «محافظات ومناطق التوصيل».');
            return;
        }
        govs.forEach(function (gov) {
            var govWrap = document.createElement('div');
            govWrap.className = 'dp-gov';

            var govBody = document.createElement('div');
            govBody.className = 'dp-gov-body';
            govBody.style.display = 'none';

            var govRow = document.createElement('div');
            govRow.className = 'dp-node-row';
            var govActiveIds = dpGovActiveAreaIds(gov);
            var govToggle = (govActiveIds.length || (gov.inactive_areas || []).length)
                ? dpMakeToggle(govBody)
                : null;
            if (govToggle) {
                govRow.appendChild(govToggle);
            } else {
                var spacer = document.createElement('span');
                spacer.className = 'dp-toggle is-leaf';
                govRow.appendChild(spacer);
            }
            var govLabel = document.createElement('label');
            govLabel.className = 'dp-node-label';
            var govCb = document.createElement('input');
            govCb.type = 'checkbox';
            govCb.className = 'dp-gov-cb';
            govCb.disabled = govActiveIds.length === 0;
            govCb.addEventListener('change', function () {
                dpSetDescendants(govWrap, govCb.checked);
                dpRecomputeTreeStates();
            });
            var govNameSpan = document.createElement('span');
            govNameSpan.innerHTML = escDp(dpSummaryGovernorateName(gov))
                + ' <span class="dp-amounts">(' + govActiveIds.length + ' منطقة)</span>';
            govLabel.appendChild(govCb);
            govLabel.appendChild(govNameSpan);
            govRow.appendChild(govLabel);
            govWrap.appendChild(govRow);

            (Array.isArray(gov.fee_groups) ? gov.fee_groups : []).forEach(function (group) {
                var areas = Array.isArray(group.areas) ? group.areas : [];
                if (!areas.length) return;
                var grpWrap = document.createElement('div');
                grpWrap.className = 'dp-grp';

                var grpBody = document.createElement('div');
                grpBody.className = 'dp-grp-body';
                grpBody.style.display = 'none';

                var grpRow = document.createElement('div');
                grpRow.className = 'dp-node-row';
                grpRow.appendChild(dpMakeToggle(grpBody));
                var grpLabel = document.createElement('label');
                grpLabel.className = 'dp-node-label';
                var grpCb = document.createElement('input');
                grpCb.type = 'checkbox';
                grpCb.className = 'dp-grp-cb';
                grpCb.addEventListener('change', function () {
                    dpSetDescendants(grpWrap, grpCb.checked);
                    dpRecomputeTreeStates();
                });
                var tag = group.is_default
                    ? ' <span class="dp-default-tag">مربوط بالمحافظة</span>'
                    : ' <span class="dp-custom-tag">مخصّص</span>';
                var grpText = document.createElement('span');
                grpText.innerHTML = '<span class="dp-amounts" dir="ltr">'
                    + escDp(dpFormatMoney(group.delivery_fee || 0)) + ' / '
                    + escDp(dpFormatMoney(group.company_delivery_cost || 0)) + '</span> '
                    + '<span class="dp-amounts">(' + areas.length + ')</span>' + tag;
                grpLabel.appendChild(grpCb);
                grpLabel.appendChild(grpText);
                grpRow.appendChild(grpLabel);
                grpWrap.appendChild(grpRow);

                var search = document.createElement('input');
                search.type = 'search';
                search.className = 'dp-area-search';
                search.placeholder = 'بحث في المناطق...';
                search.addEventListener('input', function () {
                    var q = search.value.trim().toLowerCase();
                    grpBody.querySelectorAll('.dp-area-row').forEach(function (r) {
                        var name = r.getAttribute('data-area-name') || '';
                        r.style.display = (!q || name.indexOf(q) !== -1) ? '' : 'none';
                    });
                });
                grpBody.appendChild(search);
                var areasWrap = document.createElement('div');
                areasWrap.className = 'dp-areas';
                areas.forEach(function (area) {
                    areasWrap.appendChild(dpBuildAreaRow(area));
                });
                grpBody.appendChild(areasWrap);
                grpWrap.appendChild(grpBody);
                govBody.appendChild(grpWrap);
            });

            var inactive = Array.isArray(gov.inactive_areas) ? gov.inactive_areas : [];
            if (inactive.length) {
                var inWrap = document.createElement('div');
                inWrap.className = 'dp-inactive';
                var inBody = document.createElement('div');
                inBody.className = 'dp-inactive-body';
                inBody.style.display = 'none';
                inBody.textContent = inactive.map(function (a) {
                    return a.name_ar || a.name_en || ('#' + (a.id || ''));
                }).join('، ');
                var inRow = document.createElement('div');
                inRow.className = 'dp-node-row';
                inRow.appendChild(dpMakeToggle(inBody));
                var inSpan = document.createElement('span');
                inSpan.innerHTML = '<span class="dp-inactive-badge">غير متاحة للتوصيل (' + inactive.length + ')</span>';
                inRow.appendChild(inSpan);
                inWrap.appendChild(inRow);
                inWrap.appendChild(inBody);
                govBody.appendChild(inWrap);
            }

            govWrap.appendChild(govBody);
            box.appendChild(govWrap);
        });
        dpRecomputeTreeStates();
    }

    function dpSetDescendants(wrap, checked) {
        wrap.querySelectorAll('.dp-area-cb').forEach(function (cb) {
            cb.checked = checked;
        });
    }

    function dpRecomputeTreeStates() {
        var box = document.getElementById('dp_tree');
        if (!box) return;
        var allTotal = 0;
        var allChecked = 0;
        box.querySelectorAll('.dp-gov').forEach(function (govWrap) {
            var govAreas = govWrap.querySelectorAll('.dp-area-cb');
            var govChecked = 0;
            govWrap.querySelectorAll('.dp-grp').forEach(function (grpWrap) {
                var grpAreas = grpWrap.querySelectorAll('.dp-area-cb');
                var grpChecked = 0;
                grpAreas.forEach(function (cb) { if (cb.checked) grpChecked++; });
                var grpCb = grpWrap.querySelector('.dp-grp-cb');
                if (grpCb) {
                    grpCb.checked = grpAreas.length > 0 && grpChecked === grpAreas.length;
                    grpCb.indeterminate = grpChecked > 0 && grpChecked < grpAreas.length;
                }
            });
            govAreas.forEach(function (cb) { if (cb.checked) govChecked++; });
            var govCb = govWrap.querySelector('.dp-gov-cb');
            if (govCb) {
                govCb.checked = govAreas.length > 0 && govChecked === govAreas.length;
                govCb.indeterminate = govChecked > 0 && govChecked < govAreas.length;
            }
            allTotal += govAreas.length;
            allChecked += govChecked;
        });
        var allCb = document.getElementById('dp_tree_all');
        if (allCb) {
            allCb.checked = allTotal > 0 && allChecked === allTotal;
            allCb.indeterminate = allChecked > 0 && allChecked < allTotal;
        }
    }

    function dpBindTreeAll() {
        var allCb = document.getElementById('dp_tree_all');
        if (!allCb) return;
        allCb.addEventListener('change', function () {
            var box = document.getElementById('dp_tree');
            if (!box) return;
            box.querySelectorAll('.dp-area-cb').forEach(function (cb) {
                cb.checked = allCb.checked;
            });
            dpRecomputeTreeStates();
        });
    }

    function dpTreeSelectedAreaIds() {
        var box = document.getElementById('dp_tree');
        if (!box) return [];
        var out = [];
        box.querySelectorAll('.dp-area-cb').forEach(function (cb) {
            if (cb.checked) {
                var id = parseInt(cb.value, 10) || 0;
                if (id > 0) out.push(id);
            }
        });
        return out;
    }

    function dpTreeSetSelection(areaIds, governorateIds) {
        // محافظات قديمة (legacy) تُترجَم إلى كل مناطقها النشطة الحالية (لقطة).
        var picked = {};
        (areaIds || []).forEach(function (id) { picked[String(parseInt(id, 10) || 0)] = true; });
        var govSet = {};
        (governorateIds || []).forEach(function (id) { govSet[String(parseInt(id, 10) || 0)] = true; });
        (DP_FEE_SUMMARY.governorates || []).forEach(function (gov) {
            if (govSet[String(parseInt(gov.governorate_id, 10) || 0)]) {
                dpGovActiveAreaIds(gov).forEach(function (id) { picked[String(id)] = true; });
            }
        });
        var box = document.getElementById('dp_tree');
        if (box) {
            box.querySelectorAll('.dp-area-cb').forEach(function (cb) {
                cb.checked = !!picked[String(parseInt(cb.value, 10) || 0)];
            });
        }
        dpRecomputeTreeStates();
    }

    function dpTreeClear() {
        var box = document.getElementById('dp_tree');
        if (box) {
            box.querySelectorAll('.dp-area-cb').forEach(function (cb) { cb.checked = false; });
        }
        dpRecomputeTreeStates();
    }

    async function loadDeliveryFeeSummary() {
        var res = await postJSON('/admin/api/delivery_promotions/manage.php', { action: 'get_fee_summary' });
        if (!res || !res.success) {
            DP_FEE_SUMMARY = { governorates: [] };
            dpTreeRenderEmpty((res && res.message) ? res.message : 'تعذر تحميل بيانات المحافظات/المناطق.');
            return;
        }
        DP_FEE_SUMMARY = (res.data && Array.isArray(res.data.governorates))
            ? res.data
            : { governorates: [] };
        dpRenderTree();
    }

    function dpScopeLabel(row) {
        var areaIds = Array.isArray(row.target_area_ids) ? row.target_area_ids.map(function (x) { return parseInt(x, 10) || 0; }) : [];
        var govIds = Array.isArray(row.target_governorate_ids) ? row.target_governorate_ids.map(function (x) { return parseInt(x, 10) || 0; }) : [];
        if (!areaIds.length && !govIds.length) {
            return 'كل مناطق التوصيل';
        }
        var pickedAreas = {};
        areaIds.forEach(function (id) { if (id > 0) pickedAreas[String(id)] = true; });
        var govPicked = {};
        govIds.forEach(function (id) { if (id > 0) govPicked[String(id)] = true; });
        var parts = [];
        (DP_FEE_SUMMARY.governorates || []).forEach(function (gov) {
            var gid = parseInt(gov.governorate_id, 10) || 0;
            var govName = dpSummaryGovernorateName(gov);
            var govActive = dpGovActiveAreaIds(gov);
            var inGov = govActive.filter(function (id) { return pickedAreas[String(id)]; });
            if (govPicked[String(gid)] || (govActive.length > 0 && inGov.length === govActive.length)) {
                parts.push(govName + ' (الكل)');
            } else if (inGov.length) {
                var names = [];
                (gov.fee_groups || []).forEach(function (g) {
                    (g.areas || []).forEach(function (a) {
                        if (pickedAreas[String(parseInt(a.id, 10) || 0)]) {
                            names.push(a.name_ar || a.name_en || ('#' + a.id));
                        }
                    });
                });
                parts.push(govName + ': ' + names.join('، '));
            }
        });
        return parts.length ? parts.join(' | ') : 'مناطق محددة';
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

    function dpEligibilityLabel(row) {
        var parts = [parseInt(row.requires_registered_account, 10) === 1 ? 'مسجّل فقط' : 'جميع الزوار'];
        if (parseInt(row.first_delivered_order_only, 10) === 1) {
            parts.push('أول طلب مُسلَّم');
        }
        return parts.join(' + ');
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

    function dpComputeNextSort() {
        var max = 0;
        (DP_ROWS || []).forEach(function (r) {
            var s = parseInt(r.sort_order, 10) || 0;
            if (s > max) max = s;
        });
        return max + 1;
    }

    function resetDeliveryPromotionForm() {
        document.getElementById('dp_id').value = '0';
        document.getElementById('dp_name_ar').value = '';
        document.getElementById('dp_name_en').value = '';
        document.getElementById('dp_discount_type').value = 'amount';
        document.getElementById('dp_discount_value').value = '';
        document.getElementById('dp_sort').value = String(dpComputeNextSort());
        document.getElementById('dp_reg').checked = false;
        document.getElementById('dp_first_delivered').checked = false;
        document.getElementById('dp_active').checked = true;
        ocpSetAlwaysOn('dp', false);
        ocpDefaultScheduleDates('dp');
        dpTreeClear();
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
        document.getElementById('dp_first_delivered').checked = parseInt(row.first_delivered_order_only, 10) === 1;
        document.getElementById('dp_active').checked = parseInt(row.is_active, 10) === 1;
        ocpSetAlwaysOn('dp', parseInt(row.is_always_on, 10) === 1);
        ocpSetDmyFromIso('dp_valid_from', row.valid_from);
        ocpSetDmyFromIso('dp_valid_to', row.valid_to);
        dpTreeSetSelection(row.target_area_ids || [], row.target_governorate_ids || []);
        dpApplyDiscountTypeUi();
        window.scrollTo({ top: 0, behavior: 'smooth' });
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
                '<td>' + escDp(dpEligibilityLabel(row)) + '</td>' +
                '<td>' + escDp(dpScopeLabel(row)) + '</td>' +
                '<td dir="ltr">' + escDp(ocpScheduleLabel(row)) + '</td>' +
                '<td>' + escDp(dpStatusLabel(row)) + '</td>' +
                '<td>' + escDp(String(row.sort_order != null ? row.sort_order : 0)) + '</td>' +
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
        // عند وضع «إضافة جديد» اعرض الترتيب التلقائي التالي
        if ((parseInt(document.getElementById('dp_id').value, 10) || 0) === 0) {
            document.getElementById('dp_sort').value = String(dpComputeNextSort());
        }
    }

    async function saveDeliveryPromotion() {
        var payload = {
            action: 'save',
            id: parseInt(document.getElementById('dp_id').value, 10) || 0,
            name_ar: (document.getElementById('dp_name_ar').value || '').trim(),
            name_en: (document.getElementById('dp_name_en').value || '').trim(),
            discount_type: (document.getElementById('dp_discount_type').value || 'amount').trim(),
            discount_value: (document.getElementById('dp_discount_value').value || '').trim(),
            requires_registered_account: document.getElementById('dp_reg').checked ? 1 : 0,
            first_delivered_order_only: document.getElementById('dp_first_delivered').checked ? 1 : 0,
            is_active: document.getElementById('dp_active').checked ? 1 : 0,
            is_always_on: ocpIsAlwaysOn('dp') ? 1 : 0,
            valid_from: ocpGetIso('dp_valid_from'),
            valid_to: ocpGetIso('dp_valid_to'),
            target_governorate_ids: [],
            target_area_ids: dpTreeSelectedAreaIds()
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

    async function initDeliveryPromotionsPage() {
        var discountTypeEl = document.getElementById('dp_discount_type');
        if (!discountTypeEl) {
            return;
        }
        ocpBindAlwaysOn('dp');
        discountTypeEl.addEventListener('change', dpApplyDiscountTypeUi);
        dpFillDiscountTypeOptions('amount');
        dpBindTreeAll();
        resetDeliveryPromotionForm();
        loadDeliveryPromotionAlwaysOnHistory().catch(function () {
            var tb = document.getElementById('dp_history_tbody');
            if (tb) tb.innerHTML = '<tr><td colspan="6" class="muted">تعذر تحميل السجل.</td></tr>';
        });
        try {
            await loadDeliveryFeeSummary();
        } catch (e) {
            dpTreeRenderEmpty('تعذر تحميل بيانات المحافظات/المناطق.');
        }
        if (!DP_READY) {
            return;
        }
        loadDeliveryPromotions();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDeliveryPromotionsPage);
    } else {
        initDeliveryPromotionsPage();
    }
})();
</script>
