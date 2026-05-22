<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/journal_types.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/country_provision.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
orange_catalog_ensure_gl_account_settings_alloc_tables($pdo);

$glCountryId = orange_gl_settings_effective_country_id($pdo);
$glCountryRow = orange_country_row_by_id($pdo, $glCountryId, false);
$glCountryLabel = trim((string) ($glCountryRow['name_ar'] ?? ''));
if ($glCountryLabel === '' && $glCountryRow !== null) {
    $glCountryLabel = trim((string) ($glCountryRow['name_en'] ?? ''));
}
if ($glCountryLabel === '') {
    $glCountryLabel = orange_countries_display_code(orange_admin_context_country_code($pdo));
}
$glProvStatus = orange_country_provision_status($pdo, $glCountryId);
$glCountryNeedsProvision = (int) ($glProvStatus['accounts_count'] ?? 0) === 0
    && (int) ($glProvStatus['gl_settings_count'] ?? 0) === 0;

$glSetPostingLeafCt = 0;
if (orange_journal_vouchers_ready($pdo)) {
    $glSetPostingLeafCt = orange_accounts_count_posting_leaves($pdo, $glCountryId);
}

$accountsRows = orange_accounts_fetch(
    $pdo,
    'SELECT a.id, a.name, a.code FROM accounts a WHERE 1=1',
    [],
    'a',
    $glCountryId
);
$accountsRows = array_values(array_filter($accountsRows, static function (array $a): bool {
    return trim((string) ($a['name'] ?? '')) !== '' || trim((string) ($a['code'] ?? '')) !== '';
}));
usort($accountsRows, static function (array $a, array $b): int {
    $ca = (string) ($a['code'] ?? '');
    $cb = (string) ($b['code'] ?? '');
    $cmp = strcmp($ca, $cb);
    if ($cmp !== 0) {
        return $cmp;
    }

    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
$byId = [];
foreach ($accountsRows as $a) {
    $byId[(int) $a['id']] = $a;
}

$current = orange_gl_settings_bindings_map($pdo, $glCountryId);

$journalTypesList = orange_journal_types_list($pdo);
$rowTitles = orange_gl_setting_row_short_labels();
$keyHints = orange_gl_setting_key_labels();
$orderedKeys = orange_gl_settings_ui_key_order();
$journalRules = orange_gl_journal_type_rules_list($pdo);
$ruleKeyOrder = orange_gl_journal_rule_dropdown_key_order($current);

$jtJson = json_encode($journalTypesList, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$keysJson = json_encode($orderedKeys, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$shortJson = json_encode($rowTitles, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$hintsJson = json_encode($keyHints, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$ruleKeysJson = json_encode($ruleKeyOrder, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$rulesJson = json_encode($journalRules, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$legalReservePct = orange_gl_setting_alloc_percent($pdo, 'legal_reserve');
$accountsByIdForJs = [];
foreach ($byId as $aidJs => $aRow) {
    $accountsByIdForJs[(string) (int) $aidJs] = [
        'code' => (string) ($aRow['code'] ?? ''),
        'name' => (string) ($aRow['name'] ?? ''),
    ];
}
$currentForRulesJson = json_encode($current, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$accountsByIdJson = json_encode($accountsByIdForJs, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$resolvedAccountLineByKey = [];
foreach (orange_gl_allowed_setting_keys() as $gk) {
    $aidR = (int) ($current[$gk] ?? 0);
    if ($aidR > 0 && isset($byId[$aidR])) {
        $cR = trim((string) ($byId[$aidR]['code'] ?? ''));
        $nR = trim((string) ($byId[$aidR]['name'] ?? ''));
        $resolvedAccountLineByKey[$gk] = ($cR !== '' ? $cR . ' — ' : '') . $nR;
    } else {
        $resolvedAccountLineByKey[$gk] = '';
    }
}
$resolvedLineByKeyJson = json_encode($resolvedAccountLineByKey, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
?>
<div class="page-title">
    <h1>حسابات القيود التلقائية</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;">سياق الدولة: <strong><?php echo htmlspecialchars($glCountryLabel, ENT_QUOTES, 'UTF-8'); ?></strong> — الربط والدليل المعروضان لهذه الدولة فقط.</p>
</div>

<?php if ($glCountryNeedsProvision): ?>
<div class="card" style="border:1px solid #93c5fd;background:#eff6ff;margin-bottom:12px;">
    <p class="card-hint" style="margin:0;line-height:1.55;">
        <strong>دولة جديدة:</strong> لم يُنسَخ بعد دليل الحسابات وربط القيود التلقائية لـ <?php echo htmlspecialchars($glCountryLabel, ENT_QUOTES, 'UTF-8'); ?>.
        من <a href="<?php echo htmlspecialchars(orange_admin_public_href_with_country('/admin/index.php?page=countries', orange_admin_context_country_code($pdo)), ENT_QUOTES, 'UTF-8'); ?>">الدول</a>
        شغّل <strong>إنشاء كامل</strong> (ينسخ المخزن، القنوات، الكتalog، الدليل، وربط GL من الكويت كنقطة بداية) ثم عدّل ما يلزم.
    </p>
</div>
<?php endif; ?>

<?php if (orange_journal_vouchers_ready($pdo) && $glSetPostingLeafCt === 0): ?>
<div class="card" style="border:1px solid #fcd34d;background:#fffbeb;margin-bottom:12px;">
    <p class="card-hint" style="margin:0;line-height:1.55;"><strong>تنبيه:</strong> لا توجد حسابات ترحيل (أوراق) في الدليل بعد؛ الاختيار الموصى به لكل بند هو حساب ورقة للترحيل. <strong>الشاشة والجداول تعملان</strong> — أكمل «الدليل المحاسبي» ثم عد لربط القيود التلقائية والذمم.</p>
</div>
<?php endif; ?>

<div class="card gl-auto-form-card">
        <h3 class="card-title">١ — البنود والحسابات من الدليل</h3>
        <div class="table-wrap gl-settings-table-wrap">
            <table class="gl-settings-table" id="gl_settings_section1_table">
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
                                <button type="button" class="gl-search-btn" title="<?php echo $key === 'accounts_payable_parent' ? 'بحث — حسابات الأب فقط' : 'بحث — حسابات فرعية فقط'; ?>" aria-label="بحث">🔍</button>
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
        <h3 class="card-title">٢ — ربط نوع اليومية بحساب مدين وحساب دائن</h3>
        <div class="table-wrap gl-settings-table-wrap">
            <table class="gl-settings-table" id="gl_jt_rules_table">
                <thead>
                    <tr>
                        <th>نوع اليومية</th>
                        <th>نقدي / آجل</th>
                        <th>المدين</th>
                        <th>الدائن</th>
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
        <p class="gl-pick-modal__hint muted" style="margin:0 0 8px;font-size:0.9rem;">نقرتان للاختيار</p>
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
    var glKeyAccountInitial = <?php echo $currentForRulesJson; ?>;
    var glAccountsById = <?php echo $accountsByIdJson; ?>;
    var glResolvedLineByKey = <?php echo $resolvedLineByKeyJson; ?>;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/"/g, '&quot;');
    }

    function accountPreviewForSettingKey(k) {
        k = String(k || '').trim();
        if (!k) {
            return '—';
        }
        var fromDb = String(glResolvedLineByKey[k] || '').trim();
        var section1 = document.getElementById('gl_settings_section1_table');
        var row = section1
            ? section1.querySelector('tr[data-gl-key="' + k.replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"]')
            : null;
        if (row) {
            var id = parseInt(row.getAttribute('data-account-id'), 10) || 0;
            var codeEl = row.querySelector('.gl-inp-code');
            var nameEl = row.querySelector('.gl-inp-name');
            var code = codeEl ? String(codeEl.value || '').trim() : '';
            var name = nameEl ? String(nameEl.value || '').trim() : '';
            if (code || name) {
                return (code ? code + ' — ' : '') + name;
            }
            if (id > 0 && fromDb) {
                return fromDb;
            }
        }
        if (fromDb) {
            return fromDb;
        }
        var sid = glKeyAccountInitial[k];
        var iid = typeof sid === 'number' ? sid : parseInt(String(sid || '0'), 10) || 0;
        if (iid <= 0) {
            return '— لم يُربط في القسم ١ —';
        }
        var a = glAccountsById[String(iid)] || glAccountsById[iid];
        if (!a) {
            return '— حساب #' + iid + ' —';
        }
        var c = String(a.code || '').trim();
        var n = String(a.name || '').trim();
        if (!n && !c) {
            return '— لم يُربط في القسم ١ —';
        }
        return (c ? c + ' — ' : '') + n;
    }

    /** نص خيار القائمة: الحساب من القسم ١؛ وإلا تلميح بالبند غير المربوط */
    function optionLabelForKey(k) {
        k = String(k || '').trim();
        if (!k) {
            return '';
        }
        var preview = accountPreviewForSettingKey(k);
        var shortLab = glKeyShort[k] || glKeyHints[k] || k;
        if (preview === '—' || preview.indexOf('لم يُربط') !== -1) {
            return shortLab + ' — لم يُربط في القسم ١';
        }
        return preview;
    }

    function refreshAllRuleSelectLabels() {
        document.querySelectorAll('#gl_jt_rules_body .gl-sel-debit-key, #gl_jt_rules_body .gl-sel-credit-key').forEach(function (sel) {
            for (var i = 0; i < sel.options.length; i++) {
                var opt = sel.options[i];
                var v = String(opt.value || '').trim();
                if (!v) {
                    continue;
                }
                opt.textContent = optionLabelForKey(v);
            }
        });
    }

    function resolvedAccountIdForKey(k) {
        k = String(k || '').trim();
        if (!k) {
            return 0;
        }
        var section1 = document.getElementById('gl_settings_section1_table');
        var row = section1
            ? section1.querySelector('tr[data-gl-key="' + k.replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"]')
            : null;
        if (row) {
            var id = parseInt(row.getAttribute('data-account-id'), 10) || 0;
            if (id > 0) {
                return id;
            }
        }
        var sid = glKeyAccountInitial[k];
        return typeof sid === 'number' ? sid : parseInt(String(sid || '0'), 10) || 0;
    }

    /** نفس مفتاح البند أو نفس حساب القسم ١ (حتى ببندين مختلفين) */
    function glDebitCreditKeysConflict(dk, ck) {
        dk = String(dk || '').trim();
        ck = String(ck || '').trim();
        if (!dk || !ck) {
            return false;
        }
        if (dk === ck) {
            return true;
        }
        var idD = resolvedAccountIdForKey(dk);
        var idC = resolvedAccountIdForKey(ck);
        return idD > 0 && idC > 0 && idD === idC;
    }

    /**
     * تعديل الدائن ليطابق المدين → إفراغ المدين. تعديل المدين ليطابق الدائن → إفراغ الدائن.
     */
    function enforceGlRuleDistinctSides(tr, changedSide) {
        var deb = tr.querySelector('.gl-sel-debit-key');
        var cre = tr.querySelector('.gl-sel-credit-key');
        if (!deb || !cre) {
            return;
        }
        var dk = String(deb.value || '').trim();
        var ck = String(cre.value || '').trim();
        if (!glDebitCreditKeysConflict(dk, ck)) {
            return;
        }
        if (changedSide === 'credit') {
            if (!deb.disabled && deb.style.display !== 'none') {
                deb.value = '';
            }
        } else if (changedSide === 'debit') {
            if (!cre.disabled && cre.style.display !== 'none') {
                cre.value = '';
            }
        }
    }

    function syncRuleAccountPreviews(tr) {
        if (!tr) {
            return;
        }
        var jt = parseInt((tr.querySelector('.gl-sel-jt-id') || {}).value, 10) || 0;
        var code = journalTypeCodeById(jt);
        var ptSel = tr.querySelector('.gl-sel-pt');
        var pt = ptSel && !ptSel.classList.contains('gl-sel-pt--standard')
            ? String(ptSel.value || '').trim()
            : '';
        var deb = tr.querySelector('.gl-sel-debit-key');
        var cre = tr.querySelector('.gl-sel-credit-key');
        var debDiv = tr.querySelector('.gl-rule-debit-acct');
        var creDiv = tr.querySelector('.gl-rule-credit-acct');
        if (debDiv) {
            debDiv.removeAttribute('aria-hidden');
            if (code === 'PDN' && pt === 'credit') {
                debDiv.innerHTML = '<span class="gl-rule-acct-muted">ذمة المورد (من المستند)</span>';
            } else if (deb && deb.style.display !== 'none' && !deb.disabled) {
                debDiv.textContent = '';
                debDiv.setAttribute('aria-hidden', 'true');
            } else if (deb && deb.value) {
                debDiv.textContent = accountPreviewForSettingKey(deb.value);
            } else {
                debDiv.textContent = '—';
            }
        }
        if (creDiv) {
            creDiv.removeAttribute('aria-hidden');
            if (code === 'PIN' && pt === 'credit') {
                creDiv.innerHTML = '<span class="gl-rule-acct-muted">ذمة المورد (من المستند)</span>';
            } else if (cre && cre.style.display !== 'none' && !cre.disabled) {
                creDiv.textContent = '';
                creDiv.setAttribute('aria-hidden', 'true');
            } else if (cre && cre.value) {
                creDiv.textContent = accountPreviewForSettingKey(cre.value);
            } else {
                creDiv.textContent = '—';
            }
        }
    }

    function refreshAllRuleAccountPreviews() {
        document.querySelectorAll('#gl_jt_rules_body tr[data-jt-rule]').forEach(syncRuleAccountPreviews);
        refreshAllRuleSelectLabels();
    }

    function keyOptionsHtml(selected) {
        var h = '<option value="">— اختر حساب المدين/الدائن (حسب القسم ١) —</option>';
        var sel = String(selected || '').trim();
        var seenSel = false;
        for (var i = 0; i < glRuleKeyOrder.length; i++) {
            var k = glRuleKeyOrder[i];
            if (k === sel) {
                seenSel = true;
            }
            var lab = optionLabelForKey(k);
            h += '<option value="' + esc(k) + '"' + (k === sel ? ' selected' : '') + '>' + esc(lab) + '</option>';
        }
        if (sel && !seenSel) {
            h += '<option value="' + esc(sel) + '" selected>' + esc(optionLabelForKey(sel)) + '</option>';
        }
        return h;
    }

    function journalTypeCodeById(jtId) {
        for (var i = 0; i < glJournalTypes.length; i++) {
            if (parseInt(glJournalTypes[i].id, 10) === jtId) {
                return String(glJournalTypes[i].code || '').trim().toUpperCase();
            }
        }
        return '';
    }
    function isPurchaseSplitJournalCode(code) {
        return code === 'PIN' || code === 'PDN';
    }
    function paymentTermsSelectHtml(jtId, selectedPt) {
        var code = journalTypeCodeById(jtId);
        if (!isPurchaseSplitJournalCode(code)) {
            return '<select class="gl-sel-pt gl-sel-pt--standard" aria-label="نقدي أو آجل">' +
                '<option value="" selected>قياسي</option></select>';
        }
        var pt = String(selectedPt || '').trim();
        if (pt !== 'cash' && pt !== 'credit') {
            pt = 'cash';
        }
        return '<select class="gl-sel-pt" aria-label="نقدي أو آجل">' +
            '<option value="cash"' + (pt === 'cash' ? ' selected' : '') + '>نقدي (مدين + دائن)</option>' +
            '<option value="credit"' + (pt === 'credit' ? ' selected' : '') + '>آجل (جانب من ذمة المورد)</option>' +
            '</select>';
    }
    function syncRuleRowLabels(tr) {
        var jt = parseInt((tr.querySelector('.gl-sel-jt-id') || {}).value, 10) || 0;
        var code = journalTypeCodeById(jt);
        var ptSel = tr.querySelector('.gl-sel-pt');
        var pt = ptSel ? String(ptSel.value || '').trim() : '';
        var deb = tr.querySelector('.gl-sel-debit-key');
        var cre = tr.querySelector('.gl-sel-credit-key');
        if (deb) {
            deb.title = '';
        }
        if (cre) {
            cre.title = '';
        }
        if (code === 'PIN' && pt === 'credit' && cre) {
            cre.title = '';
        }
        if (code === 'PDN' && pt === 'credit' && deb) {
            deb.title = '';
        }
    }

    /**
     * آجل PIN/PDN: إظهار قائمة مدين أو دائن فقط؛ الجانب الآخر من ذمة المورد في المستند.
     */
    function applyPurchaseCreditColumnLayout(tr) {
        var jt = parseInt((tr.querySelector('.gl-sel-jt-id') || {}).value, 10) || 0;
        var code = journalTypeCodeById(jt);
        var ptSel = tr.querySelector('.gl-sel-pt');
        var pt = ptSel && !ptSel.classList.contains('gl-sel-pt--standard')
            ? String(ptSel.value || '').trim()
            : '';
        var tdDeb = tr.querySelector('.gl-td-debit');
        var tdCre = tr.querySelector('.gl-td-credit');
        var deb = tr.querySelector('.gl-sel-debit-key');
        var cre = tr.querySelector('.gl-sel-credit-key');
        if (tdDeb) {
            tdDeb.querySelectorAll('.gl-rule-from-doc-placeholder').forEach(function (n) { n.remove(); });
        }
        if (tdCre) {
            tdCre.querySelectorAll('.gl-rule-from-doc-placeholder').forEach(function (n) { n.remove(); });
        }
        if (deb) {
            deb.style.display = '';
            deb.disabled = false;
        }
        if (cre) {
            cre.style.display = '';
            cre.disabled = false;
        }
        if (!isPurchaseSplitJournalCode(code) || pt !== 'credit') {
            return;
        }
        var ph = document.createElement('span');
        ph.className = 'gl-rule-from-doc-placeholder';
        ph.style.cssText = 'display:inline-block;padding:0.35rem 0.5rem;color:var(--muted,#666);font-size:0.92em;line-height:1.4;';
        ph.textContent = 'ذمة المورد (من المستند)';
        ph.title = 'يُحدَّد عند الترحيل من حساب المورد — لا يُختار في هذه الشاشة';

        if (code === 'PIN') {
            if (cre) {
                cre.value = '';
                cre.style.display = 'none';
                cre.disabled = true;
            }
            if (tdCre) {
                tdCre.appendChild(ph.cloneNode(true));
            }
        } else if (code === 'PDN') {
            if (deb) {
                deb.value = '';
                deb.style.display = 'none';
                deb.disabled = true;
            }
            if (tdDeb) {
                tdDeb.appendChild(ph.cloneNode(true));
            }
        }
    }

    function paintPaymentTermsCell(tr, jtId, selectedPt) {
        var td = tr.querySelector('.gl-td-pt');
        if (!td) {
            return;
        }
        td.innerHTML = paymentTermsSelectHtml(jtId, selectedPt);
        var ptEl = td.querySelector('.gl-sel-pt');
        if (ptEl && !ptEl.classList.contains('gl-sel-pt--standard')) {
            ptEl.addEventListener('change', function () {
                syncRuleRowLabels(tr);
                applyPurchaseCreditColumnLayout(tr);
                refreshAllRuleAccountPreviews();
            });
        }
        syncRuleRowLabels(tr);
        applyPurchaseCreditColumnLayout(tr);
        refreshAllRuleAccountPreviews();
    }

    function collectUsedJournalRules(exceptTr) {
        var u = {};
        document.querySelectorAll('#gl_jt_rules_body tr[data-jt-rule]').forEach(function (tr) {
            if (tr === exceptTr) {
                return;
            }
            var jt = parseInt((tr.querySelector('.gl-sel-jt-id') || {}).value, 10) || 0;
            if (jt <= 0) {
                return;
            }
            var code = journalTypeCodeById(jt);
            var pt = String((tr.querySelector('.gl-sel-pt') || {}).value || '').trim();
            if (!u[jt]) {
                u[jt] = { standard: false, cash: false, credit: false };
            }
            if (isPurchaseSplitJournalCode(code)) {
                if (pt === 'cash') {
                    u[jt].cash = true;
                } else if (pt === 'credit') {
                    u[jt].credit = true;
                }
            } else {
                u[jt].standard = true;
            }
        });
        return u;
    }

    function journalTypeOptionsHtml(selectedId, usedMap) {
        var h = '<option value="0">— نوع يومية —</option>';
        for (var i = 0; i < glJournalTypes.length; i++) {
            var jt = glJournalTypes[i];
            var id = parseInt(jt.id, 10) || 0;
            if (id <= 0) {
                continue;
            }
            var code = String(jt.code || '').trim().toUpperCase();
            if (id !== selectedId) {
                var u = usedMap[id];
                if (u) {
                    if (isPurchaseSplitJournalCode(code)) {
                        if (u.cash && u.credit) {
                            continue;
                        }
                    } else if (u.standard) {
                        continue;
                    }
                }
            }
            var lab = (jt.name_ar || jt.name_en || jt.code || '').trim();
            h += '<option value="' + id + '"' + (id === selectedId ? ' selected' : '') + '>' + esc(lab) + '</option>';
        }
        return h;
    }

    function refreshJournalTypeOptions() {
        document.querySelectorAll('#gl_jt_rules_body tr[data-jt-rule]').forEach(function (tr) {
            var sel = tr.querySelector('.gl-sel-jt-id');
            if (!sel) {
                return;
            }
            var cur = parseInt(sel.value, 10) || 0;
            var used = collectUsedJournalRules(tr);
            sel.innerHTML = journalTypeOptionsHtml(cur, used);
        });
    }

    function addRuleRow(jtId, paymentTerms, dk, ck) {
        jtId = jtId || 0;
        dk = dk || '';
        ck = ck || '';
        paymentTerms = String(paymentTerms || '').trim();
        var tbody = document.getElementById('gl_jt_rules_body');
        if (!tbody) {
            return;
        }
        var tr = document.createElement('tr');
        tr.setAttribute('data-jt-rule', '1');
        var used = collectUsedJournalRules(null);
        tr.innerHTML =
            '<td class="gl-td-jt"><select class="gl-sel-jt-id" aria-label="نوع اليومية">' + journalTypeOptionsHtml(jtId, used) + '</select></td>' +
            '<td class="gl-td-pt"></td>' +
            '<td class="gl-td-debit"><div class="gl-rule-key-cell"><select class="gl-sel-debit-key" aria-label="حساب المدين — كما في القسم ١">' + keyOptionsHtml(dk) + '</select>' +
            '<div class="gl-rule-debit-acct gl-rule-acct-preview" aria-live="polite"></div></div></td>' +
            '<td class="gl-td-credit"><div class="gl-rule-key-cell"><select class="gl-sel-credit-key" aria-label="حساب الدائن — كما في القسم ١">' + keyOptionsHtml(ck) + '</select>' +
            '<div class="gl-rule-credit-acct gl-rule-acct-preview" aria-live="polite"></div></div></td>' +
            '<td><button type="button" class="btn-secondary gl-btn-remove-rule">حذف</button></td>';
        tbody.appendChild(tr);
        paintPaymentTermsCell(tr, jtId, paymentTerms);
        var debK = tr.querySelector('.gl-sel-debit-key');
        var creK = tr.querySelector('.gl-sel-credit-key');
        if (debK) {
            debK.addEventListener('change', function () {
                enforceGlRuleDistinctSides(tr, 'debit');
                refreshAllRuleAccountPreviews();
            });
        }
        if (creK) {
            creK.addEventListener('change', function () {
                enforceGlRuleDistinctSides(tr, 'credit');
                refreshAllRuleAccountPreviews();
            });
        }
        var jtSel = tr.querySelector('.gl-sel-jt-id');
        if (jtSel) {
            jtSel.addEventListener('change', function () {
                var nid = parseInt(jtSel.value, 10) || 0;
                paintPaymentTermsCell(tr, nid, '');
                refreshJournalTypeOptions();
            });
        }
        tr.querySelector('.gl-btn-remove-rule').addEventListener('click', function () {
            tr.remove();
            refreshJournalTypeOptions();
        });
        refreshJournalTypeOptions();
    }

    if (glInitialRules && glInitialRules.length) {
        glInitialRules.forEach(function (r) {
            addRuleRow(
                parseInt(r.journal_type_id, 10) || 0,
                String(r.payment_terms != null ? r.payment_terms : ''),
                String(r.debit_setting_key || ''),
                String(r.credit_setting_key || '')
            );
        });
    }
    refreshAllRuleAccountPreviews();

    var glSection1Table = document.getElementById('gl_settings_section1_table');
    if (glSection1Table) {
        glSection1Table.addEventListener('change', refreshAllRuleAccountPreviews);
        glSection1Table.addEventListener('input', function (ev) {
            if (ev.target && ev.target.classList && ev.target.classList.contains('gl-inp-code')) {
                refreshAllRuleAccountPreviews();
            }
        });
    }

    document.getElementById('gl_btn_add_rule').addEventListener('click', function () {
        addRuleRow(0, '', '', '');
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
        refreshAllRuleAccountPreviews();
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
        refreshAllRuleAccountPreviews();
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
        var mode = activePickKey === 'accounts_payable_parent' ? '&mode=parents' : '';
        var url = '/admin/api/accounts/search-leaves.php?q=' + encodeURIComponent(q || '') + mode;
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
        var seenRuleSig = {};
        var jtRulesInvalid = false;
        document.querySelectorAll('#gl_jt_rules_body tr[data-jt-rule]').forEach(function (tr) {
            if (jtRulesInvalid) {
                return;
            }
            var jt = parseInt((tr.querySelector('.gl-sel-jt-id') || {}).value, 10) || 0;
            var dk = String((tr.querySelector('.gl-sel-debit-key') || {}).value || '').trim();
            var ck = String((tr.querySelector('.gl-sel-credit-key') || {}).value || '').trim();
            var pt = String((tr.querySelector('.gl-sel-pt') || {}).value || '').trim();
            if (jt <= 0 && dk === '' && ck === '') {
                return;
            }
            var jcode = journalTypeCodeById(jt);
            if (isPurchaseSplitJournalCode(jcode)) {
                if (pt !== 'cash' && pt !== 'credit') {
                    alert('اختر «نقدي» أو «آجل» لصف فاتورة/مردود المشتريات.');
                    jtRulesInvalid = true;
                    return;
                }
            } else {
                pt = '';
            }
            var sig = jt + '\0' + pt;
            if (seenRuleSig[sig]) {
                alert('قاعدة مكررة لنفس نوع اليومية ونفس نقدي/آجل.');
                jtRulesInvalid = true;
                return;
            }
            seenRuleSig[sig] = true;
            if (jcode === 'PIN' && pt === 'credit') {
                if (!dk) {
                    alert('فاتورة مشتريات آجل: اختر حساب المدين (مثلاً المخزون من القسم ١).');
                    jtRulesInvalid = true;
                    return;
                }
                if (ck && glDebitCreditKeysConflict(dk, ck)) {
                    alert('لا يمكن أن يكون نفس الحساب مديناً ودائناً.');
                    jtRulesInvalid = true;
                    return;
                }
            } else if (jcode === 'PIN' && pt === 'cash') {
                if (!dk || !ck || glDebitCreditKeysConflict(dk, ck)) {
                    alert('فاتورة مشتريات نقدي: اختر حسابين مختلفين للمدين والدائن (لا نفس الحساب).');
                    jtRulesInvalid = true;
                    return;
                }
            } else if (jcode === 'PDN' && pt === 'credit') {
                if (!ck) {
                    alert('مردود مشتريات آجل: اختر حساب الدائن.');
                    jtRulesInvalid = true;
                    return;
                }
                if (dk && glDebitCreditKeysConflict(dk, ck)) {
                    alert('لا يمكن أن يكون نفس الحساب مديناً ودائناً.');
                    jtRulesInvalid = true;
                    return;
                }
            } else if (jcode === 'PDN' && pt === 'cash') {
                if (!dk || !ck || glDebitCreditKeysConflict(dk, ck)) {
                    alert('مردود مشتريات نقدي: اختر حسابين مختلفين للمدين والدائن (لا نفس الحساب).');
                    jtRulesInvalid = true;
                    return;
                }
            } else {
                if (!dk || !ck || glDebitCreditKeysConflict(dk, ck)) {
                    alert('للأنواع الأخرى: اختر حسابين مختلفين للمدين والدائن (لا نفس الحساب).');
                    jtRulesInvalid = true;
                    return;
                }
            }
            journalRules.push({
                journal_type_id: jt,
                payment_terms: pt,
                debit_setting_key: dk,
                credit_setting_key: ck
            });
        });
        if (jtRulesInvalid) {
            return;
        }
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
