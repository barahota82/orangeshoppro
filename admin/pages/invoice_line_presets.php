<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/invoice_ancillary_lines.php';
require_once __DIR__ . '/../../includes/countries.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$ilpCountryId = orange_admin_context_country_id($pdo);
$ilpCountryRow = orange_country_row_by_id($pdo, $ilpCountryId, false);
$ilpCountryLabel = trim((string) ($ilpCountryRow['name_ar'] ?? ''));
if ($ilpCountryLabel === '' && $ilpCountryRow !== null) {
    $ilpCountryLabel = trim((string) ($ilpCountryRow['name_en'] ?? ''));
}
if ($ilpCountryLabel === '') {
    $ilpCountryLabel = orange_countries_display_code(orange_admin_context_country_code($pdo));
}
$ilpReady = orange_invoice_ancillary_tables_ready($pdo);
$ilpNextSort = $ilpReady ? orange_invoice_ancillary_preset_next_sort($pdo, $ilpCountryId) : 1;
// قرار المالك (2026-06): البنود الإضافية للمبيعات فقط (أُلغيت للمشتريات) — القائمة المحفوظة سياقها مبيعات فقط.
$ilpAllContextLabels = orange_invoice_ancillary_invoice_context_labels();
$ilpContextLabels = ['sales' => $ilpAllContextLabels['sales'] ?? 'مبيعات'];
$ilpLineKinds = [];
foreach (orange_invoice_ancillary_line_kind_catalog() as $kindKey => $kindMeta) {
    $ilpLineKinds[] = [
        'key' => $kindKey,
        'label_ar' => (string) ($kindMeta['label_ar'] ?? $kindKey),
        'contexts' => $kindMeta['contexts'] ?? [],
    ];
}
$ilpSystemKeyOptions = [[
    'key' => '',
    'label_ar' => '— بدون ربط تلقائي —',
    'line_kind' => '',
]];
foreach (orange_invoice_ancillary_system_key_catalog() as $sysKey => $sysMeta) {
    if ((string) ($sysMeta['invoice_context'] ?? '') !== 'sales') {
        continue;
    }
    $ilpSystemKeyOptions[] = [
        'key' => (string) $sysKey,
        'label_ar' => (string) ($sysMeta['label_ar'] ?? $sysKey),
        'line_kind' => (string) ($sysMeta['line_kind'] ?? ''),
    ];
}
?>
<style>
.ilp-form-grid {
    display: grid;
    grid-template-columns: minmax(4rem, 0.32fr) minmax(4rem, 0.38fr) minmax(5.5rem, 0.45fr) minmax(0, 1.55fr) minmax(0, 0.8fr) minmax(0, 1.4fr);
    gap: 10px 12px;
    align-items: end;
}
.ilp-form-grid-2 {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr) auto;
    gap: 10px 12px;
    align-items: end;
    margin-top: 12px;
}
.ilp-form-grid-2 .ilp-actions-cell {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: flex-end;
}
.ilp-form-grid .ilp-span-full { grid-column: 1 / -1; }
.ilp-form-grid .ilp-account-row {
    grid-column: 1 / -1;
    display: grid;
    grid-template-columns: minmax(7rem, 0.5fr) minmax(7rem, 0.8fr) minmax(0, 1fr) minmax(0, 1fr);
    gap: 10px 12px;
    align-items: end;
}
.ilp-row-ops { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
.ilp-row-ops button { padding: 3px 9px; font-size: 0.8rem; line-height: 1.2; }
.ilp-pick-results {
    margin: 6px 0 0;
    padding: 0;
    list-style: none;
    max-height: 10rem;
    overflow: auto;
    border: 1px solid #e4e4e7;
    border-radius: 8px;
}
.ilp-pick-results li {
    padding: 8px 10px;
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
}
.ilp-pick-results li:hover { background: #f8fafc; }
@media (max-width: 900px) {
    .ilp-form-grid { grid-template-columns: 1fr 1fr; }
    .ilp-form-grid .ilp-account-row { grid-template-columns: 1fr 1fr; }
}
</style>

<div class="page-title">
    <h1>قائمة بنود الفاتورة الإضافية</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars($ilpCountryLabel, ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php if (!$ilpReady): ?>
<div class="card" style="border:1px solid #fcd34d;background:#fffbeb;">
    <p class="card-hint" style="margin:0;">جداول البنود الإضافية غير جاهزة — افتح أي صفحة أدمن بعد <code>git pull</code> لتطبيق الترحيلات.</p>
</div>
<?php endif; ?>

<div class="card">
    <h3 class="card-title">إضافة / تعديل بند إضافي</h3>
    <input type="hidden" id="ilp_id" value="0">
    <input type="hidden" id="ilp_account_id" value="0">
    <div class="form-grid ilp-form-grid orange-doc-header-row">
        <div>
            <label for="ilp_sort">الترتيب</label>
            <input type="text" id="ilp_sort" class="admin-inp-readonly" readonly disabled tabindex="-1" dir="ltr" lang="en" inputmode="numeric" style="background:#f4f4f5;cursor:default;text-align:center;"
                value="<?php echo (int) $ilpNextSort; ?>">
        </div>
        <div>
            <label for="ilp_active">نشط</label>
            <select id="ilp_active" class="admin-inp"<?php echo !$ilpReady ? ' disabled' : ''; ?>>
                <option value="1">نعم</option>
                <option value="0">لا</option>
            </select>
        </div>
        <div>
            <label for="ilp_context_display">سياق الفاتورة</label>
            <input type="text" id="ilp_context_display" class="admin-inp-readonly" readonly disabled tabindex="-1" style="background:#f4f4f5;cursor:default;text-align:center;"
                value="<?php echo htmlspecialchars($ilpContextLabels['sales'] ?? 'مبيعات', ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" id="ilp_context" value="sales">
        </div>
        <div>
            <label for="ilp_line_kind">نوع البند (MD/CR)</label>
            <select id="ilp_line_kind" class="admin-inp"<?php echo !$ilpReady ? ' disabled' : ''; ?>></select>
        </div>
        <div>
            <label for="ilp_account_code">كود الحساب</label>
            <input type="text" id="ilp_account_code" class="admin-inp" placeholder="اكتب كود الحساب" autocomplete="off" dir="ltr" lang="en"<?php echo !$ilpReady ? ' disabled' : ''; ?>>
        </div>
        <div>
            <label for="ilp_account_name">اسم الحساب</label>
            <input type="text" id="ilp_account_name" class="admin-inp-readonly" readonly disabled tabindex="-1">
            <span id="ilp_account_hint" class="muted" style="display:block;font-size:0.78rem;margin-top:2px;"></span>
        </div>
    </div>
    <div class="ilp-form-grid-2 orange-doc-header-row">
        <div>
            <label for="ilp_label_ar">التسمية (عربي)</label>
            <input type="text" id="ilp_label_ar" class="admin-inp" dir="auto"<?php echo !$ilpReady ? ' disabled' : ''; ?>>
        </div>
        <div>
            <label for="ilp_label_en">English</label>
            <input type="text" id="ilp_label_en" class="admin-inp" dir="ltr" lang="en"<?php echo !$ilpReady ? ' disabled' : ''; ?>>
        </div>
        <div>
            <label for="ilp_system_key">مفتاح نظامي (اختياري)</label>
            <select id="ilp_system_key" class="admin-inp"<?php echo !$ilpReady ? ' disabled' : ''; ?>></select>
        </div>
        <div class="ilp-actions-cell">
            <button type="button" id="ilp_btn_save"<?php echo !$ilpReady ? ' disabled' : ''; ?>>حفظ</button>
            <button type="button" class="btn-secondary" id="ilp_btn_translate"<?php echo !$ilpReady ? ' disabled' : ''; ?>>ترجمة من العربي</button>
            <button type="button" class="btn-secondary" id="ilp_btn_new"<?php echo !$ilpReady ? ' disabled' : ''; ?>>جديد</button>
        </div>
    </div>
</div>

<div class="card">
    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;">
        <h3 class="card-title" style="margin:0;">البنود الإضافية المحفوظة</h3>
        <div class="orange-doc-toolbar-fields" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
            <button type="button" class="btn-secondary" id="ilp_btn_reload">تحديث</button>
            <button type="button" class="btn-secondary" id="ilp_btn_save_order"<?php echo !$ilpReady ? ' disabled' : ''; ?>>حفظ الترتيب</button>
        </div>
    </div>
    <div class="table-wrap" style="margin-top:12px;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width:2.5rem;white-space:nowrap;">#</th>
                    <th style="width:34%;">نوع البند</th>
                    <th style="width:18%;">مفتاح نظامي</th>
                    <th style="width:3.5rem;white-space:nowrap;text-align:center;">نشط</th>
                    <th style="width:25%;">الحساب</th>
                    <th style="width:25%;">التسمية</th>
                    <th style="width:4rem;white-space:nowrap;text-align:center;">الترتيب</th>
                    <th style="width:9rem;white-space:nowrap;" title="تعديل / حذف / إعادة الترتيب">إجراءات</th>
                </tr>
            </thead>
            <tbody id="ilp_list_body">
                <tr><td colspan="8" class="muted">جاري التحميل…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- منتقي حساب من الدليل (دبل كليك على خانة الكود) -->
<div class="gl-pick-modal" id="ilp_acc_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="ilp_acc_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="ilp_acc_pick_title">
        <h3 id="ilp_acc_pick_title" class="gl-pick-modal__title">اختيار حساب من الدليل</h3>
        <input type="search" id="ilp_acc_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بالكود أو الاسم…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="ilp_acc_pick_list"></ul>
        <div class="actions" style="margin-top:10px;">
            <button type="button" class="btn-secondary" id="ilp_acc_pick_close">إغلاق</button>
        </div>
    </div>
</div>

<script>
(function () {
    var ILP_LINE_KINDS = <?php echo json_encode($ilpLineKinds, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var ILP_SYSTEM_KEYS = <?php echo json_encode($ilpSystemKeyOptions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var ILP_NEXT_SORT = <?php echo (int) $ilpNextSort; ?>;
    var ilpAccountTimer = null;
    var ILP_SYSTEM_KEY_MAP = {};
    ILP_SYSTEM_KEYS.forEach(function (row) {
        var key = String((row && row.key) || '').trim();
        if (!key) return;
        ILP_SYSTEM_KEY_MAP[key] = row;
    });

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function ilpContextValue() {
        // الشاشة للمبيعات فقط (أُلغيت بنود المشتريات) — السياق ثابت.
        return 'sales';
    }

    function ilpLineKindMatchesContext(kind, ctx) {
        if (!kind || !kind.contexts) return false;
        if (ctx === 'both') return true;
        // مطابقة السياق الفعلي فقط: لا نعتمد على وجود 'both' في قائمة النوع،
        // وإلا ظهرت أنواع المشتريات (سياقها ['purchase','both']) ضمن سياق المبيعات.
        return kind.contexts.indexOf(ctx) !== -1;
    }

    function ilpRefreshLineKindOptions(selected) {
        var sel = document.getElementById('ilp_line_kind');
        if (!sel) return;
        var ctx = ilpContextValue();
        sel.innerHTML = '';
        ILP_LINE_KINDS.forEach(function (k) {
            if (!ilpLineKindMatchesContext(k, ctx)) return;
            var opt = document.createElement('option');
            opt.value = k.key;
            opt.textContent = k.label_ar || k.key;
            sel.appendChild(opt);
        });
        if (selected) sel.value = selected;
    }

    function ilpRefreshSystemKeyOptions(selected) {
        var sel = document.getElementById('ilp_system_key');
        if (!sel) return;
        sel.innerHTML = '';
        ILP_SYSTEM_KEYS.forEach(function (row) {
            var opt = document.createElement('option');
            opt.value = String((row && row.key) || '');
            opt.textContent = String((row && row.label_ar) || opt.value || '—');
            sel.appendChild(opt);
        });
        sel.value = selected || '';
        if (sel.value !== (selected || '')) {
            sel.value = '';
        }
    }

    function ilpSyncSystemKeyBinding() {
        var keySel = document.getElementById('ilp_system_key');
        var lineKindSel = document.getElementById('ilp_line_kind');
        if (!keySel || !lineKindSel) return;
        var key = (keySel.value || '').trim();
        var meta = key ? ILP_SYSTEM_KEY_MAP[key] : null;
        if (meta && meta.line_kind) {
            lineKindSel.value = String(meta.line_kind || '');
            lineKindSel.disabled = true;
            lineKindSel.title = 'نوع البند مُثبت حسب المفتاح النظامي';
            var arInput = document.getElementById('ilp_label_ar');
            if (arInput && !(arInput.value || '').trim()) {
                arInput.value = String(meta.label_ar || '');
            }
        } else {
            lineKindSel.disabled = false;
            lineKindSel.title = '';
        }
    }

    function ilpResetForm() {
        document.getElementById('ilp_id').value = '0';
        document.getElementById('ilp_account_id').value = '0';
        document.getElementById('ilp_sort').value = String(ILP_NEXT_SORT);
        document.getElementById('ilp_active').value = '1';
        document.getElementById('ilp_context').value = 'sales';
        document.getElementById('ilp_account_code').value = '';
        document.getElementById('ilp_account_name').value = '';
        document.getElementById('ilp_account_hint').textContent = '';
        document.getElementById('ilp_label_ar').value = '';
        document.getElementById('ilp_label_en').value = '';
        ilpRefreshLineKindOptions('');
        ilpRefreshSystemKeyOptions('');
        ilpSyncSystemKeyBinding();
    }

    function ilpFillForm(row) {
        document.getElementById('ilp_id').value = String(row.id || 0);
        document.getElementById('ilp_account_id').value = String(row.account_id || 0);
        document.getElementById('ilp_sort').value = String(row.sort_order || 0);
        document.getElementById('ilp_active').value = row.is_active ? '1' : '0';
        document.getElementById('ilp_context').value = row.invoice_context || 'both';
        ilpRefreshLineKindOptions(row.line_kind || '');
        document.getElementById('ilp_account_code').value = row.account_code || '';
        document.getElementById('ilp_account_name').value = row.account_name || '';
        document.getElementById('ilp_account_hint').textContent = '';
        document.getElementById('ilp_label_ar').value = row.label_ar || '';
        document.getElementById('ilp_label_en').value = row.label_en || '';
        ilpRefreshSystemKeyOptions(row.system_key || '');
        ilpSyncSystemKeyBinding();
    }

    function ilpRenderList(rows) {
        var tb = document.getElementById('ilp_list_body');
        if (!tb) return;
        tb.innerHTML = '';
        if (!rows || !rows.length) {
            tb.innerHTML = '<tr><td colspan="8" class="muted">لا توجد بنود محفوظة</td></tr>';
            return;
        }
        rows.forEach(function (row, idx) {
            var tr = document.createElement('tr');
            tr.dataset.id = String(row.id || 0);
            tr.style.cursor = 'pointer';
            tr.title = 'اضغط للتعديل';
            tr.innerHTML = '<td style="white-space:nowrap;">' + esc(String(row.id)) + '</td>'
                + '<td style="font-size:0.85rem;">' + esc(row.line_kind_label || row.line_kind || '') + '</td>'
                + '<td style="font-size:0.83rem;">' + esc(row.system_key_label_ar || row.system_key || '—') + '</td>'
                + '<td style="white-space:nowrap;text-align:center;">' + (row.is_active ? 'نعم' : 'لا') + '</td>'
                + '<td dir="ltr">' + esc((row.account_code || '') + (row.account_name ? ' — ' + row.account_name : '')) + '</td>'
                + '<td>' + esc(row.label_ar || '') + '</td>'
                + '<td dir="ltr" style="white-space:nowrap;text-align:center;">' + esc(String(row.sort_order || 0)) + '</td>'
                + '<td><div class="ilp-row-ops">'
                + '<button type="button" class="ilp-edit" title="تعديل">تعديل</button>'
                + '<button type="button" class="btn-secondary ilp-delete" title="حذف">حذف</button>'
                + '<button type="button" class="btn-secondary ilp-move-up" title="أعلى">↑</button>'
                + '<button type="button" class="btn-secondary ilp-move-down" title="أسفل">↓</button>'
                + '</div></td>';
            tr.addEventListener('click', function () { ilpFillForm(row); });
            var editBtn = tr.querySelector('.ilp-edit');
            var delBtn = tr.querySelector('.ilp-delete');
            var upBtn = tr.querySelector('.ilp-move-up');
            var dnBtn = tr.querySelector('.ilp-move-down');
            if (editBtn) editBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                ilpFillForm(row);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
            if (delBtn) delBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                ilpDelete(row);
            });
            if (upBtn) upBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var prev = tr.previousElementSibling;
                if (prev) tb.insertBefore(tr, prev);
            });
            if (dnBtn) dnBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var next = tr.nextElementSibling;
                if (next) tb.insertBefore(next, tr);
            });
            tb.appendChild(tr);
        });
    }

    function ilpLoadList() {
        var url = '/admin/api/invoice-ancillary/presets-admin-list.php?invoice_context=sales';
        fetch(url, { credentials: 'same-origin', headers: orangeAdminCountryHeaders({ 'Accept': 'application/json' }), cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success) {
                    alert((res && res.message) || 'تعذر التحميل');
                    return;
                }
                var rows = res.presets || [];
                ilpRenderList(rows);
                ilpUpdateNextSortFromRows(rows);
            })
            .catch(function (e) { alert(e.message || String(e)); });
    }

    function ilpSave() {
        var accountId = parseInt(document.getElementById('ilp_account_id').value, 10) || 0;
        var lineKind = (document.getElementById('ilp_line_kind').value || '').trim();
        if (accountId <= 0) {
            alert('اختر حساباً من الدليل.');
            return;
        }
        if (!lineKind) {
            alert('اختر نوع البند.');
            return;
        }
        var payload = {
            id: parseInt(document.getElementById('ilp_id').value, 10) || 0,
            account_id: accountId,
            line_kind: lineKind,
            invoice_context: ilpContextValue(),
            label_ar: (document.getElementById('ilp_label_ar').value || '').trim(),
            label_en: (document.getElementById('ilp_label_en').value || '').trim(),
            system_key: (document.getElementById('ilp_system_key').value || '').trim(),
            default_show_on_print: true,
            is_active: document.getElementById('ilp_active').value === '1',
            sort_order: parseInt(document.getElementById('ilp_sort').value, 10) || 0
        };
        postJSON('/admin/api/invoice-ancillary/preset-save.php', payload).then(function (res) {
            if (!res || !res.success) {
                alert((res && res.message) || 'فشل الحفظ');
                return;
            }
            alert(res.message || 'تم الحفظ');
            ilpResetForm();
            ilpLoadList();
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function ilpDelete(row) {
        var id = parseInt(row && row.id, 10) || 0;
        if (id <= 0) return;
        var label = (row.label_ar || row.system_key_label_ar || row.line_kind_label || ('#' + id));
        if (!window.confirm('حذف البند «' + label + '» نهائياً؟ الأسطر المحفوظة سابقاً لا تتأثر.')) {
            return;
        }
        postJSON('/admin/api/invoice-ancillary/preset-delete.php', { id: id }).then(function (res) {
            if (!res || !res.success) {
                alert((res && res.message) || 'تعذر الحذف');
                return;
            }
            var curId = parseInt(document.getElementById('ilp_id').value, 10) || 0;
            if (curId === id) ilpResetForm();
            ilpLoadList();
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function ilpUpdateNextSortFromRows(rows) {
        var maxSort = 0;
        (rows || []).forEach(function (r) {
            var s = parseInt(r && r.sort_order, 10) || 0;
            if (s > maxSort) maxSort = s;
        });
        ILP_NEXT_SORT = maxSort + 1;
        if ((parseInt(document.getElementById('ilp_id').value, 10) || 0) <= 0) {
            var sortEl = document.getElementById('ilp_sort');
            if (sortEl) sortEl.value = String(ILP_NEXT_SORT);
        }
    }

    function ilpSaveOrder() {
        var tb = document.getElementById('ilp_list_body');
        if (!tb) return;
        var ids = [];
        tb.querySelectorAll('tr[data-id]').forEach(function (tr) {
            var id = parseInt(tr.dataset.id, 10) || 0;
            if (id > 0) ids.push(id);
        });
        if (!ids.length) {
            alert('لا توجد بنود لترتيبها');
            return;
        }
        postJSON('/admin/api/invoice-ancillary/presets-reorder.php', { ordered_ids: ids }).then(function (res) {
            if (!res || !res.success) {
                alert((res && res.message) || 'فشل حفظ الترتيب');
                return;
            }
            ilpLoadList();
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function ilpLookupAccountByCode(code) {
        var hint = document.getElementById('ilp_account_hint');
        var nameEl = document.getElementById('ilp_account_name');
        code = String(code || '').trim();
        document.getElementById('ilp_account_id').value = '0';
        nameEl.value = '';
        if (code === '') {
            hint.textContent = '';
            return;
        }
        hint.textContent = 'جاري البحث…';
        fetch('/admin/api/accounts/search-leaves.php?q=' + encodeURIComponent(code), {
            credentials: 'same-origin',
            headers: orangeAdminCountryHeaders({ Accept: 'application/json' }),
            cache: 'no-store'
        }).then(function (r) { return r.json(); })
            .then(function (res) {
                var rows = (res && res.accounts) ? res.accounts : [];
                var match = null;
                for (var i = 0; i < rows.length; i++) {
                    if (String(rows[i].code || '').trim() === code) { match = rows[i]; break; }
                }
                if (!match) {
                    hint.textContent = 'لا يوجد حساب قابل للترحيل بهذا الكود';
                    return;
                }
                document.getElementById('ilp_account_id').value = String(match.id || 0);
                nameEl.value = match.name || '';
                hint.textContent = '';
                if (!(document.getElementById('ilp_label_ar').value || '').trim()) {
                    document.getElementById('ilp_label_ar').value = match.name || '';
                }
            })
            .catch(function (e) { hint.textContent = e.message || String(e); });
    }

    function ilpAccPickFill(acc) {
        document.getElementById('ilp_account_id').value = String(acc.id || 0);
        document.getElementById('ilp_account_code').value = acc.code || '';
        document.getElementById('ilp_account_name').value = acc.name || '';
        document.getElementById('ilp_account_hint').textContent = '';
        if (!(document.getElementById('ilp_label_ar').value || '').trim()) {
            document.getElementById('ilp_label_ar').value = acc.name || '';
        }
    }

    function ilpAccPickRender(q) {
        var listEl = document.getElementById('ilp_acc_pick_list');
        if (!listEl) return;
        listEl.innerHTML = '<li class="gl-pick-empty">جاري التحميل…</li>';
        fetch('/admin/api/accounts/search-leaves.php?q=' + encodeURIComponent(String(q || '').trim()), {
            credentials: 'same-origin',
            headers: orangeAdminCountryHeaders({ Accept: 'application/json' }),
            cache: 'no-store'
        }).then(function (r) { return r.json(); })
            .then(function (res) {
                listEl.innerHTML = '';
                var rows = (res && res.accounts) ? res.accounts : [];
                if (!rows.length) {
                    listEl.innerHTML = '<li class="gl-pick-empty">لا نتائج</li>';
                    return;
                }
                rows.forEach(function (acc) {
                    var li = document.createElement('li');
                    li.className = 'gl-pick-item';
                    li.setAttribute('role', 'button');
                    li.tabIndex = 0;
                    li.textContent = (acc.code || '') + ' — ' + (acc.name || '');
                    li.addEventListener('click', function () { ilpAccPickFill(acc); ilpAccPickClose(); });
                    li.addEventListener('dblclick', function () { ilpAccPickFill(acc); ilpAccPickClose(); });
                    li.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ilpAccPickFill(acc); ilpAccPickClose(); } });
                    listEl.appendChild(li);
                });
            })
            .catch(function (e) {
                listEl.innerHTML = '<li class="gl-pick-empty">' + esc(e.message || String(e)) + '</li>';
            });
    }

    function ilpAccPickOpen() {
        var modal = document.getElementById('ilp_acc_pick_modal');
        var qEl = document.getElementById('ilp_acc_pick_q');
        if (!modal || !qEl) return;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('gl-pick-open');
        qEl.value = (document.getElementById('ilp_account_code').value || '').trim();
        ilpAccPickRender(qEl.value);
        qEl.focus();
    }

    function ilpAccPickClose() {
        var modal = document.getElementById('ilp_acc_pick_modal');
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('gl-pick-open');
    }

    function ilpTranslate() {
        var ar = (document.getElementById('ilp_label_ar').value || '').trim();
        if (!ar) {
            alert('أدخل التسمية العربية أولاً');
            return;
        }
        postJSON('/admin/api/translate/names.php', {
            name_ar: ar,
            silent: false,
            forceFromArabic: true
        }).then(function (res) {
            if (!res || !res.success) {
                alert((res && res.message) || 'تعذر الترجمة');
                return;
            }
            var en = (res.translations && res.translations.name_en) ? res.translations.name_en : (res.name_en || '');
            if (en) document.getElementById('ilp_label_en').value = en;
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    document.getElementById('ilp_context').addEventListener('change', function () {
        ilpRefreshLineKindOptions('');
    });
    document.getElementById('ilp_btn_save').addEventListener('click', ilpSave);
    document.getElementById('ilp_btn_new').addEventListener('click', ilpResetForm);
    document.getElementById('ilp_btn_reload').addEventListener('click', ilpLoadList);
    document.getElementById('ilp_btn_save_order').addEventListener('click', ilpSaveOrder);
    document.getElementById('ilp_btn_translate').addEventListener('click', ilpTranslate);
    document.getElementById('ilp_system_key').addEventListener('change', ilpSyncSystemKeyBinding);
    document.getElementById('ilp_account_code').addEventListener('input', function () {
        if (ilpAccountTimer) clearTimeout(ilpAccountTimer);
        var code = this.value || '';
        ilpAccountTimer = setTimeout(function () { ilpLookupAccountByCode(code); }, 280);
    });
    document.getElementById('ilp_account_code').addEventListener('dblclick', ilpAccPickOpen);
    document.getElementById('ilp_acc_pick_backdrop').addEventListener('click', ilpAccPickClose);
    document.getElementById('ilp_acc_pick_close').addEventListener('click', ilpAccPickClose);
    var ilpAccPickTimer = null;
    document.getElementById('ilp_acc_pick_q').addEventListener('input', function () {
        if (ilpAccPickTimer) clearTimeout(ilpAccPickTimer);
        var q = this.value || '';
        ilpAccPickTimer = setTimeout(function () { ilpAccPickRender(q); }, 220);
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') {
            var modal = document.getElementById('ilp_acc_pick_modal');
            if (modal && !modal.hidden) ilpAccPickClose();
        }
    });

    ilpResetForm();
    ilpLoadList();
})();
</script>
