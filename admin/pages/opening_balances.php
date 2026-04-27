<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/upload_paths.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$years = array_values(array_filter(orange_fiscal_years_list($pdo), static fn ($y) => (int) ($y['is_closed'] ?? 0) === 0));

$obFyRanges = [];
foreach ($years as $y) {
    $obFyRanges[] = [
        'id' => (int) $y['id'],
        'start' => substr((string) ($y['start_date'] ?? ''), 0, 10),
        'end' => substr((string) ($y['end_date'] ?? ''), 0, 10),
    ];
}

$obDateParam = isset($_GET['ob_date']) ? trim((string) $_GET['ob_date']) : '';
$bootstrapIso = '';
if ($obDateParam !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $obDateParam)) {
    $bootstrapIso = $obDateParam;
} else {
    $bootstrapIso = date('Y-m-d');
}

$fyRowSel = null;
$fyId = 0;

$isYearOpenAndListed = static function (?array $row) use ($years): bool {
    if (!$row) {
        return false;
    }
    if ((int) ($row['is_closed'] ?? 0) === 1) {
        return false;
    }
    $id = (int) ($row['id'] ?? 0);
    foreach ($years as $y) {
        if ((int) $y['id'] === $id) {
            return true;
        }
    }

    return false;
};

if ($years !== []) {
    $fyTry = orange_fiscal_find_for_date($pdo, $bootstrapIso);
    if ($isYearOpenAndListed($fyTry)) {
        $fyRowSel = $fyTry;
        $fyId = (int) $fyRowSel['id'];
    } else {
        $first = $years[0];
        $bootstrapIso = substr((string) ($first['start_date'] ?? ''), 0, 10);
        if (strlen($bootstrapIso) !== 10) {
            $bootstrapIso = date('Y-m-d');
        }
        $fyTry2 = orange_fiscal_find_for_date($pdo, $bootstrapIso);
        if ($isYearOpenAndListed($fyTry2)) {
            $fyRowSel = $fyTry2;
            $fyId = (int) $fyRowSel['id'];
        } else {
            $fyRowSel = $first;
            $fyId = (int) $fyRowSel['id'];
            $bootstrapIso = substr((string) ($fyRowSel['start_date'] ?? ''), 0, 10);
            if (strlen($bootstrapIso) !== 10) {
                $bootstrapIso = date('Y-m-d');
            }
        }
    }
}

$obVid = 0;
$obInitial = [];
$obStatement = '';
$obRef = $fyId > 0 ? 'OB-' . $fyId : '';
$obVoucherDateDisp = strlen($bootstrapIso) === 10 ? orange_format_date_dmY($bootstrapIso) : '';
$obDocEnteredDisp = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));
$obNumberPreview = 1;

if (orange_journal_vouchers_ready($pdo)) {
    $obNumberPreview = (int) $pdo->query('SELECT COALESCE(MAX(id),0) + 1 FROM journal_vouchers')->fetchColumn();
}

if ($fyId > 0 && $fyRowSel !== null && orange_journal_vouchers_ready($pdo)) {
    $vst = $pdo->prepare(
        'SELECT id FROM journal_vouchers WHERE fiscal_year_id = ? AND entry_type = ? ORDER BY id DESC LIMIT 1'
    );
    $vst->execute([$fyId, 'opening_balance']);
    $obVid = (int) $vst->fetchColumn();
    if ($obVid > 0) {
        $vd = $pdo->prepare(
            'SELECT voucher_date, reference, description, document_entered_at, created_at FROM journal_vouchers WHERE id = ? LIMIT 1'
        );
        $vd->execute([$obVid]);
        $vh = $vd->fetch(PDO::FETCH_ASSOC);
        if ($vh) {
            $obStatement = trim((string) ($vh['description'] ?? ''));
            $vdStr = (string) ($vh['voucher_date'] ?? '');
            if (strlen($vdStr) >= 10) {
                $obVoucherDateDisp = orange_format_date_dmY(substr($vdStr, 0, 10));
            }
            $docAt = trim((string) ($vh['document_entered_at'] ?? ''));
            if ($docAt === '') {
                $docAt = trim((string) ($vh['created_at'] ?? ''));
            }
            if ($docAt !== '') {
                $obDocEnteredDisp = orange_format_datetime_dmY_hi($docAt);
            }
        }
        $lst = $pdo->prepare(
            'SELECT jl.account_id, jl.debit, jl.credit, a.code, a.name
             FROM journal_lines jl
             INNER JOIN accounts a ON a.id = jl.account_id
             WHERE jl.voucher_id = ?
             ORDER BY jl.line_no ASC'
        );
        $lst->execute([$obVid]);
        foreach ($lst->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $obInitial[] = [
                'account_id' => (int) $r['account_id'],
                'code' => (string) ($r['code'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
                'debit' => (float) $r['debit'],
                'credit' => (float) $r['credit'],
            ];
        }
    }
}

if ($obVoucherDateDisp === '' && $fyId > 0) {
    $obVoucherDateDisp = orange_format_date_dmY(date('Y-m-d'));
}

$obNumberDisplay = $obVid > 0 ? $obVid : $obNumberPreview;
$obAdminIndexUrl = storefront_public_path('/admin/index.php');
?>
<div class="page-title page-title--stacked jv-print-hide">
    <div>
        <h1>أرصدة أول المدة المالية</h1>
    </div>
</div>

<?php if ($years === []): ?>
<div class="card jv-print-hide">
    <p class="card-hint">لا توجد سنة مفتوحة — افتح سنة من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=fiscal_years'), ENT_QUOTES, 'UTF-8'); ?>">السنوات المالية</a>.</p>
</div>
<?php endif; ?>

<?php if ($fyId > 0 && $years !== []): ?>
<div class="card jv-print-area ob-opening-card">
    <h3 class="card-title">سند رصيد افتتاحي</h3>
    <div class="form-grid">
        <div class="jv-voucher-header-line jv-voucher-header-line--nav" style="grid-column:1/-1;">
            <div>
                <label for="ob_number_preview">رقم القيد</label>
                <input type="text" id="ob_number_preview" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;text-align:center;"
                    value="<?php echo (int) $obNumberDisplay; ?>"
                    title="يُثبت عند الحفظ — قيد رصيد افتتاحي واحد لكل سنة مالية">
            </div>
            <div>
                <label for="ob_date">تاريخ السند</label>
                <div class="admin-inp-dmy-with-picker">
                    <input type="text" id="ob_date" class="admin-inp orange-inp-dmy"
                        value="<?php echo htmlspecialchars($obVoucherDateDisp, ENT_QUOTES, 'UTF-8'); ?>"
                        title="يحدد السنة المالية تلقائياً — يجب أن يقع التاريخ ضمن سنة مفتوحة" dir="ltr" lang="en" autocomplete="off">
                    <input type="date" id="ob_date_picker" class="admin-inp admin-inp-dmy-picker jv-print-hide" lang="en" dir="ltr"
                        title="اختيار من التقويم" aria-label="تقويم — اختيار تاريخ السند">
                </div>
            </div>
            <div>
                <label for="ob_ref">المرجع</label>
                <input type="text" id="ob_ref" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;" tabindex="-1"
                    value="<?php echo htmlspecialchars($obRef, ENT_QUOTES, 'UTF-8'); ?>"
                    title="يُولَّد تلقائياً من السنة المالية (OB-رقم السنة)" dir="ltr" lang="en" autocomplete="off">
            </div>
            <div>
                <label for="ob_document_entered">تاريخ المستند</label>
                <input type="text" id="ob_document_entered" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;"
                    value="<?php echo htmlspecialchars($obDocEnteredDisp, ENT_QUOTES, 'UTF-8'); ?>"
                    title="يُحدَّث من السند المحفوظ؛ عند أول حفظ يُثبت تلقائياً" dir="ltr" lang="en">
            </div>
            <div>
                <label for="ob_tot_debit">مجموع المدين</label>
                <input type="text" id="ob_tot_debit" readonly class="admin-inp-readonly jv-tot-readonly" value="0.000"
                    title="إجمالي المدين من أسطر السند" dir="ltr" lang="en" inputmode="decimal">
            </div>
            <div>
                <label for="ob_tot_credit">مجموع الدائن</label>
                <input type="text" id="ob_tot_credit" readonly class="admin-inp-readonly jv-tot-readonly" value="0.000"
                    title="إجمالي الدائن من أسطر السند" dir="ltr" lang="en" inputmode="decimal">
            </div>
            <div class="jv-voucher-nav-cell jv-print-hide">
                <div class="jv-voucher-nav-btns ob-voucher-action-btns" role="group" aria-label="إجراءات سند الرصيد الافتتاحي">
                    <button type="button" class="btn-secondary jv-nav-search" id="ob_btn_delete"<?php echo $obVid <= 0 ? ' disabled' : ''; ?>>حذف السند</button>
                    <button type="button" class="btn-secondary jv-nav-search" id="ob_btn_print">طباعة السند</button>
                    <button type="button" id="ob_btn_save">حفظ</button>
                </div>
            </div>
        </div>
        <div style="grid-column:1/-1;">
            <label for="ob_statement">البيان</label>
            <input type="text" id="ob_statement" class="admin-inp" dir="rtl" autocomplete="off" value="<?php echo htmlspecialchars($obStatement, ENT_QUOTES, 'UTF-8'); ?>" aria-required="true" placeholder="وصف السند">
        </div>
    </div>
    <div class="admin-doc-frame">
        <div class="table-wrap ob-opening-table-wrap">
            <table class="admin-table admin-doc-lines-table ob-opening-table jv-lines-table">
                <colgroup>
                    <col class="jv-col-code">
                    <col class="jv-col-name">
                    <col class="jv-col-amt">
                    <col class="jv-col-amt">
                    <col class="jv-col-act">
                </colgroup>
                <thead>
                    <tr>
                        <th class="ob-th-code">كود الحساب</th>
                        <th class="ob-th-name">اسم الحساب</th>
                        <th>مدين</th>
                        <th>دائن</th>
                        <th class="admin-doc-col-actions" aria-label="حذف السطر"></th>
                    </tr>
                </thead>
                <tbody id="ob_body"></tbody>
            </table>
        </div>
    </div>
    <div class="actions admin-doc-lines-toolbar ob-opening-toolbar jv-doc-toolbar jv-print-hide" style="margin-top:10px;">
        <button type="button" class="btn-secondary" id="ob_btn_add">+ سطر يدوي</button>
    </div>
</div>

<div class="gl-pick-modal" id="ob_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="ob_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="ob_pick_title">
        <h3 id="ob_pick_title" class="gl-pick-modal__title">اختيار حساب فرعي</h3>
        <p class="gl-pick-modal__hint muted" style="margin:0 0 8px;font-size:0.9rem;">نقرتان على حقل كود الحساب في الجدول لفتح هذه القائمة — انقر صفاً للاختيار — Esc للإغلاق</p>
        <input type="search" id="ob_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بالكود أو الاسم…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="ob_pick_list"></ul>
        <button type="button" class="btn-secondary" id="ob_pick_close">إغلاق</button>
    </div>
</div>
<?php endif; ?>

<script>
var OB_PAGE_FY = <?php echo (int) $fyId; ?>;
var OB_FY_RANGES = <?php echo json_encode($obFyRanges, JSON_UNESCAPED_UNICODE); ?>;
var OB_ADMIN_INDEX = <?php echo json_encode($obAdminIndexUrl, JSON_UNESCAPED_UNICODE); ?>;
var OB_INITIAL = <?php echo json_encode($obInitial, JSON_UNESCAPED_UNICODE); ?>;
var OB_SAVED_VOUCHER_ID = <?php echo (int) $obVid; ?>;

(function () {
    var obSaveInFlight = false;
    var obDeleteInFlight = false;
    var obPrintArm = true;

    function obResolveFyIdFromIso(iso) {
        if (!iso || !OB_FY_RANGES || !OB_FY_RANGES.length) {
            return 0;
        }
        for (var i = 0; i < OB_FY_RANGES.length; i++) {
            var r = OB_FY_RANGES[i];
            if (iso >= r.start && iso <= r.end) {
                return parseInt(r.id, 10) || 0;
            }
        }
        return 0;
    }

    function obSyncRefFromDate() {
        var obDateEl = document.getElementById('ob_date');
        var refEl = document.getElementById('ob_ref');
        if (!obDateEl || !refEl) {
            return { iso: '', fy: 0 };
        }
        var iso = typeof orangeGetDmyValueAsIso === 'function' ? orangeGetDmyValueAsIso(obDateEl) : '';
        var fyResolved = obResolveFyIdFromIso(iso);
        var fyDisp = fyResolved > 0 ? fyResolved : (typeof OB_PAGE_FY !== 'undefined' && OB_PAGE_FY > 0 ? OB_PAGE_FY : 0);
        refEl.value = fyDisp > 0 ? 'OB-' + fyDisp : '';
        return { iso: iso, fy: fyResolved };
    }

    function obSyncDatePickerFromText() {
        var obDateEl = document.getElementById('ob_date');
        var pick = document.getElementById('ob_date_picker');
        if (!obDateEl || !pick || typeof orangeGetDmyValueAsIso !== 'function') {
            return;
        }
        var iso = orangeGetDmyValueAsIso(obDateEl);
        pick.value = iso || '';
    }

    function obWireNativeDatePicker() {
        var text = document.getElementById('ob_date');
        var pick = document.getElementById('ob_date_picker');
        if (!text || !pick) {
            return;
        }
        pick.addEventListener('change', function () {
            if (!pick.value) {
                return;
            }
            if (typeof orangeIsoDateToDmy === 'function') {
                text.value = orangeIsoDateToDmy(pick.value);
            } else {
                text.value = pick.value;
            }
            if (typeof orangeNormalizeDmyInput === 'function') {
                orangeNormalizeDmyInput(text);
            }
            obSyncDatePickerFromText();
            text.dispatchEvent(new Event('blur', { bubbles: true }));
        });
        obSyncDatePickerFromText();
    }

    function obWireDateReload() {
        var obDateEl = document.getElementById('ob_date');
        if (!obDateEl) {
            return;
        }
        obDateEl.addEventListener('blur', function () {
            if (typeof orangeNormalizeDmyInput === 'function') {
                orangeNormalizeDmyInput(obDateEl);
            }
            obSyncDatePickerFromText();
            var r = obSyncRefFromDate();
            if (r.fy > 0 && r.fy !== OB_PAGE_FY && r.iso) {
                window.location.href = OB_ADMIN_INDEX + '?page=opening_balances&ob_date=' + encodeURIComponent(r.iso);
            }
        });
    }

    var pickModal = document.getElementById('ob_pick_modal');
    var pickList = document.getElementById('ob_pick_list');
    var pickQ = document.getElementById('ob_pick_q');
    var pickBackdrop = document.getElementById('ob_pick_backdrop');
    var pickClose = document.getElementById('ob_pick_close');
    var activeObPickTr = null;
    var obPickSeq = 0;
    var searchTimer = null;

    function obAllRows(tb) {
        return tb ? Array.prototype.slice.call(tb.querySelectorAll('tr')) : [];
    }
    function obRowIsBlank(tr) {
        if (!tr) {
            return true;
        }
        var id = parseInt(tr.getAttribute('data-account-id'), 10) || 0;
        var d = parseFloat(String((tr.querySelector('.ob-d') || {}).value || '0').replace(',', '.')) || 0;
        var c = parseFloat(String((tr.querySelector('.ob-c') || {}).value || '0').replace(',', '.')) || 0;
        return id <= 0 && d <= 0.0000001 && c <= 0.0000001;
    }
    function obTrimExtraTrailingBlanks() {
        var tb = document.getElementById('ob_body');
        if (!tb) {
            return;
        }
        for (;;) {
            var rows = obAllRows(tb);
            if (rows.length < 2) {
                return;
            }
            var a = rows[rows.length - 2];
            var b = rows[rows.length - 1];
            if (obRowIsBlank(a) && obRowIsBlank(b)) {
                b.remove();
            } else {
                return;
            }
        }
    }
    function obSyncTrailingRows() {
        obTrimExtraTrailingBlanks();
        var tb = document.getElementById('ob_body');
        if (!tb) {
            return;
        }
        var rows = obAllRows(tb);
        if (rows.length === 0) {
            window.obAdd();
            return;
        }
        var last = rows[rows.length - 1];
        if (!obRowIsBlank(last)) {
            window.obAdd();
        }
    }
    function obBindBody() {
        var tb = document.getElementById('ob_body');
        if (!tb || tb.getAttribute('data-ob-bound') === '1') {
            return;
        }
        tb.setAttribute('data-ob-bound', '1');
        tb.addEventListener('input', function () {
            obSyncTrailingRows();
            window.obRecalc();
        });
        tb.addEventListener('change', function () {
            obSyncTrailingRows();
            window.obRecalc();
        });
        tb.addEventListener('keydown', function (e) {
            if (e.key !== 'Tab' || e.shiftKey) {
                return;
            }
            var ta = e.target;
            if (!ta || !ta.closest || !ta.classList.contains('ob-c')) {
                return;
            }
            var tr = ta.closest('tr');
            if (!tr || tr.parentElement !== tb) {
                return;
            }
            var rows = obAllRows(tb);
            if (rows.length === 0 || tr !== rows[rows.length - 1]) {
                return;
            }
            e.preventDefault();
            obSyncTrailingRows();
            var rows2 = obAllRows(tb);
            var next = rows2[rows2.length - 1];
            var codeInp = next && next.querySelector('.ob-inp-code');
            if (codeInp) {
                codeInp.focus();
            }
        });
    }

    /** عند ربط حساب: اسم الحساب معطّل؛ عند مسح الكود يُفعَّل لإعادة الاختيار (مع بقاء الحقل للعرض فقط — لا إدخال يدوي للاسم). */
    function obSyncNameFieldState(tr) {
        var n = tr.querySelector('.ob-inp-name');
        if (!n) {
            return;
        }
        var id = parseInt(tr.getAttribute('data-account-id'), 10) || 0;
        n.readOnly = true;
        n.disabled = id > 0;
        n.setAttribute('tabindex', '-1');
    }
    function obFillAccount(tr, acc) {
        if (!tr || !acc) {
            return;
        }
        tr.setAttribute('data-account-id', String(acc.id));
        var c = tr.querySelector('.ob-inp-code');
        var n = tr.querySelector('.ob-inp-name');
        if (c) {
            c.value = acc.code || '';
        }
        if (n) {
            n.value = acc.name || '';
        }
        obSyncNameFieldState(tr);
    }
    function obClearAccount(tr) {
        if (!tr) {
            return;
        }
        tr.setAttribute('data-account-id', '0');
        var c = tr.querySelector('.ob-inp-code');
        var n = tr.querySelector('.ob-inp-name');
        if (c) {
            c.value = '';
        }
        if (n) {
            n.value = '';
        }
        obSyncNameFieldState(tr);
    }
    function obStripInvalid(tr) {
        obClearAccount(tr);
    }
    function obOpenPick(tr) {
        activeObPickTr = tr;
        pickModal.hidden = false;
        pickModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ob-pick-open');
        pickQ.value = '';
        pickList.innerHTML = '';
        obPickLoad('');
        pickQ.focus();
    }
    function obClosePick() {
        activeObPickTr = null;
        pickModal.hidden = true;
        pickModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ob-pick-open');
    }
    function obPickLoad(q) {
        var mySeq = ++obPickSeq;
        var url = '/admin/api/accounts/search-leaves.php?q=' + encodeURIComponent(q || '');
        fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (mySeq !== obPickSeq) {
                    return;
                }
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
                    function obPickChoose() {
                        if (activeObPickTr) {
                            obFillAccount(activeObPickTr, { id: a.id, code: code, name: a.name || '' });
                            obSyncTrailingRows();
                            window.obRecalc();
                        }
                        obClosePick();
                    }
                    li.addEventListener('click', obPickChoose);
                    li.addEventListener('keydown', function (ev) {
                        if (ev.key === 'Enter' || ev.key === ' ') {
                            ev.preventDefault();
                            obPickChoose();
                        }
                    });
                    pickList.appendChild(li);
                });
            })
            .catch(function (e) {
                pickList.innerHTML = '<li class="gl-pick-empty">' + (e.message || String(e)) + '</li>';
            });
    }

    function obWireCodeRow(tr) {
        var codeInp = tr.querySelector('.ob-inp-code');
        if (!codeInp) {
            return;
        }
        if (!codeInp.getAttribute('data-ob-dbl')) {
            codeInp.setAttribute('data-ob-dbl', '1');
            codeInp.addEventListener('dblclick', function (e) {
                e.preventDefault();
                obOpenPick(tr);
            });
        }
        var glLookupInFlight = false;
        codeInp.addEventListener('input', function () {
            if (!String(codeInp.value || '').trim()) {
                obClearAccount(tr);
            }
        });
        codeInp.addEventListener('change', function () {
            var raw = codeInp.value.trim();
            if (!raw) {
                obClearAccount(tr);
                return;
            }
            glLookupInFlight = true;
            fetch('/admin/api/accounts/lookup-by-code.php?code=' + encodeURIComponent(raw), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) {
                        obStripInvalid(tr);
                        return;
                    }
                    obFillAccount(tr, data.account);
                    obSyncTrailingRows();
                    window.obRecalc();
                })
                .catch(function () {
                    obStripInvalid(tr);
                })
                .finally(function () {
                    glLookupInFlight = false;
                });
        });
        codeInp.addEventListener('blur', function () {
            window.setTimeout(function () {
                if (glLookupInFlight) {
                    return;
                }
                var raw = String(codeInp.value || '').trim();
                var id = parseInt(tr.getAttribute('data-account-id'), 10) || 0;
                var nameEl = tr.querySelector('.ob-inp-name');
                var nameTxt = nameEl ? String(nameEl.value || '').trim() : '';
                if (raw !== '' && id <= 0 && nameTxt === '') {
                    obClearAccount(tr);
                }
            }, 0);
        });
    }
    window.obAdd = function (preset) {
        var tb = document.getElementById('ob_body');
        if (!tb) {
            return;
        }
        var tr = document.createElement('tr');
        tr.className = 'ob-line-main';
        tr.setAttribute('data-account-id', '0');
        tr.innerHTML =
            '<td><input type="text" class="gl-inp-code ob-inp-code admin-inp" dir="ltr" autocomplete="off" value="" aria-label="كود الحساب" placeholder="نقرتان للاختيار أو اكتب الكود" title="نقرتان لفتح قائمة الحسابات — أو أدخِل الكود وغيّر الحقل للتحقق"></td>' +
            '<td><input type="text" class="gl-inp-name ob-inp-name admin-inp" readonly tabindex="-1" value="" aria-label="اسم الحساب" placeholder="—" title="يُعبأ تلقائياً"></td>' +
            '<td><input type="number" class="ob-d admin-inp-money" step="any" min="0" value="" inputmode="decimal" lang="en" dir="ltr" aria-label="مدين" placeholder="0.000"></td>' +
            '<td><input type="number" class="ob-c admin-inp-money" step="any" min="0" value="" inputmode="decimal" lang="en" dir="ltr" aria-label="دائن" placeholder="0.000"></td>' +
            '<td><button type="button" class="btn-secondary admin-doc-line-remove ob-row-del">حذف</button></td>';
        tb.appendChild(tr);
        tr.querySelector('.ob-row-del').addEventListener('click', function () {
            tr.remove();
            obSyncTrailingRows();
            window.obRecalc();
        });
        obWireCodeRow(tr);
        obSyncNameFieldState(tr);
        if (preset && preset.account_id > 0) {
            obFillAccount(tr, {
                id: preset.account_id,
                code: preset.code || '',
                name: preset.name || ''
            });
            var d = tr.querySelector('.ob-d');
            var c = tr.querySelector('.ob-c');
            var deb = parseFloat(preset.debit) || 0;
            var cre = parseFloat(preset.credit) || 0;
            var OM = window.OrangeMoney;
            var dec = OM ? OM.DECIMALS : 3;
            var cz = OM ? OM.companionZero() : '0.000';
            if (deb > 0) {
                if (d) {
                    d.value = deb.toFixed(dec);
                }
                if (c) {
                    c.value = cz;
                }
            } else if (cre > 0) {
                if (c) {
                    c.value = cre.toFixed(dec);
                }
                if (d) {
                    d.value = cz;
                }
            }
        }
        obRecalc();
    };
    window.obRecalc = function () {
        var sd = 0;
        var sc = 0;
        document.querySelectorAll('#ob_body tr').forEach(function (tr) {
            sd += parseFloat(String((tr.querySelector('.ob-d') || {}).value || '0').replace(',', '.'));
            sc += parseFloat(String((tr.querySelector('.ob-c') || {}).value || '0').replace(',', '.'));
        });
        var dec = window.OrangeMoney ? window.OrangeMoney.DECIMALS : 3;
        var elD = document.getElementById('ob_tot_debit');
        var elC = document.getElementById('ob_tot_credit');
        if (elD) {
            elD.value = sd.toFixed(dec);
        }
        if (elC) {
            elC.value = sc.toFixed(dec);
        }
    };
    window.obSave = function () {
        if (obSaveInFlight) {
            return;
        }
        var stEl = document.getElementById('ob_statement');
        var statement = stEl ? String(stEl.value || '').trim() : '';
        if (!statement) {
            alert('البيان مطلوب قبل الحفظ');
            if (stEl) {
                stEl.focus();
            }
            return;
        }
        var lines = [];
        document.querySelectorAll('#ob_body tr').forEach(function (tr) {
            var acc = parseInt(tr.getAttribute('data-account-id'), 10) || 0;
            var deb = parseFloat(String((tr.querySelector('.ob-d') || {}).value || '0').replace(',', '.'));
            var cre = parseFloat(String((tr.querySelector('.ob-c') || {}).value || '0').replace(',', '.'));
            if (deb > 0 && cre > 0) {
                cre = 0;
            }
            if (acc <= 0) {
                return;
            }
            if (deb <= 0 && cre <= 0) {
                return;
            }
            lines.push({ account_id: acc, debit: deb, credit: cre, memo: statement });
        });
        if (lines.length < 2) {
            alert('سطران على الأقل بأرصدة صحيحة وحساب فرعي مربوط');
            return;
        }
        var sd = lines.reduce(function (a, x) { return a + x.debit; }, 0);
        var sc = lines.reduce(function (a, x) { return a + x.credit; }, 0);
        if (Math.abs(sd - sc) > 0.001) {
            alert('السند غير متوازن');
            return;
        }
        var obDateEl = document.getElementById('ob_date');
        var vIso = typeof orangeGetDmyValueAsIso === 'function' && obDateEl
            ? orangeGetDmyValueAsIso(obDateEl)
            : '';
        if (!vIso) {
            alert('تاريخ السند مطلوب (يوم/شهر/سنة)');
            if (obDateEl) {
                obDateEl.focus();
            }
            return;
        }
        if (obResolveFyIdFromIso(vIso) <= 0) {
            alert('تاريخ السند خارج السنوات المالية المفتوحة');
            if (obDateEl) {
                obDateEl.focus();
            }
            return;
        }
        obSaveInFlight = true;
        var saveBtn = document.getElementById('ob_btn_save');
        if (saveBtn) {
            saveBtn.disabled = true;
        }
        postJSON('/admin/api/opening_balances/save.php', {
            statement: statement,
            voucher_date: vIso,
            lines: lines
        })
            .then(function (r) {
                if (r.success) {
                    alert(r.message || 'تم');
                    location.reload();
                    return;
                }
                if (!orangeAdminOfferSuggestOnFailure(r, 'فشل')) {
                    alert(r.message || 'فشل');
                }
            })
            .catch(function (e) { alert(e.message || String(e)); })
            .finally(function () {
                obSaveInFlight = false;
                if (saveBtn) {
                    saveBtn.disabled = false;
                }
            });
    };

    function obPrint() {
        if (!obPrintArm) {
            return;
        }
        obPrintArm = false;
        window.print();
        window.setTimeout(function () {
            obPrintArm = true;
        }, 1500);
    }
    function obDelete() {
        if (obDeleteInFlight) {
            return;
        }
        if (OB_SAVED_VOUCHER_ID <= 0) {
            alert('لا يوجد سند محفوظ للحذف');
            return;
        }
        if (!confirm('تأكيد حذف سند الرصيد الافتتاحي لهذه السنة؟')) {
            return;
        }
        var obDateEl = document.getElementById('ob_date');
        var vIso = typeof orangeGetDmyValueAsIso === 'function' && obDateEl
            ? orangeGetDmyValueAsIso(obDateEl)
            : '';
        if (!vIso) {
            alert('تاريخ السند مطلوب لتحديد السنة');
            return;
        }
        obDeleteInFlight = true;
        var delBtn = document.getElementById('ob_btn_delete');
        if (delBtn) {
            delBtn.disabled = true;
        }
        postJSON('/admin/api/opening_balances/delete.php', { voucher_date: vIso })
            .then(function (r) {
                if (r.success) {
                    alert(r.message || 'تم');
                    location.reload();
                    return;
                }
                if (!orangeAdminOfferSuggestOnFailure(r, 'فشل الحذف')) {
                    alert(r.message || 'فشل');
                }
            })
            .catch(function (e) { alert(e.message || String(e)); })
            .finally(function () {
                obDeleteInFlight = false;
                if (delBtn) {
                    delBtn.disabled = OB_SAVED_VOUCHER_ID <= 0;
                }
            });
    }

    var tb = document.getElementById('ob_body');
    if (tb) {
        document.getElementById('ob_btn_add').addEventListener('click', function () { obAdd(); });
        document.getElementById('ob_btn_save').addEventListener('click', obSave);
        var obDel = document.getElementById('ob_btn_delete');
        if (obDel) {
            obDel.addEventListener('click', obDelete);
        }
        var obPr = document.getElementById('ob_btn_print');
        if (obPr) {
            obPr.addEventListener('click', obPrint);
        }
        if (pickQ) {
            pickQ.addEventListener('input', function () {
                if (searchTimer) {
                    clearTimeout(searchTimer);
                }
                searchTimer = setTimeout(function () {
                    obPickLoad(pickQ.value.trim());
                }, 280);
            });
        }
        if (pickBackdrop) {
            pickBackdrop.addEventListener('click', obClosePick);
        }
        if (pickClose) {
            pickClose.addEventListener('click', obClosePick);
        }
        obWireDateReload();
        obWireNativeDatePicker();
        obSyncRefFromDate();
        document.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Escape') {
                return;
            }
            if (pickModal && !pickModal.hidden) {
                obClosePick();
            }
        }, true);
        if (Array.isArray(OB_INITIAL) && OB_INITIAL.length > 0) {
            OB_INITIAL.forEach(function (row) {
                obAdd(row);
            });
        } else {
            obAdd();
        }
        obBindBody();
        obSyncTrailingRows();
        window.obRecalc();
    }
})();
</script>
