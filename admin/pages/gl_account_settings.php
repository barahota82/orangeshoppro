<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/journal_types.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$accountsRows = $pdo->query(
    'SELECT id, name, code FROM accounts ORDER BY COALESCE(code, \'\'), name ASC'
)->fetchAll(PDO::FETCH_ASSOC);
$byId = [];
foreach ($accountsRows as $a) {
    $byId[(int) $a['id']] = $a;
}

$current = [];
if (orange_table_exists($pdo, 'orange_gl_account_settings')) {
    $rows = $pdo->query('SELECT setting_key, account_id FROM orange_gl_account_settings')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $current[(string) $r['setting_key']] = (int) $r['account_id'];
    }
}

$journalTypesList = orange_journal_types_list($pdo);
$rowTitles = orange_gl_setting_row_short_labels();
$keyHints = orange_gl_setting_key_labels();
$orderedKeys = orange_gl_settings_ui_key_order();
$journalRules = orange_gl_journal_type_rules_list($pdo);
$allowedGlKeys = orange_gl_allowed_setting_keys();
$ruleKeyOrder = array_values(array_unique(array_merge(
    $orderedKeys,
    array_values(array_diff($allowedGlKeys, $orderedKeys))
)));

$jtJson = json_encode($journalTypesList, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$keysJson = json_encode($orderedKeys, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$shortJson = json_encode($rowTitles, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$hintsJson = json_encode($keyHints, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$ruleKeysJson = json_encode($ruleKeyOrder, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$rulesJson = json_encode($journalRules, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$legalReservePct = orange_gl_setting_alloc_percent($pdo, 'legal_reserve');
?>
<div class="page-title page-title--stacked">
    <h1>حسابات القيود التلقائية</h1>
    <p class="page-subtitle">
        <strong>الجزء الأول:</strong> ربط كل بند بحساب فرعي من الدليل (بدون نوع يومية هنا).
        لا يُعرض هنا «مصروف عام» أو «ذمم موردين مجمّعة» — المصروفات تُربط لكل بند من شاشة المصروفات، والشراء الآجل على حساب ذمة كل مورد.
        صف <strong>الاحتياطي القانوني</strong> فقط يعرض حقل النسبة بجانب اسم البند (بدون عمود منفصل) — تُقرأ برمجياً من أرباح السنة الحالية بعد إقفال القائمة لقيود الإقفال.
        <strong>الجزء الثاني:</strong> لكل نوع يومية اختر بنداً للمدين وبنداً للدائن — يمكن تضمين مفاتيح احتياطية في القائمة المنسدلة إن وُجدت في قاعدة البيانات لقواعد قديمة.
    </p>
</div>

<div class="card gl-auto-form-card">
        <h3 class="card-title">١ — البنود والحسابات من الدليل</h3>
        <div class="table-wrap gl-settings-table-wrap">
            <table class="gl-settings-table">
                <thead>
                    <tr>
                        <th class="gl-th-label">البند</th>
                        <th class="gl-th-code">كود الحساب</th>
                        <th class="gl-th-name">اسم الحساب</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orderedKeys as $key):
                        $aid = (int) ($current[$key] ?? 0);
                        $code = $aid > 0 ? (string) ($byId[$aid]['code'] ?? '') : '';
                        $name = $aid > 0 ? (string) ($byId[$aid]['name'] ?? '') : '';
                        $title = htmlspecialchars($keyHints[$key] ?? '', ENT_QUOTES, 'UTF-8');
                        $short = htmlspecialchars($rowTitles[$key] ?? $key, ENT_QUOTES, 'UTF-8');
                        ?>
                    <tr data-gl-key="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" data-account-id="<?php echo $aid; ?>" title="<?php echo $title; ?>">
                        <td class="gl-td-label<?php echo $key === 'legal_reserve' ? ' gl-td-label--legal-pct' : ''; ?>">
                            <?php if ($key === 'legal_reserve'): ?>
                            <div class="gl-legal-pct-inline" title="من أرباح السنة الحالية بعد إقفال القائمة — للاحتياطي القانوني">
                                <span class="gl-legal-pct-inline__name"><?php echo $short; ?></span>
                                <label class="gl-legal-pct-inline__field">
                                    <span class="gl-legal-pct-inline__hint">نسبة</span>
                                    <input type="number" class="gl-inp-alloc-pct" min="0" max="100" step="0.01" inputmode="decimal" lang="en" dir="ltr"
                                        value="<?php echo htmlspecialchars((string) $legalReservePct, ENT_QUOTES, 'UTF-8'); ?>"
                                        title="نسبة من أرباح السنة الحالية (بعد إقفال الإيرادات والمصروفات) تُخصَّص للاحتياطي القانوني — للاستخدام في قيود الإقفال"
                                        aria-label="نسبة الاحتياطي القانوني من أرباح السنة الحالية">
                                    <span class="gl-legal-pct-inline__unit" aria-hidden="true">%</span>
                                </label>
                            </div>
                            <?php else: ?>
                            <?php echo $short; ?>
                            <?php endif; ?>
                        </td>
                        <td class="gl-td-code">
                            <input type="text" class="gl-inp-code" dir="ltr" autocomplete="off" value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" aria-label="كود الحساب">
                        </td>
                        <td class="gl-td-name">
                            <div class="gl-name-row">
                                <button type="button" class="gl-search-btn" title="بحث — حسابات فرعية فقط" aria-label="بحث">🔍</button>
                                <input type="text" class="gl-inp-name" readonly value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" aria-label="اسم الحساب"<?php echo $aid > 0 ? ' disabled' : ''; ?> tabindex="<?php echo $aid > 0 ? '-1' : '0'; ?>">
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
</div>

<div class="card gl-auto-form-card" style="margin-top:1rem;">
        <h3 class="card-title">٢ — ربط نوع اليومية ببند مدين وبند دائن</h3>
        <div class="table-wrap gl-settings-table-wrap">
            <table class="gl-settings-table" id="gl_jt_rules_table">
                <thead>
                    <tr>
                        <th>نوع اليومية</th>
                        <th>بند المدين</th>
                        <th>بند الدائن</th>
                        <th class="gl-th-actions" aria-label="إزالة"></th>
                    </tr>
                </thead>
                <tbody id="gl_jt_rules_body"></tbody>
            </table>
        </div>
        <div class="gl-auto-actions" style="margin-top:0.75rem;">
            <button type="button" class="btn-secondary" id="gl_btn_add_rule">إضافة قاعدة</button>
            <button type="button" id="gl_btn_save">حفظ الكل</button>
        </div>
</div>

<div class="gl-pick-modal" id="gl_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="gl_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="gl_pick_title">
        <h3 id="gl_pick_title" class="gl-pick-modal__title">اختيار حساب فرعي</h3>
        <input type="search" id="gl_pick_q" class="gl-pick-modal__search" placeholder="ابحث بالكود أو الاسم…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="gl_pick_list"></ul>
        <button type="button" class="btn-secondary" id="gl_pick_close">إغلاق</button>
    </div>
</div>

<script>
(function () {
    var glJournalTypes = <?php echo $jtJson; ?>;
    var glUiKeyOrder = <?php echo $keysJson; ?>;
    var glKeyShort = <?php echo $shortJson; ?>;
    var glKeyHints = <?php echo $hintsJson; ?>;
    var glRuleKeyOrder = <?php echo $ruleKeysJson; ?>;
    var glInitialRules = <?php echo $rulesJson; ?>;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/"/g, '&quot;');
    }

    function keyOptionsHtml(selected) {
        var h = '<option value="">— بند —</option>';
        for (var i = 0; i < glRuleKeyOrder.length; i++) {
            var k = glRuleKeyOrder[i];
            var lab = glKeyShort[k] || glKeyHints[k] || k;
            h += '<option value="' + esc(k) + '"' + (k === selected ? ' selected' : '') + '>' + esc(lab) + '</option>';
        }
        return h;
    }

    function journalTypeOptionsHtml(selectedId, excludeIds) {
        var ex = excludeIds || {};
        var h = '<option value="0">— نوع يومية —</option>';
        for (var i = 0; i < glJournalTypes.length; i++) {
            var jt = glJournalTypes[i];
            var id = parseInt(jt.id, 10) || 0;
            if (id <= 0) {
                continue;
            }
            if (ex[id] && id !== selectedId) {
                continue;
            }
            var lab = (jt.name_ar || jt.name_en || jt.code || '').trim();
            h += '<option value="' + id + '"' + (id === selectedId ? ' selected' : '') + '>' + esc(lab) + '</option>';
        }
        return h;
    }

    function collectUsedJournalTypeIds(exceptTr) {
        var u = {};
        document.querySelectorAll('#gl_jt_rules_body tr[data-jt-rule]').forEach(function (tr) {
            if (tr === exceptTr) {
                return;
            }
            var s = tr.querySelector('.gl-sel-jt-id');
            var id = s ? parseInt(s.value, 10) || 0 : 0;
            if (id > 0) {
                u[id] = true;
            }
        });
        return u;
    }

    function refreshJournalTypeOptions(exceptTr) {
        var used = collectUsedJournalTypeIds(exceptTr);
        document.querySelectorAll('#gl_jt_rules_body tr[data-jt-rule]').forEach(function (tr) {
            var sel = tr.querySelector('.gl-sel-jt-id');
            if (!sel) {
                return;
            }
            var cur = parseInt(sel.value, 10) || 0;
            sel.innerHTML = journalTypeOptionsHtml(cur, used);
        });
    }

    function addRuleRow(jtId, dk, ck) {
        jtId = jtId || 0;
        dk = dk || '';
        ck = ck || '';
        var tbody = document.getElementById('gl_jt_rules_body');
        if (!tbody) {
            return;
        }
        var tr = document.createElement('tr');
        tr.setAttribute('data-jt-rule', '1');
        var used = collectUsedJournalTypeIds(null);
        tr.innerHTML =
            '<td><select class="gl-sel-jt-id" aria-label="نوع اليومية">' + journalTypeOptionsHtml(jtId, used) + '</select></td>' +
            '<td><select class="gl-sel-debit-key" aria-label="بند المدين">' + keyOptionsHtml(dk) + '</select></td>' +
            '<td><select class="gl-sel-credit-key" aria-label="بند الدائن">' + keyOptionsHtml(ck) + '</select></td>' +
            '<td><button type="button" class="btn-secondary gl-btn-remove-rule">حذف</button></td>';
        tbody.appendChild(tr);
        var jtSel = tr.querySelector('.gl-sel-jt-id');
        if (jtSel) {
            jtSel.addEventListener('change', function () {
                refreshJournalTypeOptions(null);
            });
        }
        tr.querySelector('.gl-btn-remove-rule').addEventListener('click', function () {
            tr.remove();
            refreshJournalTypeOptions(null);
        });
        refreshJournalTypeOptions(null);
    }

    if (glInitialRules && glInitialRules.length) {
        glInitialRules.forEach(function (r) {
            addRuleRow(
                parseInt(r.journal_type_id, 10) || 0,
                String(r.debit_setting_key || ''),
                String(r.credit_setting_key || '')
            );
        });
    }

    document.getElementById('gl_btn_add_rule').addEventListener('click', function () {
        addRuleRow(0, '', '');
    });

    var pickModal = document.getElementById('gl_pick_modal');
    var pickList = document.getElementById('gl_pick_list');
    var pickQ = document.getElementById('gl_pick_q');
    var pickBackdrop = document.getElementById('gl_pick_backdrop');
    var pickClose = document.getElementById('gl_pick_close');
    var activePickKey = null;
    var searchTimer = null;
    var glPickSeq = 0;

    function glSyncNameFieldState(tr) {
        var n = tr.querySelector('.gl-inp-name');
        if (!n) {
            return;
        }
        var id = parseInt(tr.getAttribute('data-account-id'), 10) || 0;
        n.readOnly = true;
        n.disabled = id > 0;
        n.setAttribute('tabindex', id > 0 ? '-1' : '0');
    }
    function glFillRow(tr, acc) {
        if (!tr || !acc) {
            return;
        }
        tr.setAttribute('data-account-id', String(acc.id));
        var c = tr.querySelector('.gl-inp-code');
        var n = tr.querySelector('.gl-inp-name');
        if (c) {
            c.value = acc.code || '';
        }
        if (n) {
            n.value = acc.name || '';
        }
        glSyncNameFieldState(tr);
    }
    function glClearRow(tr) {
        tr.setAttribute('data-account-id', '0');
        var c = tr.querySelector('.gl-inp-code');
        var n = tr.querySelector('.gl-inp-name');
        if (c) {
            c.value = '';
        }
        if (n) {
            n.value = '';
        }
        glSyncNameFieldState(tr);
    }
    function glStripResolvedRow(tr) {
        glClearRow(tr);
    }
    function openPick(key) {
        activePickKey = key;
        pickModal.hidden = false;
        pickModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('gl-pick-open');
        pickQ.value = '';
        pickList.innerHTML = '';
        glPickLoad('');
        pickQ.focus();
    }
    function closePick() {
        activePickKey = null;
        pickModal.hidden = true;
        pickModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('gl-pick-open');
    }
    function glPickLoad(q) {
        var mySeq = ++glPickSeq;
        var url = '/admin/api/accounts/search-leaves.php?q=' + encodeURIComponent(q || '');
        fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (mySeq !== glPickSeq) {
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
                    var label = (code ? code + ' — ' : '') + (a.name || '');
                    li.textContent = label;
                    li.setAttribute('role', 'button');
                    li.tabIndex = 0;
                    li.addEventListener('click', function () {
                        if (!activePickKey) {
                            return;
                        }
                        var tr = document.querySelector('tr[data-gl-key="' + activePickKey + '"]');
                        if (tr) {
                            glFillRow(tr, { id: a.id, code: code, name: a.name || '' });
                        }
                        closePick();
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

    document.querySelectorAll('tr[data-gl-key]').forEach(function (tr) {
        var codeInp = tr.querySelector('.gl-inp-code');
        if (codeInp) {
            var glLookupInFlight = false;
            codeInp.addEventListener('input', function () {
                if (!String(codeInp.value || '').trim()) {
                    glClearRow(tr);
                }
            });
            codeInp.addEventListener('change', function () {
                var raw = codeInp.value.trim();
                if (!raw) {
                    glClearRow(tr);
                    return;
                }
                glLookupInFlight = true;
                fetch('/admin/api/accounts/lookup-by-code.php?code=' + encodeURIComponent(raw), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.success) {
                            glStripResolvedRow(tr);
                            return;
                        }
                        glFillRow(tr, data.account);
                    })
                    .catch(function () {
                        glStripResolvedRow(tr);
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
                    var nameEl = tr.querySelector('.gl-inp-name');
                    var nameTxt = nameEl ? String(nameEl.value || '').trim() : '';
                    if (raw !== '' && id <= 0 && nameTxt === '') {
                        glClearRow(tr);
                    }
                }, 0);
            });
        }
        var btn = tr.querySelector('.gl-search-btn');
        if (btn) {
            btn.addEventListener('click', function () {
                var key = tr.getAttribute('data-gl-key');
                if (key) {
                    openPick(key);
                }
            });
        }
        glSyncNameFieldState(tr);
    });

    pickQ.addEventListener('input', function () {
        if (searchTimer) {
            clearTimeout(searchTimer);
        }
        searchTimer = setTimeout(function () {
            glPickLoad(pickQ.value.trim());
        }, 280);
    });
    pickBackdrop.addEventListener('click', closePick);
    pickClose.addEventListener('click', closePick);

    document.getElementById('gl_btn_save').addEventListener('click', function () {
        var incomplete = false;
        document.querySelectorAll('tr[data-gl-key]').forEach(function (tr) {
            var codeEl = tr.querySelector('.gl-inp-code');
            var codeTxt = codeEl ? String(codeEl.value || '').trim() : '';
            var id = parseInt(tr.getAttribute('data-account-id'), 10) || 0;
            if (codeTxt !== '' && id <= 0) {
                incomplete = true;
            }
        });
        if (incomplete) {
            alert('يوجد كود مكتوب دون حساب فرعي — إمّا اختر حساباً فرعياً (يظهر الاسم) أو امسح الكود.');
            return;
        }
        var settings = {};
        document.querySelectorAll('tr[data-gl-key]').forEach(function (tr) {
            var k = tr.getAttribute('data-gl-key');
            if (!k) {
                return;
            }
            settings[k] = parseInt(tr.getAttribute('data-account-id'), 10) || 0;
        });
        var journalRules = [];
        document.querySelectorAll('#gl_jt_rules_body tr[data-jt-rule]').forEach(function (tr) {
            var jt = parseInt((tr.querySelector('.gl-sel-jt-id') || {}).value, 10) || 0;
            var dk = String((tr.querySelector('.gl-sel-debit-key') || {}).value || '').trim();
            var ck = String((tr.querySelector('.gl-sel-credit-key') || {}).value || '').trim();
            if (jt <= 0 && dk === '' && ck === '') {
                return;
            }
            journalRules.push({
                journal_type_id: jt,
                debit_setting_key: dk,
                credit_setting_key: ck
            });
        });
        var allocPercents = {};
        var pctInp = document.querySelector('tr[data-gl-key="legal_reserve"] .gl-inp-alloc-pct');
        if (pctInp) {
            var pv = parseFloat(String(pctInp.value || '').replace(',', '.'), 10);
            if (!isNaN(pv) && pv > 0) {
                allocPercents.legal_reserve = pv;
            } else {
                allocPercents.legal_reserve = '';
            }
        }
        postJSON('/admin/api/settings/gl-accounts.php', {
            action: 'save',
            settings: settings,
            journal_rules: journalRules,
            alloc_percents: allocPercents
        }).then(function (res) {
            alert(res.message || (res.success ? 'تم' : 'فشل'));
            if (res.success) {
                location.reload();
            }
        }).catch(function (e) { alert(e.message || String(e)); });
    });
})();
</script>
