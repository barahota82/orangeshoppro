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
        <label class="orange-vp-search-label" for="orange-vp-q">بحث (اسم المنتج، رقم متغير، رقم منتج، لون/مقاس)</label>
        <input type="search" id="orange-vp-q" class="admin-inp orange-vp-q" dir="auto" autocomplete="off">
        <div class="orange-vp-qty-row" id="orange-vp-qty-wrap" hidden>
            <label for="orange-vp-qty">الكمية (للكومبو / حزمة شراء BOGO)</label>
            <input type="number" id="orange-vp-qty" class="admin-inp" min="1" step="1" value="1" dir="ltr" style="max-width:7rem;">
        </div>
        <div id="orange-vp-results" class="orange-vp-results"></div>
    </div>
</div>
