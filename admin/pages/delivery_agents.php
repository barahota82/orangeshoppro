<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/delivery_agents.php';
require_once __DIR__ . '/../../includes/countries.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$hasTable = orange_table_exists($pdo, 'delivery_agents');
$adminCountryId = orange_admin_context_country_id($pdo);
$statusLabels = orange_delivery_agent_status_labels_ar();
$agents = $hasTable ? orange_delivery_agents_admin_list($pdo, $adminCountryId > 0 ? $adminCountryId : null) : [];
$nextSort = $hasTable && $adminCountryId > 0 ? orange_delivery_agents_next_sort_order($pdo, $adminCountryId) : 1;
?>
<div class="page-title">
    <h1>مناديب التوصيل</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php if (!$hasTable): ?>
<div class="card"><div class="alert-error">جدول <code>delivery_agents</code> غير جاهز.</div></div>
<?php else: ?>

<div class="card" id="dag_form_card">
    <h3>إضافة / تعديل مندوب</h3>
    <input type="hidden" id="dag_id" value="0">
    <input type="hidden" id="dag_country_id" value="<?php echo (int) $adminCountryId; ?>">
    <div class="form-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
        <div>
            <label for="dag_sort">الترتيب</label>
            <input type="number" id="dag_sort" class="admin-sort-field admin-sort-field--muted"
                value="<?php echo (int) $nextSort; ?>" readonly tabindex="-1" aria-readonly="true"
                style="background:#f4f4f5;text-align:center;">
        </div>
        <div>
            <label for="dag_name_ar">الاسم (عربي) <span style="color:#b45309;">*</span></label>
            <input type="text" id="dag_name_ar" maxlength="191" autocomplete="off">
        </div>
        <div>
            <label for="dag_name_en">English</label>
            <input type="text" id="dag_name_en" maxlength="191" lang="en" dir="ltr" autocomplete="off">
        </div>
        <div>
            <label for="dag_phone">الهاتف</label>
            <input type="text" id="dag_phone" maxlength="32" dir="ltr" autocomplete="off">
        </div>
        <div>
            <label for="dag_status">الحالة</label>
            <select id="dag_status">
                <?php foreach ($statusLabels as $code => $label): ?>
                <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div style="margin-top:10px;">
        <label for="dag_notes">ملاحظات</label>
        <textarea id="dag_notes" rows="2" style="width:100%;max-width:640px;"></textarea>
    </div>
    <div class="admin-form-actions" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:12px;align-items:center;">
        <button type="button" onclick="saveDeliveryAgent()">حفظ</button>
        <button type="button" class="btn-secondary" onclick="translateDeliveryAgent({ forceFromArabic: true })">ترجمة تلقائية من العربي</button>
        <button type="button" class="btn-secondary" onclick="resetDeliveryAgentForm()">مندوب جديد</button>
        <div class="jv-voucher-nav-btns" role="group" aria-label="تنقل بين المناديب" style="margin-right:auto;">
            <button type="button" class="btn-secondary jv-nav-btn" id="dag_nav_first" title="أول مندوب" aria-label="أول مندوب">&lt;&lt;</button>
            <button type="button" class="btn-secondary jv-nav-btn" id="dag_nav_prev" title="المندوب السابق" aria-label="المندوب السابق">&lt;</button>
            <button type="button" class="btn-secondary jv-nav-btn" id="dag_nav_next" title="المندوب التالي" aria-label="المندوب التالي">&gt;</button>
            <button type="button" class="btn-secondary jv-nav-btn" id="dag_nav_last" title="آخر مندوب" aria-label="آخر مندوب">&gt;&gt;</button>
            <button type="button" class="btn-secondary jv-nav-search" id="dag_btn_open_search" title="بحث عن مندوب لتعديله">بحث</button>
        </div>
    </div>
</div>

<div id="dag_search_modal" class="dag-search-modal" style="display:none;" aria-hidden="true" role="dialog" aria-labelledby="dag_search_modal_title">
    <div class="dag-search-modal__backdrop" id="dag_search_modal_backdrop" style="position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:1200;"></div>
    <div class="dag-search-modal__dialog" style="position:fixed;z-index:1201;top:6%;left:50%;transform:translateX(-50%);width:min(860px,94vw);max-height:86vh;overflow:auto;background:#fff;border-radius:12px;box-shadow:0 16px 48px rgba(0,0,0,.28);padding:18px;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px;">
            <h3 id="dag_search_modal_title" style="margin:0;">بحث عن مندوب لتعديله</h3>
            <button type="button" class="btn-secondary" id="dag_search_close">إغلاق</button>
        </div>
        <div class="form-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;">
            <div><label for="dag_search_id_from">رقم المندوب — من</label><input type="number" id="dag_search_id_from" class="admin-inp" min="1" step="1" dir="ltr" lang="en" autocomplete="off"></div>
            <div><label for="dag_search_id_to">رقم المندوب — إلى</label><input type="number" id="dag_search_id_to" class="admin-inp" min="1" step="1" dir="ltr" lang="en" autocomplete="off"></div>
            <div><label for="dag_search_phone">الهاتف</label><input type="text" id="dag_search_phone" class="admin-inp" autocomplete="off" dir="ltr" lang="en"></div>
            <div style="grid-column:span 2;"><label for="dag_search_name">الاسم (عربي/إنجليزي)</label><input type="text" id="dag_search_name" class="admin-inp" autocomplete="off" dir="auto"></div>
        </div>
        <div style="display:flex;gap:8px;margin:12px 0;flex-wrap:wrap;">
            <button type="button" class="btn" id="dag_search_run">تنفيذ البحث</button>
            <button type="button" class="btn-secondary" id="dag_search_clear">مسح الحقول</button>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>#</th><th>الاسم</th><th>English</th><th>الهاتف</th><th>الحالة</th><th></th></tr>
                </thead>
                <tbody id="dag_search_results_tbody"></tbody>
            </table>
        </div>
        <p id="dag_search_empty" style="display:none;color:#64748b;margin:10px 0 0;">لا نتائج مطابقة — عدّل معايير البحث.</p>
    </div>
</div>

<div class="card">
    <h3>قائمة المناديب</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>الاسم</th>
                    <th>English</th>
                    <th>الهاتف</th>
                    <th>الحالة</th>
                    <th>ترتيب</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="dag_tbody">
                <?php foreach ($agents as $row): ?>
                <?php
                $aid = (int) ($row['id'] ?? 0);
                $st = (string) ($row['status'] ?? 'active');
                ?>
                <tr>
                    <td><?php echo $aid; ?></td>
                    <td><?php echo htmlspecialchars((string) ($row['name_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars((string) ($row['name_en'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars((string) ($row['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($statusLabels[$st] ?? $st, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int) ($row['sort_order'] ?? 0); ?></td>
                    <td><button type="button" class="btn-secondary" data-dag-edit="<?php echo $aid; ?>">تعديل</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const dagAgentsJson = <?php echo json_encode($agents, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const dagStatusLabels = <?php echo json_encode($statusLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
let dagNextSort = <?php echo (int) $nextSort; ?>;

function resetDeliveryAgentForm() {
    document.getElementById('dag_id').value = '0';
    document.getElementById('dag_name_ar').value = '';
    document.getElementById('dag_name_en').value = '';
    document.getElementById('dag_phone').value = '';
    document.getElementById('dag_status').value = 'active';
    document.getElementById('dag_notes').value = '';
    var sortEl = document.getElementById('dag_sort');
    if (sortEl) {
        sortEl.value = String(dagNextSort);
    }
    if (typeof dagFormDirty !== 'undefined') { dagFormDirty = false; }
}

function fillDeliveryAgentForm(row) {
    document.getElementById('dag_id').value = String(row.id || 0);
    document.getElementById('dag_name_ar').value = row.name_ar || '';
    document.getElementById('dag_name_en').value = row.name_en || '';
    document.getElementById('dag_phone').value = row.phone || '';
    document.getElementById('dag_status').value = row.status || 'active';
    document.getElementById('dag_notes').value = row.notes || '';
    var sortEl = document.getElementById('dag_sort');
    if (sortEl) {
        sortEl.value = String(parseInt(row.sort_order, 10) || 0);
    }
    if (typeof dagFormDirty !== 'undefined') { dagFormDirty = false; }
}

function dagScrollToForm() {
    var card = document.getElementById('dag_form_card');
    if (card && typeof card.scrollIntoView === 'function') {
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    var nameEl = document.getElementById('dag_name_ar');
    if (nameEl) {
        setTimeout(function () { nameEl.focus(); }, 350);
    }
}

document.querySelectorAll('[data-dag-edit]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var id = parseInt(btn.getAttribute('data-dag-edit'), 10);
        var row = dagAgentsJson.find(function (r) { return parseInt(r.id, 10) === id; });
        if (row) {
            fillDeliveryAgentForm(row);
            dagScrollToForm();
        }
    });
});

let dagTranslateTimer = null;
async function translateDeliveryAgent(opts) {
    opts = opts || {};
    var ar = document.getElementById('dag_name_ar').value.trim();
    if (!ar) {
        if (!opts.silent) alert('أدخل الاسم العربي أولاً');
        return;
    }
    var payload = { name_ar: ar, forceFromArabic: opts.forceFromArabic !== false };
    if (!payload.forceFromArabic) payload.name_en = document.getElementById('dag_name_en').value.trim();
    try {
        var res = await postJSON('/admin/api/translate/names.php', payload);
        if (res && res.success && res.data) {
            if (res.data.name_en) document.getElementById('dag_name_en').value = res.data.name_en;
        } else if (!opts.silent) alert((res && res.message) || 'تعذر الترجمة');
    } catch (e) {
        if (!opts.silent) alert('تعذر الترجمة');
    }
}

document.getElementById('dag_name_ar').addEventListener('input', function () {
    clearTimeout(dagTranslateTimer);
    dagTranslateTimer = setTimeout(function () { translateDeliveryAgent({ silent: true, forceFromArabic: true }); }, 700);
});
document.getElementById('dag_name_en').addEventListener('input', function () {
    clearTimeout(dagTranslateTimer);
    dagTranslateTimer = setTimeout(function () { translateDeliveryAgent({ silent: true, forceFromArabic: false }); }, 650);
});

async function saveDeliveryAgent() {
    var res = await postJSON('/admin/api/delivery_agents/manage.php', {
        action: 'save',
        id: parseInt(document.getElementById('dag_id').value, 10) || 0,
        country_id: parseInt(document.getElementById('dag_country_id').value, 10) || 0,
        name_ar: document.getElementById('dag_name_ar').value.trim(),
        name_en: document.getElementById('dag_name_en').value.trim(),
        phone: document.getElementById('dag_phone').value.trim(),
        status: document.getElementById('dag_status').value,
        notes: document.getElementById('dag_notes').value.trim()
    });
    alert(res.message || (res.success ? 'تم الحفظ' : 'فشل الحفظ'));
    if (res.success) {
        if (res.next_sort_order) {
            dagNextSort = parseInt(res.next_sort_order, 10) || dagNextSort;
        }
        dagFormDirty = false;
        location.reload();
    }
}

/* ===== تنقّل + بحث (نمط سند القيد) — البيانات محمّلة في dagAgentsJson ===== */
let dagFormDirty = false;
(function dagNavSearch() {
    var ROWS = Array.isArray(dagAgentsJson) ? dagAgentsJson.slice() : [];
    ROWS.sort(function (a, b) { return (parseInt(a.id, 10) || 0) - (parseInt(b.id, 10) || 0); });

    function currentId() {
        var el = document.getElementById('dag_id');
        return el ? (parseInt(String(el.value || '0'), 10) || 0) : 0;
    }
    function indexOfId(id) {
        for (var i = 0; i < ROWS.length; i++) {
            if ((parseInt(ROWS[i].id, 10) || 0) === id) { return i; }
        }
        return -1;
    }
    function confirmLeaveIfDirty() {
        if (!dagFormDirty) { return true; }
        return confirm('لديك تغييرات غير محفوظة في المندوب الحالي. الانتقال سيتجاهلها. هل تريد المتابعة؟');
    }
    function goToId(id) {
        if (!id || id <= 0) { return; }
        if (!confirmLeaveIfDirty()) { return; }
        var row = ROWS.find(function (r) { return (parseInt(r.id, 10) || 0) === id; });
        if (!row) { return; }
        fillDeliveryAgentForm(row);
        dagFormDirty = false;
        dagScrollToForm();
    }
    /* الأسهم مفعّلة دائماً؛ بلا مندوب محمّل: تالي=أول، سابق=آخر (مطابق لسند القيد). */
    function navGo(where) {
        if (!ROWS.length) { alert('لا يوجد مناديب محفوظون بعد.'); return; }
        var cur = currentId();
        var idx = cur > 0 ? indexOfId(cur) : -1;
        var target = 0;
        if (where === 'first') {
            target = parseInt(ROWS[0].id, 10) || 0;
        } else if (where === 'last') {
            target = parseInt(ROWS[ROWS.length - 1].id, 10) || 0;
        } else if (where === 'next') {
            if (idx < 0) { target = parseInt(ROWS[0].id, 10) || 0; }
            else if (idx >= ROWS.length - 1) { alert('لا يوجد مندوب لاحق — هذا آخر مندوب.'); return; }
            else { target = parseInt(ROWS[idx + 1].id, 10) || 0; }
        } else if (where === 'prev') {
            if (idx < 0) { target = parseInt(ROWS[ROWS.length - 1].id, 10) || 0; }
            else if (idx <= 0) { alert('لا يوجد مندوب أسبق — هذا أول مندوب.'); return; }
            else { target = parseInt(ROWS[idx - 1].id, 10) || 0; }
        }
        goToId(target);
    }
    [['dag_nav_first', 'first'], ['dag_nav_prev', 'prev'], ['dag_nav_next', 'next'], ['dag_nav_last', 'last']].forEach(function (pair) {
        var b = document.getElementById(pair[0]);
        if (b) { b.addEventListener('click', function () { navGo(pair[1]); }); }
    });

    /* علم التغييرات غير المحفوظة: يُضبط على إدخال المستخدم داخل الكارت (الضبط البرمجي لا يُطلق input). */
    var card = document.getElementById('dag_form_card');
    if (card) {
        card.addEventListener('input', function (ev) {
            if (ev.target && ev.target.id === 'dag_sort') { return; }
            dagFormDirty = true;
        });
    }

    /* ===== نافذة البحث ===== */
    var modal = document.getElementById('dag_search_modal');
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function norm(s) { return String(s == null ? '' : s).trim().toLowerCase(); }
    function runSearch() {
        var idFrom = parseInt(String((document.getElementById('dag_search_id_from') || {}).value || '0'), 10) || 0;
        var idTo = parseInt(String((document.getElementById('dag_search_id_to') || {}).value || '0'), 10) || 0;
        var phone = norm((document.getElementById('dag_search_phone') || {}).value);
        var name = norm((document.getElementById('dag_search_name') || {}).value);
        var out = ROWS.filter(function (r) {
            var id = parseInt(r.id, 10) || 0;
            if (idFrom > 0 && id < idFrom) { return false; }
            if (idTo > 0 && id > idTo) { return false; }
            if (phone && norm(r.phone).indexOf(phone) === -1) { return false; }
            if (name) {
                var hay = norm(r.name_ar) + ' ' + norm(r.name_en);
                if (hay.indexOf(name) === -1) { return false; }
            }
            return true;
        });
        var tb = document.getElementById('dag_search_results_tbody');
        var emptyNote = document.getElementById('dag_search_empty');
        if (!tb) { return; }
        tb.innerHTML = '';
        if (!out.length) {
            if (emptyNote) { emptyNote.style.display = 'block'; }
            return;
        }
        if (emptyNote) { emptyNote.style.display = 'none'; }
        out.slice(0, 300).forEach(function (r) {
            var tr = document.createElement('tr');
            var st = String(r.status || 'active');
            var stTxt = dagStatusLabels[st] || st;
            tr.innerHTML =
                '<td>' + (parseInt(r.id, 10) || 0) + '</td>' +
                '<td>' + esc(r.name_ar) + '</td>' +
                '<td dir="ltr">' + esc(r.name_en) + '</td>' +
                '<td dir="ltr">' + esc(r.phone) + '</td>' +
                '<td>' + esc(stTxt) + '</td>' +
                '<td><button type="button" class="btn-secondary dag-search-pick" data-id="' + (parseInt(r.id, 10) || 0) + '">تعديل</button></td>';
            tb.appendChild(tr);
        });
    }
    function openModal() {
        if (!modal) { return; }
        modal.style.display = 'block';
        modal.setAttribute('aria-hidden', 'false');
        runSearch();
        var f = document.getElementById('dag_search_name');
        if (f) { try { f.focus(); } catch (e) {} }
    }
    function resetSearchFields() {
        ['dag_search_id_from', 'dag_search_id_to', 'dag_search_phone', 'dag_search_name'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) { el.value = ''; }
        });
        var tb = document.getElementById('dag_search_results_tbody');
        if (tb) { tb.innerHTML = ''; }
        var emptyNote = document.getElementById('dag_search_empty');
        if (emptyNote) { emptyNote.style.display = 'none'; }
    }
    function closeModal() {
        if (!modal) { return; }
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        resetSearchFields();
    }
    var openBtn = document.getElementById('dag_btn_open_search');
    if (openBtn) { openBtn.addEventListener('click', openModal); }
    var closeBtn = document.getElementById('dag_search_close');
    if (closeBtn) { closeBtn.addEventListener('click', closeModal); }
    var backdrop = document.getElementById('dag_search_modal_backdrop');
    if (backdrop) { backdrop.addEventListener('click', closeModal); }
    var runBtn = document.getElementById('dag_search_run');
    if (runBtn) { runBtn.addEventListener('click', runSearch); }
    var clearBtn = document.getElementById('dag_search_clear');
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            ['dag_search_id_from', 'dag_search_id_to', 'dag_search_phone', 'dag_search_name'].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) { el.value = ''; }
            });
            runSearch();
        });
    }
    ['dag_search_phone', 'dag_search_name', 'dag_search_id_from', 'dag_search_id_to'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) {
            el.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') { ev.preventDefault(); runSearch(); }
            });
        }
    });
    var tbodyEl = document.getElementById('dag_search_results_tbody');
    if (tbodyEl) {
        tbodyEl.addEventListener('click', function (ev) {
            var btn = ev.target && ev.target.closest ? ev.target.closest('.dag-search-pick') : null;
            if (!btn) { return; }
            var id = parseInt(String(btn.getAttribute('data-id') || '0'), 10) || 0;
            if (id <= 0) { return; }
            if (!confirmLeaveIfDirty()) { return; }
            closeModal();
            goToId(id);
        });
    }
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && modal && modal.style.display === 'block') { closeModal(); }
    });
})();
</script>
<?php endif; ?>
