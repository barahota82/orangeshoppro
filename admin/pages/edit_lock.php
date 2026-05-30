<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/edit_lock.php';
require_once __DIR__ . '/../../includes/date_format.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$elKinds = orange_edit_lock_doc_kinds();
$elDateFromDisp = orange_format_datetime_dmY_hi(date('Y-m-01 00:00:00'));
$elDateToDisp = orange_format_datetime_dmY_hi(date('Y-m-d 23:59:00'));
?>
<div class="gl-posting-page" dir="rtl">
    <header class="gl-posting-appbar">
        <span class="gl-posting-appbar__title">إقفال التعديلات</span>
    </header>
    <p class="gl-posting-intro" style="margin:0.5rem 1rem 0.75rem;font-size:0.95rem;color:#444;line-height:1.55;">
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
                    <label class="gl-posting-field__label" for="el_doc_kind">نوع المستند</label>
                    <select id="el_doc_kind" class="gl-posting-select">
                        <option value="all">— الكل —</option>
                        <?php foreach ($elKinds as $code => $meta): ?>
                        <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($meta['label_ar'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
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
                            <th>النوع</th>
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
    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
    }
    function filterParams() {
        var df = document.getElementById('el_date_from');
        var dt = document.getElementById('el_date_to');
        var dfVal = df && window.orangeDmyHiToSqlDatetime ? window.orangeDmyHiToSqlDatetime(df.value) : (df ? df.value : '');
        var dtVal = dt && window.orangeDmyHiToSqlDatetime ? window.orangeDmyHiToSqlDatetime(dt.value) : (dt ? dt.value : '');
        return {
            doc_kind: (document.getElementById('el_doc_kind') || {}).value || 'all',
            lock_filter: (document.getElementById('el_lock_filter') || {}).value || 'all',
            date_from: dfVal || '',
            date_to: dtVal || ''
        };
    }
    function renderRows(rows) {
        var tb = document.getElementById('el_lock_tbody');
        if (!tb) return;
        if (!rows || !rows.length) {
            tb.innerHTML = '<tr><td colspan="7" class="gl-posting-empty-cell">لا مستندات في الفترة.</td></tr>';
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
            html += '<td>' + esc(r.kind_label || r.doc_kind) + '</td>';
            html += '<td><small>' + esc(r.reference) + '</small><br>' + esc(r.label_ar) + '</td>';
            html += '<td dir="ltr">' + esc(r.amount != null ? r.amount : '—') + '</td>';
            html += '<td>' + esc(r.saved_at) + '</td>';
            html += '<td>' + st + '</td></tr>';
        }
        tb.innerHTML = html;
    }
    async function loadList() {
        var p = filterParams();
        var q = 'doc_kind=' + encodeURIComponent(p.doc_kind)
            + '&lock_filter=' + encodeURIComponent(p.lock_filter)
            + '&date_from=' + encodeURIComponent(p.date_from)
            + '&date_to=' + encodeURIComponent(p.date_to);
        var res = await fetch('/admin/api/edit-lock/list.php?' + q, { credentials: 'same-origin', cache: 'no-store' });
        var j = await res.json();
        if (!j.success) { window.alert(j.message || 'تعذر التحميل'); return; }
        renderRows(j.rows || []);
        document.getElementById('el_preview_tbody').innerHTML =
            '<tr><td colspan="6" class="gl-posting-empty-cell gl-posting-empty-cell--ledger">انقر صفاً للمعاينة.</td></tr>';
    }
    async function loadPreview(id) {
        var tb = document.getElementById('el_preview_tbody');
        tb.innerHTML = '<tr><td colspan="6" class="gl-posting-empty-cell">جاري التحميل…</td></tr>';
        var res = await fetch('/admin/api/edit-lock/preview.php?id=' + id, { credentials: 'same-origin' });
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
