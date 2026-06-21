<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/country_screen_copy.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

/** @var array<string, mixed>|null $admin — من admin/index.php */
$csAdmin = (isset($admin) && is_array($admin)) ? $admin : orange_admin_active_record($pdo);
$csCanUse = $csAdmin !== null && orange_admin_has_full_access($csAdmin);
$ctxCountryLabel = orange_admin_page_country_label($pdo);
?>
<div class="page-title" dir="rtl">
    <h1>نسخ إعدادات بين الدول</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;">
        <strong>المصدر (سياق الدولة الحالي):</strong> <?php echo htmlspecialchars($ctxCountryLabel, ENT_QUOTES, 'UTF-8'); ?>
    </p>
</div>

<?php if (!$csCanUse): ?>
<div class="card" dir="rtl">
    <p class="card-hint" style="margin:0;color:#92400e;">
        هذه الشاشة للمشرف العام (وصول كامل / مبدّل الدول) فقط.
    </p>
</div>
<?php else: ?>

<div class="card" dir="rtl" style="margin-bottom:1rem;" id="csc_setup_card">
    <h3 class="card-title">اختيار الشاشة والهدف</h3>
    <div class="form-grid" style="grid-template-columns:minmax(200px,1fr) minmax(200px,1fr);gap:12px;max-width:820px;">
        <div>
            <label for="csc_screen">الشاشة / الوحدة</label>
            <select id="csc_screen" class="admin-inp">
                <option value="">— اختر —</option>
            </select>
        </div>
        <div>
            <label for="csc_target">الدولة الهدف</label>
            <select id="csc_target" class="admin-inp">
                <option value="">— اختر الدولة —</option>
            </select>
        </div>
    </div>
    <p class="card-hint" id="csc_hint" style="margin:0.75rem 0 0;"></p>
</div>

<div class="card coa-copy-card" dir="rtl" style="margin-bottom:1rem;display:none;" id="csc_work_card">
    <h3 class="card-title">معاينة وتنفيذ</h3>
    <div id="csc_work_body"></div>
    <div style="margin-top:0.75rem;">
        <button type="button" id="csc_run_btn">تنفيذ النسخ</button>
    </div>
</div>

<div class="card" dir="rtl" id="csc_log_card">
    <h3 class="card-title">سجل النسخ</h3>
    <div class="table-wrap">
        <table class="fy-years-table">
            <thead>
                <tr>
                    <th>التاريخ</th>
                    <th>الشاشة</th>
                    <th>من → إلى</th>
                    <th>الملخص</th>
                </tr>
            </thead>
            <tbody id="csc_log_tbody">
                <tr><td colspan="4" class="muted">جاري التحميل…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var COA_COPY_MANDATORY_MAX_LEVEL = 4;
    var state = {
        modules: [],
        sourceLabel: '',
        preview: null,
        coaTree: [],
        mandatoryIds: [],
        jtItems: []
    };

    function cscEsc(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
    }

    function coaCopyFlatten(nodes, out) {
        out = out || [];
        (nodes || []).forEach(function (n) {
            if (!n || !n.id) { return; }
            out.push(n);
            if (n.children && n.children.length) {
                coaCopyFlatten(n.children, out);
            }
        });
        return out;
    }

    function coaCopyIsMandatoryLevel(level) {
        return (parseInt(level, 10) || 99) <= COA_COPY_MANDATORY_MAX_LEVEL;
    }

    function coaCopyIsSelectableLevel(level) {
        return !coaCopyIsMandatoryLevel(level);
    }

    function coaCopyUpdateExpandBtn(btn, expanded) {
        if (!btn) { return; }
        btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        btn.textContent = expanded ? '\u25BC' : '\u25C0';
    }

    function coaCopyDescendantCheckboxes(itemLi) {
        var childList = itemLi ? itemLi.querySelector(':scope > .coa-copy-tree-list') : null;
        if (!childList) { return []; }
        return Array.prototype.slice.call(childList.querySelectorAll('.coa-copy-chk'));
    }

    function coaCopyAncestorCheckboxes(itemLi) {
        var out = [];
        var cur = itemLi;
        while (cur) {
            var parentUl = cur.parentElement;
            if (!parentUl || !parentUl.classList.contains('coa-copy-tree-list')) { break; }
            var parentLi = parentUl.closest('.coa-copy-item');
            if (!parentLi) { break; }
            var cb = parentLi.querySelector(':scope > .coa-copy-row > .coa-copy-chk');
            if (cb) { out.push(cb); }
            cur = parentLi;
        }
        return out;
    }

    function coaCopyUpdateSelCount() {
        var el = document.getElementById('csc_coa_sel_count');
        if (!el) { return; }
        var l5 = document.querySelectorAll('#csc_coa_tree_root .coa-copy-chk:checked').length;
        el.textContent = 'M1–4: ' + state.mandatoryIds.length + ' تلقائي | M5: ' + l5 + ' محدَّد';
    }

    function coaCopyOnCheckboxChange(ev) {
        var cb = ev.target;
        if (!cb || !cb.classList || !cb.classList.contains('coa-copy-chk')) { return; }
        var li = cb.closest('.coa-copy-item');
        if (!li) { coaCopyUpdateSelCount(); return; }
        var checked = cb.checked;
        if (checked) {
            coaCopyAncestorCheckboxes(li).forEach(function (c) { c.checked = true; });
        }
        coaCopyDescendantCheckboxes(li).forEach(function (c) { c.checked = checked; });
        coaCopyUpdateSelCount();
    }

    function coaCopyCollectIdsForSubmit() {
        var seen = {};
        var ids = [];
        state.mandatoryIds.forEach(function (id) {
            if (id > 0 && !seen[id]) { seen[id] = true; ids.push(id); }
        });
        document.querySelectorAll('#csc_coa_tree_root .coa-copy-chk:checked').forEach(function (cb) {
            var v = parseInt(cb.value, 10) || 0;
            if (v > 0 && !seen[v]) { seen[v] = true; ids.push(v); }
        });
        return ids;
    }

    function coaCopyBuildList(nodes, depth) {
        depth = typeof depth === 'number' ? depth : 0;
        var ul = document.createElement('ul');
        ul.className = 'coa-copy-tree-list';
        (nodes || []).forEach(function (n) {
            if (!n || !n.id) { return; }
            var li = document.createElement('li');
            li.className = 'coa-copy-item' + (depth === 0 ? ' coa-copy-item--root' : '');
            li.setAttribute('role', 'treeitem');
            var hasKids = n.children && n.children.length;
            var row = document.createElement('div');
            row.className = 'coa-copy-row' + (coaCopyIsMandatoryLevel(n.level) ? ' coa-copy-row--mandatory' : '');
            if (hasKids) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'coa-copy-expand';
                coaCopyUpdateExpandBtn(btn, false);
                btn.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    var childUl = li.querySelector(':scope > .coa-copy-tree-list');
                    if (!childUl) { return; }
                    var open = childUl.hidden;
                    childUl.hidden = !open;
                    coaCopyUpdateExpandBtn(btn, open);
                });
                row.appendChild(btn);
            } else {
                var sp = document.createElement('span');
                sp.className = 'coa-copy-expand coa-copy-expand--spacer';
                row.appendChild(sp);
            }
            if (coaCopyIsSelectableLevel(n.level)) {
                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.className = 'coa-copy-chk';
                cb.value = String(n.id);
                cb.addEventListener('change', coaCopyOnCheckboxChange);
                row.appendChild(cb);
            } else {
                var auto = document.createElement('span');
                auto.className = 'coa-copy-auto-badge';
                auto.title = 'يُنسخ تلقائياً';
                auto.textContent = '\u2713';
                row.appendChild(auto);
            }
            var lbl = document.createElement('span');
            lbl.className = 'coa-copy-label';
            var codePart = n.code ? ('<span dir="ltr" class="coa-copy-code">' + cscEsc(n.code) + '</span> — ') : '';
            lbl.innerHTML = codePart + cscEsc(n.name || '') + ' <small class="muted">(م.' + (n.level || 1) + ')</small>';
            row.appendChild(lbl);
            li.appendChild(row);
            if (hasKids) {
                var childUl = coaCopyBuildList(n.children, depth + 1);
                childUl.hidden = true;
                li.appendChild(childUl);
            }
            ul.appendChild(li);
        });
        return ul;
    }

    function renderCoaPreview() {
        var body = document.getElementById('csc_work_body');
        if (!body) { return; }
        body.innerHTML =
            '<p class="card-hint" style="margin:0 0 0.75rem;">المستويات 1–4 تُنسخ كاملة؛ حدّد حسابات M5.</p>' +
            '<div class="coa-copy-toolbar" dir="rtl">' +
            '<button type="button" class="btn-secondary" id="csc_coa_sel_all">تحديد كل M5</button>' +
            '<button type="button" class="btn-secondary" id="csc_coa_clear">إلغاء M5</button>' +
            '<span class="coa-copy-toolbar__hint muted" id="csc_coa_sel_count">0 محدَّد</span></div>' +
            '<div class="coa-copy-tree-wrap" dir="rtl"><div id="csc_coa_tree_root" class="coa-copy-tree-root" role="tree"></div></div>';
        var root = document.getElementById('csc_coa_tree_root');
        if (!state.coaTree || !state.coaTree.length) {
            root.innerHTML = '<p class="muted">لا توجد حسابات في المصدر.</p>';
            return;
        }
        root.appendChild(coaCopyBuildList(state.coaTree));
        coaCopyUpdateSelCount();
        document.getElementById('csc_coa_sel_all').addEventListener('click', function () {
            document.querySelectorAll('#csc_coa_tree_root .coa-copy-chk').forEach(function (cb) { cb.checked = true; });
            coaCopyUpdateSelCount();
        });
        document.getElementById('csc_coa_clear').addEventListener('click', function () {
            document.querySelectorAll('#csc_coa_tree_root .coa-copy-chk').forEach(function (cb) { cb.checked = false; });
            coaCopyUpdateSelCount();
        });
    }

    function renderJtPreview() {
        var body = document.getElementById('csc_work_body');
        if (!body) { return; }
        var items = state.jtItems || [];
        if (!items.length) {
            body.innerHTML = '<p class="muted">لا توجد أنواع يوميات في المصدر.</p>';
            return;
        }
        var html = '<p class="card-hint" style="margin:0 0 0.75rem;">حدّد الأنواع المراد نسخها.</p>' +
            '<div class="table-wrap"><table class="fy-years-table"><thead><tr>' +
            '<th style="width:2.5rem;"><input type="checkbox" id="csc_jt_all" aria-label="تحديد الكل"></th>' +
            '<th>الكود</th><th>الاسم عربي</th><th>الاسم إنجليزي</th></tr></thead><tbody>';
        items.forEach(function (it) {
            html += '<tr><td style="text-align:center;"><input type="checkbox" class="csc-jt-chk" value="' + (parseInt(it.id, 10) || 0) + '"></td>' +
                '<td dir="ltr">' + cscEsc(it.code) + '</td>' +
                '<td>' + cscEsc(it.name_ar) + '</td>' +
                '<td dir="ltr">' + cscEsc(it.name_en) + '</td></tr>';
        });
        html += '</tbody></table></div>';
        body.innerHTML = html;
        var all = document.getElementById('csc_jt_all');
        if (all) {
            all.addEventListener('change', function () {
                document.querySelectorAll('.csc-jt-chk').forEach(function (cb) { cb.checked = all.checked; });
            });
        }
    }

    function renderSimplePreview(p) {
        var body = document.getElementById('csc_work_body');
        if (!body) { return; }
        var html = '<p class="card-hint" style="margin:0;">';
        if (p.screen_key === 'gl_account_settings' && p.stats) {
            html += 'في المصدر: <strong>' + (p.stats.bindings_count || 0) + '</strong> ربط حساب (قسم ١) و<strong>' +
                (p.stats.rules_count || 0) + '</strong> قاعدة (قسم ٢). يُنسخ الكل مع تخطّي ما لا يجد مطابقاً في الهدف.';
        } else if (p.screen_key === 'invoice_line_presets') {
            html += 'في المصدر: <strong>' + ((p.stats && p.stats.presets_count) || 0) + '</strong> بند.';
            if (p.items && p.items.length) {
                html += '</p><ul style="margin:0.5rem 0 0;padding-right:1.25rem;">';
                p.items.forEach(function (it) {
                    html += '<li>' + cscEsc(it.label_ar) + (it.account_code ? (' — <span dir="ltr">' + cscEsc(it.account_code) + '</span>') : '') + '</li>';
                });
                if ((p.stats && p.stats.presets_count) > p.items.length) {
                    html += '<li class="muted">…</li>';
                }
                html += '</ul>';
                body.innerHTML = html;
                return;
            }
        } else if (p.screen_key === 'analytical_dimensions' && p.items) {
            html += 'في المصدر: <strong>' + p.items.length + '</strong> بُعد.';
            if (p.items.length) {
                html += '</p><ul style="margin:0.5rem 0 0;padding-right:1.25rem;">';
                p.items.forEach(function (it) {
                    html += '<li><span dir="ltr">' + cscEsc(it.code) + '</span> — ' + cscEsc(it.label_ar) +
                        ' <small class="muted">(' + (it.value_count || 0) + ' قيمة)</small></li>';
                });
                html += '</ul>';
                body.innerHTML = html;
                return;
            }
        } else {
            html += 'اضغط «تنفيذ النسخ» لنسخ محتوى هذه الشاشة إلى الدولة الهدف.';
        }
        html += '</p>';
        body.innerHTML = html;
    }

    function renderLog(rows) {
        var tbody = document.getElementById('csc_log_tbody');
        if (!tbody) { return; }
        if (!rows || !rows.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="muted">لا يوجد سجل بعد.</td></tr>';
            return;
        }
        var html = '';
        rows.forEach(function (r) {
            html += '<tr><td dir="ltr">' + cscEsc(r.created_at) + '</td>' +
                '<td>' + cscEsc(r.screen_label || r.screen_key) + '</td>' +
                '<td>' + cscEsc(r.source_name) + ' → ' + cscEsc(r.target_name) + '</td>' +
                '<td>' + cscEsc(r.summary_ar) + '</td></tr>';
        });
        tbody.innerHTML = html;
    }

    function loadBootstrap() {
        return fetch('/admin/api/country-screen-copy/bootstrap.php', { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.success) { throw new Error(data.message || 'فشل التحميل'); }
                state.modules = data.modules || [];
                state.sourceLabel = data.source_label || '';
                var screenSel = document.getElementById('csc_screen');
                var targetSel = document.getElementById('csc_target');
                screenSel.innerHTML = '<option value="">— اختر —</option>';
                state.modules.forEach(function (m) {
                    var opt = document.createElement('option');
                    opt.value = m.key;
                    opt.textContent = m.label_ar;
                    screenSel.appendChild(opt);
                });
                targetSel.innerHTML = '<option value="">— اختر الدولة —</option>';
                (data.target_countries || []).forEach(function (c) {
                    var opt = document.createElement('option');
                    opt.value = String(c.id);
                    opt.textContent = c.label;
                    targetSel.appendChild(opt);
                });
                renderLog(data.log || []);
            });
    }

    function loadPreview() {
        var screenKey = document.getElementById('csc_screen').value;
        var hintEl = document.getElementById('csc_hint');
        var workCard = document.getElementById('csc_work_card');
        state.preview = null;
        if (!screenKey) {
            hintEl.textContent = '';
            workCard.style.display = 'none';
            return Promise.resolve();
        }
        var mod = null;
        state.modules.forEach(function (m) { if (m.key === screenKey) { mod = m; } });
        hintEl.textContent = mod ? (mod.hint_ar || '') : '';
        workCard.style.display = 'block';
        document.getElementById('csc_work_body').innerHTML = '<p class="muted">جاري المعاينة…</p>';
        return fetch('/admin/api/country-screen-copy/preview.php?screen_key=' + encodeURIComponent(screenKey), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (p) {
                if (!p.success) { throw new Error(p.message || 'فشل المعاينة'); }
                state.preview = p;
                if (p.selection === 'coa_tree') {
                    state.coaTree = p.coa_tree || [];
                    var flat = coaCopyFlatten(state.coaTree, []);
                    state.mandatoryIds = (p.mandatory_account_ids && p.mandatory_account_ids.length)
                        ? p.mandatory_account_ids
                        : flat.filter(function (n) { return coaCopyIsMandatoryLevel(n.level); })
                            .map(function (n) { return parseInt(n.id, 10) || 0; }).filter(function (id) { return id > 0; });
                    renderCoaPreview();
                } else if (p.selection === 'checkbox_list') {
                    state.jtItems = p.items || [];
                    renderJtPreview();
                } else {
                    renderSimplePreview(p);
                }
            })
            .catch(function (e) {
                document.getElementById('csc_work_body').innerHTML = '<p class="alert-error">' + cscEsc(e.message || String(e)) + '</p>';
            });
    }

    function collectPayload() {
        var p = state.preview;
        if (!p) { return {}; }
        if (p.selection === 'coa_tree') {
            return { account_ids: coaCopyCollectIdsForSubmit() };
        }
        if (p.selection === 'checkbox_list') {
            var ids = [];
            document.querySelectorAll('.csc-jt-chk:checked').forEach(function (cb) {
                var v = parseInt(cb.value, 10) || 0;
                if (v > 0) { ids.push(v); }
            });
            return { journal_type_ids: ids };
        }
        return {};
    }

    function runCopy() {
        var screenKey = document.getElementById('csc_screen').value;
        var targetId = parseInt(document.getElementById('csc_target').value, 10) || 0;
        if (!screenKey) { alert('اختر الشاشة'); return; }
        if (targetId <= 0) { alert('اختر الدولة الهدف'); return; }
        var payload = collectPayload();
        if (state.preview && state.preview.selection === 'coa_tree' && (!payload.account_ids || !payload.account_ids.length)) {
            alert('لا توجد حسابات للنسخ');
            return;
        }
        if (state.preview && state.preview.selection === 'checkbox_list' && (!payload.journal_type_ids || !payload.journal_type_ids.length)) {
            alert('حدّد نوعاً واحداً على الأقل');
            return;
        }
        var modLabel = screenKey;
        state.modules.forEach(function (m) { if (m.key === screenKey) { modLabel = m.label_ar; } });
        if (!confirm('نسخ «' + modLabel + '» من «' + state.sourceLabel + '» إلى الدولة المختارة؟')) {
            return;
        }
        postJSON('/admin/api/country-screen-copy/run.php', {
            screen_key: screenKey,
            target_country_id: targetId,
            payload: payload,
            account_ids: payload.account_ids,
            journal_type_ids: payload.journal_type_ids
        }).then(function (r) {
            alert(r.message || (r.success ? 'تم' : 'فشل'));
            if (r.success) {
                loadBootstrap();
            }
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    document.getElementById('csc_screen').addEventListener('change', loadPreview);
    document.getElementById('csc_run_btn').addEventListener('click', runCopy);

    loadBootstrap().catch(function (e) { alert(e.message || String(e)); });
})();
</script>
<?php endif; ?>
