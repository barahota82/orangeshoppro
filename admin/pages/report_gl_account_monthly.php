<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/upload_paths.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$years = orange_fiscal_years_list($pdo);
$fyId = isset($_GET['fy']) ? (int) $_GET['fy'] : 0;
if ($fyId <= 0 && $years !== []) {
    $fyId = (int) $years[0]['id'];
}

$accountId = isset($_GET['account']) ? (int) $_GET['account'] : 0;

$leafWhere = orange_accounts_posting_leaf_where_sql($pdo, 'a');
$accounts = $pdo->query(
    "SELECT a.id, a.name, a.code FROM accounts a WHERE $leafWhere ORDER BY COALESCE(a.code, ''), a.name"
)->fetchAll(PDO::FETCH_ASSOC);

$accLabel = '';
$accCodeDisp = '';
$accNameDisp = '';
if ($accountId > 0) {
    if (! orange_accounts_account_is_posting_leaf($pdo, $accountId)) {
        $accountId = 0;
    } else {
        foreach ($accounts as $a) {
            if ((int) $a['id'] === $accountId) {
                $accCodeDisp = trim((string) ($a['code'] ?? ''));
                $accNameDisp = (string) ($a['name'] ?? '');
                $accLabel = ($accCodeDisp !== '' ? $accCodeDisp . ' — ' : '') . $accNameDisp;
                break;
            }
        }
        if ($accLabel === '' && $accountId > 0) {
            $stOne = $pdo->prepare('SELECT code, name FROM accounts WHERE id = ? LIMIT 1');
            $stOne->execute([$accountId]);
            $rowOne = $stOne->fetch(PDO::FETCH_ASSOC);
            if ($rowOne) {
                $accCodeDisp = trim((string) ($rowOne['code'] ?? ''));
                $accNameDisp = (string) ($rowOne['name'] ?? '');
                $accLabel = ($accCodeDisp !== '' ? $accCodeDisp . ' — ' : '') . $accNameDisp;
            }
        }
    }
}

$fyRow = null;
foreach ($years as $y) {
    if ((int) $y['id'] === $fyId) {
        $fyRow = $y;
        break;
    }
}

/**
 * قبول مدخل month (YYYY-MM) من النموذج أو GET.
 */
$normalizeYm = static function (string $raw): ?string {
    $raw = trim($raw);
    if (!preg_match('/^(\d{4})-(\d{2})$/', $raw, $m)) {
        return null;
    }
    $month = (int) $m[2];
    if ($month < 1 || $month > 12) {
        return null;
    }

    return sprintf('%04d-%02d', (int) $m[1], $month);
};

$ymFromGet = isset($_GET['m_from']) ? $normalizeYm((string) $_GET['m_from']) : null;
$ymToGet = isset($_GET['m_to']) ? $normalizeYm((string) $_GET['m_to']) : null;

/** أول يوم من شهر YYYY-MM */
$firstDayOfYm = static function (string $ym): string {
    return $ym . '-01';
};

/** آخر يوم من شهر YYYY-MM */
$lastDayOfYm = static function (string $ym): string {
    $t = strtotime($firstDayOfYm($ym) . ' 12:00:00');

    return $t ? date('Y-m-t', $t) : $ym . '-28';
};

$useVouchers = orange_journal_vouchers_ready($pdo);
$monthlyRows = [];
$periodLabel = '';
$periodYmFrom = '';
$periodYmTo = '';
$periodDateFrom = '';
$periodDateTo = '';
/** حدود YYYY-MM للسنة المالية الحالية (لـ min/max على حقول الشهر) */
$fyYmBoundMin = '';
$fyYmBoundMax = '';

if ($fyRow) {
    $fyStartYmd = (string) ($fyRow['start_date'] ?? '');
    $fyEndYmd = (string) ($fyRow['end_date'] ?? '');
    /** أول يوم وفق yyyy-mm-dd فقط لتفادي أخطاء المقارنة مع DATETIME أو أشكال مختلفة من السيرفر */
    $fyStartDay = preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($fyStartYmd), $mY) ? $mY[1] : '';
    $fyEndDay = preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($fyEndYmd), $mY) ? $mY[1] : '';
    $fyMinYm = strlen($fyStartDay) >= 7 ? substr($fyStartDay, 0, 7) : '';
    $fyMaxYm = strlen($fyEndDay) >= 7 ? substr($fyEndDay, 0, 7) : '';
    if ($fyMinYm !== '' && $fyMaxYm !== '' && $fyMinYm <= $fyMaxYm && $fyStartDay !== '' && $fyEndDay !== '') {
        $fyYmBoundMin = $fyMinYm;
        $fyYmBoundMax = $fyMaxYm;
        $ymFrom = $ymFromGet ?? $fyMinYm;
        $ymTo = $ymToGet ?? $fyMaxYm;
        if ($ymFrom < $fyMinYm) {
            $ymFrom = $fyMinYm;
        }
        if ($ymFrom > $fyMaxYm) {
            $ymFrom = $fyMaxYm;
        }
        if ($ymTo < $fyMinYm) {
            $ymTo = $fyMinYm;
        }
        if ($ymTo > $fyMaxYm) {
            $ymTo = $fyMaxYm;
        }
        if ($ymFrom > $ymTo) {
            $tmp = $ymFrom;
            $ymFrom = $ymTo;
            $ymTo = $tmp;
        }
        $periodYmFrom = $ymFrom;
        $periodYmTo = $ymTo;

        $monthFirst = $firstDayOfYm($ymFrom);
        $monthLast = $lastDayOfYm($ymTo);
        /** التقاطع مع السنة المالية بدءاً من آخر اليوم المعقول (جميعهم YYYY-MM-DD بنفس الطول للمقارنة السليمة). */
        $periodDateFrom = strcmp($monthFirst, $fyStartDay) >= 0 ? $monthFirst : $fyStartDay;
        $periodDateTo = strcmp($monthLast, $fyEndDay) <= 0 ? $monthLast : $fyEndDay;
        if ($periodDateFrom !== '' && $periodDateTo !== '') {
            if ($periodDateFrom > $periodDateTo) {
                $periodYmFrom = '';
                $periodYmTo = '';
                $periodDateFrom = '';
                $periodDateTo = '';
            } else {
                $periodLabel = $periodDateFrom . ' — ' . $periodDateTo;
            }
        }
    }
}

if (
    $useVouchers && $fyId > 0 && $accountId > 0 && $periodDateFrom !== '' && $periodDateTo !== ''
    && $periodDateFrom <= $periodDateTo
) {
    $st = $pdo->prepare(
        'SELECT DATE_FORMAT(jv.voucher_date, \'%Y-%m\') AS ym,
                COALESCE(SUM(jl.debit), 0) AS sum_debit,
                COALESCE(SUM(jl.credit), 0) AS sum_credit
         FROM journal_lines jl
         INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id
         WHERE jl.account_id = ?
           AND jv.fiscal_year_id = ?
           AND jv.voucher_date >= ?
           AND jv.voucher_date <= ?
         GROUP BY ym
         ORDER BY ym ASC'
    );
    $st->execute([
        $accountId,
        $fyId,
        $periodDateFrom,
        $periodDateTo,
    ]);
    $monthlyRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$running = 0.0;
foreach ($monthlyRows as &$mr) {
    $d = (float) $mr['sum_debit'];
    $c = (float) $mr['sum_credit'];
    $running += ($d - $c);
    $mr['net_month'] = $d - $c;
    $mr['balance_eom'] = $running;
}
unset($mr);


?>
<div class="admin-fy-shell" dir="rtl">
    <div class="gl-acc-stmt-no-print">
        <h1 class="admin-fy-shell__title">الحركة الشهرية لحساب</h1>
    </div>

    <div class="card admin-fy-card gl-acc-stmt-no-print gas-acc-stmt-search-card">
        <form method="get" class="gas-acc-stmt-filter-form" id="gl_m_monthly_form">
            <input type="hidden" name="page" value="report_gl_account_monthly">
            <input type="hidden" name="account" id="gl_m_account_id" value="<?php echo (int) $accountId; ?>">
            <div class="gas-acc-stmt-toolbar-wrap">
                <div class="gas-acc-stmt-toolbar gl-m-monthly-toolbar gas-acc-stmt-toolbar--main-center">
                    <div class="gas-acc-stmt-field gl-m-stmt-field--fy">
                        <label for="fy_gl_m">السنة المالية</label>
                        <select name="fy" id="fy_gl_m" class="admin-inp">
                            <?php foreach ($years as $y): ?>
                                <option value="<?php echo (int) $y['id']; ?>"<?php echo (int) $y['id'] === $fyId ? ' selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) ($y['label_ar'] ?? $y['id']), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="gas-acc-stmt-field gl-m-stmt-field--month">
                        <label for="gl_m_month_from">من شهر</label>
                        <input type="month" name="m_from" id="gl_m_month_from" class="admin-inp"
                            lang="en" dir="ltr"
                            value="<?php echo htmlspecialchars($periodYmFrom, ENT_QUOTES, 'UTF-8'); ?>"
                            <?php if ($fyYmBoundMin !== '' && $fyYmBoundMax !== ''): ?>
                            min="<?php echo htmlspecialchars($fyYmBoundMin, ENT_QUOTES, 'UTF-8'); ?>"
                            max="<?php echo htmlspecialchars($fyYmBoundMax, ENT_QUOTES, 'UTF-8'); ?>"
                            <?php endif; ?>
                            autocomplete="off">
                    </div>
                    <div class="gas-acc-stmt-field gl-m-stmt-field--month">
                        <label for="gl_m_month_to">إلى شهر</label>
                        <input type="month" name="m_to" id="gl_m_month_to" class="admin-inp"
                            lang="en" dir="ltr"
                            value="<?php echo htmlspecialchars($periodYmTo, ENT_QUOTES, 'UTF-8'); ?>"
                            <?php if ($fyYmBoundMin !== '' && $fyYmBoundMax !== ''): ?>
                            min="<?php echo htmlspecialchars($fyYmBoundMin, ENT_QUOTES, 'UTF-8'); ?>"
                            max="<?php echo htmlspecialchars($fyYmBoundMax, ENT_QUOTES, 'UTF-8'); ?>"
                            <?php endif; ?>
                            autocomplete="off">
                    </div>
                    <div class="gas-acc-stmt-field gas-acc-stmt-field--code">
                        <label for="gl_m_acc_code">كود الحساب</label>
                        <input type="text" id="gl_m_acc_code" autocomplete="off" readonly
                            class="admin-inp gas-acc-stmt-acc-code-input gl-m-acc-code-inp"
                            placeholder="نقرتان للاختيار"
                            title="نقرتان للاختيار"
                            value="<?php echo htmlspecialchars($accCodeDisp, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en">
                    </div>
                    <div class="gas-acc-stmt-field gas-acc-stmt-field--name">
                        <label for="gl_m_acc_name">اسم الحساب</label>
                        <input type="text" id="gl_m_acc_name" tabindex="-1" readonly autocomplete="off"
                            class="admin-inp gas-acc-stmt-acc-name-input gl-m-acc-name-inp"
                            placeholder="—" title="يُعبأ بعد اختيار الحساب" value="<?php echo htmlspecialchars($accNameDisp, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="gas-acc-stmt-actions">
                        <button type="submit">عرض</button>
                        <?php if ($useVouchers && $fyId > 0 && $accountId > 0 && $periodLabel !== ''): ?>
                            <button type="button" class="btn-secondary" onclick="window.print()">طباعة</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
        <?php if ($fyYmBoundMin !== ''): ?>
            <p class="card-hint" style="margin-top:12px;margin-bottom:0;">الفترة أشهر تقويمية كاملة ضمن السنة؛ تُقصّ تاريخ السند عند التقاطع مع بداية أو نهاية السنة المالية إن لزم.</p>
        <?php endif; ?>
    </div>

<div class="gl-pick-modal gl-acc-stmt-no-print" id="gl_m_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="gl_m_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="gl_m_pick_title">
        <h3 id="gl_m_pick_title" class="gl-pick-modal__title">اختيار حساب فرعي</h3>
        <p class="gl-pick-modal__hint muted" style="margin:0 0 8px;font-size:0.9rem;">نقرتان للاختيار</p>
        <input type="search" id="gl_m_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بالكود أو الاسم…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="gl_m_pick_list"></ul>
        <button type="button" class="btn-secondary" id="gl_m_pick_close">إغلاق</button>
    </div>
</div>

<script>
(function () {
    var GL_M_SEARCH_LEAVES = <?php echo json_encode(storefront_public_path('/admin/api/accounts/search-leaves.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    var seq = 0;
    var tmr = null;
    function glMPickClose() {
        var pm = document.getElementById('gl_m_pick_modal');
        if (pm) {
            pm.hidden = true;
            pm.setAttribute('aria-hidden', 'true');
        }
        document.body.classList.remove('gl-pick-open');
    }
    function glMPickLoad(q) {
        var mySeq = ++seq;
        var pickList = document.getElementById('gl_m_pick_list');
        if (!pickList) {
            return;
        }
        fetch(GL_M_SEARCH_LEAVES + '?q=' + encodeURIComponent(q || ''), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (mySeq !== seq) {
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
                    function choose() {
                        var hid = document.getElementById('gl_m_account_id');
                        var cd = document.getElementById('gl_m_acc_code');
                        var nm = document.getElementById('gl_m_acc_name');
                        if (hid) { hid.value = String(a.id || '0'); }
                        if (cd) { cd.value = a.code || ''; }
                        if (nm) { nm.value = a.name || ''; }
                        glMPickClose();
                    }
                    li.addEventListener('click', choose);
                    li.addEventListener('keydown', function (ev) {
                        if (ev.key === 'Enter' || ev.key === ' ') {
                            ev.preventDefault();
                            choose();
                        }
                    });
                    pickList.appendChild(li);
                });
            })
            .catch(function (e) {
                pickList.innerHTML = '<li class="gl-pick-empty">' + (e.message || String(e)) + '</li>';
            });
    }
    function glMPickOpen() {
        var pm = document.getElementById('gl_m_pick_modal');
        var pq = document.getElementById('gl_m_pick_q');
        var pl = document.getElementById('gl_m_pick_list');
        if (!pm || !pq || !pl) {
            return;
        }
        pq.value = '';
        pl.innerHTML = '';
        glMPickLoad('');
        pm.hidden = false;
        pm.setAttribute('aria-hidden', 'false');
        document.body.classList.add('gl-pick-open');
        pq.focus();
    }
    document.addEventListener('DOMContentLoaded', function () {
        var codeIn = document.getElementById('gl_m_acc_code');
        if (codeIn) {
            codeIn.addEventListener('dblclick', function (e) {
                e.preventDefault();
                glMPickOpen();
            });
        }
        var pq = document.getElementById('gl_m_pick_q');
        if (pq && !pq.getAttribute('data-bound')) {
            pq.setAttribute('data-bound', '1');
            pq.addEventListener('input', function () {
                if (tmr) {
                    clearTimeout(tmr);
                }
                tmr = setTimeout(function () {
                    glMPickLoad(pq.value.trim());
                }, 280);
            });
        }
        var bd = document.getElementById('gl_m_pick_backdrop');
        var cl = document.getElementById('gl_m_pick_close');
        if (bd) {
            bd.addEventListener('click', glMPickClose);
        }
        if (cl) {
            cl.addEventListener('click', glMPickClose);
        }
        document.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Escape') {
                return;
            }
            var gm = document.getElementById('gl_m_pick_modal');
            if (gm && !gm.hidden) {
                glMPickClose();
            }
        }, true);
    });
})();
</script>

<?php if (!$useVouchers): ?>
<div class="card admin-fy-card">
    <p class="muted">سندات اليومية غير جاهزة بعد — لا يمكن عرض التقرير.</p>
</div>
<?php elseif ($fyId <= 0 || !$fyRow): ?>
<div class="card admin-fy-card">
    <p class="muted"><?php echo $fyId <= 0
        ? 'عرّف سنة مالية من «السنوات المالية».'
        : 'السنة المالية المطلوبة غير موجودة — اختر سنة من القائمة.'; ?></p>
</div>
<?php elseif ($fyRow && ($periodYmFrom === '' || $periodYmTo === '')): ?>
<div class="card admin-fy-card">
    <p class="muted">تعذر تحديد نطاق أشهر من تواريخ السنة المالية — راجع «السنوات المالية».</p>
</div>
<?php elseif ($accountId <= 0): ?>
<div class="card admin-fy-card">
    <p class="card-hint">اختر حساباً ثم اضغط «عرض».</p>
</div>
<?php elseif ($fyRow): ?>
<div class="card admin-fy-card gl-acc-stmt-print">
    <h3 class="card-title">النتيجة</h3>
    <p class="page-subtitle">
        <?php echo htmlspecialchars($accLabel !== '' ? $accLabel : ('#' . $accountId), ENT_QUOTES, 'UTF-8'); ?>
        —
        من شهر <?php echo htmlspecialchars($periodYmFrom, ENT_QUOTES, 'UTF-8'); ?>
        إلى <?php echo htmlspecialchars($periodYmTo, ENT_QUOTES, 'UTF-8'); ?>
        (حدود السندات: <?php echo htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?>)
    </p>
    <?php if ($monthlyRows === []): ?>
        <p class="muted">لا حركة على هذا الحساب في الأشهر المحددة ضمن الفترة (حسب السندات المرصودة لهذه السنة المالية).</p>
    <?php else: ?>
    <div class="table-wrap admin-fy-table-wrap">
        <table class="admin-fy-table">
            <thead>
                <tr>
                    <th>الشهر</th>
                    <th>مجموع مدين</th>
                    <th>مجموع دائن</th>
                    <th>صافي الشهر</th>
                    <th>رصيد متحرّك بعد الشهر</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthlyRows as $mr): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) ($mr['ym'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo number_format((float) ($mr['sum_debit'] ?? 0), 4); ?></td>
                        <td><?php echo number_format((float) ($mr['sum_credit'] ?? 0), 4); ?></td>
                        <td><?php echo number_format((float) ($mr['net_month'] ?? 0), 4); ?></td>
                        <td><?php echo number_format((float) ($mr['balance_eom'] ?? 0), 4); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="card-hint" style="margin-top:12px;">
        الرصيد المتحرّك يتراكم حسب أشهر ظهور حركة فقط؛ لكشف سطراً بسطر استخدم «التقارير المالية» مع معامل حساب.
    </p>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="card admin-fy-card">
    <p class="muted">تعذر عرض التقرير لهذه الجلسة (حالة غير متوقعة) — حدِّث الصفحة ثم إن لزم اختر السنة والحساب من جديد.</p>
</div>
<?php endif; ?>

</div>
