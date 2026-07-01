<?php

declare(strict_types=1);

/*
 * ترتيب عرض المنتجات (المتجر) — أداة خفيفة منفصلة:
 * تختار فئة فتُحمّل منتجاتها النشطة فقط (لا كل المنتجات)، ثم ترتّبها بالسحب/الأزرار/الموضع الرقمي.
 * المحرّك: ترقيم بفجوات (نقلة = تعديل صف واحد غالباً) عبر order-category-move.php.
 * لا يمسّ غير عمود sort_order، ومقيّد بدولة الأدمن. القرار: مالك 2026-07-01 (A المحسّنة + فجوات + حسب الفئة).
 *
 * @var array<string,mixed> $admin — من admin/index.php
 */
$pdo = db();
$adminCountryId = orange_admin_context_country_id($pdo);
$countrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $adminCountryId);
if (function_exists('orange_preview_hide_sql')) {
    $countrySql .= orange_preview_hide_sql($pdo, 'p');
}

$pdoSchemaReady = orange_table_exists($pdo, 'catalog_categories')
    && orange_table_exists($pdo, 'catalog_subcategories')
    && orange_table_exists($pdo, 'product_types')
    && orange_table_has_column($pdo, 'products', 'product_type_id');

$pdoCategories = [];
if ($pdoSchemaReady) {
    $deptActiveSql = function_exists('orange_department_country_active_sql')
        ? (' AND (' . orange_department_country_active_sql($pdo, 'd', $adminCountryId) . ')')
        : '';
    $catSql = 'SELECT ucc.id, ucc.name_ar, ucc.name_en, cs.name_ar AS sec_ar, d.name_ar AS dept_ar,
            (SELECT COUNT(*) FROM products p
                INNER JOIN product_types pt ON pt.id = p.product_type_id
                INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id
                WHERE ucs.catalog_category_id = ucc.id AND p.is_active = 1' . $countrySql . ') AS cnt
        FROM catalog_categories ucc
        INNER JOIN catalog_sections cs ON cs.id = ucc.catalog_section_id
        INNER JOIN departments d ON d.id = cs.department_id
        WHERE ucc.is_active = 1 AND cs.is_active = 1' . $deptActiveSql . '
        ORDER BY d.sort_order ASC, d.id ASC, cs.sort_order ASC, cs.id ASC, ucc.sort_order ASC, ucc.id ASC';
    try {
        $pdoCategories = $pdo->query($catSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $pdoCategories = [];
    }
}
?>
<div class="card">
    <h3 style="margin:0 0 6px;">ترتيب عرض المنتجات في المتجر</h3>
    <p style="margin:0;color:#555;font-size:14px;line-height:1.6;">
        اختر فئة، ثم رتّب منتجاتها بالسحب أو الأزرار أو بكتابة الموضع. الترتيب هنا هو ما يراه العميل داخل الفئة.
        يُحفظ كل تغيير تلقائياً، ولا يؤثر إلا على ترتيب العرض (لا يمسّ الأسعار/المخزون/الأكواد).
    </p>
</div>

<?php if (!$pdoSchemaReady): ?>
<div class="card" style="border:1px solid #dc2626;background:#fef2f2;">
    <p style="margin:0;color:#991b1b;">شجرة الكتالوج الموحّدة غير مكتملة بعد؛ لا يمكن عرض الفئات لإعادة الترتيب.</p>
</div>
<?php else: ?>
<div class="card">
    <div class="form-grid" style="max-width:640px;margin-bottom:12px;">
        <div>
            <label for="pdo_category">الفئة</label>
            <select id="pdo_category">
                <option value="">— اختر فئة —</option>
                <?php foreach ($pdoCategories as $c): ?>
                    <?php
                    $label = trim((string) ($c['dept_ar'] ?? '')) . ' › '
                        . trim((string) ($c['sec_ar'] ?? '')) . ' › '
                        . (trim((string) ($c['name_ar'] ?? '')) ?: trim((string) ($c['name_en'] ?? '')))
                        . ' (' . (int) ($c['cnt'] ?? 0) . ')';
                    ?>
                    <option value="<?php echo (int) $c['id']; ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="pdo_search">بحث داخل الفئة</label>
            <input type="text" id="pdo_search" placeholder="اسم أو كود المنتج…" autocomplete="off">
        </div>
    </div>
    <div id="pdo_msg" style="min-height:20px;font-size:13px;margin-bottom:8px;"></div>
    <div id="pdo_list" class="pdo-list" aria-live="polite"></div>
    <p id="pdo_empty" style="display:none;color:#64748b;margin:12px 0 0;">لا منتجات نشطة في هذه الفئة.</p>
</div>

<style>
    .pdo-list { display:flex; flex-direction:column; gap:6px; }
    .pdo-row {
        display:flex; align-items:center; gap:10px; padding:8px 10px;
        border:1px solid #e5e7eb; border-radius:8px; background:#fff;
    }
    .pdo-row.pdo-dragging { opacity:.5; border-style:dashed; }
    .pdo-row.pdo-drop-hint { border-color:#2563eb; box-shadow:0 0 0 2px rgba(37,99,235,.15); }
    .pdo-handle { cursor:grab; color:#94a3b8; font-size:18px; user-select:none; padding:0 2px; }
    .pdo-idx { min-width:2.2rem; text-align:center; font-weight:700; color:#334155; }
    .pdo-thumb { width:38px; height:38px; object-fit:cover; border-radius:6px; background:#f1f5f9; flex:0 0 auto; }
    .pdo-main { flex:1 1 auto; min-width:0; }
    .pdo-name { font-weight:600; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .pdo-code { color:#64748b; font-size:12px; }
    .pdo-ops { display:flex; gap:4px; align-items:center; flex:0 0 auto; }
    .pdo-ops .btn-secondary { padding:4px 8px; min-width:2rem; line-height:1.2; }
    .pdo-pos { width:3.5rem; text-align:center; }
    .pdo-row[hidden] { display:none; }
</style>

<script>
(function () {
    var listEl = document.getElementById('pdo_list');
    var catEl = document.getElementById('pdo_category');
    var searchEl = document.getElementById('pdo_search');
    var msgEl = document.getElementById('pdo_msg');
    var emptyEl = document.getElementById('pdo_empty');
    var currentCat = 0;
    var busy = false;

    function setMsg(t, ok) {
        msgEl.textContent = t || '';
        msgEl.style.color = ok === false ? '#b91c1c' : '#15803d';
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (m) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m];
        });
    }
    function rows() {
        return Array.prototype.slice.call(listEl.querySelectorAll('.pdo-row'));
    }
    function renumber() {
        rows().forEach(function (r, i) {
            var idx = r.querySelector('.pdo-idx');
            if (idx) { idx.textContent = String(i + 1); }
            var pos = r.querySelector('.pdo-pos');
            if (pos && document.activeElement !== pos) { pos.value = String(i + 1); }
        });
    }
    function siblingId(row, dir) {
        var sib = dir === 'prev' ? row.previousElementSibling : row.nextElementSibling;
        while (sib && !sib.classList.contains('pdo-row')) {
            sib = dir === 'prev' ? sib.previousElementSibling : sib.nextElementSibling;
        }
        return sib ? parseInt(sib.getAttribute('data-id'), 10) || 0 : 0;
    }

    async function persist(row) {
        if (!row) { return; }
        var productId = parseInt(row.getAttribute('data-id'), 10) || 0;
        var prevId = siblingId(row, 'prev');
        var nextId = siblingId(row, 'next');
        setMsg('جارٍ الحفظ…', true);
        busy = true;
        try {
            var res = await postJSON('/admin/api/products/order-category-move.php', {
                category_id: currentCat,
                product_id: productId,
                prev_id: prevId,
                next_id: nextId
            }, { inferSubmitter: false });
            if (res && res.success) {
                setMsg('تم حفظ الترتيب.', true);
            } else {
                setMsg((res && res.message) ? res.message : 'تعذر الحفظ.', false);
            }
        } catch (e) {
            setMsg('تعذر الاتصال بالخادم.', false);
        }
        busy = false;
        renumber();
    }

    function moveRow(row, where, targetIndex) {
        var all = rows();
        var i = all.indexOf(row);
        if (i < 0) { return; }
        if (where === 'up' && i > 0) {
            listEl.insertBefore(row, all[i - 1]);
        } else if (where === 'down' && i < all.length - 1) {
            listEl.insertBefore(all[i + 1], row);
        } else if (where === 'top') {
            listEl.insertBefore(row, all[0]);
        } else if (where === 'bottom') {
            listEl.appendChild(row);
        } else if (where === 'index') {
            var t = Math.max(1, Math.min(all.length, targetIndex)) - 1;
            var without = all.filter(function (r) { return r !== row; });
            var ref = without[t] || null;
            if (t >= without.length) { listEl.appendChild(row); }
            else { listEl.insertBefore(row, ref); }
        } else {
            return;
        }
        persist(row);
    }

    function makeRow(it) {
        var row = document.createElement('div');
        row.className = 'pdo-row';
        row.setAttribute('data-id', String(it.id));
        row.setAttribute('draggable', 'true');
        var haystack = (it.name + ' ' + (it.name_en || '') + ' ' + (it.item_code || '') + ' ' + it.id).toLowerCase();
        row.setAttribute('data-search', haystack);
        var thumb = '';
        if (it.main_image) {
            var src = /^https?:\/\//.test(it.main_image) || it.main_image.charAt(0) === '/'
                ? it.main_image : ('/' + it.main_image);
            thumb = '<img class="pdo-thumb" src="' + esc(src) + '" alt="" onerror="this.style.visibility=\'hidden\'">';
        } else {
            thumb = '<span class="pdo-thumb"></span>';
        }
        row.innerHTML =
            '<span class="pdo-handle" title="اسحب لإعادة الترتيب">⋮⋮</span>' +
            '<span class="pdo-idx"></span>' +
            thumb +
            '<div class="pdo-main">' +
                '<div class="pdo-name">' + esc(it.name || ('#' + it.id)) + '</div>' +
                '<div class="pdo-code">' + (it.item_code ? esc(it.item_code) + ' · ' : '') + '#' + it.id + '</div>' +
            '</div>' +
            '<div class="pdo-ops">' +
                '<input type="number" class="pdo-pos" min="1" title="اكتب الموضع ثم Enter">' +
                '<button type="button" class="btn-secondary" data-op="top" title="إلى القمة">⤒</button>' +
                '<button type="button" class="btn-secondary" data-op="up" title="أعلى">↑</button>' +
                '<button type="button" class="btn-secondary" data-op="down" title="أسفل">↓</button>' +
                '<button type="button" class="btn-secondary" data-op="bottom" title="إلى القاع">⤓</button>' +
            '</div>';
        return row;
    }

    function applySearch() {
        var q = (searchEl.value || '').trim().toLowerCase();
        rows().forEach(function (r) {
            if (q === '') { r.hidden = false; return; }
            r.hidden = (r.getAttribute('data-search') || '').indexOf(q) === -1;
        });
    }

    function render(items) {
        listEl.innerHTML = '';
        if (!items || !items.length) {
            emptyEl.style.display = '';
            return;
        }
        emptyEl.style.display = 'none';
        var frag = document.createDocumentFragment();
        items.forEach(function (it) { frag.appendChild(makeRow(it)); });
        listEl.appendChild(frag);
        renumber();
        applySearch();
    }

    async function loadCategory(catId) {
        currentCat = catId;
        listEl.innerHTML = '';
        emptyEl.style.display = 'none';
        if (!catId) { return; }
        setMsg('جارٍ التحميل…', true);
        try {
            var data = await getJSON('/admin/api/products/order-category-list.php?category_id=' + encodeURIComponent(catId));
            render(data && data.items ? data.items : []);
            setMsg((data && data.count ? data.count : 0) + ' منتج.', true);
        } catch (e) {
            setMsg('تعذر تحميل المنتجات.', false);
        }
    }

    /* أزرار الصفوف + الموضع الرقمي */
    listEl.addEventListener('click', function (e) {
        if (busy) { return; }
        var btn = e.target.closest ? e.target.closest('button[data-op]') : null;
        if (!btn) { return; }
        var row = btn.closest('.pdo-row');
        moveRow(row, btn.getAttribute('data-op'));
    });
    listEl.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') { return; }
        var pos = e.target.closest ? e.target.closest('.pdo-pos') : null;
        if (!pos) { return; }
        e.preventDefault();
        if (busy) { return; }
        var row = pos.closest('.pdo-row');
        var v = parseInt(pos.value, 10);
        if (v > 0) { moveRow(row, 'index', v); }
    });

    /* سحب وإفلات */
    var dragEl = null;
    listEl.addEventListener('dragstart', function (e) {
        var row = e.target.closest ? e.target.closest('.pdo-row') : null;
        if (!row) { return; }
        dragEl = row;
        row.classList.add('pdo-dragging');
        try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', row.getAttribute('data-id')); } catch (err) {}
    });
    listEl.addEventListener('dragover', function (e) {
        if (!dragEl) { return; }
        e.preventDefault();
        var after = getDragAfter(e.clientY);
        if (after == null) { listEl.appendChild(dragEl); }
        else if (after !== dragEl) { listEl.insertBefore(dragEl, after); }
    });
    listEl.addEventListener('drop', function (e) { if (dragEl) { e.preventDefault(); } });
    listEl.addEventListener('dragend', function () {
        if (!dragEl) { return; }
        var moved = dragEl;
        moved.classList.remove('pdo-dragging');
        dragEl = null;
        persist(moved);
    });
    function getDragAfter(y) {
        var els = rows().filter(function (r) { return r !== dragEl && !r.hidden; });
        var closest = null, closestOffset = -Infinity;
        els.forEach(function (r) {
            var box = r.getBoundingClientRect();
            var offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closestOffset) { closestOffset = offset; closest = r; }
        });
        return closest;
    }

    catEl.addEventListener('change', function () {
        var v = parseInt(catEl.value, 10) || 0;
        loadCategory(v);
    });
    searchEl.addEventListener('input', applySearch);
})();
</script>
<?php endif; ?>
