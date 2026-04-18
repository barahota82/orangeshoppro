<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';

/**
 * عرض تاريخ قاعدة Y-m-d كيوم/شهر/سنة في الواجهة.
 */
function orange_fy_display_dmy(?string $ymd): string
{
    if ($ymd !== null && $ymd !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) {
        return $m[3] . '/' . $m[2] . '/' . $m[1];
    }

    return '';
}

$pdo = db();
orange_catalog_ensure_schema($pdo);
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
?>
<div class="fy-years-page" dir="rtl">
    <h1 class="fy-years-page__title">السنوات المالية</h1>

    <div class="card fy-years-card fy-print-area">
        <div class="table-wrap fy-years-table-wrap">
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
                        $sdDisp = orange_fy_display_dmy($sd);
                        $edDisp = orange_fy_display_dmy($ed);
                        ?>
                    <tr data-fy-row data-id="<?php echo $id; ?>">
                        <td class="fy-col-num"><span class="fy-serial"><?php echo $serial; ?></span></td>
                        <td class="fy-col-year">
                            <input type="number" class="fy-inp-year" min="1900" max="2100" step="1" value="<?php echo $yr !== '' ? $yr : ''; ?>" aria-label="السنة">
                        </td>
                        <td class="fy-col-date">
                            <input type="text" class="fy-inp-start fy-inp-dmy" dir="ltr" autocomplete="off" placeholder="يوم/شهر/سنة" maxlength="10" value="<?php echo htmlspecialchars($sdDisp, ENT_QUOTES, 'UTF-8'); ?>">
                        </td>
                        <td class="fy-col-date">
                            <input type="text" class="fy-inp-end fy-inp-dmy" dir="ltr" autocomplete="off" placeholder="يوم/شهر/سنة" maxlength="10" value="<?php echo htmlspecialchars($edDisp, ENT_QUOTES, 'UTF-8'); ?>">
                        </td>
                        <td class="fy-col-closed fy-col-center">
                            <input type="checkbox" class="fy-chk-closed" <?php echo $closed ? ' checked' : ''; ?>>
                        </td>
                        <td class="fy-col-acct-close fy-col-center">
                            <?php if ($closed): ?>
                                <span class="muted">—</span>
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
            عند وجود إيرادات أو مصروفات أو تكلفة مبيعات مصنّفة في الدليل، يُنشأ سند إقفال. حدّد حساب <strong>ملخص الدخل</strong> (وسيط) و<strong>الأرباح المحتجزة</strong>،
            أو اترك الحقلين فارغين إن كانا مربوطين مسبقاً في قاعدة البيانات.
        </p>
        <label class="fy-close-check-label" style="display:flex;align-items:center;gap:8px;margin-bottom:14px;cursor:pointer;">
            <input type="checkbox" id="fy_close_do_accounting" checked>
            <span>تنفيذ قيود الإقفال المحاسبي (إيرادات/مصروفات)</span>
        </label>
        <div class="fy-close-acc-field" data-fy-close-role="income" data-account-id="0">
            <label class="fy-close-acc-label">حساب ملخص الدخل (وسيط)</label>
            <div class="fy-close-acc-row">
                <input type="text" class="fy-close-code" dir="ltr" autocomplete="off" placeholder="كود الحساب" aria-label="كود ملخص الدخل">
                <button type="button" class="fy-close-search btn-secondary" title="بحث" aria-label="بحث ملخص الدخل">🔍</button>
                <input type="text" class="fy-close-name" readonly tabindex="-1" placeholder="اسم الحساب" aria-label="اسم ملخص الدخل">
            </div>
        </div>
        <div class="fy-close-acc-field" data-fy-close-role="retained" data-account-id="0" style="margin-top:12px;">
            <label class="fy-close-acc-label">حساب الأرباح المحتجزة</label>
            <div class="fy-close-acc-row">
                <input type="text" class="fy-close-code" dir="ltr" autocomplete="off" placeholder="كود الحساب" aria-label="كود الأرباح المحتجزة">
                <button type="button" class="fy-close-search btn-secondary" title="بحث" aria-label="بحث الأرباح المحتجزة">🔍</button>
                <input type="text" class="fy-close-name" readonly tabindex="-1" placeholder="اسم الحساب" aria-label="اسم الأرباح المحتجزة">
            </div>
        </div>
        <div class="fy-close-main-actions" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;">
            <button type="button" id="fy_close_main_submit">تأكيد إغلاق السنة</button>
            <button type="button" class="btn-secondary" id="fy_close_main_cancel">إلغاء</button>
        </div>
    </div>
</div>

<div class="gl-pick-modal" id="fy_close_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="fy_close_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="fy_close_pick_title">
        <h3 id="fy_close_pick_title" class="gl-pick-modal__title">اختيار حساب فرعي</h3>
        <input type="search" id="fy_close_pick_q" class="gl-pick-modal__search" placeholder="ابحث بالكود أو الاسم…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="fy_close_pick_list"></ul>
        <button type="button" class="btn-secondary" id="fy_close_pick_close">إغلاق</button>
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

        var fyClosePendingId = 0;
        var fyClosePickRole = null;
        var fyClosePickTimer = null;
        var fyClosePickSeq = 0;
        var mainModal = document.getElementById('fy_close_main_modal');
        var mainBackdrop = document.getElementById('fy_close_main_backdrop');
        var mainCancel = document.getElementById('fy_close_main_cancel');
        var mainSubmit = document.getElementById('fy_close_main_submit');
        var pickModal = document.getElementById('fy_close_pick_modal');
        var pickBackdrop = document.getElementById('fy_close_pick_backdrop');
        var pickClose = document.getElementById('fy_close_pick_close');
        var pickList = document.getElementById('fy_close_pick_list');
        var pickQ = document.getElementById('fy_close_pick_q');
        var chkDoAccounting = document.getElementById('fy_close_do_accounting');

        function fyCloseFillField(role, acc) {
            var wrap = document.querySelector('[data-fy-close-role="' + role + '"]');
            if (!wrap || !acc) {
                return;
            }
            wrap.setAttribute('data-account-id', String(acc.id));
            var c = wrap.querySelector('.fy-close-code');
            var n = wrap.querySelector('.fy-close-name');
            if (c) {
                c.value = acc.code || '';
            }
            if (n) {
                n.value = acc.name || '';
            }
        }
        function fyCloseClearField(role) {
            var wrap = document.querySelector('[data-fy-close-role="' + role + '"]');
            if (!wrap) {
                return;
            }
            wrap.setAttribute('data-account-id', '0');
            var c = wrap.querySelector('.fy-close-code');
            var n = wrap.querySelector('.fy-close-name');
            if (c) {
                c.value = '';
            }
            if (n) {
                n.value = '';
            }
        }
        function fyCloseStripResolvedField(role) {
            var wrap = document.querySelector('[data-fy-close-role="' + role + '"]');
            if (!wrap) {
                return;
            }
            wrap.setAttribute('data-account-id', '0');
            var n = wrap.querySelector('.fy-close-name');
            if (n) {
                n.value = '';
            }
        }
        function fyCloseFieldIncomplete(wrap) {
            if (!wrap) {
                return false;
            }
            var c = wrap.querySelector('.fy-close-code');
            var code = c ? String(c.value || '').trim() : '';
            var id = parseInt(wrap.getAttribute('data-account-id'), 10) || 0;
            return code !== '' && id <= 0;
        }
        function fyCloseMainOpen(fyId) {
            fyClosePendingId = fyId;
            if (chkDoAccounting) {
                chkDoAccounting.checked = true;
            }
            fyCloseClearField('income');
            fyCloseClearField('retained');
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
        function fyClosePickOpen(role) {
            fyClosePickRole = role;
            if (!pickModal || !pickQ || !pickList) {
                return;
            }
            pickModal.hidden = false;
            pickModal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('gl-pick-open');
            pickQ.value = '';
            pickList.innerHTML = '';
            fyClosePickLoad('');
            pickQ.focus();
        }
        function fyClosePickClose() {
            fyClosePickRole = null;
            if (pickModal) {
                pickModal.hidden = true;
                pickModal.setAttribute('aria-hidden', 'true');
            }
            document.body.classList.remove('gl-pick-open');
        }
        function fyClosePickLoad(q) {
            if (!pickList) {
                return;
            }
            var url = '/admin/api/accounts/search-leaves.php?q=' + encodeURIComponent(q || '');
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) {
                        pickList.innerHTML = '<li class="gl-pick-empty">' + (data.message || 'تعذر التحميل') + '</li>';
                        return;
                    }
                    var accs = data.accounts || [];
                    if (accs.length === 0) {
                        pickList.innerHTML = '<li class="gl-pick-empty">لا نتائج</li>';
                        return;
                    }
                    pickList.innerHTML = '';
                    accs.forEach(function (a) {
                        var li = document.createElement('li');
                        li.className = 'gl-pick-item';
                        var code = a.code || '';
                        li.textContent = (code ? code + ' — ' : '') + (a.name || '');
                        li.setAttribute('role', 'button');
                        li.tabIndex = 0;
                        li.addEventListener('click', function () {
                            if (fyClosePickRole) {
                                fyCloseFillField(fyClosePickRole, { id: a.id, code: code, name: a.name || '' });
                            }
                            fyClosePickClose();
                        });
                        li.addEventListener('keydown', function (ev) {
                            if (ev.key === 'Enter' || ev.key === ' ') {
                                ev.preventDefault();
                                li.click();
                            }
                        });
                        pickList.appendChild(li);
                    });
                })
                .catch(function (e) {
                    pickList.innerHTML = '<li class="gl-pick-empty">' + (e.message || String(e)) + '</li>';
                });
        }

        if (pickQ) {
            pickQ.addEventListener('input', function () {
                if (fyClosePickTimer) {
                    clearTimeout(fyClosePickTimer);
                }
                fyClosePickTimer = setTimeout(function () {
                    fyClosePickLoad(pickQ.value.trim());
                }, 280);
            });
        }
        if (pickBackdrop) {
            pickBackdrop.addEventListener('click', fyClosePickClose);
        }
        if (pickClose) {
            pickClose.addEventListener('click', fyClosePickClose);
        }
        if (mainBackdrop) {
            mainBackdrop.addEventListener('click', fyCloseMainClose);
        }
        if (mainCancel) {
            mainCancel.addEventListener('click', fyCloseMainClose);
        }
        document.querySelectorAll('.fy-close-acc-field .fy-close-search').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var wrap = btn.closest('[data-fy-close-role]');
                var role = wrap ? wrap.getAttribute('data-fy-close-role') : null;
                if (role) {
                    fyClosePickOpen(role);
                }
            });
        });
        document.querySelectorAll('.fy-close-acc-field .fy-close-code').forEach(function (inp) {
            inp.addEventListener('change', function () {
                var wrap = inp.closest('[data-fy-close-role]');
                var role = wrap ? wrap.getAttribute('data-fy-close-role') : null;
                if (!role) {
                    return;
                }
                var raw = inp.value.trim();
                if (!raw) {
                    fyCloseClearField(role);
                    return;
                }
                fetch('/admin/api/accounts/lookup-by-code.php?code=' + encodeURIComponent(raw), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.success) {
                            fyCloseStripResolvedField(role);
                            return;
                        }
                        fyCloseFillField(role, data.account);
                    })
                    .catch(function (e) {
                        fyCloseStripResolvedField(role);
                        alert(e.message || String(e));
                    });
            });
        });
        if (mainSubmit) {
            mainSubmit.addEventListener('click', function () {
                if (fyClosePendingId <= 0) {
                    return;
                }
                var doAcct = chkDoAccounting && chkDoAccounting.checked;
                var payload = { action: 'close', id: fyClosePendingId, accounting_close: !!doAcct };
                if (doAcct) {
                    var incW = document.querySelector('[data-fy-close-role="income"]');
                    var retW = document.querySelector('[data-fy-close-role="retained"]');
                    if (fyCloseFieldIncomplete(incW) || fyCloseFieldIncomplete(retW)) {
                        alert('يوجد كود مكتوب دون حساب فرعي — أكمل الاسم عبر كود فرعي صحيح أو امسح الحقل قبل الإقفال.');
                        return;
                    }
                    var incId = incW ? parseInt(incW.getAttribute('data-account-id'), 10) || 0 : 0;
                    var retId = retW ? parseInt(retW.getAttribute('data-account-id'), 10) || 0 : 0;
                    if (incId > 0) {
                        payload.income_summary_account_id = incId;
                    }
                    if (retId > 0) {
                        payload.retained_earnings_account_id = retId;
                    }
                }
                postJSON('/admin/api/fiscal_years/save.php', payload)
                    .then(function (r) {
                        alert(r.message || (r.success ? 'تم' : 'فشل'));
                        if (r.success) {
                            fyCloseMainClose();
                            location.reload();
                        }
                    })
                    .catch(function (e) { alert(e.message || String(e)); });
            });
        }

        tbody.addEventListener('click', function (ev) {
            var acBtn = ev.target.closest('.fy-btn-acct-close');
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
            var btn = ev.target.closest('.fy-btn-del');
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
                '<td class="fy-col-year"><input type="number" class="fy-inp-year" min="1900" max="2100" step="1" value="' + y + '" aria-label="السنة"></td>' +
                '<td class="fy-col-date"><input type="text" class="fy-inp-start fy-inp-dmy" dir="ltr" autocomplete="off" placeholder="يوم/شهر/سنة" maxlength="10" value="' + dStart + '"></td>' +
                '<td class="fy-col-date"><input type="text" class="fy-inp-end fy-inp-dmy" dir="ltr" autocomplete="off" placeholder="يوم/شهر/سنة" maxlength="10" value="' + dEnd + '"></td>' +
                '<td class="fy-col-closed fy-col-center"><input type="checkbox" class="fy-chk-closed"></td>' +
                '<td class="fy-col-acct-close fy-col-center"><button type="button" class="fy-btn-acct-close btn-secondary" title="إغلاق السنة مع خيارات الإقفال">إقفال…</button></td>' +
                '<td class="fy-col-del fy-col-center"><button type="button" class="fy-btn-del btn-secondary" title="حذف">حذف</button></td>';
            tbody.appendChild(tr);
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
