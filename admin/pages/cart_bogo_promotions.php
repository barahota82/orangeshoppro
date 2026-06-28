<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/cart_promo_products.php';

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
$cbpPickRows = orange_cart_promo_admin_product_rows($pdo);
$cbpPickJson = json_encode($cbpPickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS);
?>
<div class="page-title">
    <h1>عروض BOGO (منتج / فئة / حزمة شراء)</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>
<?php require_once __DIR__ . '/../../includes/offer_gl_link_card.php'; echo orange_offer_gl_link_card_html($pdo, ['promo_bogo_discount']); ?>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>cart_bogo_promotions</code> غير جاهز.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل</h3>
    <input type="hidden" id="cbp_id" value="0">
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;margin-bottom:14px;">
        <div style="max-width:120px;">
            <label for="cbp_sort">الترتيب</label>
            <input type="number" id="cbp_sort" class="admin-inp" value="0" readonly tabindex="-1" title="يُحدَّد تلقائياً" style="background:#f1f5f9;color:#64748b;cursor:not-allowed;text-align:center;">
        </div>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" id="cbp_active" checked> نشط
        </label>
    </div>
    <div class="form-grid">
        <div>
            <label for="cbp_name_ar">اسم العرض (عربي)</label>
            <input type="text" id="cbp_name_ar" class="admin-inp" dir="auto" placeholder="مثال: اشترِ قطعتين والثالثة هدية">
        </div>
        <div>
            <label for="cbp_name_en">English</label>
            <input type="text" id="cbp_name_en" class="admin-inp" dir="ltr" lang="en" placeholder="Buy 2 get 1">
        </div>
    </div>
    <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:18px;align-items:center;">
        <button type="button" class="btn-secondary" onclick="cbpTranslateOfferFromAr()">ترجمة تلقائية من العربي</button>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" id="cbp_show_name"> <strong>السماح بظهور الاسم للعميل</strong>
        </label>
    </div>
    <div class="form-grid">
        <div style="grid-column:1/-1;">
            <label><strong>شرط السلة</strong></label>
            <div style="display:flex;gap:1.25rem;flex-wrap:wrap;margin-top:6px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="radio" name="cbp_bogo" value="same_variant" checked onchange="cbpToggleBogo()"> نفس المنتج (أي لون/مقاس)
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="radio" name="cbp_bogo" value="same_category" onchange="cbpToggleBogo()"> منتجات مختلفة من نفس الفئة
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="radio" name="cbp_bogo" value="buy_bundle" onchange="cbpToggleBogo()"> حزمة شراء (منتج أ + منتج ب…)
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
            <p class="alert-error" style="margin:0 0 10px;line-height:1.55;">
                عروض «نفس الفئة» تتطلّب <strong>الشجرة الموحّدة</strong> (سجل الترحيل + أقسام في <code dir="ltr">catalog_sections</code>) ثم اختيار أو إدخال <code dir="ltr">catalog_categories.id</code> من
                <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=unified_catalog_branches'), ENT_QUOTES, 'UTF-8'); ?>">فروع شجرة المنتجات</a>.
                لا يُدعم إدخال <code dir="ltr">categories.id</code> التراثي من هذه الشاشة.
            </p>
            <input type="hidden" id="cbp_cat" value="0" aria-hidden="true">
            <?php endif; ?>
        </div>
        <div id="cbp_buy_bundle_wrap" style="grid-column:1/-1;display:none;">
            <label>منتجات حزمة الشراء</label>
            <div style="margin:6px 0 8px;">
                <button type="button" class="btn-secondary" id="cbp_buy_add_btn">إضافة منتج (دبل كليك من القائمة)</button>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>كود</th><th>المنتج</th><th>الكمية</th><th></th></tr></thead>
                    <tbody id="cbp_buy_body"></tbody>
                </table>
            </div>
        </div>
        <div id="cbp_minbuy_wrap">
            <label>الحد الأدنى للكمية / عدد المنتجات المختلفة</label>
            <input type="number" id="cbp_minbuy" class="admin-inp" min="2" step="1" value="2" style="max-width:12rem;" dir="ltr">
        </div>
        <?php $ocpFieldPrefix = 'cbp'; require __DIR__ . '/../partials/cart_promo_schedule_fields.inc.php'; ?>
        <div style="grid-column:1/-1;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;" title="عند التفعيل يظهر سعر تجزئة الهدية مشطوباً بجوار سعرها المجاني/المخفّض للعميل (يتطلب الترخيص اللازم)">
                <input type="checkbox" id="cbp_show_old_price"> <strong>إظهار السعر القديم</strong>
            </label>
        </div>
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
            <label>منتجات مجموعة اختيار الهدية</label>
            <div style="margin:6px 0 8px;">
                <button type="button" class="btn-secondary" id="cbp_pool_add_btn">إضافة منتج (دبل كليك من القائمة)</button>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>كود</th><th>المنتج</th><th></th></tr></thead>
                    <tbody id="cbp_pool_body"></tbody>
                </table>
            </div>
        </div>
        <div id="cbp_block_fixed" style="grid-column:1/-1;display:none;">
            <label>منتج الهدية الثابتة</label>
            <div style="margin:6px 0 8px;">
                <button type="button" class="btn-secondary" id="cbp_fixed_pick_btn">اختيار منتج (دبل كليك من القائمة)</button>
            </div>
            <p id="cbp_fixed_label" class="page-subtitle" style="margin:0;">— لم يُختر منتج —</p>
            <input type="hidden" id="cbp_fixed_pid" value="0">
        </div>
        <div style="grid-column:1/-1;">
            <label for="cbp_gift_charge_kind"><strong>تسعير بند هدية BOGO (ب)</strong></label>
            <select id="cbp_gift_charge_kind" class="admin-inp" style="max-width:28rem;margin-top:6px;" onchange="cbpToggleGiftCharge()">
                <option value="free">مجانية بالكامل (سطر هدية بسعر صفر)</option>
                <option value="percent_off">خصم نسبة من سعر التجزئة للوحدة</option>
                <option value="amount_off_unit">خصم مبلغ ثابت من سعر التجزئة للوحدة</option>
                <option value="fixed_unit">سعر بيع ثابت للوحدة (د.ك)</option>
            </select>
        </div>
        <div id="cbp_gift_charge_val_wrap" style="grid-column:1/-1;display:none;">
            <label id="cbp_gift_charge_val_label">القيمة</label>
            <input type="number" id="cbp_gift_charge_val" class="admin-inp" min="0" step="0.0001" style="max-width:14rem;" dir="ltr" value="0">
        </div>
        <div style="grid-column:1/-1;display:flex;flex-wrap:wrap;gap:20px;align-items:center;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" id="cbp_reg"> <strong>للمسجّلين فقط</strong>
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" id="cbp_first_delivered"> <strong>أول طلب مُسلَّم</strong>
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
                    <th>الاسم</th>
                    <th>الشرط</th>
                    <th>فئة</th>
                    <th>حد أدنى</th>
                    <th>هدية</th>
                    <th>تسعير ب</th>
                    <th>نطاق</th>
                    <th>الفترة</th>
                    <th>الحالة</th>
                    <th>ترتيب</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="cbp_tbody"></tbody>
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
            <tbody id="cbp_history_tbody">
                <tr><td colspan="6" class="muted">جارٍ التحميل...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/admin_cart_promo_product_pick.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
<?php require __DIR__ . '/../partials/cart_promo_schedule_js.inc.php'; ?>
var CBP_CATEGORY_LABEL_MAP = <?php echo json_encode($cartBogoCategoryLabelJs, JSON_UNESCAPED_UNICODE); ?>;
var CBP_PICK_ROWS = <?php echo $cbpPickJson !== false ? $cbpPickJson : '[]'; ?>;

function cbpPickMeta(pid) {
    var id = parseInt(pid, 10) || 0;
    for (var i = 0; i < CBP_PICK_ROWS.length; i++) {
        if (parseInt(CBP_PICK_ROWS[i].product_id, 10) === id) {
            return CBP_PICK_ROWS[i];
        }
    }
    return { product_id: id, code: 'P' + id, name: '' };
}

function cbpFmtComps(comps) {
    if (!comps || !comps.length) return '—';
    return comps.map(function (c) {
        var n = c.product_name || c.code || ('#' + c.product_id);
        return n + '×' + String(c.qty);
    }).join(' + ');
}

function cbpFmtPoolIds(ids) {
    if (!ids || !ids.length) return '—';
    return ids.map(function (pid) {
        var m = cbpPickMeta(pid);
        return (m.code ? m.code + ' — ' : '') + (m.name || ('#' + pid));
    }).join('؛ ');
}

function cbpBuyRows() {
    var tb = document.getElementById('cbp_buy_body');
    if (!tb) return [];
    var out = [];
    tb.querySelectorAll('tr').forEach(function (tr) {
        var pid = parseInt(tr.getAttribute('data-product-id'), 10) || 0;
        var qEl = tr.querySelector('.cbp-buy-qty');
        var q = qEl ? parseInt(qEl.value, 10) || 0 : 0;
        if (pid > 0 && q > 0) out.push({ product_id: pid, qty: q });
    });
    return out;
}

function cbpAddBuyRow(c) {
    var tb = document.getElementById('cbp_buy_body');
    if (!tb) return;
    var pid = parseInt(c.product_id, 10) || 0;
    var tr = document.createElement('tr');
    tr.setAttribute('data-product-id', String(pid));
    tr.innerHTML =
        '<td dir="ltr">' + (c.code ? String(c.code) : ('P' + pid)) + '</td>' +
        '<td>' + (c.product_name ? String(c.product_name) : '') + '</td>' +
        '<td><input type="number" class="cbp-buy-qty admin-inp-qty" min="1" step="1" value="' + (parseInt(c.qty, 10) || 1) + '" style="width:5rem;"></td>' +
        '<td><button type="button" class="btn-secondary cbp-rm">&times;</button></td>';
    tr.querySelector('.cbp-rm').addEventListener('click', function () { tr.remove(); });
    tb.appendChild(tr);
}

function cbpRenderBuy(comps) {
    var tb = document.getElementById('cbp_buy_body');
    if (!tb) return;
    tb.innerHTML = '';
    (comps || []).forEach(function (c) { cbpAddBuyRow(c); });
}

function cbpPoolRows() {
    var tb = document.getElementById('cbp_pool_body');
    if (!tb) return [];
    var out = [];
    tb.querySelectorAll('tr').forEach(function (tr) {
        var pid = parseInt(tr.getAttribute('data-product-id'), 10) || 0;
        if (pid > 0) out.push(pid);
    });
    return out;
}

function cbpAddPoolRow(pid) {
    var id = parseInt(pid, 10) || 0;
    if (id <= 0) return;
    var tb = document.getElementById('cbp_pool_body');
    if (!tb) return;
    var dup = false;
    tb.querySelectorAll('tr').forEach(function (tr) {
        if (parseInt(tr.getAttribute('data-product-id'), 10) === id) dup = true;
    });
    if (dup) return;
    var m = cbpPickMeta(id);
    var tr = document.createElement('tr');
    tr.setAttribute('data-product-id', String(id));
    tr.innerHTML =
        '<td dir="ltr">' + (m.code ? String(m.code) : ('P' + id)) + '</td>' +
        '<td>' + (m.name ? String(m.name) : '') + '</td>' +
        '<td><button type="button" class="btn-secondary cbp-rm">&times;</button></td>';
    tr.querySelector('.cbp-rm').addEventListener('click', function () { tr.remove(); });
    tb.appendChild(tr);
}

function cbpRenderPool(ids) {
    var tb = document.getElementById('cbp_pool_body');
    if (!tb) return;
    tb.innerHTML = '';
    (ids || []).forEach(function (pid) { cbpAddPoolRow(pid); });
}

function cbpSetFixed(pid) {
    var id = parseInt(pid, 10) || 0;
    document.getElementById('cbp_fixed_pid').value = String(id);
    var lab = document.getElementById('cbp_fixed_label');
    if (!lab) return;
    if (id <= 0) {
        lab.textContent = '— لم يُختر منتج —';
        return;
    }
    var m = cbpPickMeta(id);
    lab.textContent = (m.code ? m.code + ' — ' : '') + (m.name || ('منتج #' + id));
}

function cbpOpenPick(onPick) {
    if (!window.OrangeCartPromoProductPick) return;
    OrangeCartPromoProductPick.open(CBP_PICK_ROWS, onPick);
}
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
    document.getElementById('cbp_name_ar').value = '';
    document.getElementById('cbp_name_en').value = '';
    document.getElementById('cbp_show_name').checked = false;
    var cbpShowOldReset = document.getElementById('cbp_show_old_price');
    if (cbpShowOldReset) cbpShowOldReset.checked = false;
    document.getElementById('cbp_cat').value = '';
    document.getElementById('cbp_minbuy').value = '2';
    document.getElementById('cbp_sort').value = String(cbpComputeNextSort());
    cbpRenderPool([]);
    cbpSetFixed(0);
    cbpRenderBuy([]);
    document.querySelector('input[name="cbp_bogo"][value="same_variant"]').checked = true;
    document.querySelector('input[name="cbp_gift"][value="choice"]').checked = true;
    document.getElementById('cbp_reg').checked = false;
    document.getElementById('cbp_first_delivered').checked = false;
    document.getElementById('cbp_active').checked = true;
    ocpSetAlwaysOn('cbp', false);
    ocpDefaultScheduleDates('cbp');
    document.getElementById('cbp_gift_charge_kind').value = 'free';
    document.getElementById('cbp_gift_charge_val').value = '0';
    cbpToggleBogo();
    cbpToggleGift();
    cbpToggleGiftCharge();
}

function editCartBogoPromotion(row) {
    document.getElementById('cbp_id').value = String(row.id != null ? row.id : 0);
    document.getElementById('cbp_name_ar').value = row.name_ar != null ? String(row.name_ar) : '';
    document.getElementById('cbp_name_en').value = row.name_en != null ? String(row.name_en) : '';
    document.getElementById('cbp_show_name').checked = parseInt(row.show_name_to_customer, 10) === 1;
    var cbpShowOldEl = document.getElementById('cbp_show_old_price');
    if (cbpShowOldEl) cbpShowOldEl.checked = parseInt(row.show_old_price_to_customer, 10) === 1;
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
    cbpRenderPool(row.pool_product_ids || row.pool_variant_ids || []);
    cbpSetFixed(row.fixed_product_id || row.fixed_variant_id || 0);
    document.getElementById('cbp_reg').checked = parseInt(row.requires_registered_account, 10) === 1;
    document.getElementById('cbp_first_delivered').checked = parseInt(row.first_delivered_order_only, 10) === 1;
    document.getElementById('cbp_active').checked = parseInt(row.is_active, 10) === 1;
    ocpSetAlwaysOn('cbp', parseInt(row.is_always_on, 10) === 1);
    ocpSetDmyFromIso('cbp_valid_from', row.valid_from);
    ocpSetDmyFromIso('cbp_valid_to', row.valid_to);
    cbpRenderBuy(row.buy_components || []);
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

var cbpNameArTimer = null;
var cbpNameEnTimer = null;
var CBP_ROWS = [];

function cbpComputeNextSort() {
    var max = 0;
    (CBP_ROWS || []).forEach(function (r) {
        var s = parseInt(r.sort_order, 10) || 0;
        if (s > max) max = s;
    });
    return max + 1;
}

function cbpRowName(row) {
    var ar = (row.name_ar != null ? String(row.name_ar) : '').trim();
    if (ar !== '') return ar;
    var en = (row.name_en != null ? String(row.name_en) : '').trim();
    return en !== '' ? en : ('#' + row.id);
}

async function cbpTranslateNames(opts) {
    var silent = !!(opts && opts.silent);
    var forceFromArabic = !!(opts && opts.forceFromArabic);
    var arEl = document.getElementById('cbp_name_ar');
    var enEl = document.getElementById('cbp_name_en');
    if (!arEl || !enEl) return;
    try {
        var res = await postJSON('/admin/api/translate/names.php', {
            name_ar: arEl.value.trim(),
            name_en: forceFromArabic ? '' : enEl.value.trim()
        });
        if (!res || !res.success) {
            if (!silent) alert((res && res.message) ? res.message : 'فشل الترجمة');
            return;
        }
        if (res.name_en != null) enEl.value = String(res.name_en);
    } catch (e) {
        if (!silent) alert('تعذر الاتصال بخدمة الترجمة');
    }
}

function cbpScheduleNameFromAr() {
    var arEl = document.getElementById('cbp_name_ar');
    if (!arEl) return;
    clearTimeout(cbpNameArTimer);
    cbpNameArTimer = setTimeout(function () {
        cbpTranslateNames({ silent: true, forceFromArabic: true });
    }, 700);
}

function cbpScheduleNameFromEn() {
    var enEl = document.getElementById('cbp_name_en');
    if (!enEl || enEl.value.trim() === '') return;
    clearTimeout(cbpNameEnTimer);
    cbpNameEnTimer = setTimeout(function () {
        cbpTranslateNames({ silent: true, forceFromArabic: false });
    }, 600);
}

async function cbpTranslateOfferFromAr() {
    await cbpTranslateNames({ silent: false, forceFromArabic: true });
}
window.cbpTranslateOfferFromAr = cbpTranslateOfferFromAr;

async function loadCartBogoPromotions() {
    const res = await postJSON('/admin/api/cart_bogo_promotions/manage.php', { action: 'list' });
    if (!res.success) {
        alert(res.message || 'خطأ');
        return;
    }
    const rows = res.data || [];
    CBP_ROWS = rows;
    const tb = document.getElementById('cbp_tbody');
    tb.innerHTML = '';
    rows.forEach(function (r) {
        let cond = 'نفس المنتج';
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
        var cbpScope = (parseInt(r.requires_registered_account, 10) === 1 ? 'مسجّل فقط' : 'الكل') + (parseInt(r.first_delivered_order_only, 10) === 1 ? ' • أول طلب مُسلَّم' : '');
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td>' + escCbp(String(r.id)) + '</td>' +
            '<td>' + escCbp(cbpRowName(r)) + '</td>' +
            '<td>' + cond + '</td>' +
            '<td dir="ltr">' + catDisp + '</td>' +
            '<td>' + escCbp(String(r.min_buy_qty != null ? r.min_buy_qty : 2)) + '</td>' +
            '<td>' + gkind + '</td>' +
            '<td>' + escCbp(gcharge) + '</td>' +
            '<td>' + escCbp(cbpScope) + '</td>' +
            '<td dir="ltr">' + escCbp(ocpScheduleLabel(r)) + '</td>' +
            '<td>' + escCbp(ocpStatusLabel(r)) + '</td>' +
            '<td>' + escCbp(String(r.sort_order)) + '</td>' +
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
    if ((parseInt(document.getElementById('cbp_id').value, 10) || 0) === 0) {
        document.getElementById('cbp_sort').value = String(cbpComputeNextSort());
    }
}

async function saveCartBogoPromotion() {
    const bogoEl = document.querySelector('input[name="cbp_bogo"]:checked');
    const giftEl = document.querySelector('input[name="cbp_gift"]:checked');
    const bogoKind = bogoEl ? bogoEl.value : 'same_variant';
    const payload = {
        action: 'save',
        id: parseInt(document.getElementById('cbp_id').value, 10) || 0,
        bogo_kind: bogoKind,
        category_id: parseInt(document.getElementById('cbp_cat').value, 10) || 0,
        name_ar: document.getElementById('cbp_name_ar').value.trim(),
        name_en: document.getElementById('cbp_name_en').value.trim(),
        show_name_to_customer: document.getElementById('cbp_show_name').checked ? 1 : 0,
        show_old_price_to_customer: (document.getElementById('cbp_show_old_price') && document.getElementById('cbp_show_old_price').checked) ? 1 : 0,
        min_buy_qty: parseInt(document.getElementById('cbp_minbuy').value, 10) || 2,
        requires_registered_account: document.getElementById('cbp_reg').checked ? 1 : 0,
        first_delivered_order_only: document.getElementById('cbp_first_delivered').checked ? 1 : 0,
        is_active: document.getElementById('cbp_active').checked ? 1 : 0,
        is_always_on: ocpIsAlwaysOn('cbp') ? 1 : 0,
        gift_kind: giftEl ? giftEl.value : 'choice',
        fixed_product_id: parseInt(document.getElementById('cbp_fixed_pid').value, 10) || 0,
        pool_product_ids: cbpPoolRows(),
        gift_unit_charge_kind: document.getElementById('cbp_gift_charge_kind').value,
        gift_unit_charge_value: parseFloat(document.getElementById('cbp_gift_charge_val').value) || 0,
        valid_from: ocpGetIso('cbp_valid_from'),
        valid_to: ocpGetIso('cbp_valid_to')
    };
    if (bogoKind === 'buy_bundle') {
        payload.buy_components = cbpBuyRows();
    }
    const res = await postJSON('/admin/api/cart_bogo_promotions/manage.php', payload);
    alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
    if (res.success) {
        resetCartBogoPromotionForm();
        loadCartBogoPromotions();
        loadCartBogoAlwaysOnHistory();
    }
}

function cbpAdminName(name) {
    var s = String(name || '').trim();
    return s !== '' ? s : '—';
}

async function loadCartBogoAlwaysOnHistory() {
    var tb = document.getElementById('cbp_history_tbody');
    if (!tb) return;
    var res = await postJSON('/admin/api/cart_bogo_promotions/manage.php', { action: 'always_on_history' });
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
            '<td>' + escCbp(String(row.id || '')) + '</td>' +
            '<td>عرض #' + escCbp(String(row.promotion_id || '')) + '</td>' +
            '<td dir="ltr">' + escCbp(String(row.started_at || '')) + '</td>' +
            '<td dir="ltr">' + escCbp(String(row.ended_at || '')) + '</td>' +
            '<td>' + escCbp(cbpAdminName(row.started_by_name)) + '</td>' +
            '<td>' + escCbp(cbpAdminName(row.ended_by_name)) + '</td>';
        tb.appendChild(tr);
    });
}

document.getElementById('cbp_buy_add_btn').addEventListener('click', function () {
    cbpOpenPick(function (row) {
        cbpAddBuyRow({ product_id: row.product_id, code: row.code, product_name: row.name, qty: 1 });
    });
});
document.getElementById('cbp_pool_add_btn').addEventListener('click', function () {
    cbpOpenPick(function (row) { cbpAddPoolRow(row.product_id); });
});
document.getElementById('cbp_fixed_pick_btn').addEventListener('click', function () {
    cbpOpenPick(function (row) { cbpSetFixed(row.product_id); });
});
(function () {
    var arEl = document.getElementById('cbp_name_ar');
    var enEl = document.getElementById('cbp_name_en');
    if (arEl) arEl.addEventListener('input', cbpScheduleNameFromAr);
    if (enEl) enEl.addEventListener('input', cbpScheduleNameFromEn);
})();
cbpToggleBogo();
cbpToggleGift();
cbpToggleGiftCharge();
ocpBindAlwaysOn('cbp');
ocpDefaultScheduleDates('cbp');
cbpRenderBuy([]);
cbpRenderPool([]);
loadCartBogoPromotions();
loadCartBogoAlwaysOnHistory();
</script>
