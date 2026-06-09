<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/delivery_areas.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$hasAreasTable = orange_table_exists($pdo, 'delivery_areas');
$hasGovTable = orange_delivery_governorates_table_exists($pdo);
$adminCountries = orange_countries_admin_list($pdo);
$adminCountryId = orange_admin_context_country_id($pdo);
$adminCountryRow = orange_country_row_by_id($pdo, $adminCountryId, false);
$activeAreasCount = $hasAreasTable ? orange_delivery_areas_count_active($pdo, $adminCountryId) : 0;
$daGovernoratesList = ($hasGovTable && $adminCountryId > 0)
    ? orange_delivery_governorates_admin_list($pdo, $adminCountryId)
    : [];
$daAreasList = $hasAreasTable
    ? orange_delivery_areas_admin_list($pdo, $adminCountryId > 0 ? $adminCountryId : null)
    : [];
$daNextSortOrder = $hasGovTable
    ? ''
    : (string) (int) orange_delivery_areas_next_sort_order($pdo, $adminCountryId, 0);
?>
<div class="page-title">
    <h1>محافظات ومناطق التوصيل</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>
<?php if ($activeAreasCount === 0 && $hasAreasTable): ?>
<p class="card-hint" style="margin-top:0.5rem;color:#b45309;">لا توجد مناطق توصيل نشطة لهذه الدولة — العملاء لن يكملوا الطلب حتى تُفعَّل محافظة ومنطقة على الأقل.</p>
<?php endif; ?>

<?php if (!$hasAreasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>delivery_areas</code> غير جاهز.</div>
</div>
<?php endif; ?>

<?php if (count($adminCountries) > 0): ?>
<div class="card">
    <h3>الدولة</h3>
    <label for="da_admin_country">الدولة</label>
    <select id="da_admin_country" onchange="daSwitchAdminCountry(this.value)" style="max-width:320px;">
        <?php foreach ($adminCountries as $cRow): ?>
            <?php
            $cid = (int) ($cRow['id'] ?? 0);
            $code = htmlspecialchars((string) ($cRow['code'] ?? ''), ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars(trim((string) ($cRow['name_ar'] ?? '') . ' (' . (string) ($cRow['code'] ?? '') . ')'), ENT_QUOTES, 'UTF-8');
            ?>
        <option value="<?php echo $code; ?>"<?php echo $cid === $adminCountryId ? ' selected' : ''; ?>><?php echo $label; ?></option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>

<?php if ($hasGovTable): ?>
<div class="card">
    <h3>إضافة / تعديل محافظة</h3>
    <input type="hidden" id="dg_id" value="0">
    <input type="hidden" id="dg_country_id" value="<?php echo (int) $adminCountryId; ?>">
    <div class="form-grid da-gov-form-grid">
        <div class="da-gov-ar">
            <label for="dg_name_ar">اسم المحافظة (عربي) <span style="color:#b45309;">*</span></label>
            <input type="text" id="dg_name_ar" maxlength="191" autocomplete="off">
        </div>
        <div class="da-gov-en">
            <label for="dg_name_en">English</label>
            <input type="text" id="dg_name_en" maxlength="191" autocomplete="off" lang="en" dir="ltr">
        </div>
        <div class="da-gov-sort">
            <label for="dg_sort_order">الترتيب</label>
            <input type="number" id="dg_sort_order" class="admin-sort-field admin-sort-field--muted"
                value="<?php echo (int) orange_delivery_governorates_next_sort_order($pdo, $adminCountryId); ?>"
                disabled tabindex="-1" aria-readonly="true">
        </div>
        <div class="da-gov-active">
            <label for="dg_is_active" style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:1.4rem;">
                <input type="checkbox" id="dg_is_active" checked> محافظة نشطة
            </label>
        </div>
    </div>
    <div class="admin-form-actions" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:12px;">
        <button type="button" onclick="saveGovernorate()">حفظ المحافظة</button>
        <button type="button" class="btn-secondary" onclick="translateGovernorateFromAr()">ترجمة تلقائية من العربي</button>
        <button type="button" class="btn-secondary" onclick="resetGovernorateForm()">محافظة جديدة</button>
    </div>
</div>

<div class="card">
    <h3>قائمة المحافظات</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>عربي</th>
                    <th>English</th>
                    <th>ترتيب</th>
                    <th>نشطة</th>
                    <th>عدد المناطق</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="dg_tbody">
                <?php foreach ($daGovernoratesList as $gRow): ?>
                <?php
                $gid = (int) ($gRow['id'] ?? 0);
                $gActive = (int) ($gRow['is_active'] ?? 0) === 1;
                ?>
                <tr>
                    <td><?php echo $gid; ?></td>
                    <td><?php echo htmlspecialchars((string) ($gRow['name_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars((string) ($gRow['name_en'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int) ($gRow['sort_order'] ?? 0); ?></td>
                    <td><?php echo $gActive ? 'نعم' : 'لا'; ?></td>
                    <td><?php echo (int) ($gRow['areas_count'] ?? 0); ?></td>
                    <td><button type="button" class="btn-secondary" data-dg-edit="<?php echo $gid; ?>">تعديل</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل منطقة توصيل</h3>
    <input type="hidden" id="da_id" value="0">
    <input type="hidden" id="da_country_id" value="<?php echo (int) $adminCountryId; ?>">
    <div class="form-grid da-area-form-grid">
        <?php if ($hasGovTable): ?>
        <div class="da-area-gov">
            <label for="da_governorate_id">المحافظة <span style="color:#b45309;">*</span></label>
            <select id="da_governorate_id" required>
                <option value="">اختر محافظة</option>
                <?php foreach ($daGovernoratesList as $gRow): ?>
                <?php
                $gid = (int) ($gRow['id'] ?? 0);
                $gLabel = trim((string) ($gRow['name_ar'] ?? ''));
                if ($gLabel === '') {
                    $gLabel = trim((string) ($gRow['name_en'] ?? ''));
                }
                if ($gLabel === '') {
                    $gLabel = (string) $gid;
                }
                if ((int) ($gRow['is_active'] ?? 0) !== 1) {
                    $gLabel .= ' (غير نشطة)';
                }
                ?>
                <option value="<?php echo $gid; ?>"><?php echo htmlspecialchars($gLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="da-area-ar">
            <label for="da_name_ar">اسم المنطقة (عربي) <span style="color:#b45309;">*</span></label>
            <input type="text" id="da_name_ar" maxlength="191" autocomplete="off">
        </div>
        <div class="da-area-en">
            <label for="da_name_en">English</label>
            <input type="text" id="da_name_en" maxlength="191" autocomplete="off" lang="en" dir="ltr">
        </div>
        <div class="da-area-sort">
            <label for="da_sort_order">الترتيب</label>
            <input type="number" id="da_sort_order" class="admin-sort-field admin-sort-field--muted"
                value="<?php echo htmlspecialchars((string) $daNextSortOrder, ENT_QUOTES, 'UTF-8'); ?>"
                disabled tabindex="-1" aria-readonly="true">
        </div>
        <div class="da-area-active">
            <label for="da_is_active" style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:1.4rem;">
                <input type="checkbox" id="da_is_active" checked> منطقة توصيل
            </label>
        </div>
    </div>
    <div class="admin-form-actions" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:12px;">
        <button type="button" onclick="saveDeliveryArea()" <?php echo !$hasAreasTable ? 'disabled' : ''; ?>>حفظ المنطقة</button>
        <button type="button" class="btn-secondary" onclick="translateDeliveryAreaFromAr()">ترجمة تلقائية من العربي</button>
        <button type="button" class="btn-secondary" onclick="resetDeliveryAreaForm()">منطقة جديدة</button>
    </div>
</div>

<div class="card">
    <div class="da-areas-list-head">
        <h3>قائمة المناطق</h3>
        <?php if ($hasGovTable): ?>
        <label for="da_list_all" class="da-list-all-label">
            <input type="checkbox" id="da_list_all" checked disabled>
            الكل
        </label>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <?php if ($hasGovTable): ?><th>المحافظة</th><?php endif; ?>
                    <th>عربي</th>
                    <th>English</th>
                    <th>ترتيب</th>
                    <th>منطقة توصيل</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="da_tbody">
                <?php foreach ($daAreasList as $aRow): ?>
                <?php
                $aid = (int) ($aRow['id'] ?? 0);
                $aActive = (int) ($aRow['is_active'] ?? 0) === 1;
                $govLabel = trim((string) ($aRow['governorate_name_ar'] ?? ''));
                if ($govLabel === '') {
                    $govLabel = trim((string) ($aRow['governorate_name_en'] ?? ''));
                }
                if ($govLabel === '') {
                    $govLabel = '—';
                }
                ?>
                <tr>
                    <td><?php echo $aid; ?></td>
                    <?php if ($hasGovTable): ?>
                    <td><?php echo htmlspecialchars($govLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                    <?php endif; ?>
                    <td><?php echo htmlspecialchars((string) ($aRow['name_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars((string) ($aRow['name_en'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int) ($aRow['sort_order'] ?? 0); ?></td>
                    <td><?php echo $aActive ? 'منطقة توصيل' : 'غير متاحة للتوصيل'; ?></td>
                    <td><button type="button" class="btn-secondary" data-da-edit="<?php echo $aid; ?>">تعديل</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
    .da-gov-form-grid,
    .da-area-form-grid {
        display: grid;
        gap: 12px 16px;
        align-items: end;
    }
    .da-gov-form-grid {
        grid-template-columns: 1fr 1fr;
        grid-template-areas:
            "ar en"
            "sort active";
    }
    .da-gov-ar { grid-area: ar; }
    .da-gov-en { grid-area: en; }
    .da-gov-sort { grid-area: sort; }
    .da-gov-active { grid-area: active; }
    .da-area-form-grid {
        grid-template-columns: 1fr 1fr;
        grid-template-areas:
            "gov gov"
            "ar en"
            "sort active";
    }
    .da-area-gov { grid-area: gov; }
    .da-area-ar { grid-area: ar; }
    .da-area-en { grid-area: en; }
    .da-area-sort { grid-area: sort; }
    .da-area-active { grid-area: active; }
    .da-areas-list-head {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 12px 20px;
        margin-bottom: 12px;
    }
    .da-areas-list-head h3 {
        margin: 0;
    }
    .da-list-all-label {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        margin: 0;
        user-select: none;
    }
    .da-list-all-label input:disabled {
        cursor: not-allowed;
    }
    @media (max-width: 720px) {
        .da-gov-form-grid,
        .da-area-form-grid {
            grid-template-columns: 1fr;
            grid-template-areas: unset;
        }
        .da-gov-ar, .da-gov-en, .da-gov-sort, .da-gov-active,
        .da-area-gov, .da-area-ar, .da-area-en, .da-area-sort, .da-area-active {
            grid-area: unset;
        }
    }
</style>

<script>
let daArTimer = null;
let daEnTimer = null;
let dgArTimer = null;
let dgEnTimer = null;
let daGovernoratesCache = <?php echo json_encode($daGovernoratesList, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
let daDeliveryAreasCache = <?php echo json_encode(array_values($daAreasList), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var dgSortStep = <?php echo (int) orange_delivery_governorates_sort_order_step(); ?>;
var daSortStep = dgSortStep;

function dgComputeNextSort() {
    var max = 0;
    daGovernoratesCache.forEach(function (g) {
        var so = parseInt(g.sort_order, 10) || 0;
        if (so > max) max = so;
    });
    return max <= 0 ? dgSortStep : max + dgSortStep;
}

function refreshDgSortPreview() {
    var idEl = document.getElementById('dg_id');
    var sortEl = document.getElementById('dg_sort_order');
    if (!idEl || !sortEl) return;
    if (parseInt(idEl.value, 10) > 0) return;
    sortEl.value = String(dgComputeNextSort());
}

function daSelectedGovernorateId() {
    var sel = document.getElementById('da_governorate_id');
    if (!sel) return 0;
    return parseInt(sel.value, 10) || 0;
}

function daSyncListAllCheckbox() {
    var allCb = document.getElementById('da_list_all');
    if (!allCb) return;
    var govId = daSelectedGovernorateId();
    if (govId <= 0) {
        allCb.checked = true;
        allCb.disabled = true;
    } else {
        allCb.disabled = false;
    }
}

function daAreasForList() {
    var rows = daDeliveryAreasCache || [];
    var allCb = document.getElementById('da_list_all');
    if (!allCb || allCb.checked) {
        return rows;
    }
    var govId = daSelectedGovernorateId();
    if (govId <= 0) {
        return rows;
    }
    return rows.filter(function (r) {
        return parseInt(r.governorate_id, 10) === govId;
    });
}

var daHasGovCol = <?php echo $hasGovTable ? 'true' : 'false'; ?>;

function renderDeliveryAreasTable() {
    var tb = document.getElementById('da_tbody');
    if (!tb) return;
    var rows = daAreasForList();
    tb.innerHTML = '';
    rows.forEach(function (r) {
        var tr = document.createElement('tr');
        var canDeliver = parseInt(r.is_active, 10) === 1;
        var html = '<td>' + escHtml(String(r.id)) + '</td>';
        if (daHasGovCol) {
            html += '<td>' + escHtml(String(r.governorate_name_ar || r.governorate_name_en || '—')) + '</td>';
        }
        html +=
            '<td>' + escHtml(String(r.name_ar || '')) + '</td>' +
            '<td dir="ltr">' + escHtml(String(r.name_en || '')) + '</td>' +
            '<td>' + escHtml(String(r.sort_order != null ? r.sort_order : '')) + '</td>' +
            '<td>' + (canDeliver ? 'منطقة توصيل' : 'غير متاحة للتوصيل') + '</td>' +
            '<td><button type="button" class="btn-secondary" data-da-edit="' + escAttr(String(r.id)) + '">تعديل</button></td>';
        tr.innerHTML = html;
        tb.appendChild(tr);
    });
    bindDeliveryAreaEditButtons();
}

function onDaGovernorateChange() {
    daSyncListAllCheckbox();
    refreshDaSortPreview();
    renderDeliveryAreasTable();
}

function daComputeNextSort() {
    var govId = daSelectedGovernorateId();
    var max = 0;
    daDeliveryAreasCache.forEach(function (r) {
        if (govId > 0 && parseInt(r.governorate_id, 10) !== govId) return;
        var so = parseInt(r.sort_order, 10) || 0;
        if (so > max) max = so;
    });
    return max <= 0 ? daSortStep : max + daSortStep;
}

function refreshDaSortPreview() {
    var idEl = document.getElementById('da_id');
    var sortEl = document.getElementById('da_sort_order');
    if (!idEl || !sortEl) return;
    if (parseInt(idEl.value, 10) > 0) return;
    var govId = daSelectedGovernorateId();
    var hasGovSel = !!document.getElementById('da_governorate_id');
    if (hasGovSel && govId <= 0) {
        sortEl.value = '';
        return;
    }
    sortEl.value = String(daComputeNextSort());
}

function daCountryId() {
    return parseInt(document.getElementById('da_country_id').value, 10) || 0;
}

function daSwitchAdminCountry(code) {
    if (!code) return;
    var u = new URL(window.location.href);
    u.searchParams.set('page', 'delivery_areas');
    u.searchParams.set('admin_country', String(code));
    window.location.href = u.pathname + '?' + u.searchParams.toString();
}

function escHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/"/g, '&quot;');
}
function escAttr(s) {
    return String(s).replace(/"/g, '&quot;');
}

async function translateNames(arId, enId, opts) {
    const silent = !!(opts && opts.silent);
    const forceFromArabic = !!(opts && opts.forceFromArabic);
    try {
        const res = await postJSON('/admin/api/translate/names.php', {
            name_ar: document.getElementById(arId).value.trim(),
            name_en: forceFromArabic ? '' : document.getElementById(enId).value.trim()
        });
        if (!res || !res.success) {
            if (!silent) alert((res && res.message) ? res.message : 'فشل الترجمة');
            return;
        }
        const t = res.translations || {};
        if (t.name_en) document.getElementById(enId).value = t.name_en;
    } catch (e) {
        if (!silent) alert('فشل طلب الترجمة');
    }
}

function resetGovernorateForm() {
    document.getElementById('dg_id').value = '0';
    document.getElementById('dg_name_ar').value = '';
    document.getElementById('dg_name_en').value = '';
    document.getElementById('dg_is_active').checked = true;
    refreshDgSortPreview();
}

function editGovernorate(row) {
    document.getElementById('dg_id').value = String(row.id != null ? row.id : 0);
    document.getElementById('dg_name_ar').value = row.name_ar || '';
    document.getElementById('dg_name_en').value = row.name_en || '';
    document.getElementById('dg_sort_order').value = String(row.sort_order != null ? row.sort_order : 0);
    document.getElementById('dg_is_active').checked = parseInt(row.is_active, 10) === 1;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function loadGovernorates() {
    const tb = document.getElementById('dg_tbody');
    if (!tb) return;
    const res = await postJSON('/admin/api/delivery_areas/manage.php', {
        action: 'list_governorates',
        country_id: daCountryId()
    });
    if (!res || !res.success) {
        if (res && res.message) {
            alert(res.message);
        }
        bindGovernorateEditButtons();
        refreshDgSortPreview();
        refreshDaSortPreview();
        return;
    }
    daGovernoratesCache = res.data || [];
    if (daGovernoratesCache.length === 0 && tb.querySelector('tr')) {
        bindGovernorateEditButtons();
        refreshDgSortPreview();
        refreshDaSortPreview();
        return;
    }
    tb.innerHTML = '';
    const sel = document.getElementById('da_governorate_id');
    if (sel) {
        const cur = sel.value;
        sel.innerHTML = '<option value="">اختر محافظة</option>';
        daGovernoratesCache.forEach(function (g) {
            const opt = document.createElement('option');
            opt.value = String(g.id);
            const label = String(g.name_ar || g.name_en || g.id);
            opt.textContent = label + (parseInt(g.is_active, 10) === 1 ? '' : ' (غير نشطة)');
            sel.appendChild(opt);
        });
        if (cur && daGovernoratesCache.some(function (g) { return String(g.id) === String(cur); })) {
            sel.value = cur;
        } else {
            sel.value = '';
        }
    }
    daGovernoratesCache.forEach(function (g) {
        const tr = document.createElement('tr');
        const active = parseInt(g.is_active, 10) === 1;
        tr.innerHTML =
            '<td>' + escHtml(String(g.id)) + '</td>' +
            '<td>' + escHtml(String(g.name_ar || '')) + '</td>' +
            '<td dir="ltr">' + escHtml(String(g.name_en || '')) + '</td>' +
            '<td>' + escHtml(String(g.sort_order != null ? g.sort_order : '')) + '</td>' +
            '<td>' + (active ? 'نعم' : 'لا') + '</td>' +
            '<td>' + escHtml(String(g.areas_count != null ? g.areas_count : '0')) + '</td>' +
            '<td><button type="button" class="btn-secondary" data-dg-edit="' + escAttr(String(g.id)) + '">تعديل</button></td>';
        tb.appendChild(tr);
    });
    bindGovernorateEditButtons();
    refreshDgSortPreview();
    refreshDaSortPreview();
}

function bindGovernorateEditButtons() {
    const tb = document.getElementById('dg_tbody');
    if (!tb) return;
    tb.querySelectorAll('[data-dg-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = parseInt(btn.getAttribute('data-dg-edit'), 10);
            const row = daGovernoratesCache.find(function (x) { return parseInt(x.id, 10) === id; });
            if (row) editGovernorate(row);
        });
    });
}

async function saveGovernorate() {
    const res = await postJSON('/admin/api/delivery_areas/manage.php', {
        action: 'save_governorate',
        id: parseInt(document.getElementById('dg_id').value, 10) || 0,
        country_id: daCountryId(),
        name_ar: document.getElementById('dg_name_ar').value.trim(),
        name_en: document.getElementById('dg_name_en').value.trim(),
        is_active: document.getElementById('dg_is_active').checked ? 1 : 0
    });
    alert(res.message || (res.success ? 'تم' : 'فشل'));
    if (res.success) {
        resetGovernorateForm();
        loadGovernorates();
        loadDeliveryAreas();
    }
}

function resetDeliveryAreaForm() {
    document.getElementById('da_id').value = '0';
    document.getElementById('da_name_ar').value = '';
    document.getElementById('da_name_en').value = '';
    document.getElementById('da_is_active').checked = true;
    const sel = document.getElementById('da_governorate_id');
    if (sel) sel.value = '';
    daSyncListAllCheckbox();
    refreshDaSortPreview();
    renderDeliveryAreasTable();
}

function editDeliveryArea(row) {
    document.getElementById('da_id').value = String(row.id != null ? row.id : 0);
    document.getElementById('da_name_ar').value = row.name_ar || '';
    document.getElementById('da_name_en').value = row.name_en || '';
    document.getElementById('da_sort_order').value = String(row.sort_order != null ? row.sort_order : 0);
    document.getElementById('da_is_active').checked = parseInt(row.is_active, 10) === 1;
    const sel = document.getElementById('da_governorate_id');
    if (sel && row.governorate_id) {
        sel.value = String(row.governorate_id);
    }
    daSyncListAllCheckbox();
    refreshDaSortPreview();
    document.querySelector('.da-area-form-grid').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function loadDeliveryAreas() {
    const tb = document.getElementById('da_tbody');
    if (!tb) return;
    const res = await postJSON('/admin/api/delivery_areas/manage.php', {
        action: 'list',
        country_id: daCountryId()
    });
    if (!res || !res.success) {
        if (res && res.message) {
            alert(res.message);
        }
        bindDeliveryAreaEditButtons();
        refreshDaSortPreview();
        return;
    }
    daDeliveryAreasCache = res.data || [];
    if (daDeliveryAreasCache.length === 0 && tb.querySelector('tr')) {
        daSyncListAllCheckbox();
        bindDeliveryAreaEditButtons();
        refreshDaSortPreview();
        return;
    }
    daSyncListAllCheckbox();
    renderDeliveryAreasTable();
    refreshDaSortPreview();
}

function bindDeliveryAreaEditButtons() {
    const tb = document.getElementById('da_tbody');
    if (!tb) return;
    tb.querySelectorAll('[data-da-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = parseInt(btn.getAttribute('data-da-edit'), 10);
            const row = daDeliveryAreasCache.find(function (x) { return parseInt(x.id, 10) === id; });
            if (row) editDeliveryArea(row);
        });
    });
}

async function saveDeliveryArea() {
    const govEl = document.getElementById('da_governorate_id');
    const payload = {
        action: 'save',
        id: parseInt(document.getElementById('da_id').value, 10) || 0,
        country_id: daCountryId(),
        name_ar: document.getElementById('da_name_ar').value.trim(),
        name_en: document.getElementById('da_name_en').value.trim(),
        is_active: document.getElementById('da_is_active').checked ? 1 : 0
    };
    if (govEl) {
        payload.governorate_id = parseInt(govEl.value, 10) || 0;
        if (!payload.governorate_id) {
            alert('اختر المحافظة');
            return;
        }
    }
    if (!payload.name_ar) {
        alert('اسم المنطقة بالعربي مطلوب');
        return;
    }
    const res = await postJSON('/admin/api/delivery_areas/manage.php', payload);
    alert(res.message || (res.success ? 'تم' : 'فشل'));
    if (res.success) {
        resetDeliveryAreaForm();
        loadGovernorates();
        loadDeliveryAreas();
    }
}

function scheduleDaFromAr() {
    const ar = document.getElementById('da_name_ar').value.trim();
    if (!ar) {
        document.getElementById('da_name_en').value = '';
        return;
    }
    clearTimeout(daArTimer);
    daArTimer = setTimeout(function () {
        translateNames('da_name_ar', 'da_name_en', { silent: true, forceFromArabic: true });
    }, 700);
}
function scheduleDaFromEn() {
    const en = document.getElementById('da_name_en').value.trim();
    if (!en) return;
    clearTimeout(daEnTimer);
    daEnTimer = setTimeout(function () {
        translateNames('da_name_ar', 'da_name_en', { silent: true, forceFromArabic: false });
    }, 600);
}
async function translateDeliveryAreaFromAr() {
    await translateNames('da_name_ar', 'da_name_en', { silent: false, forceFromArabic: true });
}

function scheduleDgFromAr() {
    const el = document.getElementById('dg_name_ar');
    if (!el) return;
    const ar = el.value.trim();
    if (!ar) {
        document.getElementById('dg_name_en').value = '';
        return;
    }
    clearTimeout(dgArTimer);
    dgArTimer = setTimeout(function () {
        translateNames('dg_name_ar', 'dg_name_en', { silent: true, forceFromArabic: true });
    }, 700);
}
function scheduleDgFromEn() {
    const el = document.getElementById('dg_name_en');
    if (!el) return;
    const en = el.value.trim();
    if (!en) return;
    clearTimeout(dgEnTimer);
    dgEnTimer = setTimeout(function () {
        translateNames('dg_name_ar', 'dg_name_en', { silent: true, forceFromArabic: false });
    }, 600);
}
async function translateGovernorateFromAr() {
    await translateNames('dg_name_ar', 'dg_name_en', { silent: false, forceFromArabic: true });
}

document.getElementById('da_name_ar').addEventListener('input', scheduleDaFromAr);
document.getElementById('da_name_en').addEventListener('input', scheduleDaFromEn);
const dgAr = document.getElementById('dg_name_ar');
const dgEn = document.getElementById('dg_name_en');
if (dgAr) dgAr.addEventListener('input', scheduleDgFromAr);
if (dgEn) dgEn.addEventListener('input', scheduleDgFromEn);
const daGovSel = document.getElementById('da_governorate_id');
if (daGovSel) daGovSel.addEventListener('change', onDaGovernorateChange);
const daListAll = document.getElementById('da_list_all');
if (daListAll) daListAll.addEventListener('change', renderDeliveryAreasTable);

(async function daInit() {
    try {
        bindGovernorateEditButtons();
        daSyncListAllCheckbox();
        bindDeliveryAreaEditButtons();
        refreshDgSortPreview();
        refreshDaSortPreview();
        if (document.getElementById('dg_tbody') && daGovernoratesCache.length === 0) {
            await loadGovernorates();
        }
        if (document.getElementById('da_tbody') && daDeliveryAreasCache.length === 0) {
            await loadDeliveryAreas();
        }
    } catch (e) {
        console.error('delivery_areas init', e);
    }
})();
</script>
