<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/delivery_areas.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$hasTable = orange_table_exists($pdo, 'delivery_fee_promotions');
$adminCountryId = orange_admin_context_country_id($pdo);
$dpMoney = isset($orangeAdminMoney) && is_array($orangeAdminMoney)
    ? $orangeAdminMoney
    : orange_admin_currency_context($pdo);
$dpMoneyDecimals = (int) ($dpMoney['decimals'] ?? 3);
$dpMoneyStep = isset($orangeAdminMoneyStep) && is_string($orangeAdminMoneyStep)
    ? $orangeAdminMoneyStep
    : orange_admin_money_input_step($dpMoneyDecimals);
$dpPolicy = orange_delivery_country_policy_read($pdo, $adminCountryId);
$dpBaseFeeValue = number_format(
    max(0.0, (float) ($dpPolicy['default_delivery_fee'] ?? 0.0)),
    $dpMoneyDecimals,
    '.',
    ''
);
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
    <h3>قيمة التوصيل الأساسية قبل الخصم</h3>
    <p class="card-hint" style="margin-top:0;">
        هذه القيمة هي أساس الرسوم قبل أي خصم من عروض التوصيل. اختر نمط التطبيق أولاً، ثم احفظ الأساس أو طبّقه على المناطق عند الحاجة.
    </p>
    <div class="admin-form-actions" style="display:flex;flex-wrap:wrap;gap:10px 16px;align-items:flex-end;">
        <div style="min-width:220px;">
            <label for="dp_base_delivery_fee">قيمة التوصيل الأساسية</label>
            <input type="number"
                   id="dp_base_delivery_fee"
                   min="0"
                   step="<?php echo htmlspecialchars($dpMoneyStep, ENT_QUOTES, 'UTF-8'); ?>"
                   lang="en"
                   dir="ltr"
                   value="<?php echo htmlspecialchars($dpBaseFeeValue, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:12px 16px;align-items:center;min-width:260px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" id="dp_apply_mode_all">
                <span>تطبيق على الكل</span>
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" id="dp_apply_mode_custom">
                <span>تطبيق مخصص</span>
            </label>
        </div>
    </div>
    <div class="admin-form-actions" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:8px;">
        <button type="button" class="btn-secondary" onclick="savePromotionBaseFee(false)" <?php echo !$hasTable ? 'disabled' : ''; ?>>حفظ القيمة + النمط</button>
        <button type="button" id="dp_apply_all_btn" onclick="savePromotionBaseFee(true)" <?php echo !$hasTable ? 'disabled' : ''; ?>>حفظ + تطبيق على كل المناطق النشطة</button>
        <span id="dp_apply_mode_hint" class="card-hint"></span>
        <span id="dp_base_fee_status" class="card-hint" style="margin-inline-start:auto;"></span>
    </div>
</div>

<div class="card" id="dp_custom_apply_card" style="display:none;">
    <h3>تطبيق مخصص على مناطق محددة</h3>
    <p class="card-hint" style="margin-top:0;">
        هذا الكارت يظهر فقط عند اختيار نمط "تطبيق مخصص". اختر محافظة، ثم حدّد المناطق النشطة داخلها وحدد قيمة التوصيل.
    </p>
    <div class="form-grid">
        <div>
            <label for="dp_custom_governorate_id">المحافظة</label>
            <select id="dp_custom_governorate_id" class="admin-inp"></select>
        </div>
    </div>
    <div id="dp_custom_scope_card" style="display:none;border:1px dashed #d4d4d8;border-radius:10px;padding:12px;margin-top:10px;">
        <div class="form-grid">
            <div>
                <label for="dp_custom_delivery_fee">قيمة التوصيل للمناطق المختارة</label>
                <input type="number"
                       id="dp_custom_delivery_fee"
                       min="0"
                       step="<?php echo htmlspecialchars($dpMoneyStep, ENT_QUOTES, 'UTF-8'); ?>"
                       lang="en"
                       dir="ltr"
                       class="admin-inp"
                       value="">
            </div>
            <div style="grid-column:1/-1;">
                <label for="dp_custom_area_ids">المناطق النشطة داخل المحافظة</label>
                <select id="dp_custom_area_ids" class="admin-inp" multiple size="8"></select>
                <span id="dp_custom_area_hint" class="muted" style="display:block;margin-top:4px;"></span>
            </div>
        </div>
        <div class="admin-form-actions">
            <button type="button" onclick="saveCustomDeliveryFeeGroup()" <?php echo !$hasTable ? 'disabled' : ''; ?>>حفظ التطبيق المخصص</button>
            <button type="button" class="btn-secondary" onclick="resetCustomDeliveryFeeForm()">إعادة تعيين</button>
            <span id="dp_custom_status" class="card-hint"></span>
        </div>
    </div>
</div>

<div class="card">
    <h3>ملخص القيم المحفوظة</h3>
    <p class="card-hint" style="margin-top:0;">
        هذا الكارت الثابت يعرض القيم المحفوظة فعلياً حسب النمط الحالي (الكل/المخصص) لكل محافظة مع المناطق غير النشطة.
    </p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>النمط</th>
                    <th>المحافظة</th>
                    <th>مناطق نشطة</th>
                    <th>قيمة التوصيل</th>
                    <th>مناطق غير نشطة</th>
                    <th></th>
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
    <p class="page-subtitle" style="margin-top:0;">عند تعدد العروض النشطة في نفس التاريخ يُختار أول عرض حسب الترتيب.</p>
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
    var DP_BASE_FEE = <?php echo json_encode((float) ($dpPolicy['default_delivery_fee'] ?? 0.0), JSON_UNESCAPED_UNICODE); ?>;
    var DP_BASE_APPLY_MODE = <?php echo json_encode((string) ($dpPolicy['delivery_fee_apply_mode'] ?? 'all'), JSON_UNESCAPED_UNICODE); ?>;
    var DP_GOVS = [];
    var DP_AREAS = [];
    var DP_ROWS = [];
    var DP_CUSTOM_EDIT_ROWS = [];

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

    function dpShowBaseFeeStatus(message, isError) {
        var el = document.getElementById('dp_base_fee_status');
        if (!el) return;
        el.textContent = String(message || '');
        el.style.color = isError ? '#b91c1c' : '#166534';
    }

    function dpShowCustomStatus(message, isError) {
        var el = document.getElementById('dp_custom_status');
        if (!el) return;
        el.textContent = String(message || '');
        el.style.color = isError ? '#b91c1c' : '#166534';
    }

    function dpNormalizeApplyMode(mode) {
        return String(mode || '').toLowerCase() === 'custom' ? 'custom' : 'all';
    }

    function dpApplyModeHintText(mode) {
        return mode === 'custom'
            ? 'الوضع الحالي: تطبيق مخصص (لن يتم توحيد الأسعار إلا باختيار صريح لاحقاً).'
            : 'الوضع الحالي: تطبيق على الكل (يمكنك توحيد المناطق النشطة بزر التطبيق).';
    }

    function dpSetApplyMode(mode) {
        var norm = dpNormalizeApplyMode(mode);
        var allCb = document.getElementById('dp_apply_mode_all');
        var customCb = document.getElementById('dp_apply_mode_custom');
        if (allCb) allCb.checked = norm === 'all';
        if (customCb) customCb.checked = norm === 'custom';
        DP_BASE_APPLY_MODE = norm;
        dpSyncApplyModeUi();
    }

    function dpCurrentApplyMode() {
        var allCb = document.getElementById('dp_apply_mode_all');
        var customCb = document.getElementById('dp_apply_mode_custom');
        if (customCb && customCb.checked) return 'custom';
        if (allCb && allCb.checked) return 'all';

        return dpNormalizeApplyMode(DP_BASE_APPLY_MODE);
    }

    function dpSyncApplyModeUi() {
        var mode = dpCurrentApplyMode();
        DP_BASE_APPLY_MODE = mode;
        var applyAllBtn = document.getElementById('dp_apply_all_btn');
        if (applyAllBtn) {
            applyAllBtn.disabled = !DP_READY || mode !== 'all';
            applyAllBtn.title = mode === 'all'
                ? ''
                : 'زر تطبيق الكل متاح فقط في نمط "تطبيق على الكل"';
        }
        var hint = document.getElementById('dp_apply_mode_hint');
        if (hint) {
            hint.textContent = dpApplyModeHintText(mode);
        }
        dpSyncCustomCardVisibility();
    }

    function dpBindApplyModeControls() {
        var allCb = document.getElementById('dp_apply_mode_all');
        var customCb = document.getElementById('dp_apply_mode_custom');
        if (!allCb || !customCb) {
            return;
        }
        allCb.addEventListener('change', function () {
            if (allCb.checked) {
                customCb.checked = false;
            } else if (!customCb.checked) {
                allCb.checked = true;
            }
            dpSyncApplyModeUi();
        });
        customCb.addEventListener('change', function () {
            if (customCb.checked) {
                allCb.checked = false;
            } else if (!allCb.checked) {
                customCb.checked = true;
            }
            dpSyncApplyModeUi();
        });
        dpSetApplyMode(DP_BASE_APPLY_MODE);
    }

    function dpActiveGovernoratesRows() {
        return (DP_GOVS || []).filter(function (row) {
            return (parseInt(row.is_active, 10) || 0) === 1;
        });
    }

    function dpActiveAreasByGovernorate(governorateId) {
        var gid = parseInt(governorateId, 10) || 0;
        if (gid <= 0) {
            return [];
        }
        return (DP_AREAS || []).filter(function (row) {
            return (parseInt(row.is_active, 10) || 0) === 1
                && (parseInt(row.governorate_id, 10) || 0) === gid;
        });
    }

    function dpRenderCustomGovernorates(selectedId) {
        var el = document.getElementById('dp_custom_governorate_id');
        if (!el) return;
        var selected = parseInt(selectedId, 10) || 0;
        var rows = dpActiveGovernoratesRows();
        el.innerHTML = '<option value="">اختر محافظة</option>';
        rows.forEach(function (row) {
            var id = parseInt(row.id, 10) || 0;
            if (id <= 0) return;
            var opt = document.createElement('option');
            opt.value = String(id);
            opt.textContent = row.name_ar || row.name_en || ('#' + id);
            if (id === selected) {
                opt.selected = true;
            }
            el.appendChild(opt);
        });
    }

    function dpRenderCustomAreas(governorateId, selectedIds) {
        var el = document.getElementById('dp_custom_area_ids');
        if (!el) return;
        var picked = {};
        (selectedIds || []).forEach(function (id) {
            picked[String(id)] = true;
        });
        var rows = dpActiveAreasByGovernorate(governorateId);
        el.innerHTML = '';
        rows.forEach(function (row) {
            var id = parseInt(row.id, 10) || 0;
            if (id <= 0) return;
            var isPending = parseInt(row.delivery_fee_pending, 10) === 1;
            var opt = document.createElement('option');
            opt.value = String(id);
            opt.selected = !!picked[String(id)];
            opt.textContent = (row.name_ar || row.name_en || ('#' + id)) + (isPending ? ' (بانتظار التحديد)' : '');
            el.appendChild(opt);
        });
        var hintEl = document.getElementById('dp_custom_area_hint');
        if (hintEl) {
            var pendingCount = rows.filter(function (row) {
                return parseInt(row.delivery_fee_pending, 10) === 1;
            }).length;
            if ((parseInt(governorateId, 10) || 0) <= 0) {
                hintEl.textContent = '';
            } else if (rows.length === 0) {
                hintEl.textContent = 'لا توجد مناطق نشطة داخل هذه المحافظة حالياً.';
            } else {
                hintEl.textContent = pendingCount > 0
                    ? ('عدد المناطق النشطة: ' + rows.length + ' (بانتظار التحديد: ' + pendingCount + ')')
                    : ('عدد المناطق النشطة داخل المحافظة: ' + rows.length);
            }
        }
    }

    function dpSelectedCustomAreaIds() {
        var el = document.getElementById('dp_custom_area_ids');
        if (!el) return [];
        var out = [];
        Array.prototype.forEach.call(el.options, function (opt) {
            if (!opt.selected) return;
            var id = parseInt(opt.value, 10) || 0;
            if (id > 0) {
                out.push(id);
            }
        });
        return out;
    }

    function dpOnCustomGovernorateChange() {
        var govEl = document.getElementById('dp_custom_governorate_id');
        var scopeCard = document.getElementById('dp_custom_scope_card');
        if (!govEl || !scopeCard) {
            return;
        }
        var govId = parseInt(govEl.value, 10) || 0;
        var hasGovernorate = govId > 0;
        scopeCard.style.display = hasGovernorate ? '' : 'none';
        if (!hasGovernorate) {
            dpRenderCustomAreas(0, []);
            var feeInputReset = document.getElementById('dp_custom_delivery_fee');
            if (feeInputReset) feeInputReset.value = '';
            return;
        }
        dpRenderCustomAreas(govId, []);
    }

    function resetCustomDeliveryFeeForm() {
        var govEl = document.getElementById('dp_custom_governorate_id');
        if (govEl) {
            govEl.value = '';
        }
        var feeEl = document.getElementById('dp_custom_delivery_fee');
        if (feeEl) {
            feeEl.value = '';
        }
        dpRenderCustomAreas(0, []);
        var scopeCard = document.getElementById('dp_custom_scope_card');
        if (scopeCard) {
            scopeCard.style.display = 'none';
        }
        dpShowCustomStatus('', false);
    }

    function dpSyncCustomCardVisibility() {
        var card = document.getElementById('dp_custom_apply_card');
        if (!card) return;
        var isCustom = dpCurrentApplyMode() === 'custom';
        card.style.display = isCustom ? '' : 'none';
        if (!isCustom) {
            resetCustomDeliveryFeeForm();
            return;
        }
        var govEl = document.getElementById('dp_custom_governorate_id');
        var currentGovId = govEl ? (parseInt(govEl.value, 10) || 0) : 0;
        dpRenderCustomGovernorates(currentGovId);
        dpOnCustomGovernorateChange();
    }

    function dpBindCustomControls() {
        var govEl = document.getElementById('dp_custom_governorate_id');
        if (govEl) {
            govEl.addEventListener('change', dpOnCustomGovernorateChange);
        }
        dpRenderCustomGovernorates(0);
        dpOnCustomGovernorateChange();
    }

    async function saveCustomDeliveryFeeGroup() {
        if (!DP_READY) {
            return;
        }
        if (dpCurrentApplyMode() !== 'custom') {
            dpShowCustomStatus('اختر نمط "تطبيق مخصص" أولاً.', true);
            return;
        }
        var govEl = document.getElementById('dp_custom_governorate_id');
        var feeEl = document.getElementById('dp_custom_delivery_fee');
        if (!govEl || !feeEl) {
            return;
        }
        var governorateId = parseInt(govEl.value, 10) || 0;
        if (governorateId <= 0) {
            dpShowCustomStatus('اختر المحافظة أولاً.', true);
            return;
        }
        var areaIds = dpSelectedCustomAreaIds();
        if (!areaIds.length) {
            dpShowCustomStatus('اختر منطقة واحدة على الأقل.', true);
            return;
        }
        var feeVal = dpParseMoney(feeEl.value);
        if (!Number.isFinite(feeVal) || feeVal <= 0) {
            dpShowCustomStatus('قيمة التوصيل المخصص يجب أن تكون أكبر من صفر.', true);
            return;
        }

        var payload = {
            action: 'save_custom_fee_group',
            governorate_id: governorateId,
            delivery_area_ids: areaIds,
            custom_delivery_fee: Number(dpRoundMoney(feeVal).toFixed(DP_MONEY_DECIMALS))
        };
        var res = await postJSON('/admin/api/delivery_promotions/manage.php', payload);
        if (!res || !res.success) {
            dpShowCustomStatus((res && res.message) ? res.message : 'تعذر حفظ التطبيق المخصص.', true);
            return;
        }

        resetCustomDeliveryFeeForm();
        dpShowCustomStatus(res.message || 'تم حفظ التطبيق المخصص.', false);
        try {
            await loadDeliveryPromotionTargets();
        } catch (e) {
            // loadDeliveryPromotionTargets already throws user-facing text; keep saved status.
        }
        DP_BASE_APPLY_MODE = 'custom';
        dpSyncApplyModeUi();
        await loadDeliveryFeeSummary();
    }

    function dpSummaryGovernorateName(row) {
        return row && (row.governorate_name_ar || row.governorate_name_en)
            ? (row.governorate_name_ar || row.governorate_name_en)
            : 'بدون محافظة';
    }

    function dpSummaryModeLabel(mode) {
        return mode === 'custom' ? 'تطبيق مخصص' : 'تطبيق على الكل';
    }

    function dpRenderFeeSummaryEmpty(message) {
        var tb = document.getElementById('dp_fee_summary_tbody');
        if (!tb) return;
        tb.innerHTML = '<tr><td colspan="6" class="muted">' + escDp(message || 'لا توجد بيانات') + '</td></tr>';
    }

    function dpBindFeeSummaryEditButtons() {
        var tb = document.getElementById('dp_fee_summary_tbody');
        if (!tb) return;
        tb.querySelectorAll('[data-dp-custom-edit]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var idx = parseInt(btn.getAttribute('data-dp-custom-edit'), 10) || -1;
                var row = idx >= 0 ? DP_CUSTOM_EDIT_ROWS[idx] : null;
                if (!row) {
                    return;
                }
                dpSetApplyMode('custom');
                var govEl = document.getElementById('dp_custom_governorate_id');
                if (govEl) {
                    govEl.value = String(parseInt(row.governorate_id, 10) || 0);
                }
                dpOnCustomGovernorateChange();
                dpRenderCustomAreas(row.governorate_id, row.area_ids || []);
                var feeEl = document.getElementById('dp_custom_delivery_fee');
                if (feeEl) {
                    feeEl.value = row.pending ? '' : dpFormatMoney(row.delivery_fee || 0);
                }
                dpShowCustomStatus(
                    row.pending
                        ? 'تم تحميل مناطق بانتظار التحديد. أدخل قيمة التوصيل ثم احفظ.'
                        : 'تم تحميل المجموعة للتعديل. حدّد التعديلات ثم احفظ.',
                    false
                );
                var customCard = document.getElementById('dp_custom_apply_card');
                if (customCard && typeof customCard.scrollIntoView === 'function') {
                    customCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    }

    function dpRenderFeeSummary(data) {
        var tb = document.getElementById('dp_fee_summary_tbody');
        if (!tb) return;
        var mode = dpNormalizeApplyMode(data && data.apply_mode);
        var defaultFee = dpRoundMoney(dpParseMoney(data && data.default_delivery_fee != null ? data.default_delivery_fee : DP_BASE_FEE));
        var governorates = Array.isArray(data && data.governorates) ? data.governorates : [];
        DP_CUSTOM_EDIT_ROWS = [];
        tb.innerHTML = '';
        if (!governorates.length) {
            dpRenderFeeSummaryEmpty('لا توجد بيانات محافظات/مناطق متاحة.');
            return;
        }
        governorates.forEach(function (gov) {
            var govName = dpSummaryGovernorateName(gov);
            var inactiveCount = parseInt(gov.inactive_count, 10) || 0;
            var pendingCount = parseInt(gov.pending_count, 10) || 0;
            var pricedActiveCount = Math.max(0, (parseInt(gov.active_count, 10) || 0) - pendingCount);
            var pendingAreaIds = Array.isArray(gov.pending_area_ids) ? gov.pending_area_ids : [];
            if (mode === 'all') {
                var trAll = document.createElement('tr');
                trAll.innerHTML =
                    '<td>' + escDp(dpSummaryModeLabel(mode)) + '</td>' +
                    '<td>' + escDp(govName) + '</td>' +
                    '<td>' + escDp(String(pricedActiveCount)) + '</td>' +
                    '<td dir="ltr">' + escDp(dpFormatMoney(defaultFee)) + '</td>' +
                    '<td>' + escDp(String(inactiveCount)) + '</td>' +
                    '<td class="muted">—</td>';
                tb.appendChild(trAll);
                if (pendingCount > 0) {
                    var pendingIdxAll = DP_CUSTOM_EDIT_ROWS.length;
                    DP_CUSTOM_EDIT_ROWS.push({
                        governorate_id: parseInt(gov.governorate_id, 10) || 0,
                        delivery_fee: 0,
                        pending: true,
                        area_ids: pendingAreaIds
                    });
                    var trPendingAll = document.createElement('tr');
                    trPendingAll.style.background = '#fee2e2';
                    trPendingAll.innerHTML =
                        '<td>' + escDp(dpSummaryModeLabel('custom')) + '</td>' +
                        '<td>' + escDp(govName) + '</td>' +
                        '<td>' + escDp(String(pendingCount)) + '</td>' +
                        '<td><span style="color:#991b1b;font-weight:700;">بانتظار التحديد</span></td>' +
                        '<td>' + escDp(String(inactiveCount)) + '</td>' +
                        '<td><button type="button" class="btn-secondary" data-dp-custom-edit="' + escDp(String(pendingIdxAll)) + '">تحديد السعر</button></td>';
                    tb.appendChild(trPendingAll);
                }
                return;
            }

            var groups = Array.isArray(gov.custom_groups) ? gov.custom_groups : [];
            if (!groups.length) {
                var trEmpty = document.createElement('tr');
                trEmpty.innerHTML =
                    '<td>' + escDp(dpSummaryModeLabel(mode)) + '</td>' +
                    '<td>' + escDp(govName) + '</td>' +
                    '<td>' + escDp(String(pricedActiveCount)) + '</td>' +
                    '<td class="muted">—</td>' +
                    '<td>' + escDp(String(inactiveCount)) + '</td>' +
                    '<td class="muted">لا توجد مجموعات محفوظة</td>';
                tb.appendChild(trEmpty);
            } else {
                groups.forEach(function (group) {
                    var editIdx = DP_CUSTOM_EDIT_ROWS.length;
                    DP_CUSTOM_EDIT_ROWS.push({
                        governorate_id: parseInt(gov.governorate_id, 10) || 0,
                        delivery_fee: dpRoundMoney(dpParseMoney(group && group.delivery_fee != null ? group.delivery_fee : 0)),
                        pending: false,
                        area_ids: Array.isArray(group && group.area_ids) ? group.area_ids : []
                    });
                    var areaCount = parseInt(group && group.area_count, 10) || 0;
                    var fee = dpRoundMoney(dpParseMoney(group && group.delivery_fee != null ? group.delivery_fee : 0));
                    var tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td>' + escDp(dpSummaryModeLabel(mode)) + '</td>' +
                        '<td>' + escDp(govName) + '</td>' +
                        '<td>' + escDp(String(areaCount)) + '</td>' +
                        '<td dir="ltr">' + escDp(dpFormatMoney(fee)) + '</td>' +
                        '<td>' + escDp(String(inactiveCount)) + '</td>' +
                        '<td><button type="button" class="btn-secondary" data-dp-custom-edit="' + escDp(String(editIdx)) + '">تعديل</button></td>';
                    tb.appendChild(tr);
                });
            }
            if (pendingCount > 0) {
                var pendingIdx = DP_CUSTOM_EDIT_ROWS.length;
                DP_CUSTOM_EDIT_ROWS.push({
                    governorate_id: parseInt(gov.governorate_id, 10) || 0,
                    delivery_fee: 0,
                    pending: true,
                    area_ids: pendingAreaIds
                });
                var trPending = document.createElement('tr');
                trPending.style.background = '#fee2e2';
                trPending.innerHTML =
                    '<td>' + escDp(dpSummaryModeLabel(mode)) + '</td>' +
                    '<td>' + escDp(govName) + '</td>' +
                    '<td>' + escDp(String(pendingCount)) + '</td>' +
                    '<td><span style="color:#991b1b;font-weight:700;">بانتظار التحديد</span></td>' +
                    '<td>' + escDp(String(inactiveCount)) + '</td>' +
                    '<td><button type="button" class="btn-secondary" data-dp-custom-edit="' + escDp(String(pendingIdx)) + '">تحديد السعر</button></td>';
                tb.appendChild(trPending);
            }
        });
        dpBindFeeSummaryEditButtons();
    }

    async function loadDeliveryFeeSummary() {
        var res = await postJSON('/admin/api/delivery_promotions/manage.php', { action: 'get_fee_summary' });
        if (!res || !res.success) {
            dpRenderFeeSummaryEmpty((res && res.message) ? res.message : 'تعذر تحميل ملخص القيم المحفوظة.');
            return;
        }
        dpRenderFeeSummary(res.data || {});
    }

    function dpSetBaseFeeForm(data) {
        var fee = dpRoundMoney(dpParseMoney(data && data.default_delivery_fee != null ? data.default_delivery_fee : 0));
        DP_BASE_FEE = fee;
        DP_BASE_APPLY_MODE = dpNormalizeApplyMode(data && data.delivery_fee_apply_mode);
        var input = document.getElementById('dp_base_delivery_fee');
        if (input) {
            input.value = dpFormatMoney(fee);
        }
        dpSetApplyMode(DP_BASE_APPLY_MODE);
    }

    async function loadPromotionBaseFee() {
        var res = await postJSON('/admin/api/delivery_promotions/manage.php', { action: 'get_base_fee' });
        if (!res || !res.success) {
            dpShowBaseFeeStatus((res && res.message) ? res.message : 'تعذر تحميل القيمة الأساسية', true);
            return;
        }
        dpSetBaseFeeForm(res.data || {});
        dpShowBaseFeeStatus('', false);
    }

    async function savePromotionBaseFee(applyActiveAreas) {
        var feeEl = document.getElementById('dp_base_delivery_fee');
        if (!feeEl) return;
        var feeVal = dpParseMoney(feeEl.value);
        if (!Number.isFinite(feeVal) || feeVal < 0) {
            dpShowBaseFeeStatus('قيمة التوصيل الأساسية غير صحيحة', true);
            return;
        }
        var applyMode = dpCurrentApplyMode();
        if (applyActiveAreas) {
            if (applyMode !== 'all') {
                dpShowBaseFeeStatus('لا يمكن توحيد المناطق إلا عند اختيار نمط "تطبيق على الكل"', true);
                return;
            }
            if (!window.confirm('سيتم تطبيق القيمة الأساسية الحالية على كل المناطق النشطة. متابعة؟')) {
                return;
            }
        }
        var payload = {
            action: 'save_base_fee',
            default_delivery_fee: Number(dpRoundMoney(feeVal).toFixed(DP_MONEY_DECIMALS)),
            apply_active_areas: applyActiveAreas ? 1 : 0,
            delivery_fee_apply_mode: applyMode
        };
        var res = await postJSON('/admin/api/delivery_promotions/manage.php', payload);
        if (!res || !res.success) {
            dpShowBaseFeeStatus((res && res.message) ? res.message : 'تعذر حفظ القيمة الأساسية', true);
            return;
        }
        dpSetBaseFeeForm(res.data || {});
        dpSyncApplyModeUi();
        dpShowBaseFeeStatus(
            res.message || (applyActiveAreas ? 'تم تحديث قيمة التوصيل في المناطق النشطة' : 'تم حفظ القيمة الأساسية'),
            false
        );
        await loadDeliveryFeeSummary();
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
        var customGovEl = document.getElementById('dp_custom_governorate_id');
        var currentCustomGovId = customGovEl ? (parseInt(customGovEl.value, 10) || 0) : 0;
        dpRenderCustomGovernorates(currentCustomGovId);
        dpOnCustomGovernorateChange();
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
    window.savePromotionBaseFee = savePromotionBaseFee;
    window.saveCustomDeliveryFeeGroup = saveCustomDeliveryFeeGroup;
    window.resetCustomDeliveryFeeForm = resetCustomDeliveryFeeForm;

    function initDeliveryPromotionsPage() {
        var discountTypeEl = document.getElementById('dp_discount_type');
        if (!discountTypeEl) {
            return;
        }
        dpBindCustomControls();
        dpBindApplyModeControls();
        ocpBindAlwaysOn('dp');
        discountTypeEl.addEventListener('change', dpApplyDiscountTypeUi);
        dpFillDiscountTypeOptions('amount');
        resetDeliveryPromotionForm();
        if (!DP_READY) {
            dpSyncApplyModeUi();
            loadDeliveryFeeSummary().catch(function () {
                dpRenderFeeSummaryEmpty('تعذر تحميل الملخص.');
            });
            loadDeliveryPromotionAlwaysOnHistory().catch(function () {
                var tb = document.getElementById('dp_history_tbody');
                if (tb) tb.innerHTML = '<tr><td colspan="6" class="muted">تعذر تحميل السجل.</td></tr>';
            });
            return;
        }
        loadPromotionBaseFee().catch(function (e) {
            dpShowBaseFeeStatus((e && e.message) ? e.message : String(e), true);
        });
        loadDeliveryPromotionTargets().catch(function (e) {
            alert(e.message || String(e));
        });
        loadDeliveryFeeSummary().catch(function () {
            dpRenderFeeSummaryEmpty('تعذر تحميل الملخص.');
        });
        loadDeliveryPromotionAlwaysOnHistory().catch(function () {
            var tb = document.getElementById('dp_history_tbody');
            if (tb) tb.innerHTML = '<tr><td colspan="6" class="muted">تعذر تحميل السجل.</td></tr>';
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

