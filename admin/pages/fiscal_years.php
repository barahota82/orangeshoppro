<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/upload_paths.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$fyCountryId = orange_admin_settings_effective_country_id($pdo);
$fyCountryRow = orange_country_row_by_id($pdo, $fyCountryId, false);
$fyCountryLabel = trim((string) ($fyCountryRow['name_ar'] ?? ''));
if ($fyCountryLabel === '' && $fyCountryRow !== null) {
    $fyCountryLabel = trim((string) ($fyCountryRow['name_en'] ?? ''));
}
if ($fyCountryLabel === '') {
    $fyCountryLabel = orange_countries_display_code(orange_admin_context_country_code($pdo));
}
$fyScoped = orange_fiscal_years_has_country_column($pdo);

$years = orange_fiscal_years_list($pdo);
usort($years, static function ($a, $b) {
    return strcmp((string) ($a['start_date'] ?? ''), (string) ($b['start_date'] ?? ''));
});
$maxEndY = (int) date('Y');
foreach ($years as $y) {
    $e = (string) ($y['end_date'] ?? '');
    if (preg_match('/^(\d{4})-\d{2}-\d{2}$/', $e, $m)) {
        $maxEndY = max($maxEndY, (int) $m[1]);
    }
}
$fySuggestYear = $maxEndY + 1;

$fyGlIncomeId = orange_gl_account_id_optional($pdo, 'income_summary') ?? 0;
$fyGlRetainedId = orange_gl_account_id_optional($pdo, 'retained_earnings') ?? 0;
$fyCloseGlLinked = $fyGlIncomeId > 0 && $fyGlRetainedId > 0;
$fyGlSettingsUrl = storefront_public_path('/admin/index.php?page=gl_account_settings');

$fyAccountBrief = static function (PDO $pdoConn, int $accId): array {
    if ($accId <= 0) {
        return ['code' => '', 'name' => ''];
    }
    $st = $pdoConn->prepare('SELECT code, name FROM accounts WHERE id = ? LIMIT 1');
    $st->execute([$accId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (! $r) {
        return ['code' => '', 'name' => ''];
    }

    return ['code' => (string) ($r['code'] ?? ''), 'name' => (string) ($r['name'] ?? '')];
};
$fyGlIncomeBrief = $fyAccountBrief($pdo, $fyGlIncomeId);
$fyGlRetainedBrief = $fyAccountBrief($pdo, $fyGlRetainedId);
?>
<div class="admin-fy-shell fy-years-page" dir="rtl">
    <div class="page-title">
        <h1 class="fy-years-page__title">السنوات المالية</h1>
        <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars($fyCountryLabel, ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <?php if (!$fyScoped): ?>
    <p class="card-hint" style="margin:0.35rem 0 0.75rem;color:#92400e;">
        تنبيه: عمود <code dir="ltr">country_id</code> غير مفعّل بعد على جدول السنوات المالية.
    </p>
    <?php endif; ?>

    <div class="card admin-fy-card fy-years-card fy-print-area">
        <div class="table-wrap fy-years-table-wrap admin-fy-table-wrap">
            <table class="fy-years-table">
                <thead>
                    <tr>
                        <th class="fy-col-num">مسلسل</th>
                        <th class="fy-col-year">السنة</th>
                        <th class="fy-col-date">بداية السنة</th>
                        <th class="fy-col-date">نهاية السنة</th>
                        <th class="fy-col-closed">مغلقة</th>
                        <th class="fy-col-acct-close">إقفال محاسبي</th>
                        <th class="fy-col-del" aria-label="حذف"></th>
                    </tr>
                </thead>
                <tbody id="fy_tbody">
                    <?php
                    $serial = 0;
                    foreach ($years as $y):
                        ++$serial;
                        $id = (int) $y['id'];
                        $closed = (int) ($y['is_closed'] ?? 0) === 1;
                        $sd = (string) ($y['start_date'] ?? '');
                        $ed = (string) ($y['end_date'] ?? '');
                        $yr = preg_match('/^(\d{4})-\d{2}-\d{2}$/', $sd, $mm) ? (int) $mm[1] : '';
                        $sdDisp = orange_format_date_dmY($sd);
                        $edDisp = orange_format_date_dmY($ed);
                        ?>
                    <tr data-fy-row data-id="<?php echo $id; ?>">
                        <td class="fy-col-num"><span class="fy-serial"><?php echo $serial; ?></span></td>
                        <td class="fy-col-year">
                            <input type="text" inputmode="numeric" class="fy-inp-year" maxlength="4" value="<?php echo $yr !== '' ? $yr : ''; ?>" aria-label="السنة">
                        </td>
                        <td class="fy-col-date">
                            <input type="text" class="fy-inp-start fy-inp-dmy orange-inp-dmy" dir="ltr" autocomplete="off" placeholder="يوم/شهر/سنة" maxlength="10" value="<?php echo htmlspecialchars($sdDisp, ENT_QUOTES, 'UTF-8'); ?>">
                        </td>
                        <td class="fy-col-date">
                            <input type="text" class="fy-inp-end fy-inp-dmy orange-inp-dmy" dir="ltr" autocomplete="off" placeholder="يوم/شهر/سنة" maxlength="10" value="<?php echo htmlspecialchars($edDisp, ENT_QUOTES, 'UTF-8'); ?>">
                        </td>
                        <td class="fy-col-closed fy-col-center">
                            <input type="checkbox" class="fy-chk-closed"<?php echo $closed ? ' checked disabled title="سنة مغلقة — لفتحها استخدم فك الإقفال"' : ''; ?>>
                        </td>
                        <td class="fy-col-acct-close fy-col-center">
                            <?php if ($closed): ?>
                                <button type="button" class="fy-btn-reopen btn-secondary" title="void سند YEC وإعادة فتح السنة">فك الإقفال…</button>
                            <?php else: ?>
                                <button type="button" class="fy-btn-acct-close btn-secondary" title="إغلاق السنة مع خيارات الإقفال">إقفال…</button>
                            <?php endif; ?>
                        </td>
                        <td class="fy-col-del fy-col-center">
                            <button type="button" class="fy-btn-del btn-secondary" title="حذف">حذف</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted fy-empty-hint" id="fy_empty_hint"<?php echo $years !== [] ? ' hidden' : ''; ?>>لا توجد سنوات — اضغط «إضافة» ثم «حفظ».</p>

        <div class="fy-actions">
            <button type="button" class="btn-secondary" id="fy_btn_add">إضافة</button>
            <button type="button" id="fy_btn_save">حفظ</button>
            <button type="button" class="btn-secondary" id="fy_btn_print">طباعة</button>
        </div>
    </div>
</div>

<div class="gl-pick-modal fy-close-main-modal" id="fy_close_main_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="fy_close_main_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="fy_close_main_title">
        <h3 id="fy_close_main_title" class="gl-pick-modal__title">إقفال سنة مالية</h3>
        <p class="muted" style="margin:0 0 12px;font-size:0.9rem;line-height:1.45;">
            عند تفعيل الإقفال المحاسبي ووجود إيرادات/مصروفات مصنّفة: يُنشأ <strong>سند YEC واحد</strong> (PL → RE → LR) وتُوجَّه لشاشة <strong>قيود الإقفال السنوية</strong> للمراجعة ثم <strong>حفظ = إقفال</strong>.
            حسابا الوسيط والمحتجزة يُؤخذان من <strong>حسابات القيود التلقائية</strong> إن وُجد ربطهما هناك.
        </p>
        <label class="fy-close-check-label" style="display:flex;align-items:center;gap:8px;margin-bottom:14px;cursor:pointer;">
            <input type="checkbox" id="fy_close_do_accounting" checked>
            <span>تنفيذ قيود الإقفال المحاسبي (إيرادات/مصروفات)</span>
        </label>
        <div id="fy_close_acc_linked" class="fy-close-gl-msg fy-close-gl-msg--ok" hidden>
            <p class="fy-close-gl-msg__title">الحسابات مربوطة من «حسابات القيود التلقائية» — لا حاجة لاختيارها هنا.</p>
            <ul class="fy-close-gl-msg__list">
                <li><span class="muted">أرباح / خسائر السنة الحالية:</span>
                    <span dir="ltr"><?php echo htmlspecialchars(trim(($fyGlIncomeBrief['code'] !== '' ? $fyGlIncomeBrief['code'] . ' — ' : '') . $fyGlIncomeBrief['name']), ENT_QUOTES, 'UTF-8'); ?></span></li>
                <li><span class="muted">الأرباح المحتجزة:</span>
                    <span dir="ltr"><?php echo htmlspecialchars(trim(($fyGlRetainedBrief['code'] !== '' ? $fyGlRetainedBrief['code'] . ' — ' : '') . $fyGlRetainedBrief['name']), ENT_QUOTES, 'UTF-8'); ?></span></li>
            </ul>
            <p class="muted fy-close-gl-msg__hint" style="margin:0;font-size:0.85rem;">للاحتياطي القانوني والنسبة: نفس الشاشة — صف الاحتياطي القانوني.</p>
        </div>
        <div id="fy_close_acc_need_link" class="fy-close-gl-msg fy-close-gl-msg--warn" hidden>
            <p class="fy-close-gl-msg__title">لا يمكن تنفيذ الإقفال المحاسبي دون ربط الحسابات.</p>
            <p class="fy-close-gl-msg__body">اربط <strong>حساب ملخص الدخل (أرباح/خسائر السنة الحالية)</strong> و<strong>حساب الأرباح المحتجزة</strong> من شاشة
                <a href="<?php echo htmlspecialchars($fyGlSettingsUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">حسابات القيود التلقائية</a>
                ثم حدّث صفحة «السنوات المالية» إن لزم، وأعد فتح «إقفال…».</p>
        </div>
        <div class="fy-close-main-actions" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;">
            <button type="button" id="fy_close_main_submit">تأكيد إغلاق السنة</button>
            <button type="button" class="btn-secondary" id="fy_close_main_cancel">إلغاء</button>
        </div>
    </div>
</div>

<script>
(function () {
    function fyPad2(n) {
        return n < 10 ? '0' + n : String(n);
    }
    function fyIsoToDisplay(iso) {
        if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) {
            return '';
        }
        var p = iso.split('-');
        return p[2] + '/' + p[1] + '/' + p[0];
    }
    function fyDisplayToIso(s) {
        s = String(s || '').trim().replace(/\s/g, '');
        var m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        if (!m) {
            return '';
        }
        var d = parseInt(m[1], 10);
        var mo = parseInt(m[2], 10);
        var y = parseInt(m[3], 10);
        if (mo < 1 || mo > 12 || d < 1 || d > 31 || y < 1900 || y > 2100) {
            return '';
        }
        var dt = new Date(y, mo - 1, d);
        if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d) {
            return '';
        }
        return y + '-' + fyPad2(mo) + '-' + fyPad2(d);
    }
    function fyNormalizeDmyInput(el) {
        if (!el || !el.classList.contains('fy-inp-dmy')) {
            return;
        }
        var iso = fyDisplayToIso(el.value);
        if (iso) {
            el.value = fyIsoToDisplay(iso);
        }
    }

    function initFiscalYearsTable() {
        var tbody = document.getElementById('fy_tbody');
        var btnAdd = document.getElementById('fy_btn_add');
        var btnSave = document.getElementById('fy_btn_save');
        var btnPrint = document.getElementById('fy_btn_print');
        if (!tbody || !btnAdd || !btnSave || !btnPrint) {
            return;
        }

        var suggestYear = <?php echo (int) $fySuggestYear; ?>;

        function syncDatesFromYear(tr, y) {
            if (!y || y < 1900 || y > 2100) {
                return;
            }
            var s = tr.querySelector('.fy-inp-start');
            var e = tr.querySelector('.fy-inp-end');
            var isoS = y + '-01-01';
            var isoE = y + '-12-31';
            if (s) {
                s.value = fyIsoToDisplay(isoS);
            }
            if (e) {
                e.value = fyIsoToDisplay(isoE);
            }
        }
        function syncYearFromStart(tr) {
            var s = tr.querySelector('.fy-inp-start');
            var yIn = tr.querySelector('.fy-inp-year');
            if (!s || !yIn) {
                return;
            }
            var iso = fyDisplayToIso(s.value);
            if (!iso) {
                return;
            }
            var y = parseInt(iso.slice(0, 4), 10);
            if (!isNaN(y)) {
                yIn.value = y;
            }
        }
        function renumberRows() {
            var rows = tbody.querySelectorAll('tr[data-fy-row]');
            for (var i = 0; i < rows.length; i++) {
                var sp = rows[i].querySelector('.fy-serial');
                if (sp) {
                    sp.textContent = String(i + 1);
                }
            }
            var hint = document.getElementById('fy_empty_hint');
            if (hint) {
                hint.hidden = rows.length > 0;
            }
        }
        function collectRows() {
            var out = [];
            tbody.querySelectorAll('tr[data-fy-row]').forEach(function (tr) {
                var id = parseInt(tr.getAttribute('data-id'), 10) || 0;
                var startEl = tr.querySelector('.fy-inp-start');
                var endEl = tr.querySelector('.fy-inp-end');
                var start = fyDisplayToIso(startEl ? startEl.value : '');
                var end = fyDisplayToIso(endEl ? endEl.value : '');
                var closedEl = tr.querySelector('.fy-chk-closed');
                var isClosed = closedEl && closedEl.checked;
                out.push({
                    id: id,
                    start_date: start,
                    end_date: end,
                    is_closed: !!isClosed
                });
            });
            return out;
        }

        tbody.addEventListener('change', function (ev) {
            var t = ev.target;
            var tr = t.closest('tr[data-fy-row]');
            if (!tr) {
                return;
            }
            if (t.classList.contains('fy-inp-year')) {
                var y = parseInt(t.value, 10);
                if (!isNaN(y)) {
                    syncDatesFromYear(tr, y);
                }
            }
            if (t.classList.contains('fy-inp-start')) {
                fyNormalizeDmyInput(t);
                syncYearFromStart(tr);
            }
            if (t.classList.contains('fy-inp-end')) {
                fyNormalizeDmyInput(t);
            }
        });

        tbody.addEventListener('focusout', function (ev) {
            var t = ev.target;
            if (t.classList && t.classList.contains('fy-inp-dmy')) {
                fyNormalizeDmyInput(t);
            }
        });

        var fyCloseGlLinked = <?php echo $fyCloseGlLinked ? 'true' : 'false'; ?>;
        var fyClosePendingId = 0;
        var mainModal = document.getElementById('fy_close_main_modal');
        var mainBackdrop = document.getElementById('fy_close_main_backdrop');
        var mainCancel = document.getElementById('fy_close_main_cancel');
        var mainSubmit = document.getElementById('fy_close_main_submit');
        var chkDoAccounting = document.getElementById('fy_close_do_accounting');
        var elLinked = document.getElementById('fy_close_acc_linked');
        var elNeedLink = document.getElementById('fy_close_acc_need_link');

        function fyCloseSyncGlPanels() {
            if (!elLinked || !elNeedLink || !chkDoAccounting) {
                return;
            }
            var doAcct = !!chkDoAccounting.checked;
            if (!doAcct) {
                elLinked.hidden = true;
                elNeedLink.hidden = true;
                return;
            }
            if (fyCloseGlLinked) {
                elLinked.hidden = false;
                elNeedLink.hidden = true;
            } else {
                elLinked.hidden = true;
                elNeedLink.hidden = false;
            }
        }
        function fyCloseMainOpen(fyId) {
            fyClosePendingId = fyId;
            if (chkDoAccounting) {
                chkDoAccounting.checked = true;
            }
            fyCloseSyncGlPanels();
            if (mainModal) {
                mainModal.hidden = false;
                mainModal.setAttribute('aria-hidden', 'false');
            }
        }
        function fyCloseMainClose() {
            fyClosePendingId = 0;
            if (mainModal) {
                mainModal.hidden = true;
                mainModal.setAttribute('aria-hidden', 'true');
            }
        }

        if (chkDoAccounting) {
            chkDoAccounting.addEventListener('change', fyCloseSyncGlPanels);
        }
        if (mainBackdrop) {
            mainBackdrop.addEventListener('click', fyCloseMainClose);
        }
        if (mainCancel) {
            mainCancel.addEventListener('click', fyCloseMainClose);
        }
        if (mainSubmit) {
            mainSubmit.addEventListener('click', function () {
                if (fyClosePendingId <= 0) {
                    return;
                }
                var doAcct = chkDoAccounting && chkDoAccounting.checked;
                if (doAcct && !fyCloseGlLinked) {
                    alert('اربط حسابي ملخص الدخل والأرباح المحتجزة من شاشة «حسابات القيود التلقائية» ثم أغلق النافذة وأعد فتح «إقفال…».');
                    return;
                }
                var payload = { action: 'close', id: fyClosePendingId, accounting_close: !!doAcct };
                postJSON('/admin/api/fiscal_years/save.php', payload)
                    .then(function (r) {
                        alert(r.message || (r.success ? 'تم' : 'فشل'));
                        if (r.success) {
                            fyCloseMainClose();
                            if (r.redirect) {
                                window.location.href = r.redirect;
                                return;
                            }
                            location.reload();
                        }
                    })
                    .catch(function (e) { alert(e.message || String(e)); });
            });
        }

        tbody.addEventListener('click', function (ev) {
            var reopenBtn = orangeAdminClosest(ev, '.fy-btn-reopen');
            if (reopenBtn) {
                var trR = reopenBtn.closest('tr[data-fy-row]');
                if (!trR) {
                    return;
                }
                var rid = parseInt(trR.getAttribute('data-id'), 10) || 0;
                if (rid <= 0) {
                    return;
                }
                if (!confirm('فك إقفال هذه السنة؟\n\nسيتم إلغاء (void) سند YEC وإعادة فتح السنة للتصحيح من شاشة «قيود الإقفال السنوية».\nإن وُجدت أرصدة أول مدة للسنة التالية مُرحَّلة، راجعها بعد التصحيح.')) {
                    return;
                }
                postJSON('/admin/api/fiscal_years/save.php', { action: 'reopen', id: rid })
                    .then(function (r) {
                        alert(r.message || (r.success ? 'تم' : 'فشل'));
                        if (r.success) {
                            if (r.redirect) {
                                window.location.href = r.redirect;
                                return;
                            }
                            location.reload();
                        }
                    })
                    .catch(function (e) { alert(e.message || String(e)); });
                return;
            }
            var acBtn = orangeAdminClosest(ev, '.fy-btn-acct-close');
            if (acBtn) {
                var tr = acBtn.closest('tr[data-fy-row]');
                if (!tr) {
                    return;
                }
                var fid = parseInt(tr.getAttribute('data-id'), 10) || 0;
                if (fid <= 0) {
                    alert('احفظ السنة أولاً ثم نفّذ الإقفال المحاسبي.');
                    return;
                }
                fyCloseMainOpen(fid);
                return;
            }
            var btn = orangeAdminClosest(ev, '.fy-btn-del');
            if (!btn) {
                return;
            }
            var tr = btn.closest('tr[data-fy-row]');
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
            if (!confirm('حذف هذه السنة من الجدول؟')) {
                return;
            }
            postJSON('/admin/api/fiscal_years/save.php', { action: 'delete', id: id })
                .then(function (r) {
                    alert(r.message || (r.success ? 'تم' : 'فشل'));
                    if (r.success) {
                        removeLocal();
                    }
                })
                .catch(function (e) { alert(e.message || String(e)); });
        });

        btnAdd.addEventListener('click', function () {
            var y = suggestYear;
            suggestYear += 1;
            var tr = document.createElement('tr');
            tr.setAttribute('data-fy-row', '');
            tr.setAttribute('data-id', '0');
            var dStart = fyIsoToDisplay(y + '-01-01');
            var dEnd = fyIsoToDisplay(y + '-12-31');
            tr.innerHTML =
                '<td class="fy-col-num"><span class="fy-serial"></span></td>' +
                '<td class="fy-col-year"><input type="text" inputmode="numeric" class="fy-inp-year" maxlength="4" value="' + y + '" aria-label="السنة"></td>' +
                '<td class="fy-col-date"><input type="text" class="fy-inp-start fy-inp-dmy orange-inp-dmy" dir="ltr" autocomplete="off" placeholder="يوم/شهر/سنة" maxlength="10" value="' + dStart + '"></td>' +
                '<td class="fy-col-date"><input type="text" class="fy-inp-end fy-inp-dmy orange-inp-dmy" dir="ltr" autocomplete="off" placeholder="يوم/شهر/سنة" maxlength="10" value="' + dEnd + '"></td>' +
                '<td class="fy-col-closed fy-col-center"><input type="checkbox" class="fy-chk-closed"></td>' +
                '<td class="fy-col-acct-close fy-col-center"><button type="button" class="fy-btn-acct-close btn-secondary" title="إغلاق السنة مع خيارات الإقفال">إقفال…</button></td>' +
                '<td class="fy-col-del fy-col-center"><button type="button" class="fy-btn-del btn-secondary" title="حذف">حذف</button></td>';
            tbody.appendChild(tr);
            if (typeof window.orangeInitDmyInputs === 'function') {
                window.orangeInitDmyInputs(tr);
            }
            renumberRows();
        });

        btnSave.addEventListener('click', function () {
            var rows = collectRows();
            if (rows.length === 0) {
                alert('لا توجد صفوف للحفظ');
                return;
            }
            for (var i = 0; i < rows.length; i++) {
                if (!rows[i].start_date || !rows[i].end_date) {
                    alert('أكمل التواريخ بصيغة يوم/شهر/سنة (مثال: 31/12/2026) في الصف ' + (i + 1));
                    return;
                }
                if (!/-01-01$/.test(rows[i].start_date)) {
                    alert('بداية السنة المالية يجب أن تكون 1/1 (أول يناير) في الصف ' + (i + 1));
                    return;
                }
                if (!/-12-31$/.test(rows[i].end_date)) {
                    alert('نهاية السنة المالية يجب أن تكون 31/12 (آخر ديسمبر) في الصف ' + (i + 1));
                    return;
                }
            }
            postJSON('/admin/api/fiscal_years/save.php', { action: 'save_rows', rows: rows })
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
        document.addEventListener('DOMContentLoaded', initFiscalYearsTable);
    } else {
        initFiscalYearsTable();
    }
})();
</script>
