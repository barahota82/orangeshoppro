<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';
require_once __DIR__ . '/../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/upload_paths.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/edit_lock_ui.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';
require_once __DIR__ . '/../../includes/voucher_print_banner.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$obCaps = orange_admin_caps_for_page($admin, $pdo, 'opening_balances');

$ctxCountryId = orange_admin_settings_effective_country_id($pdo);
$ctxCountryRow = orange_country_row_by_id($pdo, $ctxCountryId, false);
$ctxCountryLabel = trim((string) ($ctxCountryRow['name_ar'] ?? ''));
if ($ctxCountryLabel === '' && $ctxCountryRow !== null) {
    $ctxCountryLabel = trim((string) ($ctxCountryRow['name_en'] ?? ''));
}
if ($ctxCountryLabel === '') {
    $ctxCountryLabel = orange_countries_display_code(orange_admin_context_country_code($pdo));
}

$years = array_values(array_filter(orange_fiscal_years_list($pdo, $ctxCountryId), static fn ($y) => (int) ($y['is_closed'] ?? 0) === 0));

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
    $fyTry = orange_fiscal_find_for_date($pdo, $bootstrapIso, $ctxCountryId);
    if ($isYearOpenAndListed($fyTry)) {
        $fyRowSel = $fyTry;
        $fyId = (int) $fyRowSel['id'];
    } else {
        $first = $years[0];
        $bootstrapIso = substr((string) ($first['start_date'] ?? ''), 0, 10);
        if (strlen($bootstrapIso) !== 10) {
            $bootstrapIso = date('Y-m-d');
        }
        $fyTry2 = orange_fiscal_find_for_date($pdo, $bootstrapIso, $ctxCountryId);
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
$obRef = $fyId > 0 ? orange_opening_balance_reference($pdo, $fyId, $ctxCountryId) : '';
$obCountryCode = orange_opening_balance_country_code($pdo, $ctxCountryId);
$obVoucherDateDisp = strlen($bootstrapIso) === 10 ? orange_format_date_dmY($bootstrapIso) : '';
$obDocEnteredDisp = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));
$obNumberPreview = 1;

if (orange_journal_vouchers_ready($pdo)) {
    orange_journal_types_sync_canonical_defaults($pdo);
    $obFyForSerial = $fyId;
    if ($obFyForSerial > 0 && orange_table_has_column($pdo, 'journal_vouchers', 'voucher_serial')) {
        $obMeta = orange_journal_voucher_resolve_serial_meta($pdo, 'opening_balance', null);
        $obNumberPreview = orange_journal_voucher_next_serial($pdo, $obFyForSerial, $obMeta['journal_serial_bucket']);
    } else {
        $obNumberPreview = orange_gl_voucher_next_id_preview($pdo, $ctxCountryId);
    }
}

if ($fyId > 0 && $fyRowSel !== null && orange_journal_vouchers_ready($pdo)) {
    $obJvSql = 'SELECT id FROM journal_vouchers WHERE fiscal_year_id = ? AND entry_type = ?';
    $obJvParams = [$fyId, 'opening_balance'];
    if ($ctxCountryId > 0 && orange_table_has_country_id($pdo, 'journal_vouchers')) {
        $obJvSql .= ' AND country_id = ?';
        $obJvParams[] = $ctxCountryId;
    }
    $obJvSql .= ' ORDER BY id DESC LIMIT 1';
    $vst = $pdo->prepare($obJvSql);
    $vst->execute($obJvParams);
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
            $refFromDb = trim((string) ($vh['reference'] ?? ''));
            if ($refFromDb !== '') {
                $obRef = $refFromDb;
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

$obPostingLeafCt = orange_accounts_count_posting_leaves($pdo);

?>
<div class="page-title page-title--stacked jv-print-hide">
    <div>
        <h1>أرصدة أول المدة المالية</h1>
        <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars($ctxCountryLabel, ENT_QUOTES, 'UTF-8'); ?> — الأرصدة والسند لهذه الدولة فقط.</p>
    </div>
</div>

<?php if ($years === []): ?>
<div class="card jv-print-hide">
    <p class="card-hint">لا توجد سنة مفتوحة — افتح سنة من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=fiscal_years'), ENT_QUOTES, 'UTF-8'); ?>">السنوات المالية</a>.</p>
</div>
<?php endif; ?>

<?php if ($years !== [] && orange_journal_vouchers_ready($pdo) && $obPostingLeafCt === 0): ?>
<div class="card jv-print-hide" style="border:1px solid #fcd34d;background:#fffbeb;">
    <p class="card-hint" style="margin:0;line-height:1.55;"><strong>تنبيه:</strong> لا توجد حسابات ترحيل (أوراق) في الدليل بعد؛ أسطر القيد ستبقى نادرة أو فارغة حتى تعريف أوراق في «الدليل المحاسبي». <strong>الشاشة تعمل</strong> لإعداد التاريخ ومعلومات السند، ثم أكمل الأسطر بعد إنشاء الحسابات.</p>
</div>
<?php endif; ?>

<?php if ($fyId > 0 && $years !== []): ?>
<div class="card jv-print-area ob-opening-card">
    <h3 class="card-title">سند رصيد افتتاحي</h3>
    <?php orange_edit_lock_ui_toolbar(['prefix' => 'ob', 'doc_kind' => 'opening_balance', 'country_id' => $ctxCountryId]); ?>
    <table class="jv-voucher-print-sheet ta-report-print-table" dir="rtl">
        <?php orange_voucher_print_banner_thead($pdo, $ctxCountryId, ['title_ar' => 'سند رصيد افتتاحي']); ?>
        <tbody>
            <tr>
                <td class="jv-voucher-print-body-cell">
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
                <input type="text" id="ob_date" class="admin-inp orange-inp-dmy"
                    value="<?php echo htmlspecialchars($obVoucherDateDisp, ENT_QUOTES, 'UTF-8'); ?>"
                    title="عرض يوم/شهر/سنة — يحدد السنة المالية تلقائياً (التقويم يُضاف تلقائياً لكل حقول التاريخ في الإدارة)" dir="ltr" lang="en-GB" autocomplete="off">
            </div>
            <div>
                <label for="ob_ref">المرجع</label>
                <input type="text" id="ob_ref" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;" tabindex="-1"
                    value="<?php echo htmlspecialchars($obRef, ENT_QUOTES, 'UTF-8'); ?>"
                    title="يُولَّد تلقائياً: OBV-رمز الدولة-رقم القيد (مثل OBV-KW-1)" dir="ltr" lang="en" autocomplete="off">
            </div>
            <div>
                <label for="ob_document_entered">تاريخ المستند</label>
                <input type="text" id="ob_document_entered" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;"
                    value="<?php echo htmlspecialchars($obDocEnteredDisp, ENT_QUOTES, 'UTF-8'); ?>"
                    title="يُحدَّث من السند المحفوظ؛ عند أول حفظ يُثبت تلقائياً" dir="ltr" lang="en">
            </div>
            <div>
                <label for="ob_tot_debit">مجموع المدين</label>
                <input type="text" id="ob_tot_debit" readonly class="admin-inp-readonly jv-tot-readonly" value="<?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?>"
                    title="إجمالي المدين من أسطر السند" dir="ltr" lang="en" inputmode="decimal">
            </div>
            <div>
                <label for="ob_tot_credit">مجموع الدائن</label>
                <input type="text" id="ob_tot_credit" readonly class="admin-inp-readonly jv-tot-readonly" value="<?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?>"
                    title="إجمالي الدائن من أسطر السند" dir="ltr" lang="en" inputmode="decimal">
            </div>
            <div class="jv-voucher-nav-cell jv-print-hide">
                <div class="jv-voucher-nav-btns ob-voucher-action-btns" role="group" aria-label="إجراءات سند الرصيد الافتتاحي">
                    <button type="button" id="ob_btn_save" data-orange-perm="edit" data-orange-page="opening_balances">حفظ السند</button>
                    <button type="button" class="btn-secondary jv-nav-search" id="ob_btn_print" onclick="obOpenPrintDialog(); return false;">طباعة السند</button>
                    <button type="button" class="btn-secondary jv-nav-search" id="ob_btn_delete"<?php echo $obVid <= 0 ? ' disabled' : ''; ?>>حذف السند</button>
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
                    <col class="jv-col-act">
                    <col class="jv-col-amt">
                    <col class="jv-col-amt">
                </colgroup>
                <thead>
                    <tr>
                        <th class="ob-th-code">كود الحساب</th>
                        <th class="ob-th-name">اسم الحساب</th>
                        <th class="admin-doc-col-actions" aria-label="حذف السطر"></th>
                        <th>مدين</th>
                        <th>دائن</th>
                    </tr>
                </thead>
                <tbody id="ob_body"></tbody>
            </table>
        </div>
    </div>
    <div class="actions admin-doc-lines-toolbar ob-opening-toolbar jv-doc-toolbar jv-print-hide" style="margin-top:10px;">
        <button type="button" class="btn-secondary" id="ob_btn_add">+ سطر يدوي</button>
    </div>
                </td>
            </tr>
        </tbody>
    </table>
    <?php orange_voucher_print_metafoot(); ?>
</div>

<div class="gl-pick-modal" id="ob_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="ob_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="ob_pick_title">
        <h3 id="ob_pick_title" class="gl-pick-modal__title">اختيار حساب فرعي</h3>
        <p class="gl-pick-modal__hint muted" style="margin:0 0 8px;font-size:0.9rem;">نقرتان للاختيار</p>
        <input type="search" id="ob_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بالكود أو الاسم…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="ob_pick_list"></ul>
        <button type="button" class="btn-secondary" id="ob_pick_close">إغلاق</button>
    </div>
</div>
<?php endif; ?>

<script>
var OB_PAGE_FY = <?php echo (int) $fyId; ?>;
var OB_COUNTRY_CODE = <?php echo json_encode($obCountryCode, JSON_UNESCAPED_UNICODE); ?>;
var OB_NUMBER_PREVIEW = <?php echo (int) $obNumberPreview; ?>;
var OB_TYPE_CODE = 'OBV';
var OB_FY_RANGES = <?php echo json_encode($obFyRanges, JSON_UNESCAPED_UNICODE); ?>;
var OB_ADMIN_INDEX = <?php echo json_encode($obAdminIndexUrl, JSON_UNESCAPED_UNICODE); ?>;
var OB_INITIAL = <?php echo json_encode($obInitial, JSON_UNESCAPED_UNICODE); ?>;
var OB_SAVED_VOUCHER_ID = <?php echo (int) $obVid; ?>;
var OB_COUNTRY_ID = <?php echo (int) $ctxCountryId; ?>;
var OB_CAPS = <?php echo json_encode($obCaps, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
var obEditLockCtl = null;

function obOpenPrintDialog() {
    return orangeAdminOpenPrintDialog(
        orangeAdminBuildVoucherPrintDocTitle(null, 'ob_number_preview', 'سند رصيد افتتاحي')
    );
}

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
        var serial = typeof OB_NUMBER_PREVIEW !== 'undefined' && OB_NUMBER_PREVIEW > 0 ? OB_NUMBER_PREVIEW : 1;
        refEl.value = fyDisp > 0 ? ((OB_TYPE_CODE || 'OBV') + '-' + (OB_COUNTRY_CODE || 'XX') + '-' + serial) : '';
        return { iso: iso, fy: fyResolved };
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
            '<td><input type="text" class="gl-inp-code ob-inp-code admin-inp" dir="ltr" autocomplete="off" value="" aria-label="كود الحساب" placeholder="نقرتان للاختيار" title="نقرتان للاختيار"></td>' +
            '<td><input type="text" class="gl-inp-name ob-inp-name admin-inp" readonly tabindex="-1" value="" aria-label="اسم الحساب" placeholder="—" title="يُعبأ تلقائياً"></td>' +
            '<td><button type="button" class="btn-secondary admin-doc-line-remove ob-row-del">حذف</button></td>' +
            '<td><input type="number" class="ob-d admin-inp-money" step="any" min="0" value="" inputmode="decimal" lang="en" dir="ltr" aria-label="مدين" placeholder="0.000"></td>' +
            '<td><input type="number" class="ob-c admin-inp-money" step="any" min="0" value="" inputmode="decimal" lang="en" dir="ltr" aria-label="دائن" placeholder="0.000"></td>';
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
            var cz = OM ? OM.zeroAmount() : orangeMoneyZero();
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
        var elD = document.getElementById('ob_tot_debit');
        var elC = document.getElementById('ob_tot_credit');
        if (window.OrangeMoney && window.OrangeMoney.setJvTotals) {
            window.OrangeMoney.setJvTotals(elD, elC, sd, sc);
        } else {
            var dec = window.OrangeMoney ? window.OrangeMoney.DECIMALS : 3;
            if (elD) {
                elD.value = sd.toFixed(dec);
            }
            if (elC) {
                elC.value = sc.toFixed(dec);
            }
        }
    };
    window.obSave = function () {
        if (!OB_CAPS.can_edit) {
            alert('لا تملك صلاحية تعديل أرصدة أول المدة');
            return;
        }
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
        if (window.OrangeEditLock) {
            obEditLockCtl = OrangeEditLock.bind({
                prefix: 'ob',
                docKind: 'opening_balance',
                page: 'opening_balances',
                canLock: !!OB_CAPS.can_lock,
                canUnlock: !!OB_CAPS.can_unlock,
                countryId: OB_COUNTRY_ID,
                getEntityId: function () {
                    return (OB_PAGE_FY > 0 && OB_SAVED_VOUCHER_ID > 0) ? OB_PAGE_FY : 0;
                }
            });
        }
        obWireDateReload();
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
