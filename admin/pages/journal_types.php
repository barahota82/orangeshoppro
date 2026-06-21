<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/journal_types.php';
require_once __DIR__ . '/../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
orange_catalog_ensure_journal_types_country_scope($pdo);

/** @var array<string, mixed>|null $admin — من admin/index.php عند التضمين */
$jtAdmin = (isset($admin) && is_array($admin)) ? $admin : orange_admin_active_record($pdo);
$jtCanCopyBetweenCountries = $jtAdmin !== null && orange_admin_has_full_access($jtAdmin);
$jtCountries = $jtCanCopyBetweenCountries ? orange_countries_admin_list($pdo) : [];

$jtCountryId = orange_admin_context_country_id($pdo);
$jtCountryRow = orange_country_row_by_id($pdo, $jtCountryId, false);
$jtCountryLabel = trim((string) ($jtCountryRow['name_ar'] ?? ''));
if ($jtCountryLabel === '' && $jtCountryRow !== null) {
    $jtCountryLabel = trim((string) ($jtCountryRow['name_en'] ?? ''));
}
if ($jtCountryLabel === '') {
    $jtCountryLabel = orange_countries_display_code(orange_admin_context_country_code($pdo));
}
$jtScoped = orange_journal_types_has_country_column($pdo);

$types = orange_journal_types_list($pdo, $jtCountryId);
$jtShowEmptyHint = ($types === []);
$jtCanAutoSeed = orange_journal_types_should_auto_seed($pdo, $jtCountryId);
?>
<div class="fy-years-page" dir="rtl">
    <div class="page-title">
        <h1 class="fy-years-page__title">أنواع اليوميات</h1>
        <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars($jtCountryLabel, ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <?php if (!$jtScoped): ?>
    <p class="card-hint" style="margin:0.35rem 0 0.75rem;color:#92400e;">
        تنبيه: عمود <code dir="ltr">country_id</code> غير مفعّل بعد على جدول أنواع اليوميات — افتح أي صفحة أدmin لإكمال الترحيل، أو تحقق من سجل PHP.
    </p>
    <?php endif; ?>

    <?php if ($jtCanCopyBetweenCountries): ?>
    <div class="card" style="margin:0 0 1rem;" id="jt_copy_card">
        <h3 class="card-title">نسخ أنواع اليوميات إلى دولة أخرى</h3>
        <?php if (!$jtScoped): ?>
        <p class="card-hint" style="margin:0;color:#92400e;">
            النسخ بين الدول يتطلّب تفعيل عمود <code dir="ltr">country_id</code> على جدول أنواع اليوميات — أعد تحميل الصفحة بعد اكتمال الترحيل.
        </p>
        <?php else: ?>
        <p class="card-hint" style="margin:0 0 0.75rem;">
            المصدر: <strong><?php echo htmlspecialchars($jtCountryLabel, ENT_QUOTES, 'UTF-8'); ?></strong> —
            حدّد الصفوف بالمربعات في الجدول أدناه ثم اختر الدولة الهدف. إن وُجد نفس الكود في الهدف يُحدَّث الاسم فقط.
        </p>
        <div class="form-grid" style="grid-template-columns:minmax(180px,1fr) auto;align-items:end;gap:12px;max-width:640px;">
            <div>
                <label for="jt_copy_target">الدولة الهدف</label>
                <select id="jt_copy_target" class="admin-inp">
                    <option value="">— اختر الدولة —</option>
                    <?php foreach ($jtCountries as $c):
                        $cid = (int) ($c['id'] ?? 0);
                        if ($cid <= 0 || $cid === $jtCountryId) {
                            continue;
                        }
                        $lbl = trim((string) ($c['name_ar'] ?? ''));
                        if ($lbl === '') {
                            $lbl = trim((string) ($c['name_en'] ?? ''));
                        }
                        if ($lbl === '') {
                            $lbl = orange_countries_display_code((string) ($c['code'] ?? ''));
                        }
                        ?>
                    <option value="<?php echo $cid; ?>"><?php echo htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" id="jt_copy_btn">نسخ المحدّد</button>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card fy-years-card fy-print-area">
        <?php if ($jtShowEmptyHint): ?>
            <p class="card-hint" style="margin:0 0 0.75rem;">
                <?php if ($jtCanAutoSeed): ?>
                لا توجد صفوف في القاعدة بعد — أضف نوعاً واضغط <strong>حفظ</strong>،
                أو أعد تحميل الصفحة لإعادة محاولة استيراد الأنواع المرجعية للكويت.
                <?php else: ?>
                لا توجد أنواع يوميات لهذه الدولة — هذا متوقَّع قبل «إنشاء كامل» من شاشة <strong>الدول</strong>.
                يمكنك أيضاً إضافة أنواع يدوياً هنا ثم <strong>حفظ</strong>.
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <div class="table-wrap fy-years-table-wrap">
            <table class="fy-years-table">
                <thead>
                    <tr>
                        <?php if ($jtCanCopyBetweenCountries): ?>
                        <th class="fy-col-check" style="width:2.5rem;text-align:center;" title="تحديد للنسخ">
                            <input type="checkbox" id="jt_copy_all" aria-label="تحديد الكل">
                        </th>
                        <?php endif; ?>
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
                        <?php if ($jtCanCopyBetweenCountries): ?>
                        <td class="fy-col-check" style="text-align:center;">
                            <?php if ($id > 0): ?>
                            <input type="checkbox" class="jt-copy-chk" value="<?php echo $id; ?>" aria-label="تحديد للنسخ">
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
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

        <div class="fy-actions">
            <button type="button" class="btn-secondary" id="jt_btn_add">إضافة</button>
            <button type="button" id="jt_btn_save">حفظ</button>
            <button type="button" class="btn-secondary" id="jt_btn_print">طباعة</button>
        </div>
    </div>
</div>

<script>
(function () {
    var jtCanAutoSeed = <?php echo $jtCanAutoSeed ? 'true' : 'false'; ?>;
    var jtIsSuper = <?php echo $jtCanCopyBetweenCountries ? 'true' : 'false'; ?>;
    function jtCopyColHtml() {
        return jtIsSuper ? '<td class="fy-col-check" style="text-align:center;"></td>' : '';
    }
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

        /** صف مساعد في المتصفح فقط (data-id=0) حتى يضغط المستخدم «حفظ» فيُنشئ السيرفر السجل الحقيقي. */
        function jtAddDefaultOpeningRow() {
            var tr = document.createElement('tr');
            tr.setAttribute('data-jt-row', '');
            tr.setAttribute('data-id', '0');
            tr.innerHTML =
                jtCopyColHtml() +
                '<td class="fy-col-num"><span class="jt-serial"></span></td>' +
                '<td class="fy-col-jt-code"><input type="text" class="jt-inp-code" dir="ltr" maxlength="32" autocomplete="off" value="OBV" aria-label="ترميز الكود"></td>' +
                '<td class="fy-col-jt-name-ar"><input type="text" class="jt-inp-name-ar" maxlength="255" value="سند رصيد افتتاحي" aria-label="الاسم عربي"></td>' +
                '<td class="fy-col-jt-name-en"><input type="text" class="jt-inp-name-en" dir="ltr" maxlength="255" value="Opening balance voucher" aria-label="الاسم إنجليزي"></td>' +
                '<td class="fy-col-del fy-col-center"><button type="button" class="jt-btn-del fy-btn-del btn-secondary" title="حذف">حذف</button></td>';
            tbody.appendChild(tr);
        }

        function renumberRows() {
            var rows = tbody.querySelectorAll('tr[data-jt-row]');
            if (rows.length === 0 && jtCanAutoSeed) {
                jtAddDefaultOpeningRow();
                rows = tbody.querySelectorAll('tr[data-jt-row]');
            }
            for (var i = 0; i < rows.length; i++) {
                var sp = rows[i].querySelector('.jt-serial');
                if (sp) {
                    sp.textContent = String(i + 1);
                }
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
            var btn = orangeAdminClosest(ev, '.jt-btn-del');
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
                jtCopyColHtml() +
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

        if (jtIsSuper) {
            var copyAll = document.getElementById('jt_copy_all');
            var copyBtn = document.getElementById('jt_copy_btn');
            var copyTarget = document.getElementById('jt_copy_target');
            if (copyAll) {
                copyAll.addEventListener('change', function () {
                    tbody.querySelectorAll('.jt-copy-chk').forEach(function (cb) {
                        cb.checked = copyAll.checked;
                    });
                });
            }
            if (copyBtn && copyTarget) {
                copyBtn.addEventListener('click', function () {
                    var targetId = parseInt(copyTarget.value, 10) || 0;
                    if (targetId <= 0) {
                        alert('اختر الدولة الهدف');
                        return;
                    }
                    var ids = [];
                    tbody.querySelectorAll('.jt-copy-chk:checked').forEach(function (cb) {
                        var v = parseInt(cb.value, 10) || 0;
                        if (v > 0) { ids.push(v); }
                    });
                    if (ids.length === 0) {
                        alert('حدّد نوعاً واحداً على الأقل');
                        return;
                    }
                    if (!confirm('نسخ ' + ids.length + ' نوع يومية إلى الدولة المختارة؟')) {
                        return;
                    }
                    postJSON('/admin/api/country-copy/journal-types.php', {
                        target_country_id: targetId,
                        journal_type_ids: ids
                    }).then(function (r) {
                        alert(r.message || (r.success ? 'تم' : 'فشل'));
                    }).catch(function (e) { alert(e.message || String(e)); });
                });
            }
        }

        renumberRows();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initJournalTypesTable);
    } else {
        initJournalTypesTable();
    }
})();
</script>
