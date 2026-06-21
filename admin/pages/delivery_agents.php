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
        <div>
            <label for="dag_sort">الترتيب</label>
            <input type="number" id="dag_sort" class="admin-sort-field admin-sort-field--muted"
                value="<?php echo (int) $nextSort; ?>" readonly tabindex="-1" aria-readonly="true">
        </div>
    </div>
    <div style="margin-top:10px;">
        <label for="dag_notes">ملاحظات</label>
        <textarea id="dag_notes" rows="2" style="width:100%;max-width:640px;"></textarea>
    </div>
    <div class="admin-form-actions" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:12px;">
        <button type="button" onclick="saveDeliveryAgent()">حفظ</button>
        <button type="button" class="btn-secondary" onclick="translateDeliveryAgent({ forceFromArabic: true })">ترجمة تلقائية من العربي</button>
        <button type="button" class="btn-secondary" onclick="resetDeliveryAgentForm()">مندوب جديد</button>
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
        location.reload();
    }
}
</script>
<?php endif; ?>
