<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/storefront_promo_messages.php';
require_once __DIR__ . '/../../includes/cart_promotion_country.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$hasTable = orange_table_exists($pdo, 'storefront_promo_messages');
$spmSlots = orange_storefront_promo_message_slots();
$spmOfferTypes = orange_storefront_promo_message_offer_types();
$spmAdminCid = (int) orange_cart_promotion_admin_country_id($pdo);
$spmIsGlobal = $spmAdminCid <= 0;

/** @var list<array{id:int,label:string}> */
$spmCountries = [];
if ($spmIsGlobal && orange_table_exists($pdo, 'countries')) {
    try {
        $nameCol = orange_table_has_column($pdo, 'countries', 'name_ar') ? 'name_ar' : 'name';
        $rows = $pdo->query("SELECT id, $nameCol AS label FROM countries WHERE is_active = 1 ORDER BY $nameCol ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows ?: [] as $r) {
            $cid = (int) ($r['id'] ?? 0);
            if ($cid > 0) {
                $spmCountries[] = ['id' => $cid, 'label' => (string) ($r['label'] ?? ('#' . $cid))];
            }
        }
    } catch (Throwable $e) {
        $spmCountries = [];
    }
}
$spmCountryLabelJs = [];
foreach ($spmCountries as $c) {
    $spmCountryLabelJs[(string) $c['id']] = $c['label'];
}
?>
<div class="page-title">
    <h1>الرسائل التحفيزية للواجهة</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;">نصوص تحفيزية يتحكّم بها المشرف وتظهر في خانات مُسمّاة بالواجهة (بنطاق دولة وجدولة) دون تعديل أكواد.</p>
</div>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>storefront_promo_messages</code> غير جاهز.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل</h3>
    <input type="hidden" id="spm_id" value="0">
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;margin-bottom:14px;">
        <div style="max-width:140px;">
            <label for="spm_sort">الترتيب</label>
            <input type="number" id="spm_sort" class="admin-inp" value="0" step="1" style="text-align:center;">
        </div>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" id="spm_active" checked> نشط
        </label>
    </div>
    <div class="form-grid">
        <div>
            <label for="spm_slot">مكان الظهور (الخانة)</label>
            <select id="spm_slot" class="admin-inp">
                <?php foreach ($spmSlots as $key => $label): ?>
                <option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($spmIsGlobal): ?>
        <div>
            <label for="spm_country">الدولة</label>
            <select id="spm_country" class="admin-inp">
                <option value="">كل الدول</option>
                <?php foreach ($spmCountries as $c): ?>
                <option value="<?php echo (int) $c['id']; ?>"><?php echo htmlspecialchars($c['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>
    <div class="form-grid" id="spm_offer_wrap" style="display:none;">
        <div>
            <label for="spm_offer_type">نوع العرض</label>
            <select id="spm_offer_type" class="admin-inp">
                <?php foreach ($spmOfferTypes as $key => $label): ?>
                <option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="spm_offer_id">رقم العرض</label>
            <input type="number" id="spm_offer_id" class="admin-inp" min="1" step="1" dir="ltr" placeholder="مثال: 12">
            <p class="card-hint" style="margin:4px 0 0;">رقم العرض من شاشة عروض المنتج/الكومبو/BOGO المعنية.</p>
        </div>
    </div>
    <div class="form-grid">
        <div>
            <label for="spm_text_ar">النص (عربي)</label>
            <textarea id="spm_text_ar" class="admin-inp" rows="2" dir="auto" maxlength="500" placeholder="مثال: خصومات العيد الآن — لا تفوّت العروض!"></textarea>
        </div>
        <div>
            <label for="spm_text_en">English</label>
            <textarea id="spm_text_en" class="admin-inp" rows="2" dir="ltr" lang="en" maxlength="500"></textarea>
        </div>
        <div>
            <label for="spm_text_fil">Filipino</label>
            <textarea id="spm_text_fil" class="admin-inp" rows="2" dir="ltr" maxlength="500"></textarea>
        </div>
        <div>
            <label for="spm_text_hi">हिन्दी</label>
            <textarea id="spm_text_hi" class="admin-inp" rows="2" dir="ltr" maxlength="500"></textarea>
        </div>
    </div>
    <div style="margin-top:8px;">
        <button type="button" class="btn-secondary" onclick="spmTranslateFromAr()">ترجمة تلقائية من العربي</button>
    </div>
    <?php $ocpFieldPrefix = 'spm'; require __DIR__ . '/../partials/cart_promo_schedule_fields.inc.php'; ?>
    <div class="admin-form-actions">
        <button type="button" onclick="saveSpm()" <?php echo !$hasTable ? 'disabled' : ''; ?>>حفظ</button>
        <button type="button" class="btn-secondary" onclick="resetSpmForm()">جديد</button>
    </div>
</div>

<div class="card">
    <h3>الرسائل</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>الخانة</th>
                    <th>النص (عربي)</th>
                    <th>الدولة</th>
                    <th>الفترة</th>
                    <th>الحالة</th>
                    <th>ترتيب</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="spm_tbody"></tbody>
        </table>
    </div>
</div>

<script>
<?php require __DIR__ . '/../partials/cart_promo_schedule_js.inc.php'; ?>
var SPM_SLOTS = <?php echo json_encode($spmSlots, JSON_UNESCAPED_UNICODE); ?>;
var SPM_OFFER_TYPES = <?php echo json_encode($spmOfferTypes, JSON_UNESCAPED_UNICODE); ?>;
var SPM_COUNTRY_LABELS = <?php echo json_encode($spmCountryLabelJs, JSON_UNESCAPED_UNICODE); ?>;
var SPM_IS_GLOBAL = <?php echo $spmIsGlobal ? 'true' : 'false'; ?>;
var SPM_ROWS = [];
var spmArTimer = null;
var spmEnTimer = null;

function escSpm(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/"/g, '&quot;');
}

function spmSyncOfferWrap() {
    var slotEl = document.getElementById('spm_slot');
    var wrap = document.getElementById('spm_offer_wrap');
    if (!slotEl || !wrap) return;
    wrap.style.display = (slotEl.value === 'offer_card') ? '' : 'none';
}

function spmComputeNextSort() {
    var max = 0;
    (SPM_ROWS || []).forEach(function (r) {
        var s = parseInt(r.sort_order, 10) || 0;
        if (s > max) max = s;
    });
    return max + 1;
}

async function spmTranslate(opts) {
    var silent = !!(opts && opts.silent);
    var forceFromArabic = !!(opts && opts.forceFromArabic);
    var arEl = document.getElementById('spm_text_ar');
    var enEl = document.getElementById('spm_text_en');
    var filEl = document.getElementById('spm_text_fil');
    var hiEl = document.getElementById('spm_text_hi');
    if (!arEl) return;
    try {
        var res = await postJSON('/admin/api/translate/descriptions.php', {
            description_ar: arEl.value.trim(),
            description_en: forceFromArabic ? '' : (enEl ? enEl.value.trim() : '')
        });
        if (!res || !res.success || !res.translations) {
            if (!silent) alert((res && res.message) ? res.message : 'فشل الترجمة');
            return;
        }
        var tr = res.translations;
        if (enEl && tr.description_en != null) enEl.value = String(tr.description_en);
        if (filEl && tr.description_fil != null) filEl.value = String(tr.description_fil);
        if (hiEl && tr.description_hi != null) hiEl.value = String(tr.description_hi);
    } catch (e) {
        if (!silent) alert('تعذر الاتصال بخدمة الترجمة');
    }
}

function spmScheduleFromAr() {
    clearTimeout(spmArTimer);
    spmArTimer = setTimeout(function () { spmTranslate({ silent: true, forceFromArabic: true }); }, 700);
}
function spmScheduleFromEn() {
    var enEl = document.getElementById('spm_text_en');
    if (!enEl || enEl.value.trim() === '') return;
    clearTimeout(spmEnTimer);
    spmEnTimer = setTimeout(function () { spmTranslate({ silent: true, forceFromArabic: false }); }, 650);
}
async function spmTranslateFromAr() {
    await spmTranslate({ silent: false, forceFromArabic: true });
}
window.spmTranslateFromAr = spmTranslateFromAr;

function resetSpmForm() {
    document.getElementById('spm_id').value = '0';
    document.getElementById('spm_text_ar').value = '';
    document.getElementById('spm_text_en').value = '';
    document.getElementById('spm_text_fil').value = '';
    document.getElementById('spm_text_hi').value = '';
    document.getElementById('spm_active').checked = true;
    document.getElementById('spm_sort').value = String(spmComputeNextSort());
    var slotEl = document.getElementById('spm_slot');
    if (slotEl) slotEl.selectedIndex = 0;
    var cEl = document.getElementById('spm_country');
    if (cEl) cEl.value = '';
    var otEl = document.getElementById('spm_offer_type');
    if (otEl) otEl.selectedIndex = 0;
    var oiEl = document.getElementById('spm_offer_id');
    if (oiEl) oiEl.value = '';
    spmSyncOfferWrap();
    ocpSetAlwaysOn('spm', false);
    ocpDefaultScheduleDates('spm');
}

function editSpm(row) {
    document.getElementById('spm_id').value = String(row.id != null ? row.id : 0);
    document.getElementById('spm_text_ar').value = row.text_ar != null ? String(row.text_ar) : '';
    document.getElementById('spm_text_en').value = row.text_en != null ? String(row.text_en) : '';
    document.getElementById('spm_text_fil').value = row.text_fil != null ? String(row.text_fil) : '';
    document.getElementById('spm_text_hi').value = row.text_hi != null ? String(row.text_hi) : '';
    document.getElementById('spm_active').checked = parseInt(row.is_active, 10) === 1;
    document.getElementById('spm_sort').value = String(row.sort_order != null ? row.sort_order : 0);
    var slotEl = document.getElementById('spm_slot');
    if (slotEl) slotEl.value = String(row.slot || 'offers_top');
    var cEl = document.getElementById('spm_country');
    if (cEl) cEl.value = row.country_id != null && row.country_id !== '' ? String(row.country_id) : '';
    var otEl = document.getElementById('spm_offer_type');
    if (otEl) otEl.value = row.offer_type ? String(row.offer_type) : (otEl.options[0] ? otEl.options[0].value : '');
    var oiEl = document.getElementById('spm_offer_id');
    if (oiEl) oiEl.value = (row.offer_id != null && parseInt(row.offer_id, 10) > 0) ? String(row.offer_id) : '';
    spmSyncOfferWrap();
    ocpSetAlwaysOn('spm', parseInt(row.is_always_on, 10) === 1);
    ocpSetDmyFromIso('spm_valid_from', row.valid_from);
    ocpSetDmyFromIso('spm_valid_to', row.valid_to);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function spmCountryLabel(cid) {
    if (cid == null || cid === '' || parseInt(cid, 10) <= 0) return 'كل الدول';
    var l = SPM_COUNTRY_LABELS[String(cid)];
    return l ? l : ('#' + cid);
}

async function loadSpm() {
    var res = await postJSON('/admin/api/storefront_promo_messages/manage.php', { action: 'list' });
    if (!res || !res.success) {
        alert((res && res.message) || 'خطأ');
        return;
    }
    SPM_ROWS = res.data || [];
    var tb = document.getElementById('spm_tbody');
    tb.innerHTML = '';
    SPM_ROWS.forEach(function (r) {
        var slotLabel = SPM_SLOTS[r.slot] ? SPM_SLOTS[r.slot] : r.slot;
        if (r.slot === 'offer_card' && r.offer_type && parseInt(r.offer_id, 10) > 0) {
            var otLabel = SPM_OFFER_TYPES[r.offer_type] ? SPM_OFFER_TYPES[r.offer_type] : r.offer_type;
            slotLabel += ' — ' + otLabel + ' #' + r.offer_id;
        }
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td>' + escSpm(r.id) + '</td>' +
            '<td>' + escSpm(slotLabel) + '</td>' +
            '<td>' + escSpm(r.text_ar) + '</td>' +
            '<td>' + escSpm(spmCountryLabel(r.country_id)) + '</td>' +
            '<td dir="ltr">' + escSpm(ocpScheduleLabel(r)) + '</td>' +
            '<td>' + escSpm(ocpStatusLabel(r)) + '</td>' +
            '<td>' + escSpm(r.sort_order) + '</td>' +
            '<td>' +
            '<button type="button" class="btn-secondary" data-spm-edit="' + escSpm(r.id) + '">تعديل</button> ' +
            '<button type="button" class="btn-secondary" data-spm-del="' + escSpm(r.id) + '">حذف</button>' +
            '</td>';
        tb.appendChild(tr);
    });
    tb.querySelectorAll('[data-spm-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = parseInt(btn.getAttribute('data-spm-edit'), 10);
            var row = SPM_ROWS.find(function (x) { return parseInt(x.id, 10) === id; });
            if (row) editSpm(row);
        });
    });
    tb.querySelectorAll('[data-spm-del]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var id = parseInt(btn.getAttribute('data-spm-del'), 10);
            if (!confirm('حذف هذه الرسالة؟')) return;
            var res2 = await postJSON('/admin/api/storefront_promo_messages/manage.php', { action: 'delete', id: id });
            alert((res2 && res2.message) || (res2 && res2.success ? 'تم' : 'فشل'));
            if (res2 && res2.success) loadSpm();
        });
    });
    if ((parseInt(document.getElementById('spm_id').value, 10) || 0) === 0) {
        document.getElementById('spm_sort').value = String(spmComputeNextSort());
    }
}

async function saveSpm() {
    var cEl = document.getElementById('spm_country');
    var payload = {
        action: 'save',
        id: parseInt(document.getElementById('spm_id').value, 10) || 0,
        slot: document.getElementById('spm_slot').value,
        offer_type: (document.getElementById('spm_offer_type') || {}).value || '',
        offer_id: parseInt((document.getElementById('spm_offer_id') || {}).value, 10) || 0,
        country_id: cEl ? (parseInt(cEl.value, 10) || 0) : 0,
        text_ar: document.getElementById('spm_text_ar').value.trim(),
        text_en: document.getElementById('spm_text_en').value.trim(),
        text_fil: document.getElementById('spm_text_fil').value.trim(),
        text_hi: document.getElementById('spm_text_hi').value.trim(),
        is_active: document.getElementById('spm_active').checked ? 1 : 0,
        is_always_on: ocpIsAlwaysOn('spm') ? 1 : 0,
        sort_order: parseInt(document.getElementById('spm_sort').value, 10) || 0,
        valid_from: ocpGetIso('spm_valid_from'),
        valid_to: ocpGetIso('spm_valid_to')
    };
    var res = await postJSON('/admin/api/storefront_promo_messages/manage.php', payload);
    alert((res && res.message) || (res && res.success ? 'تم الحفظ' : 'فشل'));
    if (res && res.success) {
        resetSpmForm();
        loadSpm();
    }
}

(function () {
    var arEl = document.getElementById('spm_text_ar');
    var enEl = document.getElementById('spm_text_en');
    if (arEl) arEl.addEventListener('input', spmScheduleFromAr);
    if (enEl) enEl.addEventListener('input', spmScheduleFromEn);
    var slotEl = document.getElementById('spm_slot');
    if (slotEl) slotEl.addEventListener('change', spmSyncOfferWrap);
    spmSyncOfferWrap();
    ocpBindAlwaysOn('spm');
    ocpDefaultScheduleDates('spm');
    loadSpm();
})();
</script>
