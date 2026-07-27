<?php

declare(strict_types=1);

/**
 * Shared promo schedule JS helpers (included inside a <script> block).
 * Default From/To calendar days come from Current Admin Country Context — not Browser Date.
 */
$ocpCountryTodayYmd = '';
$ocpCountryPlus1yYmd = '';
if (isset($pdo) && $pdo instanceof PDO && function_exists('orange_admin_time_document_date_today_for_admin_context')) {
    try {
        $ocpCountryTodayYmd = orange_admin_time_document_date_today_for_admin_context($pdo);
        $base = DateTimeImmutable::createFromFormat('!Y-m-d', $ocpCountryTodayYmd, new DateTimeZone('UTC'));
        if ($base instanceof DateTimeImmutable) {
            $ocpCountryPlus1yYmd = $base->modify('+1 year')->format('Y-m-d');
        }
    } catch (Throwable $e) {
        $ocpCountryTodayYmd = '';
        $ocpCountryPlus1yYmd = '';
    }
}
?>
var OCP_COUNTRY_TODAY_YMD = <?php echo json_encode($ocpCountryTodayYmd, JSON_UNESCAPED_UNICODE); ?>;
var OCP_COUNTRY_PLUS_1Y_YMD = <?php echo json_encode($ocpCountryPlus1yYmd, JSON_UNESCAPED_UNICODE); ?>;
function ocpFmtIsoDate(s) {
    if (!s) return '';
    return String(s).substring(0, 10);
}
function ocpAlwaysOnEl(prefix) {
    return document.getElementById(prefix + '_always_on');
}
function ocpIsAlwaysOn(prefix) {
    var el = ocpAlwaysOnEl(prefix);
    return !!(el && el.checked);
}
function ocpSyncAlwaysOnUi(prefix) {
    var alwaysOn = ocpIsAlwaysOn(prefix);
    var fromEl = document.getElementById(prefix + '_valid_from');
    var toEl = document.getElementById(prefix + '_valid_to');
    [fromEl, toEl].forEach(function (el) {
        if (!el) return;
        el.disabled = alwaysOn;
        el.required = !alwaysOn;
        el.setAttribute('aria-disabled', alwaysOn ? 'true' : 'false');
        // حقل DMY يلتفّ بزر تقويم أصلي منفصل — لا بد من تعطيله أيضاً وإلا بقي الاختيار ممكناً
        var wrap = el.closest ? el.closest('.admin-inp-dmy-with-picker') : null;
        if (wrap) {
            wrap.classList.toggle('is-disabled', alwaysOn);
            var pickWrap = wrap.querySelector('.admin-inp-dmy-picker-wrap');
            if (pickWrap) pickWrap.style.display = alwaysOn ? 'none' : '';
            var native = wrap.querySelector('.admin-inp-dmy-picker-native');
            if (native) native.disabled = alwaysOn;
        }
    });
}
function ocpSetAlwaysOn(prefix, enabled) {
    var el = ocpAlwaysOnEl(prefix);
    if (!el) return;
    el.checked = !!enabled;
    ocpSyncAlwaysOnUi(prefix);
}
function ocpBindAlwaysOn(prefix) {
    var el = ocpAlwaysOnEl(prefix);
    if (!el) return;
    if (el.dataset.ocpBound === '1') {
        ocpSyncAlwaysOnUi(prefix);
        return;
    }
    el.dataset.ocpBound = '1';
    el.addEventListener('change', function () {
        ocpSyncAlwaysOnUi(prefix);
    });
    ocpSyncAlwaysOnUi(prefix);
}
function ocpSetDmyFromIso(fieldId, iso) {
    var el = document.getElementById(fieldId);
    if (!el) return;
    // Empty clears the field (permanent offers must not show DB fillers in the edit form).
    if (!iso) {
        if (typeof orangeSetDmyValueFromIso === 'function') {
            orangeSetDmyValueFromIso(el, '');
        } else {
            el.value = '';
        }
        return;
    }
    var d = String(iso).substring(0, 10);
    if (typeof orangeSetDmyValueFromIso === 'function') {
        orangeSetDmyValueFromIso(el, d);
    } else {
        el.value = d;
    }
}
function ocpGetIso(fieldId) {
    var el = document.getElementById(fieldId);
    if (!el) return '';
    if (typeof orangeGetDmyValueAsIso === 'function') {
        return orangeGetDmyValueAsIso(el) || '';
    }
    return el.value.trim();
}
function ocpSchedulePayload(prefix) {
    return {
        valid_from: ocpGetIso(prefix + '_valid_from'),
        valid_to: ocpGetIso(prefix + '_valid_to'),
        is_always_on: ocpIsAlwaysOn(prefix) ? 1 : 0
    };
}
function ocpStatusLabel(r) {
    var pr = (r.auto_paused_reason || '');
    if (pr === 'promo_stock') {
        return 'موقوف — نفاد مخزون العرض';
    }
    if (pr === 'gift_stock') {
        return 'موقوف — عدم توفر الهدية';
    }
    if (parseInt(r.is_active, 10) !== 1) {
        return 'غير نشط';
    }
    if (parseInt(r.is_always_on, 10) === 1) {
        return 'نشط (دائم)';
    }
    return 'نشط';
}
function ocpScheduleLabel(r) {
    if (parseInt(r.is_always_on, 10) === 1) {
        return 'تفعيل دائم';
    }
    return ocpFmtIsoDate(r.valid_from) + ' → ' + ocpFmtIsoDate(r.valid_to);
}
function ocpDefaultScheduleDates(prefix) {
    var f = String(OCP_COUNTRY_TODAY_YMD || '').substring(0, 10);
    var t = String(OCP_COUNTRY_PLUS_1Y_YMD || '').substring(0, 10);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(f) || !/^\d{4}-\d{2}-\d{2}$/.test(t)) {
        return;
    }
    ocpSetDmyFromIso(prefix + '_valid_from', f);
    ocpSetDmyFromIso(prefix + '_valid_to', t);
    ocpSyncAlwaysOnUi(prefix);
}
