<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/upload_paths.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

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

$yNow = (int) date('Y');
$mNow = (int) date('n');
/** سنة السيرفر؛ يوسِّع القائمة كل سنة — حد ثابت 2000/2100 لم يعود مستخدماً */
$yearMin = max(1900, $yNow - 120);
$yearMax = $yNow + 80;
$calYmMinBound = sprintf('%04d-01', $yearMin);
$calYmMaxBound = sprintf('%04d-12', $yearMax);

$ymFromGet = null;
if (isset($_GET['from_y'], $_GET['from_m'])) {
    $yf = (int) $_GET['from_y'];
    $mf = (int) $_GET['from_m'];
    if ($yf >= $yearMin && $yf <= $yearMax && $mf >= 1 && $mf <= 12) {
        $ymFromGet = sprintf('%04d-%02d', $yf, $mf);
    }
}
if ($ymFromGet === null && isset($_GET['m_from'])) {
    $ymFromGet = $normalizeYm((string) $_GET['m_from']);
}

$ymToGet = null;
if (isset($_GET['to_y'], $_GET['to_m'])) {
    $yt = (int) $_GET['to_y'];
    $mt = (int) $_GET['to_m'];
    if ($yt >= $yearMin && $yt <= $yearMax && $mt >= 1 && $mt <= 12) {
        $ymToGet = sprintf('%04d-%02d', $yt, $mt);
    }
}
if ($ymToGet === null && isset($_GET['m_to'])) {
    $ymToGet = $normalizeYm((string) $_GET['m_to']);
}

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

/** قيم مختارة لقوائم سنة/شهر */
$selYFrom = (int) substr($periodYmFrom, 0, 4);
$selMFrom = substr($periodYmFrom, 5, 2);
$selYTo = (int) substr($periodYmTo, 0, 4);
$selMTo = substr($periodYmTo, 5, 2);

$yearUiMin = min($yearMin, $selYFrom, $selYTo);
$yearUiMax = max($yearMax, $selYFrom, $selYTo);

$gregMonthsAr = [
    '01' => 'كانون الثاني',
    '02' => 'شباط',
    '03' => 'آذار',
    '04' => 'نيسان',
    '05' => 'أيار',
    '06' => 'حزيران',
    '07' => 'تمّوز',
    '08' => 'آب',
    '09' => 'أيلول',
    '10' => 'تشرين الأوّل',
    '11' => 'تشرين الثاني',
    '12' => 'كانون الأول',
];

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
           AND jv.voucher_date >= ?
           AND jv.voucher_date <= ?
         GROUP BY ym
         ORDER BY ym ASC'
    );
    $st->execute([$accountId, $periodDateFrom, $periodDateTo]);
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
                    <div class="gas-acc-stmt-field gl-m-stmt-field--period-range">
                        <span class="gl-m-period-heading">من شهر</span>
                        <div class="gl-m-ym-pair" role="group" aria-label="من شهر: اختر السنة ثم الشهر">
                            <div class="gl-m-ym-unit">
                                <label for="gl_from_y">سنة</label>
                                <select name="from_y" id="gl_from_y" class="admin-inp" autocomplete="off"
                                    dir="ltr" lang="en">
                                    <?php for ($yy = $yearUiMin; $yy <= $yearUiMax; ++$yy): ?>
                                        <option value="<?php echo (int) $yy; ?>"<?php echo $yy === $selYFrom ? ' selected' : ''; ?>><?php echo (int) $yy; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="gl-m-ym-unit">
                                <label for="gl_from_m">شهر</label>
                                <select name="from_m" id="gl_from_m" class="admin-inp" autocomplete="off">
                                    <?php foreach ($gregMonthsAr as $mv => $mLabel): ?>
                                        <option value="<?php echo htmlspecialchars($mv, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $mv === $selMFrom ? ' selected' : ''; ?>><?php echo htmlspecialchars($mLabel . ' (' . $mv . ')', ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="gas-acc-stmt-field gl-m-stmt-field--period-range">
                        <span class="gl-m-period-heading">إلى شهر</span>
                        <div class="gl-m-ym-pair" role="group" aria-label="إلى شهر: اختر السنة ثم الشهر">
                            <div class="gl-m-ym-unit">
                                <label for="gl_to_y">سنة</label>
                                <select name="to_y" id="gl_to_y" class="admin-inp" autocomplete="off"
                                    dir="ltr" lang="en">
                                    <?php for ($yy = $yearUiMin; $yy <= $yearUiMax; ++$yy): ?>
                                        <option value="<?php echo (int) $yy; ?>"<?php echo $yy === $selYTo ? ' selected' : ''; ?>><?php echo (int) $yy; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="gl-m-ym-unit">
                                <label for="gl_to_m">شهر</label>
                                <select name="to_m" id="gl_to_m" class="admin-inp" autocomplete="off">
                                    <?php foreach ($gregMonthsAr as $mv => $mLabel): ?>
                                        <option value="<?php echo htmlspecialchars($mv, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $mv === $selMTo ? ' selected' : ''; ?>><?php echo htmlspecialchars($mLabel . ' (' . $mv . ')', ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
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
        <p class="card-hint" style="margin-top:12px;margin-bottom:0;">
            تقرير تقويمي: أول وآخر يوم للشهرين المختارين؛ يجمّع أي سند سندًا لـ<strong>تاريخ السند</strong> ضمن المدى (قد تشمل أكثر من سنة مالية). الأشهر بلا حركة لا صفوف لها.
            <span class="muted" style="display:block;margin-top:8px;font-size:0.9rem;">نطاق السنوات يتوسّع تلقائياً مع سنة السيرفر؛ لسنوات خارج القائمة استخدم رابطاً بصيغة <code dir="ltr">m_from=YYYY-MM&amp;m_to=YYYY-MM</code>. روابط <code dir="ltr">m_from</code> / <code dir="ltr">m_to</code> القديمة لا تزال تعمل.</span>
        </p>
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
<?php elseif ($accountId <= 0): ?>
<div class="card admin-fy-card">
    <p class="card-hint">اختر حساباً ثم اضغط «عرض».</p>
</div>
<?php elseif ($periodLabel !== ''): ?>
<div class="card admin-fy-card gl-acc-stmt-print">
    <h3 class="card-title">النتيجة</h3>
    <p class="page-subtitle">
        <?php echo htmlspecialchars($accLabel !== '' ? $accLabel : ('#' . $accountId), ENT_QUOTES, 'UTF-8'); ?>
        —
        من شهر <?php echo htmlspecialchars($periodYmFrom, ENT_QUOTES, 'UTF-8'); ?>
        إلى <?php echo htmlspecialchars($periodYmTo, ENT_QUOTES, 'UTF-8'); ?>
        — حدود تاريخ السند: <?php echo htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?>
    </p>
    <?php if ($monthlyRows === []): ?>
        <p class="muted">لا حركة على هذا الحساب في الأشهر المحددة (حسب تاريخ السند في المدّة المختارة).</p>
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
    <p class="muted">تعذّر حساب مدى التقويم لهذه الجلسة.</p>
</div>
<?php endif; ?>

</div>
