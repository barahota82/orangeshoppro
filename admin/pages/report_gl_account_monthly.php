<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/upload_paths.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/company_settings.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$accountId = isset($_GET['account']) ? (int) $_GET['account'] : 0;

$leafWhere = orange_accounts_posting_leaf_where_sql($pdo, 'a');
$accounts = orange_accounts_fetch(
    $pdo,
    "SELECT a.id, a.name, a.code FROM accounts a WHERE $leafWhere ORDER BY COALESCE(a.code, ''), a.name",
    [],
    'a'
);

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

$firstDayOfYm = static function (string $ym): string {
    return $ym . '-01';
};

$lastDayOfYm = static function (string $ym): string {
    $d0 = $ym . '-01';
    $t = strtotime($d0 . ' 12:00:00');

    return $t ? date('Y-m-t', $t) : $ym . '-28';
};

$useVouchers = orange_journal_vouchers_ready($pdo);
$monthlyRows = [];
$periodLabel = '';
$periodYmFrom = '';
$periodYmTo = '';
$periodDateFrom = '';
$periodDateTo = '';

/** نطاق مسموح لحقول شهر/سنة (تقييد واجهة فقط؛ التاريخ يُقيَّد أيضاً قبل الاستعلام) */
$calYmMinBound = '2000-01';
$calYmMaxBound = '2100-12';

$yNow = (int) date('Y');
$mNow = (int) date('n');
$defaultYmJan = sprintf('%04d-01', $yNow);
$defaultYmToday = sprintf('%04d-%02d', $yNow, $mNow);

/** إن لم تُرسَل أشهر في الرابط استخدم كانون الثاني الحالي إلى الشهر الجاري؛ وإلا املأ الغائص لاحقاً. */
$ymFrom = $ymFromGet ?? $defaultYmJan;
$ymTo = $ymToGet ?? $defaultYmToday;
if ($ymFrom < $calYmMinBound) {
    $ymFrom = $calYmMinBound;
}
if ($ymFrom > $calYmMaxBound) {
    $ymFrom = $calYmMaxBound;
}
if ($ymTo < $calYmMinBound) {
    $ymTo = $calYmMinBound;
}
if ($ymTo > $calYmMaxBound) {
    $ymTo = $calYmMaxBound;
}
if ($ymFrom > $ymTo) {
    $swap = $ymFrom;
    $ymFrom = $ymTo;
    $ymTo = $swap;
}
$periodYmFrom = $ymFrom;
$periodYmTo = $ymTo;

$periodDateFrom = $firstDayOfYm($periodYmFrom);
$periodDateTo = $lastDayOfYm($periodYmTo);
if (strcmp($periodDateFrom, $periodDateTo) <= 0) {
    $periodLabel = $periodDateFrom . ' — ' . $periodDateTo;
}

if (
    $useVouchers && $accountId > 0 && $periodLabel !== ''
    && $periodDateFrom !== '' && $periodDateTo !== ''
    && strcmp($periodDateFrom, $periodDateTo) <= 0
) {
    $st = $pdo->prepare(
        'SELECT DATE_FORMAT(jv.voucher_date, \'%Y-%m\') AS ym,
                COALESCE(SUM(jl.debit), 0) AS sum_debit,
                COALESCE(SUM(jl.credit), 0) AS sum_credit
         FROM journal_lines jl
         INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id
         WHERE jl.account_id = ?
           AND DATE(jv.voucher_date) >= ?
           AND DATE(jv.voucher_date) <= ?
         GROUP BY ym
         ORDER BY ym ASC'
    );
    $st->execute([$accountId, $periodDateFrom, $periodDateTo]);
    $monthlyRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$openingBal = 0.0;
if (
    $useVouchers && $accountId > 0 && $periodDateFrom !== ''
    && strcmp($periodDateFrom, $periodDateTo) <= 0
) {
    $stOb = $pdo->prepare(
        'SELECT COALESCE(SUM(jl.debit), 0) AS sd, COALESCE(SUM(jl.credit), 0) AS sc
         FROM journal_lines jl
         INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id
         WHERE jl.account_id = ?
           AND DATE(jv.voucher_date) < ?'
    );
    $stOb->execute([$accountId, $periodDateFrom]);
    $obl = $stOb->fetch(PDO::FETCH_ASSOC);
    if (is_array($obl)) {
        $openingBal = (float) $obl['sd'] - (float) $obl['sc'];
    }
}

$glMonthlyMonthLabelAr = static function (string $ym): string {
    static $months = [
        '01' => 'يناير', '02' => 'فبراير', '03' => 'مارس', '04' => 'إبريل',
        '05' => 'مايو', '06' => 'يونيو', '07' => 'يوليو', '08' => 'أغسطس',
        '09' => 'سبتمبر', '10' => 'أكتوبر', '11' => 'نوفمبر', '12' => 'ديسمبر',
    ];
    if (! preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
        return $ym;
    }
    $mo = $m[2];

    return ($months[$mo] ?? $mo) . ' ' . $m[1];
};

$running = $openingBal;
$totalDebitPeriod = 0.0;
$totalCreditPeriod = 0.0;
$totalNetPeriod = 0.0;
foreach ($monthlyRows as &$mr) {
    $d = (float) $mr['sum_debit'];
    $c = (float) $mr['sum_credit'];
    $running += ($d - $c);
    $mr['net_month'] = $d - $c;
    $mr['balance_eom'] = $running;
    $totalDebitPeriod += $d;
    $totalCreditPeriod += $c;
    $totalNetPeriod += ($d - $c);
}
unset($mr);

$closingBal = $running;
$reportDateFromDmY = orange_format_date_dmY($periodDateFrom);
$reportDateToDmY = orange_format_date_dmY($periodDateTo);
$todayDmY = orange_format_date_dmY(date('Y-m-d'));
$printDatetime = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));

$companyNameAr = orange_company_settings_name_ar($pdo);


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
                    <div class="gas-acc-stmt-field gl-m-stmt-field--month">
                        <label for="gl_m_month_from">من شهر</label>
                        <input type="month" name="m_from" id="gl_m_month_from" class="admin-inp"
                            lang="en" dir="ltr"
                            value="<?php echo htmlspecialchars($periodYmFrom, ENT_QUOTES, 'UTF-8'); ?>"
                            min="<?php echo htmlspecialchars($calYmMinBound, ENT_QUOTES, 'UTF-8'); ?>"
                            max="<?php echo htmlspecialchars($calYmMaxBound, ENT_QUOTES, 'UTF-8'); ?>"
                            title="انقر الحقل؛ في منتقي المتصفّح انقر سنة الشهر أو استخدم الأسهم لتغيير السنة (2000–2100)."
                            autocomplete="off">
                    </div>
                    <div class="gas-acc-stmt-field gl-m-stmt-field--month">
                        <label for="gl_m_month_to">إلى شهر</label>
                        <input type="month" name="m_to" id="gl_m_month_to" class="admin-inp"
                            lang="en" dir="ltr"
                            value="<?php echo htmlspecialchars($periodYmTo, ENT_QUOTES, 'UTF-8'); ?>"
                            min="<?php echo htmlspecialchars($calYmMinBound, ENT_QUOTES, 'UTF-8'); ?>"
                            max="<?php echo htmlspecialchars($calYmMaxBound, ENT_QUOTES, 'UTF-8'); ?>"
                            title="انقر الحقل؛ في منتقي المتصفّح انقر سنة الشهر أو استخدم الأسهم لتغيير السنة (2000–2100)."
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
                        <?php if ($useVouchers && $accountId > 0 && $periodLabel !== ''): ?>
                            <button type="button" class="btn-secondary" onclick="window.print()">طباعة</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>

<?php if ($useVouchers && $accounts === []): ?>
    <div class="card admin-fy-card gl-acc-stmt-no-print" style="border:1px solid #fcd34d;background:#fffbeb;">
        <p class="muted" style="margin:0;line-height:1.55;"><strong>تنبيه:</strong> لا توجد حسابات ترحيل (أوراق) في الدليل بعد؛ اختر حساباً بعد إنشاء الدليل. <strong>الشاشة والفترات تعملان</strong>.</p>
    </div>
<?php endif; ?>

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
<?php elseif ($accountId <= 0): ?>
<div class="card admin-fy-card">
    <p class="card-hint">اختر حساباً ثم اضغط «عرض».</p>
</div>
<?php elseif ($periodLabel !== ''): ?>
<div class="card admin-fy-card gl-acc-stmt-print">
    <div class="gl-acc-stmt-print-sheet gl-m-monthly-print-sheet">
        <header class="gl-acc-stmt-print-banner">
            <?php if ($companyNameAr !== ''): ?>
                <p class="gl-acc-stmt-print-company"><?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <h2 class="gl-acc-stmt-print-title gl-m-monthly-print-title">
                <span class="gl-acc-stmt-print-title-ar" lang="ar">تقـــــــرير&nbsp;&nbsp;الحركة الشهرية لحساب عــن الفتــرة من <?php echo htmlspecialchars($reportDateFromDmY, ENT_QUOTES, 'UTF-8'); ?> إلى&nbsp;&nbsp;<?php echo htmlspecialchars($reportDateToDmY, ENT_QUOTES, 'UTF-8'); ?></span>
            </h2>
        </header>
        <div class="gl-acc-stmt-print-grid">
            <div class="gl-acc-stmt-print-row"><span class="gl-acc-stmt-print-k">رقـــم الحســاب</span><span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($accCodeDisp !== '' ? $accCodeDisp : '—', ENT_QUOTES, 'UTF-8'); ?></span></div>
            <div class="gl-acc-stmt-print-row"><span class="gl-acc-stmt-print-k">اسم الحســـــــــاب</span><span class="gl-acc-stmt-print-v"><?php echo htmlspecialchars($accNameDisp !== '' ? $accNameDisp : ($accLabel !== '' ? $accLabel : '—'), ENT_QUOTES, 'UTF-8'); ?></span></div>
            <div class="gl-acc-stmt-print-row gl-acc-stmt-print-row--dates">
                <span class="gl-acc-stmt-print-k">من تاريخ</span><span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($reportDateFromDmY, ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="gl-acc-stmt-print-k">الى تاريخ</span><span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($reportDateToDmY, ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="gl-acc-stmt-print-k">تاريخ الكشف</span><span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($todayDmY, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        </div>
        <div class="table-wrap admin-fy-table-wrap gl-acc-stmt-table-wrap">
            <table class="admin-fy-table gl-acc-stmt-table">
                <thead>
                    <tr>
                        <th>الشهر</th>
                        <th class="gl-acc-stmt-col-num">مديـــــن</th>
                        <th class="gl-acc-stmt-col-num">دائــــــن</th>
                        <th class="gl-acc-stmt-col-num">رصيد حركة الشهر</th>
                        <th class="gl-acc-stmt-col-num">الرصيد</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="gl-acc-stmt-row-opening">
                        <td>رصيد افتتاحي</td>
                        <td class="gl-acc-stmt-col-num">ـــــــــــ</td>
                        <td class="gl-acc-stmt-col-num">ـــــــــــ</td>
                        <td class="gl-acc-stmt-col-num">ـــــــــــ</td>
                        <td class="gl-acc-stmt-col-num"><?php echo number_format($openingBal, 4); ?></td>
                    </tr>
                    <?php if ($monthlyRows === []): ?>
                        <tr>
                            <td colspan="5" class="muted">لا حركة على هذا الحساب في الأشهر المحددة (حسب تاريخ السند في المدّة المختارة) بعد الرصيد الافتتاحي.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($monthlyRows as $mr): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($glMonthlyMonthLabelAr((string) ($mr['ym'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo number_format((float) ($mr['sum_debit'] ?? 0), 4); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo number_format((float) ($mr['sum_credit'] ?? 0), 4); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo number_format((float) ($mr['net_month'] ?? 0), 4); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo number_format((float) ($mr['balance_eom'] ?? 0), 4); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="gl-acc-stmt-foot-label">
                        <td class="gl-acc-stmt-foot-total-title">الإجمالى</td>
                        <td class="gl-acc-stmt-col-num"><?php echo number_format($totalDebitPeriod, 4); ?></td>
                        <td class="gl-acc-stmt-col-num"><?php echo number_format($totalCreditPeriod, 4); ?></td>
                        <td class="gl-acc-stmt-col-num"><?php echo number_format($totalNetPeriod, 4); ?></td>
                        <td class="gl-acc-stmt-col-num"><?php echo number_format($closingBal, 4); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="gl-acc-stmt-print-footer gl-m-monthly-print-footer">
            <p class="gl-acc-stmt-print-metafoot" dir="ltr">تاريخ ووقت الطباعة: <?php echo htmlspecialchars($printDatetime, ENT_QUOTES, 'UTF-8'); ?> — صفحة 1 من 1</p>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card admin-fy-card">
    <p class="muted">تعذّر حساب مدى التقويم لهذه الجلسة.</p>
</div>
<?php endif; ?>

</div>
