<?php

declare(strict_types=1);

?>
<div id="orange-variant-picker-overlay" class="orange-vp-overlay" hidden aria-hidden="true">
    <div class="orange-vp-modal" role="dialog" aria-modal="true" aria-labelledby="orange-vp-title">
        <div class="orange-vp-head">
            <h3 id="orange-vp-title" class="orange-vp-title">اختيار متغير من المخزون</h3>
            <button type="button" class="orange-vp-close btn-secondary" aria-label="إغلاق">إغلاق</button>
        </div>
        <p class="orange-vp-lead muted" id="orange-vp-hint"></p>
        <div class="orange-vp-filters" id="orange-vp-filters">
            <div class="orange-vp-filters-title">تصفية حسب الهيكل (اختياري)</div>
            <div class="orange-vp-filters-grid">
                <div class="orange-vp-filter-cell" id="orange-vp-dept-wrap">
                    <label for="orange-vp-dept">القسم</label>
                    <select id="orange-vp-dept" class="admin-inp">
                        <option value="">— كل الأقسام —</option>
                    </select>
                </div>
                <div class="orange-vp-filter-cell">
                    <label for="orange-vp-cat">الفئة</label>
                    <select id="orange-vp-cat" class="admin-inp">
                        <option value="">— كل الفئات —</option>
                    </select>
                </div>
                <div class="orange-vp-filter-cell">
                    <label for="orange-vp-sub">الفئة الفرعية</label>
                    <select id="orange-vp-sub" class="admin-inp">
                        <option value="">— الكل —</option>
                    </select>
                </div>
                <div class="orange-vp-filter-cell orange-vp-filter-actions">
                    <label class="orange-vp-filter-spacer" aria-hidden="true">&nbsp;</label>
                    <button type="button" class="btn-secondary" id="orange-vp-clear-filters">مسح التصفية</button>
                </div>
            </div>
            <p class="muted orange-vp-filters-note" id="orange-vp-filters-note">
                نفس المنتجات المعروضة للبيع؛ الهدية تُختار كمتغير (لون/مقاس) للصنف.
            </p>
        </div>
        <label class="orange-vp-search-label" for="orange-vp-q">بحث (اسم المنتج، رقم متغير، رقم منتج، لون/مقاس)</label>
        <input type="search" id="orange-vp-q" class="admin-inp orange-vp-q" dir="auto" autocomplete="off">
        <div class="orange-vp-qty-row" id="orange-vp-qty-wrap" hidden>
            <label for="orange-vp-qty">الكمية (للكومبو / حزمة شراء BOGO)</label>
            <input type="number" id="orange-vp-qty" class="admin-inp" min="1" step="1" value="1" dir="ltr" style="max-width:7rem;">
        </div>
        <div id="orange-vp-results" class="orange-vp-results"></div>
    </div>
</div>
