<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/journal_types.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$types = orange_journal_types_list($pdo);
?>
<div class="fy-years-page" dir="rtl">
    <h1 class="fy-years-page__title">أنواع اليوميات</h1>

    <div class="card fy-years-card fy-print-area">
        <div class="table-wrap fy-years-table-wrap">
            <table class="fy-years-table">
                <thead>
                    <tr>
                        <th class="fy-col-num">مسلسل</th>
                        <th class="fy-col-jt-code">ترميز الكود</th>
                        <th class="fy-col-jt-name-ar">الاسم عربي</th>
                        <th class="fy-col-jt-name-en">الاسم إنجليزي</th>
                        <th class="fy-col-del" aria-label="حذف"></th>
                    </tr>
                </thead>
                <tbody id="jt_tbody">
                    <?php
                    $serial = 0;
                    foreach ($types as $t):
                        ++$serial;
                        $id = (int) $t['id'];
                        $code = (string) ($t['code'] ?? '');
                        $nameAr = (string) ($t['name_ar'] ?? '');
                        $nameEn = (string) ($t['name_en'] ?? '');
                        ?>
                    <tr data-jt-row data-id="<?php echo $id; ?>">
                        <td class="fy-col-num"><span class="jt-serial"><?php echo $serial; ?></span></td>
                        <td class="fy-col-jt-code">
                            <input type="text" class="jt-inp-code" dir="ltr" maxlength="32" autocomplete="off" value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" aria-label="ترميز الكود">
                        </td>
                        <td class="fy-col-jt-name-ar">
                            <input type="text" class="jt-inp-name-ar" maxlength="255" value="<?php echo htmlspecialchars($nameAr, ENT_QUOTES, 'UTF-8'); ?>" aria-label="الاسم عربي">
                        </td>
                        <td class="fy-col-jt-name-en">
                            <input type="text" class="jt-inp-name-en" dir="ltr" maxlength="255" value="<?php echo htmlspecialchars($nameEn, ENT_QUOTES, 'UTF-8'); ?>" aria-label="الاسم إنجليزي">
                        </td>
                        <td class="fy-col-del fy-col-center">
                            <button type="button" class="jt-btn-del fy-btn-del btn-secondary" title="حذف">حذف</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted fy-empty-hint" id="jt_empty_hint"<?php echo $types !== [] ? ' hidden' : ''; ?>>لا توجد أنواع — اضغط «إضافة» ثم «حفظ».</p>

        <div class="fy-actions">
            <button type="button" class="btn-secondary" id="jt_btn_add">إضافة</button>
            <button type="button" id="jt_btn_save">حفظ</button>
            <button type="button" class="btn-secondary" id="jt_btn_print">طباعة</button>
        </div>
    </div>
</div>

<script>
(function () {
    function jtNormalizeCode(s) {
        s = String(s || '').trim().toUpperCase().replace(/\s+/g, '');
        return s.replace(/[^A-Z0-9]/g, '');
    }
    function initJournalTypesTable() {
        var tbody = document.getElementById('jt_tbody');
        var btnAdd = document.getElementById('jt_btn_add');
        var btnSave = document.getElementById('jt_btn_save');
        var btnPrint = document.getElementById('jt_btn_print');
        if (!tbody || !btnAdd || !btnSave || !btnPrint) {
            return;
        }

        function renumberRows() {
            var rows = tbody.querySelectorAll('tr[data-jt-row]');
            for (var i = 0; i < rows.length; i++) {
                var sp = rows[i].querySelector('.jt-serial');
                if (sp) {
                    sp.textContent = String(i + 1);
                }
            }
            var hint = document.getElementById('jt_empty_hint');
            if (hint) {
                hint.hidden = rows.length > 0;
            }
        }

        function collectRows() {
            var out = [];
            tbody.querySelectorAll('tr[data-jt-row]').forEach(function (tr) {
                var id = parseInt(tr.getAttribute('data-id'), 10) || 0;
                var c = tr.querySelector('.jt-inp-code');
                var ar = tr.querySelector('.jt-inp-name-ar');
                var en = tr.querySelector('.jt-inp-name-en');
                out.push({
                    id: id,
                    code: c ? jtNormalizeCode(c.value) : '',
                    name_ar: ar ? String(ar.value || '').trim() : '',
                    name_en: en ? String(en.value || '').trim() : ''
                });
            });
            return out;
        }

        tbody.addEventListener('focusout', function (ev) {
            var t = ev.target;
            if (t.classList && t.classList.contains('jt-inp-code')) {
                var n = jtNormalizeCode(t.value);
                if (n !== t.value) {
                    t.value = n;
                }
            }
        });

        tbody.addEventListener('click', function (ev) {
            var btn = ev.target.closest('.jt-btn-del');
            if (!btn) {
                return;
            }
            var tr = btn.closest('tr[data-jt-row]');
            if (!tr) {
                return;
            }
            var id = parseInt(tr.getAttribute('data-id'), 10) || 0;
            function removeLocal() {
                tr.remove();
                renumberRows();
            }
            if (id <= 0) {
                removeLocal();
                return;
            }
            if (!confirm('حذف هذا النوع من الجدول؟')) {
                return;
            }
            postJSON('/admin/api/journal_types/save.php', { action: 'delete', id: id })
                .then(function (r) {
                    alert(r.message || (r.success ? 'تم' : 'فشل'));
                    if (r.success) {
                        removeLocal();
                    }
                })
                .catch(function (e) { alert(e.message || String(e)); });
        });

        btnAdd.addEventListener('click', function () {
            var tr = document.createElement('tr');
            tr.setAttribute('data-jt-row', '');
            tr.setAttribute('data-id', '0');
            tr.innerHTML =
                '<td class="fy-col-num"><span class="jt-serial"></span></td>' +
                '<td class="fy-col-jt-code"><input type="text" class="jt-inp-code" dir="ltr" maxlength="32" autocomplete="off" value="" aria-label="ترميز الكود"></td>' +
                '<td class="fy-col-jt-name-ar"><input type="text" class="jt-inp-name-ar" maxlength="255" value="" aria-label="الاسم عربي"></td>' +
                '<td class="fy-col-jt-name-en"><input type="text" class="jt-inp-name-en" dir="ltr" maxlength="255" value="" aria-label="الاسم إنجليزي"></td>' +
                '<td class="fy-col-del fy-col-center"><button type="button" class="jt-btn-del fy-btn-del btn-secondary" title="حذف">حذف</button></td>';
            tbody.appendChild(tr);
            renumberRows();
            var inp = tr.querySelector('.jt-inp-code');
            if (inp) {
                inp.focus();
            }
        });

        btnSave.addEventListener('click', function () {
            var rows = collectRows();
            if (rows.length === 0) {
                alert('لا توجد صفوف للحفظ');
                return;
            }
            for (var i = 0; i < rows.length; i++) {
                if (!rows[i].code || rows[i].code.length < 2) {
                    alert('أكمل ترميز الكود (حرفان على الأقل) في الصف ' + (i + 1));
                    return;
                }
                if (!rows[i].name_ar || !rows[i].name_en) {
                    alert('أكمل الاسم العربي والإنجليزي في الصف ' + (i + 1));
                    return;
                }
            }
            postJSON('/admin/api/journal_types/save.php', { action: 'save_rows', rows: rows })
                .then(function (r) {
                    alert(r.message || (r.success ? 'تم' : 'فشل'));
                    if (r.success) {
                        location.reload();
                    }
                })
                .catch(function (e) { alert(e.message || String(e)); });
        });

        btnPrint.addEventListener('click', function () {
            window.print();
        });

        renumberRows();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initJournalTypesTable);
    } else {
        initJournalTypesTable();
    }
})();
</script>
