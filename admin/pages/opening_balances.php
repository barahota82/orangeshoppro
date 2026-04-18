<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

/**
 * عرض تاريخ السنة المالية بصيغة يوم/شهر/سنة.
 */
function orange_opening_balance_format_dmY(string $ymd): string
{
    $ymd = trim($ymd);
    if ($ymd === '') {
        return '';
    }
    $t = strtotime($ymd);

    return $t !== false ? date('d/m/Y', $t) : $ymd;
}

$years = array_values(array_filter(orange_fiscal_years_list($pdo), static fn ($y) => (int) ($y['is_closed'] ?? 0) === 0));
$fyId = isset($_GET['fy']) ? (int) $_GET['fy'] : 0;
if ($fyId <= 0 && $years !== []) {
    $fyId = (int) $years[0]['id'];
}

$obInitial = [];
$obStatement = '';
if ($fyId > 0 && orange_journal_vouchers_ready($pdo)) {
    $vst = $pdo->prepare(
        'SELECT id FROM journal_vouchers WHERE fiscal_year_id = ? AND entry_type = ? ORDER BY id DESC LIMIT 1'
    );
    $vst->execute([$fyId, 'opening_balance']);
    $obVid = (int) $vst->fetchColumn();
    if ($obVid > 0) {
        $vd = $pdo->prepare('SELECT description FROM journal_vouchers WHERE id = ? LIMIT 1');
        $vd->execute([$obVid]);
        $obStatement = trim((string) $vd->fetchColumn());
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
?>
<div class="page-title">
    <h1>أرصدة أول المدة المالية</h1>
</div>

<div class="card">
    <form method="get" action="" class="form-grid" style="align-items:end;">
        <input type="hidden" name="page" value="opening_balances">
        <div>
            <label for="ob_fy">السنة المالية (مفتوحة)</label>
            <select id="ob_fy" name="fy" onchange="this.form.submit()">
                <?php foreach ($years as $y): ?>
                    <?php
                    $sd = orange_opening_balance_format_dmY((string) ($y['start_date'] ?? ''));
                    $ed = orange_opening_balance_format_dmY((string) ($y['end_date'] ?? ''));
                    $range = $sd !== '' && $ed !== '' ? $sd . ' — ' . $ed : '';
                    ?>
                    <option value="<?php echo (int) $y['id']; ?>" <?php echo ((int) $y['id'] === $fyId) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(trim($y['label_ar'] . ($range !== '' ? ' (' . $range . ')' : '')), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <?php if ($years === []): ?>
        <p class="card-hint">لا توجد سنة مفتوحة — افتح سنة من <a href="/admin/index.php?page=fiscal_years">السنوات المالية</a>.</p>
    <?php endif; ?>
</div>

<?php if ($fyId > 0 && $years !== []): ?>
<div class="card ob-opening-card">
    <h3 class="card-title">أسطر الأرصدة المالية</h3>
    <div class="ob-opening-summary" style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin:0 0 12px;">
        <span class="card-hint" id="ob_hint_totals" style="margin:0;">مجموع المدين: 0 — مجموع الدائن: 0</span>
        <label for="ob_statement" style="display:inline-flex;align-items:center;gap:8px;margin:0;">
            <span>البيان</span>
            <input type="text" id="ob_statement" class="gl-inp-code" style="min-width:18rem;max-width:32rem;" dir="rtl" autocomplete="off" value="<?php echo htmlspecialchars($obStatement, ENT_QUOTES, 'UTF-8'); ?>" aria-required="true">
        </label>
    </div>
    <div class="table-wrap ob-opening-table-wrap">
        <table class="ob-opening-table">
            <thead>
                <tr>
                    <th class="ob-th-code">كود الحساب</th>
                    <th class="ob-th-name">اسم الحساب</th>
                    <th>مدين</th>
                    <th>دائن</th>
                    <th aria-label="حذف"></th>
                </tr>
            </thead>
            <tbody id="ob_body"></tbody>
        </table>
    </div>
    <div class="actions" style="margin-top:10px;">
        <button type="button" class="btn-secondary" id="ob_btn_add">إضافة سطر</button>
        <button type="button" id="ob_btn_save">حفظ الرصيد الافتتاحي</button>
    </div>
</div>

<div class="gl-pick-modal" id="ob_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="ob_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="ob_pick_title">
        <h3 id="ob_pick_title" class="gl-pick-modal__title">اختيار حساب فرعي</h3>
        <input type="search" id="ob_pick_q" class="gl-pick-modal__search" placeholder="ابحث بالكود أو الاسم…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="ob_pick_list"></ul>
        <button type="button" class="btn-secondary" id="ob_pick_close">إغلاق</button>
    </div>
</div>
<?php endif; ?>

<script>
var OB_FY = <?php echo (int) $fyId; ?>;
var OB_INITIAL = <?php echo json_encode($obInitial, JSON_UNESCAPED_UNICODE); ?>;

(function () {
    var pickModal = document.getElementById('ob_pick_modal');
    var pickList = document.getElementById('ob_pick_list');
    var pickQ = document.getElementById('ob_pick_q');
    var pickBackdrop = document.getElementById('ob_pick_backdrop');
    var pickClose = document.getElementById('ob_pick_close');
    var activeObPickTr = null;
    var obPickSeq = 0;
    var searchTimer = null;

    /** عند ربط حساب: اسم الحساب معطّل؛ عند مسح الكود يُفعَّل لإعادة الاختيار (مع بقاء الحقل للعرض فقط — لا إدخال يدوي للاسم). */
    function obSyncNameFieldState(tr) {
        var n = tr.querySelector('.ob-inp-name');
        if (!n) {
            return;
        }
        var id = parseInt(tr.getAttribute('data-account-id'), 10) || 0;
        n.readOnly = true;
        n.disabled = id > 0;
        n.setAttribute('tabindex', id > 0 ? '-1' : '0');
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
                    li.addEventListener('click', function () {
                        if (activeObPickTr) {
                            obFillAccount(activeObPickTr, { id: a.id, code: code, name: a.name || '' });
                        }
                        obClosePick();
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

    function obWireCodeRow(tr) {
        var codeInp = tr.querySelector('.ob-inp-code');
        if (!codeInp) {
            return;
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
        var btn = tr.querySelector('.ob-search-btn');
        if (btn) {
            btn.addEventListener('click', function () {
                obOpenPick(tr);
            });
        }
    }
    window.obAdd = function (preset) {
        var tb = document.getElementById('ob_body');
        if (!tb) {
            return;
        }
        var tr = document.createElement('tr');
        tr.setAttribute('data-account-id', '0');
        tr.innerHTML =
            '<td><input type="text" class="gl-inp-code ob-inp-code" dir="ltr" autocomplete="off" value="" aria-label="كود الحساب"></td>' +
            '<td><div class="gl-name-row">' +
            '<button type="button" class="gl-search-btn ob-search-btn" title="بحث — حسابات فرعية فقط" aria-label="بحث">🔍</button>' +
            '<input type="text" class="gl-inp-name ob-inp-name" readonly value="" aria-label="اسم الحساب">' +
            '</div></td>' +
            '<td><input type="number" class="ob-d admin-inp-money" step="any" min="0" value="" inputmode="decimal" lang="en" dir="ltr" aria-label="مدين" placeholder="0.000"></td>' +
            '<td><input type="number" class="ob-c admin-inp-money" step="any" min="0" value="" inputmode="decimal" lang="en" dir="ltr" aria-label="دائن" placeholder="0.000"></td>' +
            '<td><button type="button" class="btn-secondary ob-row-del">حذف</button></td>';
        tb.appendChild(tr);
        tr.querySelector('.ob-row-del').addEventListener('click', function () {
            tr.remove();
            obRecalc();
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
        var el = document.getElementById('ob_hint_totals');
        if (!el) {
            return;
        }
        var sd = 0;
        var sc = 0;
        document.querySelectorAll('#ob_body tr').forEach(function (tr) {
            sd += parseFloat(String((tr.querySelector('.ob-d') || {}).value || '0').replace(',', '.'));
            sc += parseFloat(String((tr.querySelector('.ob-c') || {}).value || '0').replace(',', '.'));
        });
        var dec = window.OrangeMoney ? window.OrangeMoney.DECIMALS : 3;
        el.textContent = 'مجموع المدين: ' + sd.toFixed(dec) + ' — مجموع الدائن: ' + sc.toFixed(dec);
    };
    window.obSave = function () {
        if (OB_FY <= 0) {
            alert('اختر سنة');
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
        postJSON('/admin/api/opening_balances/save.php', { fiscal_year_id: OB_FY, statement: statement, lines: lines })
            .then(function (r) {
                alert(r.message || (r.success ? 'تم' : 'فشل'));
                if (r.success) {
                    location.reload();
                }
            })
            .catch(function (e) { alert(e.message || String(e)); });
    };

    var tb = document.getElementById('ob_body');
    if (tb) {
        document.getElementById('ob_btn_add').addEventListener('click', function () { obAdd(); });
        document.getElementById('ob_btn_save').addEventListener('click', obSave);
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
        if (Array.isArray(OB_INITIAL) && OB_INITIAL.length > 0) {
            OB_INITIAL.forEach(function (row) {
                obAdd(row);
            });
        } else {
            obAdd();
            obAdd();
        }
    }
})();
</script>
