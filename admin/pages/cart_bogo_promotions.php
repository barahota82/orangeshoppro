<?php

declare(strict_types=1);

$pdo = db();
orange_catalog_ensure_schema($pdo);
require_once __DIR__ . '/../../includes/catalog_taxonomy_migrate.php';
$hasTable = orange_table_exists($pdo, 'cart_bogo_promotions');
$cartBogoUnifiedCategoryHint =
    orange_catalog_nav_use_unified($pdo)
    && orange_table_exists($pdo, 'catalog_categories')
    && orange_table_exists($pdo, 'catalog_sections')
    && orange_table_exists($pdo, 'departments')
    && orange_table_exists($pdo, 'product_types');

/** @var list<array{id:int,label:string}> */
$cartBogoCatalogCategoryDropdown = [];
if ($cartBogoUnifiedCategoryHint) {
    try {
        $crow = $pdo->query(
            'SELECT ucc.id,
                    CONCAT_WS(
                        \' ← \',
                        NULLIF(TRIM(d.name_ar), \'\'),
                        NULLIF(TRIM(cs.name_ar), \'\'),
                        NULLIF(TRIM(ucc.name_ar), \'\')
                    ) AS trail_ar
             FROM catalog_categories ucc
             INNER JOIN catalog_sections cs ON cs.id = ucc.catalog_section_id AND cs.is_active = 1
             INNER JOIN departments d ON d.id = cs.department_id AND d.is_active = 1
             WHERE ucc.is_active = 1
             ORDER BY d.sort_order ASC, d.id ASC, cs.sort_order ASC, cs.id ASC, ucc.sort_order ASC, ucc.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($crow ?: [] as $r) {
            if (!is_array($r)) {
                continue;
            }
            $cid = isset($r['id']) ? (int) $r['id'] : 0;
            if ($cid <= 0) {
                continue;
            }
            $tr = trim((string) ($r['trail_ar'] ?? ''));
            if ($tr === '') {
                $tr = '#' . $cid;
            }
            $cartBogoCatalogCategoryDropdown[] = ['id' => $cid, 'label' => $tr];
        }
    } catch (Throwable $e) {
        $cartBogoCatalogCategoryDropdown = [];
    }
}
$cartBogoCategoryLabelJs = [];
foreach ($cartBogoCatalogCategoryDropdown as $e) {
    $cartBogoCategoryLabelJs[(string) $e['id']] = $e['label'];
}
?>
<div class="page-title page-title--stacked">
    <h1>عروض BOGO (متطابق / فئة / حزمة شراء أ+ب)</h1>
    <p class="page-subtitle"><strong>س4:</strong> (1) <em>نفس المتغير</em> — عدد القطع على سطر واحد يبلغ الحد الأدنى (افتراضي 2).
        (2) <em>نفس الفئة</em> — عدد المنتجات <strong>المختلفة</strong> ضمن الفئة يبلغ الحد الأدنى.
        (3) <em>حزمة شراء</em> — متغيران مختلفان على الأقل بكميات محددة في السلة (أسعار التجزئة للشراء؛ الهدية كما تضبطها أدناه).
        الهدية: ثابتة أو اختيار. يُطبَّق أول قاعدة نشطة بالترتيب تطابق السلة.</p>
</div>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>cart_bogo_promotions</code> غير جاهز.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل</h3>
    <input type="hidden" id="cbp_id" value="0">
    <div class="form-grid">
        <div style="grid-column:1/-1;">
            <label><strong>شرط السلة</strong></label>
            <div style="display:flex;gap:1.25rem;flex-wrap:wrap;margin-top:6px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="radio" name="cbp_bogo" value="same_variant" checked onchange="cbpToggleBogo()"> نفس المتغير (كمية على السطر)
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="radio" name="cbp_bogo" value="same_category" onchange="cbpToggleBogo()"> منتجات مختلفة من نفس الفئة
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="radio" name="cbp_bogo" value="buy_bundle" onchange="cbpToggleBogo()"> حزمة شراء (متغير أ + متغير ب…)
                </label>
            </div>
        </div>
        <div id="cbp_cat_wrap" style="grid-column:1/-1;display:none;">
            <?php if ($cartBogoUnifiedCategoryHint && $cartBogoCatalogCategoryDropdown !== []): ?>
            <label for="cbp_cat">الفئة في الشجرة الموحّدة</label>
            <p class="page-subtitle" style="margin:6px 0 10px;line-height:1.45;"><strong>تصنيف موحّد:</strong> تُقيَّد قائمة منتجات السلة وفق ورقة نوع المنتج ومسار <code dir="ltr">catalog_categories.id</code> المختارة (وليس عمود الفئة القديم على المنتج).</p>
            <select id="cbp_cat" class="admin-inp" style="max-width:100%;width:min(42rem,100%);">
                <option value="">— اختر فئة —</option>
                <?php foreach ($cartBogoCatalogCategoryDropdown as $opt): ?>
                <option value="<?php echo (int) $opt['id']; ?>"><?php echo htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <?php elseif ($cartBogoUnifiedCategoryHint): ?>
            <label for="cbp_cat">معرّف فئة الشجرة الموحّدة</label>
            <p class="page-subtitle" style="margin:6px 0 10px;line-height:1.45;"><strong>تصنيف موحّد:</strong> لا توجد فئات نشطة في <code dir="ltr">catalog_categories</code> — أدخل <code dir="ltr">id</code> يدوياً أو أنشئ الفروع من صفحة فروع شجرة المنتجات.</p>
            <input type="number" id="cbp_cat" class="admin-inp" min="1" step="1" style="max-width:12rem;" dir="ltr">
            <?php else: ?>
            <label for="cbp_cat">رقم الفئة القديم على المنتج (<code dir="ltr">categories.id</code>)</label>
            <input type="number" id="cbp_cat" class="admin-inp" min="1" step="1" style="max-width:12rem;" dir="ltr">
            <?php endif; ?>
        </div>
        <div id="cbp_buy_bundle_wrap" style="grid-column:1/-1;display:none;">
            <label>مكوّنات الشراء — سطر لكل متغير: <code dir="ltr">variant_id qty</code> (متغيران مختلفان على الأقل)</label>
            <div style="margin:6px 0 8px;">
                <button type="button" class="btn-secondary" onclick="orangeOpenVariantPicker({ mode: 'lines', targetId: 'cbp_buy_comp' })">اختيار بصري — إضافة سطر</button>
            </div>
            <textarea id="cbp_buy_comp" rows="4" class="admin-inp" dir="ltr" style="width:100%;max-width:40rem;font-family:monospace;" placeholder="101 1&#10;205 1"></textarea>
        </div>
        <div id="cbp_minbuy_wrap">
            <label>الحد الأدنى للكمية / عدد المنتجات المختلفة</label>
            <input type="number" id="cbp_minbuy" class="admin-inp" min="2" step="1" value="2" style="max-width:12rem;" dir="ltr">
        </div>
        <div><label>الترتيب</label><input type="number" id="cbp_sort" value="0" style="max-width:120px;"></div>
        <div style="grid-column:1/-1;">
            <label><strong>نوع الهدية</strong></label>
            <div style="display:flex;gap:1.25rem;flex-wrap:wrap;margin-top:6px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="radio" name="cbp_gift" value="choice" checked onchange="cbpToggleGift()"> اختيار من مجموعة
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="radio" name="cbp_gift" value="fixed" onchange="cbpToggleGift()"> هدية ثابتة
                </label>
            </div>
        </div>
        <div id="cbp_block_pool" style="grid-column:1/-1;">
            <label>أرقام متغيرات المنتج (Variant IDs)</label>
            <div style="margin:6px 0 8px;">
                <button type="button" class="btn-secondary" onclick="orangeOpenVariantPicker({ mode: 'pool', targetId: 'cbp_pool' })">اختيار بصري — إضافة للقائمة</button>
            </div>
            <textarea id="cbp_pool" rows="3" class="admin-inp" dir="ltr" style="width:100%;max-width:40rem;font-family:monospace;" placeholder="201, 202"></textarea>
        </div>
        <div id="cbp_block_fixed" style="grid-column:1/-1;display:none;">
            <label>رقم المتغير الثابت</label>
            <div style="margin:6px 0 8px;">
                <button type="button" class="btn-secondary" onclick="orangeOpenVariantPicker({ mode: 'fixed', targetId: 'cbp_fixed' })">اختيار بصري — متغير واحد</button>
            </div>
            <input type="number" id="cbp_fixed" class="admin-inp" min="1" step="1" style="max-width:12rem;" dir="ltr">
        </div>
        <div style="grid-column:1/-1;">
            <label for="cbp_gift_charge_kind"><strong>تسعير بند هدية BOGO (ب)</strong></label>
            <select id="cbp_gift_charge_kind" class="admin-inp" style="max-width:28rem;margin-top:6px;" onchange="cbpToggleGiftCharge()">
                <option value="free">مجانية بالكامل (سطر هدية بسعر صفر)</option>
                <option value="percent_off">خصم نسبة من سعر التجزئة للوحدة</option>
                <option value="amount_off_unit">خصم مبلغ ثابت من سعر التجزئة للوحدة</option>
                <option value="fixed_unit">سعر بيع ثابت للوحدة (د.ك)</option>
            </select>
            <p class="page-subtitle" style="margin-top:6px;">للنسبة والمبلغ المخصوم: يُحسب من <code dir="ltr">products.price</code> للمنتج المختار كهدية. معاينة العربة تضيف أعلى تكلفة ممكنة عند «اختيار من مجموعة».</p>
        </div>
        <div id="cbp_gift_charge_val_wrap" style="grid-column:1/-1;display:none;">
            <label id="cbp_gift_charge_val_label">القيمة</label>
            <input type="number" id="cbp_gift_charge_val" class="admin-inp" min="0" step="0.0001" style="max-width:14rem;" dir="ltr" value="0">
        </div>
        <div style="grid-column:1/-1;">
            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;max-width:52rem;line-height:1.45;">
                <input type="checkbox" id="cbp_reg" style="margin-top:4px;flex-shrink:0;">
                <span><strong>للمسجّلين فقط</strong></span>
            </label>
        </div>
        <div style="display:flex;align-items:flex-end;gap:8px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" id="cbp_active" checked> نشط
            </label>
        </div>
    </div>
    <div class="admin-form-actions">
        <button type="button" onclick="saveCartBogoPromotion()" <?php echo !$hasTable ? 'disabled' : ''; ?>>حفظ</button>
        <button type="button" class="btn-secondary" onclick="resetCartBogoPromotionForm()">جديد</button>
    </div>
</div>

<div class="card">
    <h3>القواعد</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>الشرط</th>
                    <th>فئة</th>
                    <th>حد أدنى</th>
                    <th>هدية</th>
                    <th>تسعير ب</th>
                    <th>نطاق</th>
                    <th>ترتيب</th>
                    <th>نشط</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="cbp_tbody"></tbody>
        </table>
    </div>
</div>

<script>
var CBP_CATEGORY_LABEL_MAP = <?php echo json_encode($cartBogoCategoryLabelJs, JSON_UNESCAPED_UNICODE); ?>;
function cbpToggleBogo() {
    const el = document.querySelector('input[name="cbp_bogo"]:checked');
    const v = el ? el.value : 'same_variant';
    document.getElementById('cbp_cat_wrap').style.display = v === 'same_category' ? 'block' : 'none';
    document.getElementById('cbp_buy_bundle_wrap').style.display = v === 'buy_bundle' ? 'block' : 'none';
    document.getElementById('cbp_minbuy_wrap').style.display = v === 'buy_bundle' ? 'none' : 'block';
}

function cbpToggleGift() {
    const fixed = document.querySelector('input[name="cbp_gift"]:checked');
    const isFixed = fixed && fixed.value === 'fixed';
    document.getElementById('cbp_block_fixed').style.display = isFixed ? 'block' : 'none';
    document.getElementById('cbp_block_pool').style.display = isFixed ? 'none' : 'block';
}

function cbpToggleGiftCharge() {
    const sel = document.getElementById('cbp_gift_charge_kind');
    const k = sel ? sel.value : 'free';
    const wrap = document.getElementById('cbp_gift_charge_val_wrap');
    const lab = document.getElementById('cbp_gift_charge_val_label');
    const inp = document.getElementById('cbp_gift_charge_val');
    if (!wrap || !lab || !inp) {
        return;
    }
    if (k === 'free') {
        wrap.style.display = 'none';
        inp.value = '0';
        return;
    }
    wrap.style.display = 'block';
    if (k === 'percent_off') {
        lab.textContent = 'نسبة الخصم (0–100)';
        inp.max = '100';
        inp.step = '0.01';
    } else if (k === 'fixed_unit') {
        lab.textContent = 'سعر الوحدة (د.ك)';
        inp.removeAttribute('max');
        inp.step = '0.0001';
    } else {
        lab.textContent = 'المبلغ المخصوم من سعر التجزئة للوحدة (د.ك)';
        inp.removeAttribute('max');
        inp.step = '0.0001';
    }
}

function resetCartBogoPromotionForm() {
    document.getElementById('cbp_id').value = '0';
    document.getElementById('cbp_cat').value = '';
    document.getElementById('cbp_minbuy').value = '2';
    document.getElementById('cbp_sort').value = '0';
    document.getElementById('cbp_pool').value = '';
    document.getElementById('cbp_fixed').value = '';
    document.getElementById('cbp_buy_comp').value = '';
    document.querySelector('input[name="cbp_bogo"][value="same_variant"]').checked = true;
    document.querySelector('input[name="cbp_gift"][value="choice"]').checked = true;
    document.getElementById('cbp_reg').checked = false;
    document.getElementById('cbp_active').checked = true;
    document.getElementById('cbp_gift_charge_kind').value = 'free';
    document.getElementById('cbp_gift_charge_val').value = '0';
    cbpToggleBogo();
    cbpToggleGift();
    cbpToggleGiftCharge();
}

function editCartBogoPromotion(row) {
    document.getElementById('cbp_id').value = String(row.id != null ? row.id : 0);
    let bk = 'same_variant';
    if ((row.bogo_kind || '') === 'same_category') {
        bk = 'same_category';
    } else if ((row.bogo_kind || '') === 'buy_bundle') {
        bk = 'buy_bundle';
    }
    document.querySelector('input[name="cbp_bogo"][value="' + bk + '"]').checked = true;
    cbpToggleBogo();
    document.getElementById('cbp_cat').value =
        row.category_id != null && row.category_id !== '' ? String(row.category_id) : '';
    document.getElementById('cbp_minbuy').value = String(row.min_buy_qty != null ? row.min_buy_qty : 2);
    document.getElementById('cbp_sort').value = String(row.sort_order != null ? row.sort_order : 0);
    const gk = (row.gift_kind || 'choice') === 'fixed' ? 'fixed' : 'choice';
    document.querySelector('input[name="cbp_gift"][value="' + gk + '"]').checked = true;
    cbpToggleGift();
    const pool = row.pool_variant_ids || [];
    document.getElementById('cbp_pool').value = Array.isArray(pool) ? pool.join(', ') : '';
    document.getElementById('cbp_fixed').value =
        row.fixed_variant_id != null && row.fixed_variant_id !== '' ? String(row.fixed_variant_id) : '';
    document.getElementById('cbp_reg').checked = parseInt(row.requires_registered_account, 10) === 1;
    document.getElementById('cbp_active').checked = parseInt(row.is_active, 10) === 1;
    var buyLines = [];
    (row.buy_components || []).forEach(function (c) {
        buyLines.push(String(c.variant_id) + ' ' + String(c.qty));
    });
    document.getElementById('cbp_buy_comp').value = buyLines.join('\n');
    var gck = (row.gift_unit_charge_kind || 'free').toLowerCase();
    var allowed = { free: 1, percent_off: 1, fixed_unit: 1, amount_off_unit: 1 };
    document.getElementById('cbp_gift_charge_kind').value = allowed[gck] ? gck : 'free';
    document.getElementById('cbp_gift_charge_val').value =
        row.gift_unit_charge_value != null && row.gift_unit_charge_value !== '' ? String(row.gift_unit_charge_value) : '0';
    cbpToggleGiftCharge();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function escCbp(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/"/g, '&quot;');
}

async function loadCartBogoPromotions() {
    const res = await postJSON('/admin/api/cart_bogo_promotions/manage.php', { action: 'list' });
    if (!res.success) {
        alert(res.message || 'خطأ');
        return;
    }
    const rows = res.data || [];
    const tb = document.getElementById('cbp_tbody');
    tb.innerHTML = '';
    rows.forEach(function (r) {
        let cond = 'نفس المتغير';
        if ((r.bogo_kind || '') === 'same_category') {
            cond = 'فئة — منتجات مختلفة';
        } else if ((r.bogo_kind || '') === 'buy_bundle') {
            cond = 'حزمة شراء';
        }
        const catRaw = String(r.category_id != null ? r.category_id : '');
        let catDisp = '—';
        if (catRaw !== '') {
            const lab =
                typeof CBP_CATEGORY_LABEL_MAP !== 'undefined' ? CBP_CATEGORY_LABEL_MAP[catRaw] : null;
            catDisp =
                lab && String(lab).trim() !== ''
                    ? escCbp(String(lab)) + ' (' + escCbp(catRaw) + ')'
                    : escCbp(catRaw);
        }
        const gkind = (r.gift_kind || '') === 'fixed' ? 'ثابتة' : 'اختيار';
        var gcharge = 'مجانية';
        var gck = (r.gift_unit_charge_kind || 'free').toLowerCase();
        if (gck === 'percent_off') {
            gcharge = 'خصم %';
        } else if (gck === 'fixed_unit') {
            gcharge = 'سعر ثابت';
        } else if (gck === 'amount_off_unit') {
            gcharge = 'خصم مبلغ';
        }
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td>' + escCbp(String(r.id)) + '</td>' +
            '<td>' + cond + '</td>' +
            '<td dir="ltr">' + catDisp + '</td>' +
            '<td>' + escCbp(String(r.min_buy_qty != null ? r.min_buy_qty : 2)) + '</td>' +
            '<td>' + gkind + '</td>' +
            '<td>' + escCbp(gcharge) + '</td>' +
            '<td>' + (parseInt(r.requires_registered_account, 10) === 1 ? 'مسجّل فقط' : 'الكل') + '</td>' +
            '<td>' + escCbp(String(r.sort_order)) + '</td>' +
            '<td>' + (parseInt(r.is_active, 10) === 1 ? 'نعم' : 'لا') + '</td>' +
            '<td><button type="button" class="btn-secondary" data-cbp-edit="' + escCbp(String(r.id)) + '">تعديل</button></td>';
        tb.appendChild(tr);
    });
    tb.querySelectorAll('[data-cbp-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = parseInt(btn.getAttribute('data-cbp-edit'), 10);
            const row = rows.find(function (x) { return parseInt(x.id, 10) === id; });
            if (row) editCartBogoPromotion(row);
        });
    });
}

async function saveCartBogoPromotion() {
    const bogoEl = document.querySelector('input[name="cbp_bogo"]:checked');
    const giftEl = document.querySelector('input[name="cbp_gift"]:checked');
    const res = await postJSON('/admin/api/cart_bogo_promotions/manage.php', {
        action: 'save',
        id: parseInt(document.getElementById('cbp_id').value, 10) || 0,
        bogo_kind: bogoEl ? bogoEl.value : 'same_variant',
        category_id: parseInt(document.getElementById('cbp_cat').value, 10) || 0,
        min_buy_qty: parseInt(document.getElementById('cbp_minbuy').value, 10) || 2,
        sort_order: parseInt(document.getElementById('cbp_sort').value, 10) || 0,
        requires_registered_account: document.getElementById('cbp_reg').checked ? 1 : 0,
        is_active: document.getElementById('cbp_active').checked ? 1 : 0,
        gift_kind: giftEl ? giftEl.value : 'choice',
        fixed_variant_id: parseInt(document.getElementById('cbp_fixed').value, 10) || 0,
        pool_variant_ids_text: document.getElementById('cbp_pool').value,
        buy_components_text: document.getElementById('cbp_buy_comp').value,
        gift_unit_charge_kind: document.getElementById('cbp_gift_charge_kind').value,
        gift_unit_charge_value: parseFloat(document.getElementById('cbp_gift_charge_val').value) || 0
    });
    alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
    if (res.success) {
        resetCartBogoPromotionForm();
        loadCartBogoPromotions();
    }
}

cbpToggleBogo();
cbpToggleGift();
cbpToggleGiftCharge();
loadCartBogoPromotions();
</script>
