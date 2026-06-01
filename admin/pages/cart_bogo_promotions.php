<?php

declare(strict_types=1);

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
<div class="page-title page-title--stacked">
    <h1>عروض BOGO</h1>
    <p class="page-subtitle">اشترِ بشرط (نفس منتج / فئة / حزمة) واحصل على هدية — منتج كامل في الأدمن؛ اللون والمقاس عند الدفع.</p>
</div>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>cart_bogo_promotions</code> غير جاهز.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل قاعدة</h3>
    <input type="hidden" id="cbp_id" value="0">
    <div class="ocp-form">
        <section class="ocp-section">
            <h4 class="ocp-section__title">١ — شرط الشراء (متى يُطبَّق العرض)</h4>
            <div class="ocp-section__body">
                <div class="ocp-choices">
                    <label class="ocp-choice">
                        <input type="radio" name="cbp_bogo" value="same_variant" checked onchange="cbpToggleBogo()">
                        <span>نفس المنتج — أي لون/مقاس</span>
                    </label>
                    <label class="ocp-choice">
                        <input type="radio" name="cbp_bogo" value="same_category" onchange="cbpToggleBogo()">
                        <span>منتجات مختلفة من نفس الفئة</span>
                    </label>
                    <label class="ocp-choice">
                        <input type="radio" name="cbp_bogo" value="buy_bundle" onchange="cbpToggleBogo()">
                        <span>حزمة شراء (منتج أ + ب…)</span>
                    </label>
                </div>
                <div id="cbp_cat_wrap" class="ocp-panel" style="display:none;">
                    <?php if ($cartBogoUnifiedCategoryHint && $cartBogoCatalogCategoryDropdown !== []): ?>
                    <label for="cbp_cat">الفئة في الشجرة الموحّدة</label>
                    <p class="card-hint" style="margin:6px 0 10px;">تُقيَّد السلة بمسار <code dir="ltr">catalog_categories.id</code> المختار.</p>
                    <select id="cbp_cat" class="admin-inp" style="max-width:100%;width:min(42rem,100%);">
                        <option value="">— اختر فئة —</option>
                        <?php foreach ($cartBogoCatalogCategoryDropdown as $opt): ?>
                        <option value="<?php echo (int) $opt['id']; ?>"><?php echo htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php elseif ($cartBogoUnifiedCategoryHint): ?>
                    <label for="cbp_cat">معرّف فئة الشجرة</label>
                    <p class="card-hint" style="margin:6px 0 10px;">لا فئات نشطة — أدخل <code dir="ltr">id</code> أو أنشئ من فروع الشجرة.</p>
                    <input type="number" id="cbp_cat" class="admin-inp" min="1" step="1" dir="ltr">
                    <?php else: ?>
                    <p class="alert-error" style="margin:0;line-height:1.55;">
                        «نفس الفئة» تتطلّب الشجرة الموحّدة —
                        <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=unified_catalog_branches'), ENT_QUOTES, 'UTF-8'); ?>">فروع شجرة المنتجات</a>.
                    </p>
                    <input type="hidden" id="cbp_cat" value="0" aria-hidden="true">
                    <?php endif; ?>
                </div>
                <div id="cbp_buy_bundle_wrap" class="ocp-product-panel" style="display:none;">
                    <div class="ocp-toolbar">
                        <button type="button" class="btn-secondary" id="cbp_buy_add_btn">+ إضافة منتج للحزمة</button>
                        <span class="card-hint">منتجان مختلفان على الأقل، لكل منتج كمية.</span>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>كود</th><th>المنتج</th><th>الكمية</th><th></th></tr></thead>
                            <tbody id="cbp_buy_body"></tbody>
                        </table>
                    </div>
                </div>
                <div id="cbp_minbuy_wrap" class="ocp-meta-row">
                    <div>
                        <label>الحد الأدنى (كمية أو عدد منتجات)</label>
                        <input type="number" id="cbp_minbuy" class="admin-inp" min="2" step="1" value="2" dir="ltr">
                    </div>
                </div>
            </div>
        </section>
        <section class="ocp-section">
            <h4 class="ocp-section__title">٢ — الهدية (ب)</h4>
            <div class="ocp-section__body">
                <div class="ocp-choices">
                    <label class="ocp-choice">
                        <input type="radio" name="cbp_gift" value="choice" checked onchange="cbpToggleGift()">
                        <span>اختيار من مجموعة منتجات</span>
                    </label>
                    <label class="ocp-choice">
                        <input type="radio" name="cbp_gift" value="fixed" onchange="cbpToggleGift()">
                        <span>هدية ثابتة</span>
                    </label>
                </div>
                <div id="cbp_block_pool" class="ocp-product-panel">
                    <div class="ocp-toolbar">
                        <button type="button" class="btn-secondary" id="cbp_pool_add_btn">+ إضافة منتج للهدية</button>
                        <span class="card-hint">نقرتان للاختيار — العميل يختار اللون/المقاس لاحقاً.</span>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>كود</th><th>المنتج</th><th></th></tr></thead>
                            <tbody id="cbp_pool_body"></tbody>
                        </table>
                    </div>
                </div>
                <div id="cbp_block_fixed" class="ocp-panel" style="display:none;">
                    <div class="ocp-fixed-pick">
                        <button type="button" class="btn-secondary" id="cbp_fixed_pick_btn">اختيار منتج</button>
                        <p id="cbp_fixed_label" class="ocp-fixed-pick__label">— لم يُختر منتج —</p>
                    </div>
                    <input type="hidden" id="cbp_fixed_pid" value="0">
                </div>
            </div>
        </section>
        <section class="ocp-section">
            <h4 class="ocp-section__title">٣ — تسعير بند الهدية</h4>
            <div class="ocp-section__body ocp-meta-row">
                <div style="grid-column:1/-1;">
                    <label for="cbp_gift_charge_kind">نوع التسعير</label>
                    <select id="cbp_gift_charge_kind" class="admin-inp" onchange="cbpToggleGiftCharge()">
                        <option value="free">مجانية بالكامل</option>
                        <option value="percent_off">خصم نسبة من التجزئة</option>
                        <option value="amount_off_unit">خصم مبلغ من التجزئة للوحدة</option>
                        <option value="fixed_unit">سعر بيع ثابت للوحدة (د.ك)</option>
                    </select>
                </div>
                <div id="cbp_gift_charge_val_wrap" style="display:none;">
                    <label id="cbp_gift_charge_val_label">القيمة</label>
                    <input type="number" id="cbp_gift_charge_val" class="admin-inp" min="0" step="0.0001" dir="ltr" value="0">
                </div>
            </div>
        </section>
        <section class="ocp-section">
            <h4 class="ocp-section__title">٤ — الترتيب والنطاق</h4>
            <div class="ocp-section__body">
                <div class="ocp-meta-row">
                    <div>
                        <label>الترتيب</label>
                        <input type="number" id="cbp_sort" value="0" class="admin-inp" min="0" step="1">
                    </div>
                </div>
                <div class="ocp-flags">
                    <label class="ocp-flag">
                        <input type="checkbox" id="cbp_reg">
                        <span><strong>للمسجّلين فقط</strong></span>
                    </label>
                    <label class="ocp-flag">
                        <input type="checkbox" id="cbp_active" checked>
                        <span><strong>نشط</strong></span>
                    </label>
                </div>
            </div>
        </section>
    </div>
    <div class="admin-form-actions">
        <button type="button" onclick="saveCartBogoPromotion()" <?php echo !$hasTable ? 'disabled' : ''; ?>>حفظ</button>
        <button type="button" class="btn-secondary" onclick="resetCartBogoPromotionForm()">قاعدة جديدة</button>
    </div>
</div>

<div class="card ocp-list-card">
    <h3>القواعد المحفوظة</h3>
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

<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/admin_cart_promo_product_pick.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
var CBP_CATEGORY_LABEL_MAP = <?php echo json_encode($cartBogoCategoryLabelJs, JSON_UNESCAPED_UNICODE); ?>;
var CBP_PICK_ROWS = <?php echo $cbpPickJson !== false ? $cbpPickJson : '[]'; ?>;

function ocpEmptyRow(tb, cols, msg) {
    if (!tb) return;
    if (tb.querySelector('tr[data-product-id], tr[data-ocp-empty]')) return;
    var tr = document.createElement('tr');
    tr.setAttribute('data-ocp-empty', '1');
    tr.innerHTML = '<td colspan="' + cols + '" class="ocp-empty-row">' + msg + '</td>';
    tb.appendChild(tr);
}

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
    tb.querySelectorAll('tr[data-product-id]').forEach(function (tr) {
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
    var empty = tb.querySelector('tr[data-ocp-empty]');
    if (empty) empty.remove();
    var pid = parseInt(c.product_id, 10) || 0;
    var tr = document.createElement('tr');
    tr.setAttribute('data-product-id', String(pid));
    tr.innerHTML =
        '<td dir="ltr">' + (c.code ? String(c.code) : ('P' + pid)) + '</td>' +
        '<td>' + (c.product_name ? String(c.product_name) : '') + '</td>' +
        '<td><input type="number" class="cbp-buy-qty admin-inp-qty" min="1" step="1" value="' + (parseInt(c.qty, 10) || 1) + '" style="width:5rem;"></td>' +
        '<td><button type="button" class="btn-secondary cbp-rm">&times;</button></td>';
    tr.querySelector('.cbp-rm').addEventListener('click', function () {
        tr.remove();
        if (!tb.querySelector('tr[data-product-id]')) {
            ocpEmptyRow(tb, 4, 'لا منتجات في الحزمة');
        }
    });
    tb.appendChild(tr);
}

function cbpRenderBuy(comps) {
    var tb = document.getElementById('cbp_buy_body');
    if (!tb) return;
    tb.innerHTML = '';
    (comps || []).forEach(function (c) { cbpAddBuyRow(c); });
    if (!tb.querySelector('tr[data-product-id]')) {
        ocpEmptyRow(tb, 4, 'لا منتجات في الحزمة');
    }
}

function cbpPoolRows() {
    var tb = document.getElementById('cbp_pool_body');
    if (!tb) return [];
    var out = [];
    tb.querySelectorAll('tr[data-product-id]').forEach(function (tr) {
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
    tb.querySelectorAll('tr[data-product-id]').forEach(function (tr) {
        if (parseInt(tr.getAttribute('data-product-id'), 10) === id) dup = true;
    });
    if (dup) return;
    var empty = tb.querySelector('tr[data-ocp-empty]');
    if (empty) empty.remove();
    var m = cbpPickMeta(id);
    var tr = document.createElement('tr');
    tr.setAttribute('data-product-id', String(id));
    tr.innerHTML =
        '<td dir="ltr">' + (m.code ? String(m.code) : ('P' + id)) + '</td>' +
        '<td>' + (m.name ? String(m.name) : '') + '</td>' +
        '<td><button type="button" class="btn-secondary cbp-rm">&times;</button></td>';
    tr.querySelector('.cbp-rm').addEventListener('click', function () {
        tr.remove();
        if (!tb.querySelector('tr[data-product-id]')) {
            ocpEmptyRow(tb, 3, 'لا منتجات للهدية');
        }
    });
    tb.appendChild(tr);
}

function cbpRenderPool(ids) {
    var tb = document.getElementById('cbp_pool_body');
    if (!tb) return;
    tb.innerHTML = '';
    (ids || []).forEach(function (pid) { cbpAddPoolRow(pid); });
    if (!tb.querySelector('tr[data-product-id]')) {
        ocpEmptyRow(tb, 3, 'لا منتجات للهدية');
    }
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
    document.getElementById('cbp_minbuy_wrap').style.display = v === 'buy_bundle' ? 'none' : 'grid';
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
    cbpRenderPool([]);
    cbpSetFixed(0);
    cbpRenderBuy([]);
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
    cbpRenderPool(row.pool_product_ids || row.pool_variant_ids || []);
    cbpSetFixed(row.fixed_product_id || row.fixed_variant_id || 0);
    document.getElementById('cbp_reg').checked = parseInt(row.requires_registered_account, 10) === 1;
    document.getElementById('cbp_active').checked = parseInt(row.is_active, 10) === 1;
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
    const bogoKind = bogoEl ? bogoEl.value : 'same_variant';
    const payload = {
        action: 'save',
        id: parseInt(document.getElementById('cbp_id').value, 10) || 0,
        bogo_kind: bogoKind,
        category_id: parseInt(document.getElementById('cbp_cat').value, 10) || 0,
        min_buy_qty: parseInt(document.getElementById('cbp_minbuy').value, 10) || 2,
        sort_order: parseInt(document.getElementById('cbp_sort').value, 10) || 0,
        requires_registered_account: document.getElementById('cbp_reg').checked ? 1 : 0,
        is_active: document.getElementById('cbp_active').checked ? 1 : 0,
        gift_kind: giftEl ? giftEl.value : 'choice',
        fixed_product_id: parseInt(document.getElementById('cbp_fixed_pid').value, 10) || 0,
        pool_product_ids: cbpPoolRows(),
        gift_unit_charge_kind: document.getElementById('cbp_gift_charge_kind').value,
        gift_unit_charge_value: parseFloat(document.getElementById('cbp_gift_charge_val').value) || 0
    };
    if (bogoKind === 'buy_bundle') {
        payload.buy_components = cbpBuyRows();
    }
    const res = await postJSON('/admin/api/cart_bogo_promotions/manage.php', payload);
    alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
    if (res.success) {
        resetCartBogoPromotionForm();
        loadCartBogoPromotions();
    }
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
cbpRenderBuy([]);
cbpRenderPool([]);
cbpToggleBogo();
cbpToggleGift();
cbpToggleGiftCharge();
loadCartBogoPromotions();
</script>
