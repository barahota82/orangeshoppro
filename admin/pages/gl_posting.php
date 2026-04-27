<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/journal_types.php';
require_once __DIR__ . '/../../includes/date_format.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
orange_journal_types_sync_canonical_defaults($pdo);
/** @var list<array<string, mixed>> $postingJournalTypes */
$postingJournalTypes = orange_journal_types_list($pdo);
$glPostDateFromDisp = orange_format_datetime_dmY_hi(date('Y-m-01 00:00:00'));
$glPostDateToDisp = orange_format_datetime_dmY_hi(date('Y-m-d 23:59:00'));
?>
<div class="gl-posting-page" dir="rtl">
    <header class="gl-posting-appbar">
        <span class="gl-posting-appbar__title">ترحيل الحركات</span>
    </header>
    <?php if ($postingJournalTypes === []): ?>
    <p class="gl-posting-intro" style="margin:0.5rem 1rem 0.75rem;font-size:0.95rem;color:#444;line-height:1.5;">
        <strong style="color:#b45309;">تنبيه:</strong> لا توجد أنواع يومية في النظام — راجع ترحيل المخطط أو جدول <code>journal_types</code>.
    </p>
    <?php endif; ?>
    <style>
        #gl_post_movements_tbody tr.gl-row-selected { outline: 2px solid #2563eb; outline-offset: -2px; background: #eff6ff; }
        #gl_post_movements_tbody tr[data-id] { cursor: pointer; }
        #gl_post_movements_tbody tr[data-id] input, #gl_post_movements_tbody tr[data-id] label { cursor: default; }
    </style>

    <div class="gl-posting-workbench">
        <!-- في RTL العمود الأول يظهر يمين الشاشة: مصدر الحركات -->
        <section class="gl-posting-pane gl-posting-pane--source" aria-labelledby="gl_post_movements_table_title">
            <div class="gl-posting-pane__toolbar gl-posting-pane__toolbar--filters">
                <div class="gl-posting-field">
                    <span class="gl-posting-field__label" id="gl_post_movement_type_label">نوع الحركة :</span>
                    <div class="gl-posting-field__row gl-posting-field__row--movement">
                        <select id="gl_post_movement_type" class="gl-posting-select gl-posting-select--movement-type" aria-labelledby="gl_post_movement_type_label"<?php echo $postingJournalTypes === [] ? ' disabled' : ''; ?>>
                            <option value="">— اختر نوع اليومية —</option>
                            <?php if ($postingJournalTypes !== []): ?>
                            <option value="all">الكل</option>
                            <?php endif; ?>
                            <?php foreach ($postingJournalTypes as $jt):
                                $jid = (int) ($jt['id'] ?? 0);
                                $jname = trim((string) ($jt['name_ar'] ?? ''));
                                ?>
                            <option value="<?php echo $jid; ?>"><?php echo htmlspecialchars($jname, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="gl-posting-check-label">
                            <input type="checkbox" id="gl_post_all_movements" class="gl-posting-chk">
                            جميع الحركات
                        </label>
                    </div>
                </div>
                <div class="gl-posting-dates">
                    <div class="gl-posting-field">
                        <label class="gl-posting-field__label" for="gl_post_date_from">تاريخ الحركة من</label>
                        <input type="text" id="gl_post_date_from" class="gl-posting-inp-datetime orange-inp-dmyhi" value="<?php echo htmlspecialchars($glPostDateFromDisp, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                    </div>
                    <div class="gl-posting-field">
                        <label class="gl-posting-field__label" for="gl_post_date_to">إلى تاريخ</label>
                        <input type="text" id="gl_post_date_to" class="gl-posting-inp-datetime orange-inp-dmyhi" value="<?php echo htmlspecialchars($glPostDateToDisp, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                    </div>
                </div>
            </div>

            <div class="gl-posting-pane__toolbar gl-posting-pane__toolbar--actions">
                <button type="button" class="btn-secondary" id="gl_post_btn_unposted">إسترجاع الحركات الغير مرحلة</button>
                <button type="button" class="btn-secondary" id="gl_post_btn_posted">إسترجاع الحركات المرحلة</button>
            </div>

            <h2 id="gl_post_movements_table_title" class="gl-posting-subcap">حركات غير مرحلة</h2>
            <div class="gl-posting-table-frame gl-posting-table-frame--scroll">
                <table class="gl-posting-gridtable">
                    <thead>
                        <tr>
                            <th class="gl-posting-col-chk" aria-label="اختيار"></th>
                            <th>م</th>
                            <th>نوع القيد</th>
                            <th>المصدر</th>
                            <th>البيان / المرجع</th>
                            <th>المبلغ</th>
                            <th>تاريخ الحركة</th>
                        </tr>
                    </thead>
                    <tbody id="gl_post_movements_tbody">
                        <tr>
                            <td colspan="7" class="gl-posting-empty-cell">لا توجد حركات — اضغط «إسترجاع الحركات الغير مرحلة» أو «الحركات المرحلة».</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="gl-posting-pane__footer">
                <button type="button" class="btn" id="gl_post_btn_do_post">ترحيل</button>
                <button type="button" class="btn-secondary" id="gl_post_btn_undo">إلغاء الترحيل</button>
            </div>
        </section>

        <section class="gl-posting-pane gl-posting-pane--ledger" aria-labelledby="gl_post_entries_cap">
            <h2 id="gl_post_entries_cap" class="gl-posting-subcap gl-posting-subcap--ledger">معاينة أسطر الحركة</h2>
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
                    <tbody id="gl_post_entries_tbody">
                        <tr class="gl-posting-placeholder-row">
                            <td colspan="6" class="gl-posting-empty-cell gl-posting-empty-cell--ledger">انقر صفاً في الجدول الأيسر (ليس على مربع الاختيار) لمعاينة أسطر القيد قبل أو بعد الترحيل.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<script>
(function () {
    var GL_ENTRY_LABELS = <?php echo json_encode(orange_gl_entry_type_labels_map(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    function glEntryTypeLabel(et) {
        et = String(et == null ? '' : et);
        return GL_ENTRY_LABELS[et] || et || '—';
    }
    var sel = document.getElementById('gl_post_movement_type');
    var chkAll = document.getElementById('gl_post_all_movements');
    var VAL_ALL = 'all';
    var listMode = 'pending';
    function scheduleReloadMovements() {
        if (listMode === 'pending' || listMode === 'posted') {
            loadMovements(listMode);
        }
    }

    if (chkAll && sel) {
        function syncAllMovements() {
            if (chkAll.checked) {
                sel.disabled = true;
                sel.value = VAL_ALL;
            } else {
                sel.disabled = false;
                if (sel.value === VAL_ALL) {
                    sel.value = '';
                }
            }
        }
        chkAll.addEventListener('change', function () {
            syncAllMovements();
            scheduleReloadMovements();
        });
        sel.addEventListener('change', function () {
            chkAll.checked = (sel.value === VAL_ALL);
            syncAllMovements();
            scheduleReloadMovements();
        });
        syncAllMovements();
    }

    var tbody = document.getElementById('gl_post_movements_tbody');
    var cap = document.querySelector('h2#gl_post_movements_table_title');
    var ledgerCap = document.getElementById('gl_post_entries_cap');
    var ledgerTbody = document.getElementById('gl_post_entries_tbody');
    var LEDGER_PLACEHOLDER = '<tr class="gl-posting-placeholder-row"><td colspan="6" class="gl-posting-empty-cell gl-posting-empty-cell--ledger">انقر صفاً في الجدول الأيسر (ليس على مربع الاختيار) لمعاينة أسطر القيد قبل أو بعد الترحيل.</td></tr>';
    var lastFilterInfo = null;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/"/g, '&quot;');
    }

    function renderRows(rows) {
        if (!tbody) {
            return;
        }
        if (!rows || !rows.length) {
            var msg = 'لا توجد سجلات في الفترة المحددة.';
            if (lastFilterInfo && lastFilterInfo.entry_type_mode === 'unmapped_journal_type') {
                msg = 'لا توجد حركات في الطابور لهذا النوع بعد: نوع اليومية غير مربوط بأنواع الدخول في الطابور (مثلاً مردود مبيعات قبل تفعيل مسارها)، أو لا توجد حركات فعلياً. جرّب «الكل» أو نوعاً آخر.';
            } else if (lastFilterInfo && lastFilterInfo.entry_type_mode === 'mapped' && sel && chkAll && !chkAll.disabled && !chkAll.checked) {
                var jtv = sel.value;
                if (jtv && jtv !== VAL_ALL) {
                    msg = 'لا توجد حركات ضمن نوع اليومية المحدد والفترة الزمنية.';
                }
            }
            tbody.innerHTML = '<tr><td colspan="7" class="gl-posting-empty-cell">' + esc(msg) + '</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var id = parseInt(r.id, 10) || 0;
            var chk = listMode === 'pending'
                ? '<input type="checkbox" class="gl-pick" value="' + id + '">'
                : '<input type="checkbox" class="gl-unpost" value="' + id + '">';
            html += '<tr data-id="' + id + '"><td class="gl-posting-col-chk">' + chk + '</td>';
            html += '<td>' + id + '</td>';
            html += '<td><small title="' + esc(r.entry_type || '') + '">' + esc(glEntryTypeLabel(r.entry_type)) + '</small></td>';
            html += '<td>' + esc(r.source_label) + '</td>';
            html += '<td><small>' + esc(r.reference) + '</small><br>' + esc(r.description) + '</td>';
            html += '<td>' + esc(r.amount) + '</td>';
            html += '<td>' + esc(r.movement_at) + '</td></tr>';
        }
        tbody.innerHTML = html;
    }

    function resetLedgerPanel() {
        if (ledgerCap) {
            ledgerCap.textContent = 'معاينة أسطر الحركة';
        }
        if (ledgerTbody) {
            ledgerTbody.innerHTML = LEDGER_PLACEHOLDER;
        }
    }

    function renderLedgerLines(lines) {
        if (!ledgerTbody) {
            return;
        }
        if (!lines || !lines.length) {
            ledgerTbody.innerHTML = '<tr><td colspan="6" class="gl-posting-empty-cell gl-posting-empty-cell--ledger">لا أسطر للمعاينة.</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < lines.length; i++) {
            var ln = lines[i];
            var no = ln.line_no != null ? ln.line_no : (i + 1);
            html += '<tr><td class="gl-posting-col-num">' + esc(no) + '</td>';
            html += '<td dir="ltr">' + esc(ln.code) + '</td>';
            html += '<td>' + esc(ln.name) + '</td>';
            html += '<td dir="ltr">' + esc(ln.debit) + '</td>';
            html += '<td dir="ltr">' + esc(ln.credit) + '</td>';
            html += '<td>' + esc(ln.memo) + '</td></tr>';
        }
        ledgerTbody.innerHTML = html;
    }

    async function loadPreview(pendingId) {
        if (!ledgerTbody || pendingId <= 0) {
            return;
        }
        ledgerTbody.innerHTML = '<tr><td colspan="6" class="gl-posting-empty-cell gl-posting-empty-cell--ledger">جاري التحميل…</td></tr>';
        try {
            var res = await fetch('/admin/api/gl/pending-preview.php?id=' + pendingId, { credentials: 'same-origin', cache: 'no-store' });
            var j = await res.json();
            if (!j.success) {
                ledgerTbody.innerHTML = '<tr><td colspan="6" class="gl-posting-empty-cell gl-posting-empty-cell--ledger">' + esc(j.message || 'تعذر المعاينة') + '</td></tr>';
                return;
            }
            if (ledgerCap && j.meta) {
                var m = j.meta;
                var ref = m.reference ? ' — ' + m.reference : '';
                var st = m.status === 'posted' ? ' (مرحّل)' : ' (معلّق)';
                ledgerCap.textContent = 'معاينة حركة #' + m.id + ref + st;
            }
            renderLedgerLines(j.lines || []);
        } catch (e) {
            ledgerTbody.innerHTML = '<tr><td colspan="6" class="gl-posting-empty-cell gl-posting-empty-cell--ledger">' + esc(e.message || String(e)) + '</td></tr>';
        }
    }

    if (tbody) {
        tbody.addEventListener('click', function (ev) {
            var tr = ev.target.closest('tr[data-id]');
            if (!tr) {
                return;
            }
            if (ev.target.closest('input[type="checkbox"]')) {
                return;
            }
            var pid = parseInt(tr.getAttribute('data-id'), 10) || 0;
            if (pid <= 0) {
                return;
            }
            tbody.querySelectorAll('tr.gl-row-selected').forEach(function (x) { x.classList.remove('gl-row-selected'); });
            tr.classList.add('gl-row-selected');
            loadPreview(pid);
        });
    }

    async function loadMovements(status) {
        listMode = status;
        if (cap) {
            cap.textContent = status === 'posted' ? 'حركات مرحّلة (عرض)' : 'حركات غير مرحلة';
        }
        var df = document.getElementById('gl_post_date_from');
        var dt = document.getElementById('gl_post_date_to');
        var dfVal = df && window.orangeDmyHiToSqlDatetime ? window.orangeDmyHiToSqlDatetime(df.value) : (df ? df.value : '');
        var dtVal = dt && window.orangeDmyHiToSqlDatetime ? window.orangeDmyHiToSqlDatetime(dt.value) : (dt ? dt.value : '');
        var jtParam = '';
        if (sel && chkAll && !sel.disabled && !chkAll.checked) {
            var jtv = sel.value;
            if (jtv && jtv !== VAL_ALL) {
                jtParam = '&journal_type_id=' + encodeURIComponent(jtv);
            }
        }
        var q = 'status=' + encodeURIComponent(status)
            + '&date_from=' + encodeURIComponent(dfVal || '')
            + '&date_to=' + encodeURIComponent(dtVal || '')
            + jtParam;
        var res = await fetch('/admin/api/gl/pending-list.php?' + q, { credentials: 'same-origin' });
        var j = await res.json();
        if (!j.success) {
            lastFilterInfo = null;
            window.alert(j.message || 'تعذر التحميل');
            renderRows([]);
            resetLedgerPanel();
            return;
        }
        lastFilterInfo = j.filter || null;
        renderRows(j.movements || []);
        resetLedgerPanel();
    }

    var btnUn = document.getElementById('gl_post_btn_unposted');
    var btnPo = document.getElementById('gl_post_btn_posted');
    var btnDo = document.getElementById('gl_post_btn_do_post');
    var btnUndo = document.getElementById('gl_post_btn_undo');
    if (btnUn) {
        btnUn.addEventListener('click', function () { loadMovements('pending'); });
    }
    if (btnPo) {
        btnPo.addEventListener('click', function () { loadMovements('posted'); });
    }
    if (btnDo) {
        btnDo.addEventListener('click', async function () {
            if (listMode !== 'pending') {
                window.alert('اعرض «الحركات الغير مرحلة» ثم اختر البنود للترحيل.');
                return;
            }
            var ids = Array.from(document.querySelectorAll('.gl-pick:checked'))
                .map(function (c) { return parseInt(c.value, 10); })
                .filter(function (x) { return x > 0; });
            if (!ids.length) {
                window.alert('حدد حركة واحدة على الأقل.');
                return;
            }
            try {
                var r = await postJSON('/admin/api/gl/pending-post.php', { ids: ids });
                var hasP = r.posted && r.posted.length;
                if (r.success && hasP) {
                    window.alert(r.message || 'تم');
                    if (!orangeAdminOfferSuggestAfterWarnings(r)) {
                        loadMovements('pending');
                    }
                } else if (orangeAdminOfferSuggestOnFailure(r, 'تعذر الترحيل')) {
                    /* اقتراح شاشة الإعدادات أو السنوات المالية */
                } else {
                    window.alert(r.message || (r.success ? 'تم' : 'فشل'));
                    if (r.success && hasP) {
                        loadMovements('pending');
                    }
                }
            } catch (e) {
                window.alert('خطأ في الترحيل');
            }
        });
    }
    if (btnUndo) {
        btnUndo.addEventListener('click', async function () {
            if (listMode !== 'posted') {
                window.alert('اعرض «الحركات المرحلة» ثم حدد البنود المراد فك ترحيلها.');
                return;
            }
            var ids = Array.from(document.querySelectorAll('.gl-unpost:checked'))
                .map(function (c) { return parseInt(c.value, 10); })
                .filter(function (x) { return x > 0; });
            if (!ids.length) {
                window.alert('حدد حركة واحدة على الأقل.');
                return;
            }
            if (!window.confirm('فك الترحيل يحذف سند القيد المرتبط ويعيد الحركة إلى قائمة غير المرحّلة. المتابعة؟')) {
                return;
            }
            try {
                var r = await postJSON('/admin/api/gl/pending-unpost.php', { ids: ids });
                var hasUn = r.unposted && r.unposted.length;
                if (r.success && hasUn) {
                    window.alert(r.message || 'تم');
                    if (!orangeAdminOfferSuggestAfterWarnings(r)) {
                        loadMovements('posted');
                    }
                } else if (orangeAdminOfferSuggestOnFailure(r, 'تعذر فك الترحيل')) {
                    /* عرض اقتراح شاشة الإدارة */
                } else {
                    window.alert(r.message || (r.success ? 'تم' : 'فشل'));
                    if (r.success && hasUn) {
                        loadMovements('posted');
                    }
                }
            } catch (e) {
                window.alert(e.message || String(e));
            }
        });
    }

    var dfEl = document.getElementById('gl_post_date_from');
    var dtEl = document.getElementById('gl_post_date_to');
    if (dfEl) {
        dfEl.addEventListener('change', scheduleReloadMovements);
    }
    if (dtEl) {
        dtEl.addEventListener('change', scheduleReloadMovements);
    }
})();
</script>
