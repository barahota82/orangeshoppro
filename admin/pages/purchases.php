<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/catalog_taxonomy_migrate.php';
require_once __DIR__ . '/../../includes/party_subledger.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/supplier_payable_account.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

$pdo = orange_admin_page_pdo();

$adminCountryId = orange_admin_context_country_id($pdo);
$adminDefaultCurrency = orange_admin_context_currency_code($pdo);
$adminCurrencyUnit = orange_currency_display_unit($adminDefaultCurrency);
$purchasesProductsCountrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $adminCountryId);
$purchasesSuppliersCountrySql = orange_sql_country_and_fragment($pdo, 'suppliers', 'suppliers', $adminCountryId);

/* ── Products (with item_code / barcode when available) ────────────── */
$pv2ProdCols = 'p.id, p.name, p.cost, p.has_colors, p.has_sizes';
if (orange_table_has_column($pdo, 'products', 'item_code')) {
    $pv2ProdCols .= ', p.item_code';
}
if (orange_table_has_column($pdo, 'products', 'barcode')) {
    $pv2ProdCols .= ', p.barcode';
}
$products = $pdo->query(
    "SELECT $pv2ProdCols FROM products p WHERE p.is_active = 1" . $purchasesProductsCountrySql . ' ORDER BY p.name ASC'
)->fetchAll(PDO::FETCH_ASSOC);

/* ── Variants by product ───────────────────────────────────────────── */
$variantsByProduct = [];
$vRows = $pdo->query(
    'SELECT id, product_id, color, size FROM product_variants ORDER BY product_id ASC, id ASC'
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($vRows as $vr) {
    $pid = (int) $vr['product_id'];
    if (!isset($variantsByProduct[$pid])) {
        $variantsByProduct[$pid] = [];
    }
    $c = trim((string) ($vr['color'] ?? ''));
    $s = trim((string) ($vr['size'] ?? ''));
    $label = ($c !== '' || $s !== '')
        ? trim($c . ($c !== '' && $s !== '' ? ' / ' : '') . $s)
        : ('#' . (int) $vr['id']);
    $variantsByProduct[$pid][] = [
        'id' => (int) $vr['id'],
        'label' => $label,
    ];
}

/* ── Suppliers (same filter as partner_supplier_payment) ───────────── */
$suppliers = [];
$supplierPayableMap = [];
$pv2SupplierPickRows = [];
if (orange_table_exists($pdo, 'suppliers')) {
    $suppliers = $pdo->query(
        'SELECT id, name, phone FROM suppliers WHERE 1=1' . $purchasesSuppliersCountrySql . ' ORDER BY name ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($suppliers as $s) {
        $sid = (int) $s['id'];
        try {
            $aid = orange_supplier_payable_account_id($pdo, $sid);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] purchases supplier #' . $sid . ': ' . $e->getMessage());
            }
            $aid = 0;
        }
        $st = $pdo->prepare('SELECT id, code, name FROM accounts WHERE id = ? LIMIT 1');
        $st->execute([$aid]);
        $arow = $st->fetch(PDO::FETCH_ASSOC);
        $supplierPayableMap[$sid] = [
            'id' => $arow ? (int) $arow['id'] : $aid,
            'code' => $arow ? (string) ($arow['code'] ?? '') : '',
            'name' => $arow ? (string) ($arow['name'] ?? '') : ('#' . $aid),
        ];
    }
}

$supBal = [];
foreach ($suppliers as $s) {
    $supBal[(int) $s['id']] = orange_party_balance_supplier($pdo, (int) $s['id']);
}

$pv2ApParentId = orange_gl_supplier_parent_account_id($pdo);
$pv2ApDescendantSet = [];
if ($pv2ApParentId !== null && $pv2ApParentId > 0 && orange_table_has_column($pdo, 'accounts', 'parent_id')) {
    $pv2ApDescIds = [$pv2ApParentId];
    for ($depth = 0; $depth < 10; ++$depth) {
        $ph = implode(',', array_fill(0, count($pv2ApDescIds), '?'));
        $chSt = $pdo->prepare("SELECT id FROM accounts WHERE parent_id IN ($ph) AND id NOT IN ($ph)");
        $chSt->execute(array_merge($pv2ApDescIds, $pv2ApDescIds));
        $newIds = $chSt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($newIds === []) {
            break;
        }
        foreach ($newIds as $nid) {
            $pv2ApDescIds[] = (int) $nid;
        }
    }
    $pv2ApDescendantSet = array_flip($pv2ApDescIds);
}

foreach ($suppliers as $s) {
    $sid = (int) ($s['id'] ?? 0);
    if ($sid <= 0) {
        continue;
    }
    $map = $supplierPayableMap[$sid] ?? ['id' => 0, 'code' => '', 'name' => ''];
    $mapAccountId = (int) ($map['id'] ?? 0);
    if ($pv2ApDescendantSet !== [] && ($mapAccountId <= 0 || !isset($pv2ApDescendantSet[$mapAccountId]))) {
        continue;
    }
    $supplierName = trim((string) ($s['name'] ?? ''));
    $supplierPhone = trim((string) ($s['phone'] ?? ''));
    $accountCode = trim((string) ($map['code'] ?? ''));
    $accountName = trim((string) ($map['name'] ?? ''));
    $balance = (float) ($supBal[$sid] ?? 0.0);
    $pv2SupplierPickRows[] = [
        'id' => $sid,
        'name' => $supplierName,
        'phone' => $supplierPhone,
        'balance' => round($balance, 3),
        'account_id' => $mapAccountId,
        'account_code' => $accountCode,
        'account_name' => $accountName,
    ];
}

/* ── GL accounts ───────────────────────────────────────────────────── */
$inventoryAccId = orange_gl_account_id_optional($pdo, 'inventory');
$cashAccId = orange_gl_account_id_optional($pdo, 'cash');

$prefillSupplierId = 0;
$prefillStmtId = (int) ($_GET['stmt_party_id'] ?? 0);
$prefillStmtKind = trim((string) ($_GET['stmt_party_kind'] ?? ''));
$prefillSupplierDirect = (int) ($_GET['supplier_id'] ?? 0);
if ($prefillStmtKind === 'supplier' && $prefillStmtId > 0) {
    $prefillSupplierId = $prefillStmtId;
} elseif ($prefillSupplierDirect > 0) {
    $prefillSupplierId = $prefillSupplierDirect;
}

$pv2Ready = ($inventoryAccId !== null && $inventoryAccId > 0 && $cashAccId !== null && $cashAccId > 0);
$jvGlSettingsUrl = storefront_public_path('/admin/index.php?page=gl_account_settings');
$otherVouchersUrl = storefront_public_path('/admin/index.php?page=other_vouchers');
?>
<style>
/* صف 2 — 3 خانات في سطر واحد: تصغير الجانبين 0.35fr → ملاحظات +0.7fr (0.65 + 1.7 + 0.65 = 3fr) */
.form-grid.form-grid-3.pv2-header-row2 {
    grid-template-columns: minmax(6.5rem, 0.65fr) minmax(0, 1.7fr) minmax(5.5rem, 0.65fr);
}
</style>

<div class="page-title page-title--stacked">
    <div>
        <h1>فاتورة شراء</h1>
        <p class="card-hint" style="margin:0.35rem 0 0;">سياق الدولة — المبالغ بعملة <strong><?php echo htmlspecialchars($adminDefaultCurrency, ENT_QUOTES, 'UTF-8'); ?></strong>؛ الموردون والمنتجات لهذه الدولة فقط. يُولَّد القيد المحاسبي تلقائياً ويُعرض في <a href="<?php echo htmlspecialchars($otherVouchersUrl, ENT_QUOTES, 'UTF-8'); ?>">سندات أخرى</a>.</p>
    </div>
</div>

<?php if (!$pv2Ready): ?>
<div class="card" style="border:1px solid #fcd34d;background:#fffbeb;margin-bottom:12px;">
    <p class="card-hint" style="margin:0;line-height:1.55;">اربط حساب <strong>المخزون</strong> و<strong>الخزينة / النقدية</strong> من <a href="<?php echo htmlspecialchars($jvGlSettingsUrl, ENT_QUOTES, 'UTF-8'); ?>">حسابات القيود التلقائية</a>.</p>
</div>
<?php endif; ?>

<div class="card">
    <h3 class="card-title">فاتورة شراء</h3>

    <!-- ١ — المورد -->
    <div class="form-grid" style="margin-bottom:12px;">
        <div style="grid-column:1/-1;">
            <label for="pv2_supplier_code">المورد</label>
            <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,2fr);gap:10px 14px;">
                <input type="text" id="pv2_supplier_code" autocomplete="off" dir="ltr" lang="en" readonly placeholder="نقرتان للاختيار" title="نقرتان للاختيار" style="cursor:pointer;"<?php echo !$pv2Ready ? ' disabled' : ''; ?>>
                <input type="text" id="pv2_supplier_name" class="admin-inp-readonly" readonly disabled tabindex="-1" placeholder="يُعبأ تلقائياً">
            </div>
            <input type="hidden" id="pv2_supplier_id" value="0">
        </div>
    </div>

    <!-- ٢ — رقم فاتورة المورد، ملاحظات، نوع الشراء -->
    <div class="form-grid form-grid-3 pv2-header-row2" style="margin-bottom:16px;">
        <div>
            <label for="pv2_supplier_invoice">رقم فاتورة المورد</label>
            <input type="text" id="pv2_supplier_invoice" placeholder="رقم فاتورة المورد" dir="ltr" lang="en" autocomplete="off" maxlength="64"<?php echo !$pv2Ready ? ' disabled' : ''; ?>>
        </div>
        <div>
            <label for="pv2_notes">ملاحظات</label>
            <input type="text" id="pv2_notes" placeholder="شروط، ملاحظات إضافية، …"<?php echo !$pv2Ready ? ' disabled' : ''; ?>>
        </div>
        <div>
            <label for="pv2_type">نوع الشراء</label>
            <select id="pv2_type"<?php echo !$pv2Ready ? ' disabled' : ''; ?>>
                <option value="cash">نقدي</option>
                <option value="credit">آجل</option>
            </select>
        </div>
    </div>

    <!-- ٢ — أسطر الأصناف -->
    <h4 style="font-size:0.9rem;font-weight:600;color:#444;margin:0 0 10px;">أسطر الأصناف</h4>
    <div class="admin-doc-frame">
        <div class="table-wrap">
            <table class="admin-table admin-doc-lines-table pur-lines-table">
                <thead>
                    <tr>
                        <th class="pur-col-idx" style="width:2.5rem;">#</th>
                        <th style="min-width:8rem;">كود-باركود</th>
                        <th style="min-width:10rem;">الصنف</th>
                        <th style="min-width:8rem;">المتغير (لون/مقاس)</th>
                        <th style="width:5rem;">الكمية</th>
                        <th style="width:6rem;">تكلفة الوحدة</th>
                        <th style="width:6rem;">خصم</th>
                        <th style="width:7rem;">إجمالي السطر</th>
                        <th class="admin-doc-col-actions" aria-label="حذف السطر" style="width:3rem;"></th>
                    </tr>
                </thead>
                <tbody id="pv2_lines_body"></tbody>
            </table>
        </div>
    </div>

    <!-- ٣ — خصم الفاتورة + المجاميع -->
    <div style="margin-top:14px;display:flex;flex-wrap:wrap;align-items:flex-end;gap:14px 24px;">
        <div style="flex:0 0 auto;">
            <label for="pv2_invoice_discount" style="font-size:0.82rem;font-weight:600;">خصم الفاتورة</label>
            <input type="text" id="pv2_invoice_discount" placeholder="0 أو 5%" dir="ltr" lang="en" style="width:8rem;" autocomplete="off"<?php echo !$pv2Ready ? ' disabled' : ''; ?>>
        </div>
        <div style="flex:1 1 auto;text-align:left;direction:ltr;font-size:0.95rem;line-height:1.8;">
            <span style="color:#64748b;">إجمالي البنود:</span> <strong id="pv2_subtotal" class="admin-money-display" dir="ltr" lang="en"><?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?></strong><br>
            <span style="color:#64748b;">صافي الفاتورة:</span> <strong id="pv2_net_total" class="admin-money-display" dir="ltr" lang="en" style="color:#059669;"><?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
    </div>

    <!-- ٤ — أزرار -->
    <div class="actions admin-doc-lines-toolbar" style="margin-top:16px;">
        <span></span>
        <div class="jv-toolbar-primary-group">
            <button type="button" id="pv2_btn_new" title="فاتورة جديدة">فاتورة جديدة</button>
            <button type="button" id="pv2_btn_save"<?php echo !$pv2Ready ? ' disabled' : ''; ?>>حفظ</button>
        </div>
    </div>
</div>

<!-- Supplier Picker Modal -->
<div class="gl-pick-modal" id="pv2_supplier_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="pv2_supplier_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="pv2_supplier_pick_title">
        <h3 id="pv2_supplier_pick_title" class="gl-pick-modal__title">اختيار المورد</h3>
        <p class="gl-pick-modal__hint muted" style="margin:0 0 8px;font-size:0.9rem;">نقرتان للاختيار</p>
        <input type="search" id="pv2_supplier_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بكود الحساب أو اسم المورد…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="pv2_supplier_pick_list"></ul>
        <button type="button" class="btn-secondary" id="pv2_supplier_pick_close">إغلاق</button>
    </div>
</div>

<script>
(function () {
    var PV2_PRODUCTS = <?php echo json_encode($products, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var PV2_VARIANTS = <?php echo json_encode($variantsByProduct, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var PV2_SUPPLIER_PICK_ROWS = <?php echo json_encode($pv2SupplierPickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var PV2_SUPPLIER_PAYABLE = <?php echo json_encode($supplierPayableMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var PV2_PREFILL_SUPPLIER = <?php echo (int) $prefillSupplierId; ?>;
    var PV2_READY = <?php echo $pv2Ready ? 'true' : 'false'; ?>;

    var currentSupplierId = 0;

    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function fmt3(n) {
        var f = window.orangeFmtMoney || (window.OrangeMoney && window.OrangeMoney.formatAmount);
        if (f) {
            return f(n);
        }
        var d = (window.ORANGE_ADMIN_MONEY && window.ORANGE_ADMIN_MONEY.decimals) || 3;
        return (parseFloat(n) || 0).toFixed(d);
    }
    function fmtZero() {
        if (window.orangeMoneyZero) {
            return window.orangeMoneyZero();
        }
        return fmt3(0);
    }

    /* ── Product lookup by code/barcode ─────────────────────────────── */
    function findProductByCode(code) {
        code = String(code || '').trim();
        if (!code) return null;
        var lower = code.toLowerCase();
        for (var i = 0; i < PV2_PRODUCTS.length; i++) {
            var p = PV2_PRODUCTS[i];
            var ic = String(p.item_code || '').trim().toLowerCase();
            var bc = String(p.barcode || '').trim().toLowerCase();
            if ((ic && ic === lower) || (bc && bc === lower)) return p;
        }
        return null;
    }

    /* ── Discount parsing ───────────────────────────────────────────── */
    function parseDiscount(raw, lineAmount) {
        raw = String(raw || '').trim();
        if (!raw || raw === '0') return 0;
        if (raw.endsWith('%')) {
            var pct = parseFloat(raw.slice(0, -1)) || 0;
            return Math.round(lineAmount * pct / 100 * 10000) / 10000;
        }
        return parseFloat(raw) || 0;
    }

    /* ── Supplier picker ────────────────────────────────────────────── */
    function supplierById(id) {
        id = parseInt(String(id || '0'), 10) || 0;
        for (var i = 0; i < PV2_SUPPLIER_PICK_ROWS.length; i++) {
            if ((parseInt(String(PV2_SUPPLIER_PICK_ROWS[i].id || '0'), 10) || 0) === id) return PV2_SUPPLIER_PICK_ROWS[i];
        }
        return null;
    }

    function selectSupplier(id) {
        var row = supplierById(id);
        var codeEl = document.getElementById('pv2_supplier_code');
        var nameEl = document.getElementById('pv2_supplier_name');
        var idEl = document.getElementById('pv2_supplier_id');
        if (!row) {
            currentSupplierId = 0;
            if (codeEl) codeEl.value = '';
            if (nameEl) nameEl.value = '';
            if (idEl) idEl.value = '0';
        } else {
            currentSupplierId = parseInt(String(row.id), 10) || 0;
            if (codeEl) codeEl.value = row.account_code || '';
            if (nameEl) nameEl.value = row.name || '';
            if (idEl) idEl.value = String(currentSupplierId);
        }
    }

    function pickerOpen() {
        var modal = document.getElementById('pv2_supplier_pick_modal');
        var qEl = document.getElementById('pv2_supplier_pick_q');
        if (!modal || !qEl) return;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('gl-pick-open');
        qEl.value = '';
        pickerRender('');
        qEl.focus();
    }
    function pickerClose() {
        var modal = document.getElementById('pv2_supplier_pick_modal');
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('gl-pick-open');
    }
    function pickerRender(q) {
        var listEl = document.getElementById('pv2_supplier_pick_list');
        if (!listEl) return;
        var query = String(q || '').trim().toLowerCase();
        var rows = PV2_SUPPLIER_PICK_ROWS.filter(function (r) {
            if (!query) return true;
            var hay = (r.account_code + ' ' + r.account_name + ' ' + r.name + ' ' + r.phone).toLowerCase();
            return hay.indexOf(query) !== -1;
        });
        listEl.innerHTML = '';
        if (!rows.length) { listEl.innerHTML = '<li class="gl-pick-empty">لا نتائج</li>'; return; }
        rows.forEach(function (r) {
            var li = document.createElement('li');
            li.className = 'gl-pick-item';
            li.setAttribute('role', 'button');
            li.tabIndex = 0;
            li.textContent = (r.account_code ? r.account_code + ' — ' : '') + r.name + (r.phone ? ' (' + r.phone + ')' : '') + ' [رصيد ' + orangeFmtMoney(r.balance) + ']';
            li.addEventListener('dblclick', function () { selectSupplier(r.id); pickerClose(); });
            li.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { selectSupplier(r.id); pickerClose(); } });
            listEl.appendChild(li);
        });
    }

    /* ── Dynamic rows ───────────────────────────────────────────────── */
    function variantOptionsHtml(pid) {
        var list = (PV2_VARIANTS && PV2_VARIANTS[String(pid)]) ? PV2_VARIANTS[String(pid)] : [];
        if (!list.length) return '<option value="0">— لا متغيرات —</option>';
        return list.map(function (v) {
            return '<option value="' + v.id + '">' + esc(v.label) + '</option>';
        }).join('');
    }

    function productOptionsHtml() {
        var blank = '<option value="">' + esc('— اختر صنفاً —') + '</option>';
        return blank + PV2_PRODUCTS.map(function (p) {
            return '<option value="' + p.id + '" data-cost="' + (parseFloat(p.cost) || 0) + '">' + esc(p.name) + '</option>';
        }).join('');
    }

    function addLine() {
        var tb = document.getElementById('pv2_lines_body');
        if (!tb) return;
        var tr = document.createElement('tr');
        tr.className = 'pv2-line';
        tr.innerHTML =
            '<td class="pur-col-idx"></td>' +
            '<td><input type="text" class="pv2-barcode admin-inp" placeholder="كود/باركود" dir="ltr" lang="en" autocomplete="off" style="width:100%;"></td>' +
            '<td><select class="pv2-product" style="min-width:10rem;">' + productOptionsHtml() + '</select></td>' +
            '<td><select class="pv2-variant"></select></td>' +
            '<td><input type="number" class="pv2-qty admin-inp-qty" min="1" step="1" value="1" inputmode="numeric" lang="en" dir="ltr"></td>' +
            '<td><input type="number" class="pv2-cost admin-inp-money" min="0" step="any" value="' + fmtZero() + '" inputmode="decimal" lang="en" dir="ltr"></td>' +
            '<td><input type="text" class="pv2-discount admin-inp" placeholder="0" dir="ltr" lang="en" autocomplete="off" style="width:100%;"></td>' +
            '<td><input type="text" class="pv2-line-total admin-inp-money" value="' + fmtZero() + '" readonly data-money-allow-zero tabindex="-1" dir="ltr" lang="en"></td>' +
            '<td><button type="button" class="btn-secondary admin-doc-line-remove" title="حذف">&times;</button></td>';
        tb.appendChild(tr);
        renumberRows();
        var sel = tr.querySelector('.pv2-product');
        if (sel) updateVariantCell(sel);
        recalcAll();
    }

    function removeLine(btn) {
        var tb = document.getElementById('pv2_lines_body');
        if (!tb) return;
        if (tb.querySelectorAll('tr').length <= 1) {
            var tr = btn.closest('tr');
            tr.querySelector('.pv2-barcode').value = '';
            tr.querySelector('.pv2-product').value = '';
            tr.querySelector('.pv2-qty').value = '1';
            tr.querySelector('.pv2-cost').value = fmtZero();
            tr.querySelector('.pv2-discount').value = '';
            updateVariantCell(tr.querySelector('.pv2-product'));
            syncTrailing();
            recalcAll();
            return;
        }
        btn.closest('tr').remove();
        renumberRows();
        syncTrailing();
        recalcAll();
    }

    function renumberRows() {
        var tb = document.getElementById('pv2_lines_body');
        if (!tb) return;
        var rows = tb.querySelectorAll('tr');
        for (var i = 0; i < rows.length; i++) {
            var c = rows[i].querySelector('.pur-col-idx');
            if (c) c.textContent = String(i + 1);
        }
    }

    function rowIsBlank(tr) {
        var pid = parseInt(tr.querySelector('.pv2-product').value, 10) || 0;
        var q = parseInt(tr.querySelector('.pv2-qty').value, 10) || 0;
        var code = (tr.querySelector('.pv2-barcode').value || '').trim();
        return pid <= 0 && q <= 0 && !code;
    }

    function trimExtraTrailing() {
        var tb = document.getElementById('pv2_lines_body');
        if (!tb) return;
        for (;;) {
            var rows = tb.querySelectorAll('tr');
            if (rows.length < 2) return;
            var a = rows[rows.length - 2];
            var b = rows[rows.length - 1];
            if (rowIsBlank(a) && rowIsBlank(b)) {
                a.remove();
                renumberRows();
            } else {
                return;
            }
        }
    }

    function syncTrailing() {
        trimExtraTrailing();
        var tb = document.getElementById('pv2_lines_body');
        if (!tb) return;
        var rows = tb.querySelectorAll('tr');
        if (rows.length === 0) { addLine(); return; }
        var last = rows[rows.length - 1];
        if (!rowIsBlank(last)) addLine();
    }

    function updateVariantCell(sel) {
        var row = sel.closest('tr');
        var pid = parseInt(sel.value, 10) || 0;
        var vsel = row.querySelector('.pv2-variant');
        if (vsel) vsel.innerHTML = variantOptionsHtml(pid);
    }

    function onProductChanged(sel) {
        var row = sel.closest('tr');
        var pid = parseInt(sel.value, 10) || 0;
        var opt = sel.options[sel.selectedIndex];
        var cost = opt ? parseFloat(opt.getAttribute('data-cost') || '0') : 0;
        var costInp = row.querySelector('.pv2-cost');
        if (costInp && pid > 0) costInp.value = fmt3(cost);
        updateVariantCell(sel);
        var barcodeInp = row.querySelector('.pv2-barcode');
        if (barcodeInp && pid > 0) {
            var prod = null;
            for (var i = 0; i < PV2_PRODUCTS.length; i++) {
                if (PV2_PRODUCTS[i].id === pid || String(PV2_PRODUCTS[i].id) === String(pid)) { prod = PV2_PRODUCTS[i]; break; }
            }
            if (prod) {
                barcodeInp.value = prod.item_code || prod.barcode || '';
            }
        }
        recalcAll();
    }

    function onBarcodeBlurOrEnter(inp) {
        var code = (inp.value || '').trim();
        if (!code) return;
        var prod = findProductByCode(code);
        if (!prod) return;
        var row = inp.closest('tr');
        var sel = row.querySelector('.pv2-product');
        if (sel) {
            sel.value = String(prod.id);
            onProductChanged(sel);
        }
        syncTrailing();
    }

    /* ── Recalculate ────────────────────────────────────────────────── */
    function recalcAll() {
        var tb = document.getElementById('pv2_lines_body');
        if (!tb) return;
        var rows = tb.querySelectorAll('tr.pv2-line');
        var subtotal = 0;
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var q = parseInt(r.querySelector('.pv2-qty').value, 10) || 0;
            var c = parseFloat(r.querySelector('.pv2-cost').value) || 0;
            var lineGross = q * c;
            var discRaw = (r.querySelector('.pv2-discount').value || '').trim();
            var discAmt = parseDiscount(discRaw, lineGross);
            var lineNet = Math.max(0, lineGross - discAmt);
            var ltEl = r.querySelector('.pv2-line-total');
            if (ltEl) ltEl.value = fmt3(lineNet);
            subtotal += lineNet;
        }
        var invDiscRaw = (document.getElementById('pv2_invoice_discount').value || '').trim();
        var invDiscAmt = parseDiscount(invDiscRaw, subtotal);
        var netTotal = Math.max(0, subtotal - invDiscAmt);
        var stEl = document.getElementById('pv2_subtotal');
        var ntEl = document.getElementById('pv2_net_total');
        if (stEl) stEl.textContent = fmt3(subtotal);
        if (ntEl) ntEl.textContent = fmt3(netTotal);
    }

    /* ── Save ───────────────────────────────────────────────────────── */
    function save() {
        if (!PV2_READY) return;
        var supplierId = parseInt(document.getElementById('pv2_supplier_id').value, 10) || 0;
        var purType = document.getElementById('pv2_type').value;
        var notes = (document.getElementById('pv2_notes').value || '').trim();
        var supplierInvoice = (document.getElementById('pv2_supplier_invoice').value || '').trim();

        if (purType === 'credit' && supplierId <= 0) {
            alert('شراء آجل يتطلّب مورداً.');
            return;
        }

        var tb = document.getElementById('pv2_lines_body');
        if (!tb) { alert('لا توجد أصناف'); return; }
        var rows = tb.querySelectorAll('tr.pv2-line');
        var items = [];
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var pid = parseInt(r.querySelector('.pv2-product').value, 10) || 0;
            var vid = parseInt(r.querySelector('.pv2-variant').value, 10) || 0;
            var q = parseInt(r.querySelector('.pv2-qty').value, 10) || 0;
            var c = parseFloat(r.querySelector('.pv2-cost').value) || 0;
            var discRaw = (r.querySelector('.pv2-discount').value || '').trim();
            if (!pid || q < 1) continue;
            var lineGross = q * c;
            var discAmt = parseDiscount(discRaw, lineGross);
            items.push({ product_id: pid, variant_id: vid, qty: q, cost: c, discount_raw: discRaw, discount_amount: discAmt });
        }
        if (!items.length) {
            alert('أضف سطرًا واحدًا على الأقل بصنف وكمية صحيحة');
            return;
        }

        var invDiscRaw = (document.getElementById('pv2_invoice_discount').value || '').trim();
        var subtotal = parseFloat(document.getElementById('pv2_subtotal').textContent) || 0;
        var invDiscAmt = parseDiscount(invDiscRaw, subtotal);

        var payload = {
            supplier_id: supplierId,
            type: purType,
            notes: notes,
            supplier_invoice_number: supplierInvoice,
            items: items,
            invoice_discount_raw: invDiscRaw,
            invoice_discount_amount: invDiscAmt
        };

        postJSON('/admin/api/purchases/create.php', payload).then(function (res) {
            if (res.success) {
                if (typeof orangeAdminOfferOpenGlVoucherAfterSave === 'function') {
                    orangeAdminOfferOpenGlVoucherAfterSave(res, function () {
                        location.reload();
                    });
                } else {
                    alert(res.message || 'تم حفظ فاتورة الشراء');
                    location.reload();
                }
                return;
            }
            if (typeof orangeAdminOfferSuggestOnFailure === 'function' && orangeAdminOfferSuggestOnFailure(res, 'فشل')) return;
            alert(res.message || 'فشل');
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    /* ── Init & bindings ────────────────────────────────────────────── */
    function init() {
        var codeEl = document.getElementById('pv2_supplier_code');
        if (codeEl) {
            codeEl.addEventListener('dblclick', function (e) { e.preventDefault(); pickerOpen(); });
            codeEl.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pickerOpen(); } });
        }
        document.getElementById('pv2_supplier_pick_backdrop').addEventListener('click', pickerClose);
        document.getElementById('pv2_supplier_pick_close').addEventListener('click', pickerClose);
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') pickerClose(); });

        var pickQ = document.getElementById('pv2_supplier_pick_q');
        var pickTimer = null;
        if (pickQ) {
            pickQ.addEventListener('input', function () {
                if (pickTimer) clearTimeout(pickTimer);
                pickTimer = setTimeout(function () { pickerRender(pickQ.value || ''); }, 180);
            });
        }

        document.getElementById('pv2_invoice_discount').addEventListener('input', function () { recalcAll(); });

        document.getElementById('pv2_btn_save').addEventListener('click', save);
        document.getElementById('pv2_btn_new').addEventListener('click', function () { location.reload(); });

        var tb = document.getElementById('pv2_lines_body');
        if (tb) {
            tb.addEventListener('change', function (e) {
                if (e.target && e.target.classList.contains('pv2-product')) {
                    onProductChanged(e.target);
                }
                syncTrailing();
                recalcAll();
            });
            tb.addEventListener('input', function (e) {
                if (e.target && e.target.classList.contains('pv2-barcode')) return;
                syncTrailing();
                recalcAll();
            });
            tb.addEventListener('keydown', function (e) {
                if (e.target && e.target.classList.contains('pv2-barcode') && e.key === 'Enter') {
                    e.preventDefault();
                    onBarcodeBlurOrEnter(e.target);
                    return;
                }
                if (e.key !== 'Tab' || e.shiftKey) return;
                var ta = e.target;
                if (!ta || !ta.classList.contains('pv2-discount')) return;
                var tr = ta.closest('tr');
                if (!tr || tr.parentElement !== tb) return;
                var rows = tb.querySelectorAll('tr');
                if (tr !== rows[rows.length - 1]) return;
                e.preventDefault();
                syncTrailing();
                var rows2 = tb.querySelectorAll('tr');
                var next = rows2[rows2.length - 1];
                var bc = next && next.querySelector('.pv2-barcode');
                if (bc) bc.focus();
            });
            tb.addEventListener('focusout', function (e) {
                if (e.target && e.target.classList.contains('pv2-barcode')) {
                    onBarcodeBlurOrEnter(e.target);
                }
            });
            tb.addEventListener('click', function (e) {
                if (e.target && e.target.classList.contains('admin-doc-line-remove')) {
                    removeLine(e.target);
                }
            });

            addLine();
            syncTrailing();
        }

        if (PV2_PREFILL_SUPPLIER > 0) {
            selectSupplier(PV2_PREFILL_SUPPLIER);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
