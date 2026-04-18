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
$currentJournalType = [];
if (orange_table_exists($pdo, 'orange_gl_account_settings')) {
    $hasJt = orange_table_has_column($pdo, 'orange_gl_account_settings', 'journal_type_id');
    $sql = $hasJt
        ? 'SELECT setting_key, account_id, journal_type_id FROM orange_gl_account_settings'
        : 'SELECT setting_key, account_id FROM orange_gl_account_settings';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $k = (string) $r['setting_key'];
        $current[$k] = (int) $r['account_id'];
        $currentJournalType[$k] = $hasJt ? (int) ($r['journal_type_id'] ?? 0) : 0;
    }
}

$journalTypesList = orange_journal_types_list($pdo);

$rowTitles = orange_gl_setting_row_short_labels();
$keyHints = orange_gl_setting_key_labels();
$orderedKeys = orange_gl_settings_form_key_order();
$labelsKeys = array_keys($keyHints);
$orderedKeys = array_values(array_filter($orderedKeys, static function ($k) use ($labelsKeys) {
    return in_array($k, $labelsKeys, true);
}));
?>
<div class="gl-auto-page" dir="rtl">
    <h1 class="gl-auto-page__title">حسابات القيود التلقائية</h1>

    <div class="card gl-auto-form-card">
        <h3 class="card-title">الحساب من الدليل المحاسبي</h3>
        <p class="muted gl-auto-hint">
            اختر <strong>نوع اليومية</strong> (من أنواع اليوميات المعرّفة)، ثم أدخل <strong>كود الحساب</strong> فيُكمّل الاسم تلقائياً، أو اضغط <strong>البحث</strong> لعرض <strong>الحسابات الفرعية فقط</strong>.
        </p>
        <div class="table-wrap gl-settings-table-wrap">
            <table class="gl-settings-table">
                <thead>
                    <tr>
                        <th class="gl-th-label">البند</th>
                        <th class="gl-th-journal-type">نوع اليومية</th>
                        <th class="gl-th-code">كود الحساب</th>
                        <th class="gl-th-name">اسم الحساب</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orderedKeys as $key):
                        $aid = (int) ($current[$key] ?? 0);
                        $jtId = (int) ($currentJournalType[$key] ?? 0);
                        $code = $aid > 0 ? (string) ($byId[$aid]['code'] ?? '') : '';
                        $name = $aid > 0 ? (string) ($byId[$aid]['name'] ?? '') : '';
                        $title = htmlspecialchars($keyHints[$key] ?? '', ENT_QUOTES, 'UTF-8');
                        $short = htmlspecialchars($rowTitles[$key] ?? $key, ENT_QUOTES, 'UTF-8');
                        ?>
                    <tr data-gl-key="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" data-account-id="<?php echo $aid; ?>" title="<?php echo $title; ?>">
                        <td class="gl-td-label"><?php echo $short; ?></td>
                        <td class="gl-td-journal-type">
                            <select class="gl-sel-journal-type" aria-label="نوع اليومية">
                                <option value="0">— اختر —</option>
                                <?php foreach ($journalTypesList as $jt):
                                    $jid = (int) ($jt['id'] ?? 0);
                                    $jlab = trim((string) ($jt['name_ar'] ?? ''));
                                    if ($jlab === '') {
                                        $jlab = trim((string) ($jt['name_en'] ?? ''));
                                    }
                                    ?>
                                <option value="<?php echo $jid; ?>"<?php echo $jid === $jtId ? ' selected' : ''; ?>><?php echo htmlspecialchars($jlab, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
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
        <div class="gl-auto-actions">
            <button type="button" id="gl_btn_save">حفظ الربط</button>
        </div>
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
    /** كود غير صالح أو ليس ورقة ترحيل: يُفرّغ الكود والاسم ولا يُربط معرّف (سلوك احترافي عند الخروج من الحقل). */
    function glStripResolvedRow(tr) {
        if (!tr) {
            return;
        }
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
                    .catch(function (e) {
                        glStripResolvedRow(tr);
                        alert(e.message || String(e));
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
            alert('يوجد كود مكتوب دون حساب فرعي — لن يُحفظ الربط. إمّا اختر حساباً فرعياً (يظهر الاسم) أو امسح الكود.');
            return;
        }
        var settings = {};
        var journalTypeIds = {};
        var anyFullPair = false;
        var anyEmptyBoth = false;
        var trList = document.querySelectorAll('tr[data-gl-key]');
        for (var i = 0; i < trList.length; i++) {
            var tr = trList[i];
            var k = tr.getAttribute('data-gl-key');
            if (!k) {
                continue;
            }
            var id = parseInt(tr.getAttribute('data-account-id'), 10) || 0;
            var sel = tr.querySelector('.gl-sel-journal-type');
            var jt = sel ? parseInt(sel.value, 10) || 0 : 0;
            var lblEl = tr.querySelector('.gl-td-label');
            var labelTxt = lblEl ? String(lblEl.textContent || '').trim() : k;
            if (id > 0 && jt <= 0) {
                alert('يجب اختيار «نوع اليومية» للبند: ' + labelTxt + '.\nلا يُحفظ ربط حساب بدون نوع يومية.');
                return;
            }
            if (jt > 0 && id <= 0) {
                alert('يجب ربط حساب من الدليل للبند: ' + labelTxt + '.\nلا يُحفظ نوع يومية بدون حساب.');
                return;
            }
            if (id > 0 && jt > 0) {
                anyFullPair = true;
            }
            if (id <= 0 && jt <= 0) {
                anyEmptyBoth = true;
            }
            settings[k] = id;
            journalTypeIds[k] = jt;
        }
        if (anyFullPair && anyEmptyBoth) {
            if (!window.confirm(
                'توجد بنود أخرى لم يُربط لها حساب ولا نوع يومية بعد.\nهل تريد حفظ الربط الحالي فقط؟\n(مناسب إن كان العمل يقتصر مثلاً على بيع نقدي أو أونلاين فقط.)'
            )) {
                return;
            }
        }
        postJSON('/admin/api/settings/gl-accounts.php', {
            action: 'save',
            settings: settings,
            journal_type_ids: journalTypeIds
        }).then(function (res) {
            alert(res.message || (res.success ? 'تم' : 'فشل'));
            if (res.success) {
                location.reload();
            }
        }).catch(function (e) { alert(e.message || String(e)); });
    });
})();
</script>
