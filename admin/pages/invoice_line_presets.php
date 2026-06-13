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
?>
<style>
.ilp-form-grid {
    display: grid;
    grid-template-columns: minmax(5rem, 0.5fr) minmax(5rem, 0.55fr) minmax(7rem, 0.65fr) minmax(0, 1fr) minmax(0, 1fr);
    gap: 10px 12px;
    align-items: end;
}
.ilp-form-grid .ilp-span-full { grid-column: 1 / -1; }
.ilp-form-grid .ilp-account-row {
    grid-column: 1 / -1;
    display: grid;
    grid-template-columns: minmax(8rem, 0.55fr) minmax(0, 1fr) minmax(0, 1.2fr);
    gap: 10px 12px;
    align-items: end;
}
.ilp-row-ops { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
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
    .ilp-form-grid .ilp-account-row { grid-template-columns: 1fr; }
}
</style>

<div class="page-title">
    <h1>قائمة بنود الفاتورة المحفوظة</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars($ilpCountryLabel, ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php if (!$ilpReady): ?>
<div class="card" style="border:1px solid #fcd34d;background:#fffbeb;">
    <p class="card-hint" style="margin:0;">جداول البنود الإضافية غير جاهزة — افتح أي صفحة أدmin بعد <code>git pull</code> لترحيل rev 68.</p>
</div>
<?php endif; ?>

<div class="card">
    <h3 class="card-title">إضافة / تعديل بند محفوظ</h3>
    <input type="hidden" id="ilp_id" value="0">
    <input type="hidden" id="ilp_account_id" value="0">
    <div class="form-grid ilp-form-grid orange-doc-header-row">
        <div>
            <label for="ilp_sort">الترتيب</label>
            <input type="number" id="ilp_sort" class="admin-inp-readonly" readonly disabled tabindex="-1" dir="ltr" lang="en"
                value="<?php echo (int) $ilpNextSort; ?>">
        </div>
        <div>
            <label for="ilp_active">نشط</label>
            <select id="ilp_active" class="admin-inp"<?php echo !$ilpReady ? ' disabled' : ''; ?>>
                <option value="1">نعم</option>
                <option value="0">لا (مخفي من المنتقي)</option>
            </select>
        </div>
        <div>
            <label for="ilp_context">سياق الفاتورة</label>
            <select id="ilp_context" class="admin-inp"<?php echo !$ilpReady ? ' disabled' : ''; ?>>
                <?php foreach ($ilpContextLabels as $ctxKey => $ctxLabel): ?>
                <option value="<?php echo htmlspecialchars($ctxKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($ctxLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="ilp_line_kind">نوع البند (MD/CR)</label>
            <select id="ilp_line_kind" class="admin-inp"<?php echo !$ilpReady ? ' disabled' : ''; ?>></select>
        </div>
        <div>
            <label for="ilp_show_print">افتراضي — يظهر بالطباعة</label>
            <select id="ilp_show_print" class="admin-inp"<?php echo !$ilpReady ? ' disabled' : ''; ?>>
                <option value="0">لا</option>
                <option value="1">نعم</option>
            </select>
        </div>
        <div class="ilp-account-row">
            <div>
                <label for="ilp_account_q">بحث حساب (دليل)</label>
                <input type="search" id="ilp_account_q" class="admin-inp" placeholder="كود أو اسم…" autocomplete="off" dir="rtl"<?php echo !$ilpReady ? ' disabled' : ''; ?>>
            </div>
            <div>
                <label for="ilp_account_code">كود الحساب</label>
                <input type="text" id="ilp_account_code" class="admin-inp-readonly" readonly disabled tabindex="-1" dir="ltr" lang="en">
            </div>
            <div>
                <label for="ilp_account_name">اسم الحساب</label>
                <input type="text" id="ilp_account_name" class="admin-inp-readonly" readonly disabled tabindex="-1">
            </div>
            <ul class="ilp-pick-results ilp-span-full" id="ilp_account_results" hidden></ul>
        </div>
        <div>
            <label for="ilp_label_ar">التسمية (عربي)</label>
            <input type="text" id="ilp_label_ar" class="admin-inp" dir="auto"<?php echo !$ilpReady ? ' disabled' : ''; ?>>
        </div>
        <div>
            <label for="ilp_label_en">English</label>
            <input type="text" id="ilp_label_en" class="admin-inp" dir="ltr" lang="en"<?php echo !$ilpReady ? ' disabled' : ''; ?>>
        </div>
    </div>
    <div class="actions" style="margin-top:14px;">
        <button type="button" id="ilp_btn_save"<?php echo !$ilpReady ? ' disabled' : ''; ?>>حفظ</button>
        <button type="button" class="btn-secondary" id="ilp_btn_translate"<?php echo !$ilpReady ? ' disabled' : ''; ?>>ترجمة من العربي</button>
        <button type="button" class="btn-secondary" id="ilp_btn_new"<?php echo !$ilpReady ? ' disabled' : ''; ?>>جديد</button>
    </div>
</div>

<div class="card">
    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;">
        <h3 class="card-title" style="margin:0;">البنود المحفوظة</h3>
        <div class="orange-doc-toolbar-fields" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
            <label for="ilp_filter_context" class="muted" style="font-size:0.85rem;">فلتر:</label>
            <select id="ilp_filter_context" class="admin-inp" style="min-width:8rem;">
                <?php foreach ($ilpContextLabels as $ctxKey => $ctxLabel): ?>
                <option value="<?php echo htmlspecialchars($ctxKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($ctxLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn-secondary" id="ilp_btn_reload">تحديث</button>
            <button type="button" class="btn-secondary" id="ilp_btn_save_order"<?php echo !$ilpReady ? ' disabled' : ''; ?>>حفظ الترتيب</button>
        </div>
    </div>
    <div class="table-wrap" style="margin-top:12px;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width:3rem;">#</th>
                    <th>التسمية</th>
                    <th>الحساب</th>
                    <th>السياق</th>
                    <th>نوع البند</th>
                    <th>طباعة</th>
                    <th>نشط</th>
                    <th>ترتيب</th>
                    <th>إجراءات</th>
                </tr>
            </thead>
            <tbody id="ilp_list_body">
                <tr><td colspan="9" class="muted">جاري التحميل…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var ILP_LINE_KINDS = <?php echo json_encode($ilpLineKinds, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var ILP_NEXT_SORT = <?php echo (int) $ilpNextSort; ?>;
    var ilpAccountTimer = null;

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function ilpContextValue() {
        return (document.getElementById('ilp_context').value || 'both').trim();
    }

    function ilpLineKindMatchesContext(kind, ctx) {
        if (!kind || !kind.contexts) return false;
        if (ctx === 'both') return true;
        return kind.contexts.indexOf(ctx) !== -1 || kind.contexts.indexOf('both') !== -1;
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

    function ilpResetForm() {
        document.getElementById('ilp_id').value = '0';
        document.getElementById('ilp_account_id').value = '0';
        document.getElementById('ilp_sort').value = String(ILP_NEXT_SORT);
        document.getElementById('ilp_active').value = '1';
        document.getElementById('ilp_context').value = 'sales';
        document.getElementById('ilp_show_print').value = '0';
        document.getElementById('ilp_account_q').value = '';
        document.getElementById('ilp_account_code').value = '';
        document.getElementById('ilp_account_name').value = '';
        document.getElementById('ilp_label_ar').value = '';
        document.getElementById('ilp_label_en').value = '';
        document.getElementById('ilp_account_results').hidden = true;
        ilpRefreshLineKindOptions('');
    }

    function ilpFillForm(row) {
        document.getElementById('ilp_id').value = String(row.id || 0);
        document.getElementById('ilp_account_id').value = String(row.account_id || 0);
        document.getElementById('ilp_sort').value = String(row.sort_order || 0);
        document.getElementById('ilp_active').value = row.is_active ? '1' : '0';
        document.getElementById('ilp_context').value = row.invoice_context || 'both';
        ilpRefreshLineKindOptions(row.line_kind || '');
        document.getElementById('ilp_show_print').value = row.default_show_on_print ? '1' : '0';
        document.getElementById('ilp_account_code').value = row.account_code || '';
        document.getElementById('ilp_account_name').value = row.account_name || '';
        document.getElementById('ilp_label_ar').value = row.label_ar || '';
        document.getElementById('ilp_label_en').value = row.label_en || '';
        document.getElementById('ilp_account_results').hidden = true;
    }

    function ilpRenderList(rows) {
        var tb = document.getElementById('ilp_list_body');
        if (!tb) return;
        tb.innerHTML = '';
        if (!rows || !rows.length) {
            tb.innerHTML = '<tr><td colspan="9" class="muted">لا توجد بنود محفوظة</td></tr>';
            return;
        }
        rows.forEach(function (row, idx) {
            var tr = document.createElement('tr');
            tr.dataset.id = String(row.id || 0);
            tr.innerHTML = '<td>' + esc(String(row.id)) + '</td>'
                + '<td>' + esc(row.label_ar || '') + '</td>'
                + '<td dir="ltr">' + esc((row.account_code || '') + (row.account_name ? ' — ' + row.account_name : '')) + '</td>'
                + '<td>' + esc(row.invoice_context_label || row.invoice_context || '') + '</td>'
                + '<td style="font-size:0.85rem;">' + esc(row.line_kind_label || row.line_kind || '') + '</td>'
                + '<td>' + (row.default_show_on_print ? 'نعم' : 'لا') + '</td>'
                + '<td>' + (row.is_active ? 'نعم' : 'لا') + '</td>'
                + '<td dir="ltr">' + esc(String(row.sort_order || 0)) + '</td>'
                + '<td><div class="ilp-row-ops">'
                + '<button type="button" class="btn-secondary ilp-move-up" title="أعلى">↑</button>'
                + '<button type="button" class="btn-secondary ilp-move-down" title="أسفل">↓</button>'
                + '<button type="button" class="btn-secondary ilp-edit">تعديل</button>'
                + '</div></td>';
            tr.querySelector('.ilp-edit').addEventListener('click', function () { ilpFillForm(row); });
            tr.querySelector('.ilp-move-up').addEventListener('click', function () {
                var prev = tr.previousElementSibling;
                if (prev) tb.insertBefore(tr, prev);
            });
            tr.querySelector('.ilp-move-down').addEventListener('click', function () {
                var next = tr.nextElementSibling;
                if (next) tb.insertBefore(next, tr);
            });
            tb.appendChild(tr);
        });
    }

    function ilpLoadList() {
        var ctx = (document.getElementById('ilp_filter_context').value || '').trim();
        var url = '/admin/api/invoice-ancillary/presets-admin-list.php';
        if (ctx) url += '?invoice_context=' + encodeURIComponent(ctx);
        fetch(url, { credentials: 'same-origin', headers: orangeAdminCountryHeaders({ 'Accept': 'application/json' }), cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success) {
                    alert((res && res.message) || 'تعذر التحميل');
                    return;
                }
                ilpRenderList(res.presets || []);
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
            default_show_on_print: document.getElementById('ilp_show_print').value === '1',
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

    function ilpSearchAccounts(q) {
        var list = document.getElementById('ilp_account_results');
        if (!list) return;
        q = String(q || '').trim();
        if (q.length < 1) {
            list.hidden = true;
            list.innerHTML = '';
            return;
        }
        fetch('/admin/api/accounts/search-leaves.php?q=' + encodeURIComponent(q), {
            credentials: 'same-origin',
            headers: orangeAdminCountryHeaders({ Accept: 'application/json' }),
            cache: 'no-store'
        }).then(function (r) { return r.json(); })
            .then(function (res) {
                list.innerHTML = '';
                var rows = (res && res.accounts) ? res.accounts : [];
                if (!rows.length) {
                    list.innerHTML = '<li class="muted">لا نتائج</li>';
                    list.hidden = false;
                    return;
                }
                rows.forEach(function (acc) {
                    var li = document.createElement('li');
                    li.textContent = (acc.code || '') + ' — ' + (acc.name || '');
                    li.addEventListener('click', function () {
                        document.getElementById('ilp_account_id').value = String(acc.id || 0);
                        document.getElementById('ilp_account_code').value = acc.code || '';
                        document.getElementById('ilp_account_name').value = acc.name || '';
                        if (!(document.getElementById('ilp_label_ar').value || '').trim()) {
                            document.getElementById('ilp_label_ar').value = acc.name || '';
                        }
                        list.hidden = true;
                    });
                    list.appendChild(li);
                });
                list.hidden = false;
            });
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
            if (res.name_en) document.getElementById('ilp_label_en').value = res.name_en;
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    document.getElementById('ilp_context').addEventListener('change', function () {
        ilpRefreshLineKindOptions('');
    });
    document.getElementById('ilp_btn_save').addEventListener('click', ilpSave);
    document.getElementById('ilp_btn_new').addEventListener('click', ilpResetForm);
    document.getElementById('ilp_btn_reload').addEventListener('click', ilpLoadList);
    document.getElementById('ilp_btn_save_order').addEventListener('click', ilpSaveOrder);
    document.getElementById('ilp_filter_context').addEventListener('change', ilpLoadList);
    document.getElementById('ilp_btn_translate').addEventListener('click', ilpTranslate);
    document.getElementById('ilp_account_q').addEventListener('input', function () {
        if (ilpAccountTimer) clearTimeout(ilpAccountTimer);
        var q = this.value || '';
        ilpAccountTimer = setTimeout(function () { ilpSearchAccounts(q); }, 220);
    });

    ilpResetForm();
    ilpLoadList();
})();
</script>
