<?php

declare(strict_types=1);

/**
 * بارشال مشترك: حقل «نص تحفيزي يظهر للعميل على العرض» متعدد اللغات (ar/en/fil/hi)
 * بنمط الترجمة الصامتة الإلزامي (توأم show_name_to_customer). يُستخدَم في شاشات
 * عروض المنتجات/الكومبو/BOGO. النص المضمّن أولى من رسالة offer_card المركزية.
 *
 * الاستخدام داخل النموذج:
 *   orange_render_promo_offer_text_fields('ofr');   // prefix فريد لكل شاشة
 * المعرّفات الناتجة: {prefix}_promo_text_ar / _en / _fil / _hi
 *
 * القراءة/الحفظ في JS الشاشة:
 *   - editX: OrangePromoOfferText.fill('ofr', row);
 *   - resetX: OrangePromoOfferText.clear('ofr');
 *   - saveX:  Object.assign(payload, OrangePromoOfferText.payload('ofr'));
 */
function orange_render_promo_offer_text_fields(string $prefix): void
{
    $p = htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8');
    ?>
    <div class="promo-offer-text" style="margin-top:14px;border-top:1px dashed #e5e7eb;padding-top:12px;">
        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:0 0 8px;">
            <strong>جملة تحفيزية تظهر للعميل على هذا العرض (اختياري)</strong>
            <button type="button" class="btn-secondary" onclick="OrangePromoOfferText.translateFromAr('<?php echo $p; ?>')">ترجمة تلقائية من العربي</button>
        </div>
        <p class="page-subtitle" style="margin:0 0 8px;">اتركها فارغة لإخفائها. تظهر على بطاقة العرض وصفحة العرض، وتتقدّم على «الرسائل التحفيزية» المركزية لنفس العرض.</p>
        <div class="form-grid">
            <div>
                <label for="<?php echo $p; ?>_promo_text_ar">النص (عربي)</label>
                <textarea id="<?php echo $p; ?>_promo_text_ar" class="admin-inp" rows="2" dir="auto" maxlength="255" placeholder="مثال: وفّر أكثر مع هذه الباقة!"></textarea>
            </div>
            <div>
                <label for="<?php echo $p; ?>_promo_text_en">English</label>
                <textarea id="<?php echo $p; ?>_promo_text_en" class="admin-inp" rows="2" dir="ltr" lang="en" maxlength="255"></textarea>
            </div>
            <div>
                <label for="<?php echo $p; ?>_promo_text_fil">Filipino</label>
                <textarea id="<?php echo $p; ?>_promo_text_fil" class="admin-inp" rows="2" dir="ltr" maxlength="255"></textarea>
            </div>
            <div>
                <label for="<?php echo $p; ?>_promo_text_hi">हिन्दी</label>
                <textarea id="<?php echo $p; ?>_promo_text_hi" class="admin-inp" rows="2" dir="ltr" maxlength="255"></textarea>
            </div>
        </div>
    </div>
    <?php
    if (!defined('ORANGE_PROMO_OFFER_TEXT_JS_DONE')) {
        define('ORANGE_PROMO_OFFER_TEXT_JS_DONE', true);
        ?>
        <script>
        window.OrangePromoOfferText = (function () {
            var timers = {};
            function el(prefix, lang) { return document.getElementById(prefix + '_promo_text_' + lang); }
            async function translate(prefix, opts) {
                opts = opts || {};
                var silent = !!opts.silent;
                var forceFromArabic = !!opts.forceFromArabic;
                var arEl = el(prefix, 'ar');
                var enEl = el(prefix, 'en');
                var filEl = el(prefix, 'fil');
                var hiEl = el(prefix, 'hi');
                if (!arEl) return;
                if (arEl.value.trim() === '' && forceFromArabic) {
                    if (enEl) enEl.value = '';
                    if (filEl) filEl.value = '';
                    if (hiEl) hiEl.value = '';
                    return;
                }
                try {
                    var res = await postJSON('/admin/api/translate/descriptions.php', {
                        description_ar: arEl.value.trim(),
                        description_en: forceFromArabic ? '' : (enEl ? enEl.value.trim() : '')
                    });
                    if (!res || !res.success || !res.translations) {
                        if (!silent) alert((res && res.message) ? res.message : 'فشل الترجمة');
                        return;
                    }
                    var tr = res.translations;
                    if (enEl && tr.description_en != null) enEl.value = String(tr.description_en);
                    if (filEl && tr.description_fil != null) filEl.value = String(tr.description_fil);
                    if (hiEl && tr.description_hi != null) hiEl.value = String(tr.description_hi);
                } catch (e) {
                    if (!silent) alert('تعذر الاتصال بخدمة الترجمة');
                }
            }
            function scheduleFromAr(prefix) {
                clearTimeout(timers[prefix + '_ar']);
                timers[prefix + '_ar'] = setTimeout(function () {
                    translate(prefix, { silent: true, forceFromArabic: true });
                }, 700);
            }
            function scheduleFromEn(prefix) {
                var enEl = el(prefix, 'en');
                if (!enEl || enEl.value.trim() === '') return;
                clearTimeout(timers[prefix + '_en']);
                timers[prefix + '_en'] = setTimeout(function () {
                    translate(prefix, { silent: true, forceFromArabic: false });
                }, 650);
            }
            return {
                attach: function (prefix) {
                    var arEl = el(prefix, 'ar');
                    var enEl = el(prefix, 'en');
                    if (arEl) arEl.addEventListener('input', function () { scheduleFromAr(prefix); });
                    if (enEl) enEl.addEventListener('input', function () { scheduleFromEn(prefix); });
                },
                translateFromAr: function (prefix) { translate(prefix, { silent: false, forceFromArabic: true }); },
                fill: function (prefix, row) {
                    if (!row) { this.clear(prefix); return; }
                    ['ar', 'en', 'fil', 'hi'].forEach(function (lang) {
                        var e = el(prefix, lang);
                        if (e) e.value = (row['promo_text_' + lang] != null) ? String(row['promo_text_' + lang]) : '';
                    });
                },
                clear: function (prefix) {
                    ['ar', 'en', 'fil', 'hi'].forEach(function (lang) {
                        var e = el(prefix, lang);
                        if (e) e.value = '';
                    });
                },
                payload: function (prefix) {
                    var out = {};
                    ['ar', 'en', 'fil', 'hi'].forEach(function (lang) {
                        var e = el(prefix, lang);
                        out['promo_text_' + lang] = e ? e.value.trim() : '';
                    });
                    return out;
                }
            };
        })();
        </script>
        <?php
    }
    ?>
    <script>OrangePromoOfferText.attach('<?php echo $p; ?>');</script>
    <?php
}
