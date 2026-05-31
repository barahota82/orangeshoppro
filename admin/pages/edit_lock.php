<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/edit_lock.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/journal_types.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/admin_settings_country.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$elCountryId = orange_admin_settings_effective_country_id($pdo);
$elCountryRow = orange_country_row_by_id($pdo, $elCountryId, false);
$elCountryLabel = trim((string) ($elCountryRow['name_ar'] ?? ''));
if ($elCountryLabel === '' && $elCountryRow !== null) {
    $elCountryLabel = trim((string) ($elCountryRow['name_en'] ?? ''));
}
if ($elCountryLabel === '') {
    $elCountryLabel = orange_countries_display_code(orange_admin_context_country_code($pdo));
}
orange_journal_types_sync_canonical_defaults($pdo);
/** @var list<array<string, mixed>> $elJournalTypes */
$elJournalTypes = orange_journal_types_list($pdo, $elCountryId);
$elDateFromDisp = orange_format_datetime_dmY_hi(date('Y-m-01 00:00:00'));
$elDateToDisp = orange_format_datetime_dmY_hi(date('Y-m-d 23:59:00'));
?>
<div class="gl-posting-page" dir="rtl">
    <header class="gl-posting-appbar">
        <span class="gl-posting-appbar__title">إقفال التعديلات</span>
    </header>
    <p class="gl-posting-intro" style="margin:0.5rem 1rem 0.75rem;font-size:0.95rem;color:#444;line-height:1.55;">
        <strong>سياق الدولة:</strong> <?php echo htmlspecialchars($elCountryLabel, ENT_QUOTES, 'UTF-8'); ?>
        — تُعرض مستندات هذه الدولة فقط.
        رقابة على إدخالات الموظفين: <strong>قفل</strong> يمنع التعديل والحذف؛ <strong>فك القفل</strong> (بصلاحية) للتصحيح من شاشة المستند.
        الحفظ يؤثر فوراً في التقارير — هذه الشاشة <strong>لا</strong> تؤجل الترحيل المحاسبي.
    </p>
    <style>
        #el_lock_tbody tr.el-row-selected { outline: 2px solid #2563eb; outline-offset: -2px; background: #eff6ff; }
        #el_lock_tbody tr[data-id] { cursor: pointer; }
        #el_lock_tbody tr[data-id] input { cursor: default; }
        .el-badge-open { color: #15803d; font-weight: 600; }
        .el-badge-locked { color: #b45309; font-weight: 600; }
    </style>

    <div class="gl-posting-workbench">
        <section class="gl-posting-pane gl-posting-pane--source" aria-labelledby="el_lock_table_title">
            <div class="gl-posting-pane__toolbar gl-posting-pane__toolbar--filters">
                <div class="gl-posting-field">
                    <span class="gl-posting-field__label" id="el_movement_type_label">نوع الحركة :</span>
                    <div class="gl-posting-field__row gl-posting-field__row--movement">
                        <select id="el_movement_type" class="gl-posting-select gl-posting-select--movement-type" aria-labelledby="el_movement_type_label"<?php echo $elJournalTypes === [] ? ' disabled' : ''; ?>>
                            <option value="">— اختر نوع اليومية —</option>
                            <?php if ($elJournalTypes !== []): ?>
                            <option value="all">الكل</option>
                            <?php endif; ?>
                            <?php foreach ($elJournalTypes as $jt):
                                $jid = (int) ($jt['id'] ?? 0);
                                $jname = trim((string) ($jt['name_ar'] ?? ''));
                                ?>
                            <option value="<?php echo $jid; ?>"><?php echo htmlspecialchars($jname, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="gl-posting-check-label">
                            <input type="checkbox" id="el_all_movements" class="gl-posting-chk">
                            جميع الحركات
                        </label>
                    </div>
                </div>
                <div class="gl-posting-field">
                    <label class="gl-posting-field__label" for="el_lock_filter">حالة القفل</label>
                    <select id="el_lock_filter" class="gl-posting-select">
                        <option value="all">الكل</option>
                        <option value="open">مفتوح للتعديل</option>
                        <option value="locked">مقفول</option>
                    </select>
                </div>
                <div class="gl-posting-dates">
                    <div class="gl-posting-field">
                        <label class="gl-posting-field__label" for="el_date_from">من تاريخ</label>
                        <input type="text" id="el_date_from" class="gl-posting-inp-datetime orange-inp-dmyhi" value="<?php echo htmlspecialchars($elDateFromDisp, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                    </div>
                    <div class="gl-posting-field">
                        <label class="gl-posting-field__label" for="el_date_to">إلى تاريخ</label>
                        <input type="text" id="el_date_to" class="gl-posting-inp-datetime orange-inp-dmyhi" value="<?php echo htmlspecialchars($elDateToDisp, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                    </div>
                </div>
            </div>
            <div class="gl-posting-pane__toolbar gl-posting-pane__toolbar--actions">
                <button type="button" class="btn-secondary" id="el_btn_reload">تحديث القائمة</button>
            </div>
            <h2 id="el_lock_table_title" class="gl-posting-subcap">المستندات المحفوظة</h2>
            <div class="gl-posting-table-frame gl-posting-table-frame--scroll">
                <table class="gl-posting-gridtable">
                    <thead>
                        <tr>
                            <th class="gl-posting-col-chk" aria-label="اختيار"></th>
                            <th>م</th>
                            <th>نوع القيد</th>
                            <th>المرجع / البيان</th>
                            <th>المبلغ</th>
                            <th>التاريخ</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody id="el_lock_tbody">
                        <tr><td colspan="7" class="gl-posting-empty-cell">اضغط «تحديث القائمة».</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="gl-posting-pane__footer">
                <button type="button" class="btn" id="el_btn_lock_sel">قفل المحدد</button>
                <button type="button" class="btn-secondary" id="el_btn_unlock_sel">فك قفل المحدد</button>
                <button type="button" class="btn-secondary" id="el_btn_lock_filtered">قفل كل نتائج الفترة</button>
                <button type="button" class="btn-secondary" id="el_btn_unlock_filtered">فك قفل المقفول في الفترة</button>
            </div>
        </section>
        <section class="gl-posting-pane gl-posting-pane--ledger" aria-labelledby="el_preview_cap">
            <h2 id="el_preview_cap" class="gl-posting-subcap gl-posting-subcap--ledger">معاينة أسطر القيد</h2>
            <div class="gl-posting-table-frame gl-posting-table-frame--grow">
                <table class="gl-posting-gridtable gl-posting-gridtable--ledger">
                    <thead>
                        <tr>
                            <th class="gl-posting-col-num">م</th>
                            <th>كود الحساب</th>
                            <th>اسم الحساب</th>
                            <th>مدين</th>
                            <th>دائن</th>
                            <th>البيان</th>
                        </tr>
                    </thead>
                    <tbody id="el_preview_tbody">
                        <tr><td colspan="6" class="gl-posting-empty-cell gl-posting-empty-cell--ledger">انقر صفاً للمعاينة.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<script>
(function () {
    var GL_ENTRY_LABELS = <?php echo json_encode(orange_gl_entry_type_labels_map(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var elSel = document.getElementById('el_movement_type');
    var elChkAll = document.getElementById('el_all_movements');
    var VAL_ALL = 'all';
    var lastFilterInfo = null;

    function glEntryTypeLabel(et) {
        et = String(et == null ? '' : et);
        return GL_ENTRY_LABELS[et] || et || '—';
    }

    function syncAllMovementsUi() {
        if (!elChkAll || !elSel) return;
        if (elChkAll.checked) {
            elSel.disabled = true;
            elSel.value = VAL_ALL;
        } else {
            elSel.disabled = false;
            if (elSel.value === VAL_ALL) {
                elSel.value = '';
            }
        }
    }

    if (elChkAll && elSel) {
        elChkAll.addEventListener('change', function () {
            syncAllMovementsUi();
            loadList();
        });
        elSel.addEventListener('change', function () {
            elChkAll.checked = (elSel.value === VAL_ALL);
            syncAllMovementsUi();
            loadList();
        });
        elChkAll.checked = true;
        syncAllMovementsUi();
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
    }
    function filterParams() {
        var df = document.getElementById('el_date_from');
        var dt = document.getElementById('el_date_to');
        var dfVal = df && window.orangeDmyHiToSqlDatetime ? window.orangeDmyHiToSqlDatetime(df.value) : (df ? df.value : '');
        var dtVal = dt && window.orangeDmyHiToSqlDatetime ? window.orangeDmyHiToSqlDatetime(dt.value) : (dt ? dt.value : '');
        return {
            all_movements: !!(elChkAll && elChkAll.checked),
            journal_type_id: (elSel && !elSel.disabled && elSel.value && elSel.value !== VAL_ALL) ? elSel.value : '',
            lock_filter: (document.getElementById('el_lock_filter') || {}).value || 'all',
            date_from: dfVal || '',
            date_to: dtVal || ''
        };
    }
    function renderRows(rows) {
        var tb = document.getElementById('el_lock_tbody');
        if (!tb) return;
        if (!rows || !rows.length) {
            var msg = 'لا مستندات في الفترة المحددة.';
            if (lastFilterInfo && lastFilterInfo.entry_type_mode === 'unmapped_journal_type') {
                msg = 'لا توجد حركات لهذا النوع بعد، أو نوع اليومية غير مربوط بأنواع الدخول. جرّب «جميع الحركات».';
            }
            tb.innerHTML = '<tr><td colspan="7" class="gl-posting-empty-cell">' + esc(msg) + '</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var id = parseInt(r.id, 10) || 0;
            var st = r.is_locked ? '<span class="el-badge-locked">مقفول</span>' : '<span class="el-badge-open">مفتوح</span>';
            html += '<tr data-id="' + id + '">';
            html += '<td class="gl-posting-col-chk"><input type="checkbox" class="el-pick" value="' + id + '"></td>';
            html += '<td>' + id + '</td>';
            html += '<td><small title="' + esc(r.doc_kind || '') + '">' + esc(r.kind_label || glEntryTypeLabel(r.doc_kind)) + '</small></td>';
            html += '<td><small>' + esc(r.reference) + '</small><br>' + esc(r.label_ar) + '</td>';
            html += '<td dir="ltr">' + esc(r.amount != null ? r.amount : '—') + '</td>';
            html += '<td>' + esc(r.saved_at) + '</td>';
            html += '<td>' + st + '</td></tr>';
        }
        tb.innerHTML = html;
    }
    async function loadList() {
        var p = filterParams();
        var q = 'all_movements=' + (p.all_movements ? '1' : '0')
            + '&lock_filter=' + encodeURIComponent(p.lock_filter)
            + '&date_from=' + encodeURIComponent(p.date_from)
            + '&date_to=' + encodeURIComponent(p.date_to);
        if (p.journal_type_id) {
            q += '&journal_type_id=' + encodeURIComponent(p.journal_type_id);
        }
        var res = await fetch('/admin/api/edit-lock/list.php?' + q, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: orangeAdminCountryHeaders()
        });
        var j = await res.json();
        if (!j.success) { window.alert(j.message || 'تعذر التحميل'); return; }
        lastFilterInfo = j.filter || null;
        renderRows(j.rows || []);
        document.getElementById('el_preview_tbody').innerHTML =
            '<tr><td colspan="6" class="gl-posting-empty-cell gl-posting-empty-cell--ledger">انقر صفاً للمعاينة.</td></tr>';
    }
    async function loadPreview(id) {
        var tb = document.getElementById('el_preview_tbody');
        tb.innerHTML = '<tr><td colspan="6" class="gl-posting-empty-cell">جاري التحميل…</td></tr>';
        var res = await fetch('/admin/api/edit-lock/preview.php?id=' + id, {
            credentials: 'same-origin',
            headers: orangeAdminCountryHeaders()
        });
        var j = await res.json();
        if (!j.success) {
            tb.innerHTML = '<tr><td colspan="6" class="gl-posting-empty-cell">' + esc(j.message || 'تعذر') + '</td></tr>';
            return;
        }
        var cap = document.getElementById('el_preview_cap');
        if (cap && j.meta) {
            cap.textContent = 'معاينة — ' + (j.meta.reference || '') + (j.meta.is_locked ? ' (مقفول)' : ' (مفتوح)');
        }
        var lines = j.lines || [];
        if (!lines.length) {
            tb.innerHTML = '<tr><td colspan="6" class="gl-posting-empty-cell">لا أسطر قيد (مخزون فقط أو بدون GL).</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < lines.length; i++) {
            var ln = lines[i];
            html += '<tr><td>' + esc(ln.line_no) + '</td><td dir="ltr">' + esc(ln.code) + '</td><td>' + esc(ln.name)
                + '</td><td dir="ltr">' + esc(ln.debit) + '</td><td dir="ltr">' + esc(ln.credit) + '</td><td>' + esc(ln.memo) + '</td></tr>';
        }
        tb.innerHTML = html;
    }
    function pickedIds() {
        return Array.from(document.querySelectorAll('.el-pick:checked')).map(function (c) {
            return parseInt(c.value, 10);
        }).filter(function (x) { return x > 0; });
    }
    document.getElementById('el_btn_reload').addEventListener('click', loadList);
    document.getElementById('el_lock_tbody').addEventListener('click', function (ev) {
        var tr = orangeAdminClosest(ev, 'tr[data-id]');
        if (!tr || orangeAdminClosest(ev, 'input[type="checkbox"]')) return;
        document.querySelectorAll('#el_lock_tbody tr.el-row-selected').forEach(function (x) { x.classList.remove('el-row-selected'); });
        tr.classList.add('el-row-selected');
        loadPreview(parseInt(tr.getAttribute('data-id'), 10) || 0);
    });
    document.getElementById('el_btn_lock_sel').addEventListener('click', async function () {
        var ids = pickedIds();
        if (!ids.length) { window.alert('حدد مستنداً واحداً على الأقل'); return; }
        var r = await postJSON('/admin/api/edit-lock/lock.php', { ids: ids });
        window.alert(r.message || (r.success ? 'تم' : 'فشل'));
        if (r.success) loadList();
    });
    document.getElementById('el_btn_unlock_sel').addEventListener('click', async function () {
        var ids = pickedIds();
        if (!ids.length) { window.alert('حدد مستنداً واحداً على الأقل'); return; }
        var r = await postJSON('/admin/api/edit-lock/unlock.php', { ids: ids });
        window.alert(r.message || (r.success ? 'تم' : 'فشل'));
        if (r.success) loadList();
    });
    document.getElementById('el_btn_lock_filtered').addEventListener('click', async function () {
        if (!window.confirm('قفل كل المستندات المفتوحة ضمن الفلتر الحالي؟')) return;
        var p = filterParams();
        p.lock_filtered = true;
        var r = await postJSON('/admin/api/edit-lock/lock.php', p);
        window.alert(r.message || (r.success ? 'تم' : 'فشل'));
        if (r.success) loadList();
    });
    document.getElementById('el_btn_unlock_filtered').addEventListener('click', async function () {
        if (!window.confirm('فك قفل كل المستندات المقفولة ضمن الفلتر؟')) return;
        var p = filterParams();
        p.unlock_filtered = true;
        var r = await postJSON('/admin/api/edit-lock/unlock.php', p);
        window.alert(r.message || (r.success ? 'تم' : 'فشل'));
        if (r.success) loadList();
    });
    loadList();
})();
</script>
