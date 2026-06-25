<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';
require_once __DIR__ . '/../../includes/delivery_areas.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$hasAreasTable = orange_table_exists($pdo, 'delivery_areas');
$hasGovTable = orange_delivery_governorates_table_exists($pdo);
$adminCountries = orange_countries_admin_list($pdo);
$adminCountryId = orange_admin_context_country_id($pdo);
$adminCountryRow = orange_country_row_by_id($pdo, $adminCountryId, false);
$activeAreasCount = $hasAreasTable ? orange_delivery_areas_count_active($pdo, $adminCountryId) : 0;
$daGovernoratesList = ($hasGovTable && $adminCountryId > 0)
    ? orange_delivery_governorates_admin_list($pdo, $adminCountryId)
    : [];
$daAreasList = $hasAreasTable
    ? orange_delivery_areas_admin_list($pdo, $adminCountryId > 0 ? $adminCountryId : null)
    : [];
$daNextSortOrder = $hasGovTable
    ? ''
    : (string) (int) orange_delivery_areas_next_sort_order($pdo, $adminCountryId, 0);
$daMoney = isset($orangeAdminMoney) && is_array($orangeAdminMoney)
    ? $orangeAdminMoney
    : orange_admin_currency_context($pdo);
$daMoneyDecimals = (int) ($daMoney['decimals'] ?? 3);
$daMoneyStep = isset($orangeAdminMoneyStep) && is_string($orangeAdminMoneyStep)
    ? $orangeAdminMoneyStep
    : orange_admin_money_input_step($daMoneyDecimals);
$daMoneyZero = isset($orangeAdminMoneyZero) && is_string($orangeAdminMoneyZero)
    ? $orangeAdminMoneyZero
    : orange_admin_money_zero_string($daMoneyDecimals);
$daPolicy = orange_delivery_country_policy_read($pdo, $adminCountryId);
$hasCompanyCostCol = orange_delivery_areas_has_company_cost_column($pdo);
$hasGovCompanyCol = orange_delivery_governorates_has_company_column($pdo);
$hasGovDefaultAmounts = orange_delivery_governorates_has_default_amounts_column($pdo);
$hasAreaFollowFlags = orange_delivery_areas_has_follow_flags_column($pdo);
$daDeliveryCompanies = ($hasGovCompanyCol && $adminCountryId > 0)
    ? orange_delivery_companies_list($pdo, $adminCountryId)
    : [];
?>
<div class="page-title">
    <h1>محافظات ومناطق التوصيل</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>
<?php if ($activeAreasCount === 0 && $hasAreasTable): ?>
<p class="card-hint" style="margin-top:0.5rem;color:#b45309;">لا توجد مناطق توصيل نشطة لهذه الدولة — العملاء لن يكملوا الطلب حتى تُفعَّل محافظة ومنطقة على الأقل.</p>
<?php endif; ?>

<?php if (!$hasAreasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>delivery_areas</code> غير جاهز.</div>
</div>
<?php endif; ?>

<?php if (count($adminCountries) > 0): ?>
<div class="card">
    <h3>الدولة</h3>
    <label for="da_admin_country">الدولة</label>
    <select id="da_admin_country" onchange="daSwitchAdminCountry(this.value)" style="max-width:320px;">
        <?php foreach ($adminCountries as $cRow): ?>
            <?php
            $cid = (int) ($cRow['id'] ?? 0);
            $code = htmlspecialchars((string) ($cRow['code'] ?? ''), ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars(trim((string) ($cRow['name_ar'] ?? '') . ' (' . (string) ($cRow['code'] ?? '') . ')'), ENT_QUOTES, 'UTF-8');
            ?>
        <option value="<?php echo $code; ?>"<?php echo $cid === $adminCountryId ? ' selected' : ''; ?>><?php echo $label; ?></option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>

<?php if ($hasGovTable): ?>
<div class="card">
    <h3>إضافة / تعديل محافظة</h3>
    <input type="hidden" id="dg_id" value="0">
    <input type="hidden" id="dg_country_id" value="<?php echo (int) $adminCountryId; ?>">
    <div class="form-grid da-gov-form-grid">
        <div class="da-gov-sort">
            <label for="dg_sort_order">الترتيب</label>
            <input type="number" id="dg_sort_order" class="admin-sort-field admin-sort-field--muted"
                value="<?php echo (int) orange_delivery_governorates_next_sort_order($pdo, $adminCountryId); ?>"
                disabled tabindex="-1" aria-readonly="true">
        </div>
        <div class="da-gov-active">
            <label for="dg_is_active" style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:1.4rem;">
                <input type="checkbox" id="dg_is_active" checked> محافظة نشطة
            </label>
        </div>
        <div class="da-gov-ar">
            <label for="dg_name_ar">اسم المحافظة (عربي) <span style="color:#b45309;">*</span></label>
            <input type="text" id="dg_name_ar" maxlength="191" autocomplete="off">
        </div>
        <div class="da-gov-en">
            <label for="dg_name_en">English</label>
            <input type="text" id="dg_name_en" maxlength="191" autocomplete="off" lang="en" dir="ltr">
        </div>
        <?php if ($hasGovCompanyCol): ?>
        <div class="da-gov-company">
            <label for="dg_delivery_company_code">شركة التوصيل (مورّد)</label>
            <input type="hidden" id="dg_delivery_company_id" value="0">
            <div class="dg-company-pick">
                <input type="text" id="dg_delivery_company_code" class="admin-inp dg-company-code" readonly placeholder="—" title="نقرتان لاختيار المورد" dir="ltr">
                <span id="dg_delivery_company_name" class="dg-company-name muted">لا يوجد شركة توصيل</span>
                <button type="button" class="btn-secondary" id="dg_company_clear_btn">مسح</button>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($hasGovDefaultAmounts): ?>
        <div class="da-gov-deffee">
            <label for="dg_default_delivery_fee">قيمة التوصيل (افتراضي المحافظة)</label>
            <div class="dg-default-amount">
                <input type="number" id="dg_default_delivery_fee" min="0" step="<?php echo htmlspecialchars($daMoneyStep, ENT_QUOTES, 'UTF-8'); ?>" lang="en" dir="ltr" placeholder="<?php echo htmlspecialchars($daMoneyZero, ENT_QUOTES, 'UTF-8'); ?>">
                <label class="dg-apply-all-label"><input type="checkbox" id="dg_fee_apply_all"> كل المناطق</label>
            </div>
        </div>
        <div class="da-gov-defcost">
            <label for="dg_default_company_cost">تكلفة التوصيل على الشركة (افتراضي المحافظة)</label>
            <div class="dg-default-amount">
                <input type="number" id="dg_default_company_cost" min="0" step="<?php echo htmlspecialchars($daMoneyStep, ENT_QUOTES, 'UTF-8'); ?>" lang="en" dir="ltr" placeholder="<?php echo htmlspecialchars($daMoneyZero, ENT_QUOTES, 'UTF-8'); ?>">
                <label class="dg-apply-all-label"><input type="checkbox" id="dg_cost_apply_all"> كل المناطق</label>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="gl-pick-modal" id="dg_company_pick_modal" hidden aria-hidden="true">
        <div class="gl-pick-modal__backdrop" id="dg_company_pick_backdrop"></div>
        <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="dg_company_pick_title">
            <h3 id="dg_company_pick_title" class="gl-pick-modal__title">اختيار شركة توصيل (مورّد)</h3>
            <p class="gl-pick-modal__hint muted" style="margin:0 0 8px;font-size:0.9rem;">نقرتان للاختيار</p>
            <input type="search" id="dg_company_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بالكود أو الاسم…" autocomplete="off" dir="rtl">
            <ul class="gl-pick-modal__list" id="dg_company_pick_list"></ul>
        </div>
    </div>
    <div class="admin-form-actions" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:12px;">
        <button type="button" onclick="saveGovernorate()">حفظ المحافظة</button>
        <button type="button" class="btn-secondary" onclick="translateGovernorateFromAr()">ترجمة تلقائية من العربي</button>
        <button type="button" class="btn-secondary" onclick="resetGovernorateForm()">محافظة جديدة</button>
    </div>
</div>

<div class="card">
    <h3>قائمة المحافظات</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>عربي</th>
                    <th>English</th>
                    <th>ترتيب</th>
                    <th>نشطة</th>
                    <th>عدد المناطق</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="dg_tbody">
                <?php foreach ($daGovernoratesList as $gRow): ?>
                <?php
                $gid = (int) ($gRow['id'] ?? 0);
                $gActive = (int) ($gRow['is_active'] ?? 0) === 1;
                ?>
                <tr>
                    <td><?php echo $gid; ?></td>
                    <td><?php echo htmlspecialchars((string) ($gRow['name_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars((string) ($gRow['name_en'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int) ($gRow['sort_order'] ?? 0); ?></td>
                    <td><?php echo $gActive ? 'نعم' : 'لا'; ?></td>
                    <td><?php echo (int) ($gRow['areas_count'] ?? 0); ?></td>
                    <td><button type="button" class="btn-secondary" data-dg-edit="<?php echo $gid; ?>">تعديل</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل منطقة توصيل</h3>
    <input type="hidden" id="da_id" value="0">
    <input type="hidden" id="da_country_id" value="<?php echo (int) $adminCountryId; ?>">
    <div class="form-grid da-area-form-grid">
        <div class="da-area-sort">
            <label for="da_sort_order">الترتيب</label>
            <input type="number" id="da_sort_order" class="admin-sort-field admin-sort-field--muted"
                value="<?php echo htmlspecialchars((string) $daNextSortOrder, ENT_QUOTES, 'UTF-8'); ?>"
                disabled tabindex="-1" aria-readonly="true">
        </div>
        <div class="da-area-active">
            <label for="da_is_active" style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:1.4rem;">
                <input type="checkbox" id="da_is_active" checked> منطقة توصيل
            </label>
        </div>
        <?php if ($hasGovTable): ?>
        <div class="da-area-gov">
            <label for="da_gov_combo_field">المحافظة <span style="color:#b45309;">*</span></label>
            <select id="da_governorate_id" required class="da-gov-combo__native">
                <option value="">اختر محافظة</option>
                <?php foreach ($daGovernoratesList as $gRow): ?>
                <?php
                $gid = (int) ($gRow['id'] ?? 0);
                $gLabel = trim((string) ($gRow['name_ar'] ?? ''));
                if ($gLabel === '') {
                    $gLabel = trim((string) ($gRow['name_en'] ?? ''));
                }
                if ($gLabel === '') {
                    $gLabel = (string) $gid;
                }
                if ((int) ($gRow['is_active'] ?? 0) !== 1) {
                    $gLabel .= ' (غير نشطة)';
                }
                ?>
                <option value="<?php echo $gid; ?>"><?php echo htmlspecialchars($gLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="da-gov-combo" id="da_gov_combo">
                <button type="button" class="da-gov-combo__field" id="da_gov_combo_field" aria-haspopup="listbox" aria-expanded="false">
                    <span class="da-gov-combo__text muted" id="da_gov_combo_text">اختر محافظة</span>
                    <span class="da-gov-combo__arrow" aria-hidden="true">▾</span>
                </button>
                <div class="da-gov-combo__panel" id="da_gov_combo_panel" hidden>
                    <input type="search" class="da-gov-combo__search admin-inp" id="da_gov_combo_q" placeholder="ابحث باسم المحافظة…" autocomplete="off" dir="rtl">
                    <ul class="da-gov-combo__list" id="da_gov_combo_list" role="listbox"></ul>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="da-area-ar">
            <label for="da_name_ar">اسم المنطقة (عربي) <span style="color:#b45309;">*</span></label>
            <input type="text" id="da_name_ar" maxlength="191" autocomplete="off">
        </div>
        <div class="da-area-en">
            <label for="da_name_en">English</label>
            <input type="text" id="da_name_en" maxlength="191" autocomplete="off" lang="en" dir="ltr">
        </div>
        <div class="da-area-fee">
            <label for="da_delivery_fee">قيمة التوصيل</label>
            <input type="number" id="da_delivery_fee" min="0" step="<?php echo htmlspecialchars($daMoneyStep, ENT_QUOTES, 'UTF-8'); ?>" lang="en" dir="ltr" value="<?php echo htmlspecialchars($daMoneyZero, ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($hasAreaFollowFlags && $hasGovDefaultAmounts): ?>
            <label class="da-follow-toggle"><input type="checkbox" id="da_fee_follows_gov"> <span class="da-follow-toggle__txt">تتبع المحافظة</span></label>
            <?php endif; ?>
        </div>
        <?php if ($hasCompanyCostCol): ?>
        <div class="da-area-company-cost">
            <label for="da_company_delivery_cost">تكلفة التوصيل على الشركة</label>
            <input type="number" id="da_company_delivery_cost" min="0" step="<?php echo htmlspecialchars($daMoneyStep, ENT_QUOTES, 'UTF-8'); ?>" lang="en" dir="ltr" value="<?php echo htmlspecialchars($daMoneyZero, ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($hasAreaFollowFlags && $hasGovDefaultAmounts): ?>
            <label class="da-follow-toggle"><input type="checkbox" id="da_cost_follows_gov"> <span class="da-follow-toggle__txt">تتبع المحافظة</span></label>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="admin-form-actions" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:12px;">
        <button type="button" onclick="saveDeliveryArea()" <?php echo !$hasAreasTable ? 'disabled' : ''; ?>>حفظ المنطقة</button>
        <button type="button" class="btn-secondary" onclick="translateDeliveryAreaFromAr()">ترجمة تلقائية من العربي</button>
        <button type="button" class="btn-secondary" onclick="resetDeliveryAreaForm()">منطقة جديدة</button>
    </div>
</div>

<div class="card">
    <div class="da-areas-list-head">
        <h3>قائمة المناطق</h3>
        <?php if ($hasGovTable): ?>
        <label for="da_list_all" class="da-list-all-label">
            <input type="checkbox" id="da_list_all" checked disabled>
            الكل
        </label>
        <?php endif; ?>
    </div>
    <?php if ($hasAreaFollowFlags && $hasGovDefaultAmounts): ?>
    <p class="da-amounts-legend muted"><span class="da-follow-badge">🔗 محافظة</span> = تتبع المحافظة (تتحدّث معها) • <span class="da-custom-badge">مخصّص</span> = قيمة خاصة بالمنطقة</p>
    <?php endif; ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <?php if ($hasGovTable): ?><th>المحافظة</th><?php endif; ?>
                    <th>عربي</th>
                    <th>English</th>
                    <th>قيمة التوصيل</th>
                    <?php if ($hasCompanyCostCol): ?><th>تكلفة التوصيل</th><?php endif; ?>
                    <th>ترتيب</th>
                    <th>منطقة توصيل</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="da_tbody">
                <?php foreach ($daAreasList as $aRow): ?>
                <?php
                $aid = (int) ($aRow['id'] ?? 0);
                $aActive = (int) ($aRow['is_active'] ?? 0) === 1;
                $aPending = (int) ($aRow['delivery_fee_pending'] ?? 0) === 1 && $aActive;
                $govLabel = trim((string) ($aRow['governorate_name_ar'] ?? ''));
                if ($govLabel === '') {
                    $govLabel = trim((string) ($aRow['governorate_name_en'] ?? ''));
                }
                if ($govLabel === '') {
                    $govLabel = '—';
                }
                ?>
                <tr class="<?php echo $aPending ? 'da-area-pending-row' : ''; ?>">
                    <td><?php echo $aid; ?></td>
                    <?php if ($hasGovTable): ?>
                    <td><?php echo htmlspecialchars($govLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                    <?php endif; ?>
                    <td><?php echo htmlspecialchars((string) ($aRow['name_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars((string) ($aRow['name_en'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr">
                        <?php if ($aPending): ?>
                            <span class="da-pending-fee-badge">بانتظار التحديد</span>
                        <?php else: ?>
                            <?php echo htmlspecialchars(number_format(max(0.0, (float) ($aRow['delivery_fee'] ?? 0)), $daMoneyDecimals, '.', ''), ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                        <?php if ($hasAreaFollowFlags && $hasGovDefaultAmounts): ?>
                            <?php if ((int) ($aRow['fee_follows_gov'] ?? 0) === 1): ?>
                                <span class="da-follow-badge" title="موروثة من المحافظة">🔗 محافظة</span>
                            <?php else: ?>
                                <span class="da-custom-badge">مخصّص</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <?php if ($hasCompanyCostCol): ?>
                    <td dir="ltr">
                        <?php echo htmlspecialchars(number_format(max(0.0, (float) ($aRow['company_delivery_cost'] ?? 0)), $daMoneyDecimals, '.', ''), ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ($hasAreaFollowFlags && $hasGovDefaultAmounts): ?>
                            <?php if ((int) ($aRow['cost_follows_gov'] ?? 0) === 1): ?>
                                <span class="da-follow-badge" title="موروثة من المحافظة">🔗 محافظة</span>
                            <?php else: ?>
                                <span class="da-custom-badge">مخصّص</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td><?php echo (int) ($aRow['sort_order'] ?? 0); ?></td>
                    <td><?php echo $aActive ? ($aPending ? 'منطقة توصيل (بانتظار السعر)' : 'منطقة توصيل') : 'غير متاحة للتوصيل'; ?></td>
                    <td><button type="button" class="btn-secondary" data-da-edit="<?php echo $aid; ?>">تعديل</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
    .da-gov-form-grid,
    .da-area-form-grid {
        display: grid;
        gap: 12px 16px;
        align-items: end;
    }
    .da-gov-form-grid {
        grid-template-columns: 1fr 1fr;
        grid-template-areas:
            "sort active"
            "ar en"
            "company company"
            "deffee defcost";
    }
    .da-gov-ar { grid-area: ar; }
    .da-gov-en { grid-area: en; }
    .da-gov-sort { grid-area: sort; }
    .da-gov-active { grid-area: active; }
    .da-gov-company { grid-area: company; }
    .da-gov-deffee { grid-area: deffee; }
    .da-gov-defcost { grid-area: defcost; }
    .dg-default-amount {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .dg-default-amount input[type="number"] {
        max-width: 160px;
    }
    .dg-apply-all-label {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
        user-select: none;
        font-size: 0.9rem;
        white-space: nowrap;
    }
    .dg-company-pick {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
    }
    .dg-company-pick .dg-company-code {
        max-width: 140px;
        cursor: pointer;
        background: #fff;
    }
    .dg-company-pick .dg-company-name {
        font-weight: 600;
    }
    .da-area-form-grid {
        grid-template-columns: 1fr 1fr;
        grid-template-areas:
            "sort active"
            "gov gov"
            "ar en"
            "fee cost";
    }
    .da-area-gov { grid-area: gov; }
    .da-area-ar { grid-area: ar; }
    .da-area-en { grid-area: en; }
    .da-area-fee { grid-area: fee; }
    .da-area-company-cost { grid-area: cost; }
    .da-area-sort { grid-area: sort; }
    .da-area-active { grid-area: active; }
    .da-gov-combo__native { display: none; }
    .da-gov-combo { position: relative; }
    .da-gov-combo__field {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        width: 100%;
        min-height: 42px;
        padding: 0 12px;
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        font: inherit;
        color: inherit;
        cursor: pointer;
        text-align: right;
    }
    .da-gov-combo__field:hover { border-color: #94a3b8; }
    .da-gov-combo__text {
        flex: 1;
        text-align: right;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .da-gov-combo__arrow { color: #64748b; font-size: 0.85rem; }
    .da-gov-combo__panel {
        position: absolute;
        z-index: 60;
        top: calc(100% + 4px);
        right: 0;
        left: 0;
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
        padding: 8px;
    }
    .da-gov-combo__panel[hidden] { display: none; }
    .da-gov-combo__search { width: 100%; margin-bottom: 8px; }
    .da-gov-combo__list {
        list-style: none;
        margin: 0;
        padding: 0;
        max-height: 240px;
        overflow-y: auto;
    }
    .da-gov-combo__opt {
        padding: 9px 10px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
    }
    .da-gov-combo__opt:hover { background: #e0e7ff; }
    .da-gov-combo__opt.is-selected { font-weight: 700; background: #eef2ff; }
    .da-gov-combo__empty { padding: 12px; color: #64748b; font-size: 13px; }
    .da-areas-list-head {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 12px 20px;
        margin-bottom: 12px;
    }
    .da-areas-list-head h3 {
        margin: 0;
    }
    .da-list-all-label {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        margin: 0;
        user-select: none;
    }
    .da-list-all-label input:disabled {
        cursor: not-allowed;
    }
    .da-area-pending-row {
        background: #fee2e2;
    }
    .da-pending-fee-badge {
        display: inline-block;
        color: #991b1b;
        font-weight: 700;
    }
    .da-follow-badge {
        display: inline-block;
        margin-inline-start: 6px;
        padding: 1px 6px;
        border-radius: 6px;
        background: #f1f5f9;
        color: #64748b;
        font-size: 0.72rem;
        font-weight: 600;
        white-space: nowrap;
    }
    .da-custom-badge {
        display: inline-block;
        margin-inline-start: 6px;
        padding: 1px 6px;
        border-radius: 6px;
        background: var(--primary-soft, #fff1e6);
        color: var(--primary-hover, #c2410c);
        font-size: 0.72rem;
        font-weight: 700;
        white-space: nowrap;
    }
    .da-amounts-legend {
        margin: 0 0 10px;
        font-size: 0.82rem;
    }
    .da-follow-toggle {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
        user-select: none;
        margin-top: 4px;
        font-size: 0.85rem;
    }
    .da-area-fee input.is-following,
    .da-area-company-cost input.is-following {
        background: #f8fafc;
        color: #64748b;
    }
    @media (max-width: 720px) {
        .da-gov-form-grid,
        .da-area-form-grid {
            grid-template-columns: 1fr;
            grid-template-areas: unset;
        }
        .da-gov-ar, .da-gov-en, .da-gov-sort, .da-gov-active, .da-gov-company, .da-gov-deffee, .da-gov-defcost,
        .da-area-gov, .da-area-ar, .da-area-en, .da-area-fee, .da-area-company-cost, .da-area-sort, .da-area-active {
            grid-area: unset;
        }
    }
</style>

<script>
let daArTimer = null;
let daEnTimer = null;
let dgArTimer = null;
let dgEnTimer = null;
let daGovernoratesCache = <?php echo json_encode($daGovernoratesList, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
let daDeliveryAreasCache = <?php echo json_encode(array_values($daAreasList), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var dgSortStep = <?php echo (int) orange_delivery_governorates_sort_order_step(); ?>;
var daSortStep = dgSortStep;
var daMoneyDecimals = <?php echo (int) $daMoneyDecimals; ?>;
var daMoneyZero = <?php echo json_encode($daMoneyZero, JSON_UNESCAPED_UNICODE); ?>;
var daAreaDefaultFee = <?php echo json_encode((float) ($daPolicy['default_delivery_fee'] ?? 0), JSON_UNESCAPED_UNICODE); ?>;
var dgCompanies = <?php echo json_encode(array_values($daDeliveryCompanies), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function dgCompanyNoneLabel() { return 'لا يوجد شركة توصيل'; }

function dgSetCompany(id) {
    var hid = document.getElementById('dg_delivery_company_id');
    var codeEl = document.getElementById('dg_delivery_company_code');
    var nameEl = document.getElementById('dg_delivery_company_name');
    if (!hid) return;
    var cid = parseInt(id, 10) || 0;
    var match = null;
    if (cid > 0) {
        match = dgCompanies.find(function (c) { return parseInt(c.id, 10) === cid; }) || null;
    }
    if (match) {
        hid.value = String(cid);
        if (codeEl) codeEl.value = String(match.code || '');
        if (nameEl) {
            nameEl.textContent = String(match.name_ar || '');
            nameEl.classList.remove('muted');
        }
    } else {
        hid.value = '0';
        if (codeEl) codeEl.value = '';
        if (nameEl) {
            nameEl.textContent = dgCompanyNoneLabel();
            nameEl.classList.add('muted');
        }
    }
}

function dgCompanyPickerClose() {
    var pm = document.getElementById('dg_company_pick_modal');
    if (pm) {
        pm.hidden = true;
        pm.setAttribute('aria-hidden', 'true');
    }
    document.body.classList.remove('gl-pick-open');
}

function dgCompanyPickerRender(q) {
    var list = document.getElementById('dg_company_pick_list');
    if (!list) return;
    var needle = String(q || '').trim().toLowerCase();
    list.innerHTML = '';
    var rows = dgCompanies.filter(function (c) {
        if (needle === '') return true;
        var code = String(c.code || '').toLowerCase();
        var name = String(c.name_ar || '').toLowerCase();
        return code.indexOf(needle) !== -1 || name.indexOf(needle) !== -1;
    });
    if (rows.length === 0) {
        list.innerHTML = '<li class="gl-pick-empty">لا توجد نتائج</li>';
        return;
    }
    rows.forEach(function (c) {
        var li = document.createElement('li');
        li.className = 'gl-pick-item';
        li.tabIndex = 0;
        var code = String(c.code || '');
        var name = String(c.name_ar || '');
        li.textContent = (code !== '' ? code + ' — ' : '') + name;
        li.addEventListener('dblclick', function () {
            dgSetCompany(c.id);
            dgCompanyPickerClose();
        });
        li.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') {
                dgSetCompany(c.id);
                dgCompanyPickerClose();
            }
        });
        list.appendChild(li);
    });
}

function dgCompanyPickerOpen() {
    var pm = document.getElementById('dg_company_pick_modal');
    var q = document.getElementById('dg_company_pick_q');
    if (!pm || !q) return;
    q.value = '';
    dgCompanyPickerRender('');
    pm.hidden = false;
    pm.setAttribute('aria-hidden', 'false');
    document.body.classList.add('gl-pick-open');
    q.focus();
}

function dgComputeNextSort() {
    var max = 0;
    daGovernoratesCache.forEach(function (g) {
        var so = parseInt(g.sort_order, 10) || 0;
        if (so > max) max = so;
    });
    return max <= 0 ? dgSortStep : max + dgSortStep;
}

function refreshDgSortPreview() {
    var idEl = document.getElementById('dg_id');
    var sortEl = document.getElementById('dg_sort_order');
    if (!idEl || !sortEl) return;
    if (parseInt(idEl.value, 10) > 0) return;
    sortEl.value = String(dgComputeNextSort());
}

function daSelectedGovernorateId() {
    var sel = document.getElementById('da_governorate_id');
    if (!sel) return 0;
    return parseInt(sel.value, 10) || 0;
}

function daSyncListAllCheckbox() {
    var allCb = document.getElementById('da_list_all');
    if (!allCb) return;
    var govId = daSelectedGovernorateId();
    if (govId <= 0) {
        allCb.checked = true;
        allCb.disabled = true;
    } else {
        allCb.disabled = false;
    }
}

function daAreasForList() {
    var rows = daDeliveryAreasCache || [];
    var allCb = document.getElementById('da_list_all');
    if (!allCb || allCb.checked) {
        return rows;
    }
    var govId = daSelectedGovernorateId();
    if (govId <= 0) {
        return rows;
    }
    return rows.filter(function (r) {
        return parseInt(r.governorate_id, 10) === govId;
    });
}

var daHasGovCol = <?php echo $hasGovTable ? 'true' : 'false'; ?>;
var daHasCompanyCostCol = <?php echo $hasCompanyCostCol ? 'true' : 'false'; ?>;
var daHasFollowFeature = <?php echo ($hasAreaFollowFlags && $hasGovDefaultAmounts) ? 'true' : 'false'; ?>;

function daGovDefaultsFor(govId) {
    var gid = parseInt(govId, 10) || 0;
    if (gid <= 0) return { fee: null, cost: null };
    var g = (daGovernoratesCache || []).find(function (x) { return parseInt(x.id, 10) === gid; });
    if (!g) return { fee: null, cost: null };
    return {
        fee: (g.default_delivery_fee === null || g.default_delivery_fee === undefined) ? null : g.default_delivery_fee,
        cost: (g.default_company_delivery_cost === null || g.default_company_delivery_cost === undefined) ? null : g.default_company_delivery_cost
    };
}

function daSyncFollowField(kind) {
    if (!daHasFollowFeature) return;
    var cb = document.getElementById(kind === 'fee' ? 'da_fee_follows_gov' : 'da_cost_follows_gov');
    var inp = document.getElementById(kind === 'fee' ? 'da_delivery_fee' : 'da_company_delivery_cost');
    var txt = cb ? cb.parentElement.querySelector('.da-follow-toggle__txt') : null;
    if (!cb || !inp) return;
    var defs = daGovDefaultsFor(daSelectedGovernorateId());
    var defVal = kind === 'fee' ? defs.fee : defs.cost;
    if (cb.checked) {
        inp.classList.add('is-following');
        inp.value = (defVal === null) ? '' : daFormatMoney(defVal);
        if (txt) txt.textContent = (defVal === null) ? 'تتبع المحافظة (لا قيمة افتراضية بعد)' : 'تتبع المحافظة';
    } else {
        inp.classList.remove('is-following');
        if (txt) txt.textContent = 'مخصّص';
    }
}

function daDetachFollow(kind) {
    if (!daHasFollowFeature) return;
    var cb = document.getElementById(kind === 'fee' ? 'da_fee_follows_gov' : 'da_cost_follows_gov');
    if (cb && cb.checked) {
        cb.checked = false;
        daSyncFollowField(kind);
    }
}

function renderDeliveryAreasTable() {
    var tb = document.getElementById('da_tbody');
    if (!tb) return;
    var rows = daAreasForList();
    tb.innerHTML = '';
    rows.forEach(function (r) {
        var tr = document.createElement('tr');
        var canDeliver = parseInt(r.is_active, 10) === 1;
        var feePending = canDeliver && parseInt(r.delivery_fee_pending, 10) === 1;
        if (feePending) {
            tr.classList.add('da-area-pending-row');
        }
        var feeText = feePending
            ? '<span class="da-pending-fee-badge">بانتظار التحديد</span>'
            : escHtml(daFormatMoney(r.delivery_fee));
        var deliverLabel = canDeliver
            ? (feePending ? 'منطقة توصيل (بانتظار السعر)' : 'منطقة توصيل')
            : 'غير متاحة للتوصيل';
        var html = '<td>' + escHtml(String(r.id)) + '</td>';
        if (daHasGovCol) {
            html += '<td>' + escHtml(String(r.governorate_name_ar || r.governorate_name_en || '—')) + '</td>';
        }
        var feeBadge = daHasFollowFeature
            ? (parseInt(r.fee_follows_gov, 10) === 1
                ? ' <span class="da-follow-badge" title="موروثة من المحافظة">🔗 محافظة</span>'
                : ' <span class="da-custom-badge">مخصّص</span>')
            : '';
        var costBadge = daHasFollowFeature
            ? (parseInt(r.cost_follows_gov, 10) === 1
                ? ' <span class="da-follow-badge" title="موروثة من المحافظة">🔗 محافظة</span>'
                : ' <span class="da-custom-badge">مخصّص</span>')
            : '';
        html +=
            '<td>' + escHtml(String(r.name_ar || '')) + '</td>' +
            '<td dir="ltr">' + escHtml(String(r.name_en || '')) + '</td>' +
            '<td dir="ltr">' + feeText + feeBadge + '</td>';
        if (daHasCompanyCostCol) {
            html += '<td dir="ltr">' + escHtml(daFormatMoney(r.company_delivery_cost != null ? r.company_delivery_cost : 0)) + costBadge + '</td>';
        }
        html +=
            '<td>' + escHtml(String(r.sort_order != null ? r.sort_order : '')) + '</td>' +
            '<td>' + deliverLabel + '</td>' +
            '<td><button type="button" class="btn-secondary" data-da-edit="' + escAttr(String(r.id)) + '">تعديل</button></td>';
        tr.innerHTML = html;
        tb.appendChild(tr);
    });
    bindDeliveryAreaEditButtons();
}

function onDaGovernorateChange() {
    daSyncListAllCheckbox();
    if (daHasFollowFeature) {
        daSyncFollowField('fee');
        daSyncFollowField('cost');
    }
    refreshDaSortPreview();
    renderDeliveryAreasTable();
}

function daComputeNextSort() {
    var govId = daSelectedGovernorateId();
    var max = 0;
    daDeliveryAreasCache.forEach(function (r) {
        if (govId > 0 && parseInt(r.governorate_id, 10) !== govId) return;
        var so = parseInt(r.sort_order, 10) || 0;
        if (so > max) max = so;
    });
    return max <= 0 ? daSortStep : max + daSortStep;
}

function refreshDaSortPreview() {
    var idEl = document.getElementById('da_id');
    var sortEl = document.getElementById('da_sort_order');
    if (!idEl || !sortEl) return;
    if (parseInt(idEl.value, 10) > 0) return;
    var govId = daSelectedGovernorateId();
    var hasGovSel = !!document.getElementById('da_governorate_id');
    if (hasGovSel && govId <= 0) {
        sortEl.value = '';
        return;
    }
    sortEl.value = String(daComputeNextSort());
}

function daCountryId() {
    return parseInt(document.getElementById('da_country_id').value, 10) || 0;
}

function daSwitchAdminCountry(code) {
    if (!code) return;
    var u = new URL(window.location.href);
    u.searchParams.set('page', 'delivery_areas');
    u.searchParams.set('admin_country', String(code));
    window.location.href = u.pathname + '?' + u.searchParams.toString();
}

function escHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/"/g, '&quot;');
}
function escAttr(s) {
    return String(s).replace(/"/g, '&quot;');
}

function daParseMoney(v) {
    var raw = String(v == null ? '' : v).trim().replace(',', '.');
    if (raw === '') return 0;
    var n = parseFloat(raw);
    if (!Number.isFinite(n) || n < 0) return NaN;
    return n;
}

function daRoundMoney(v) {
    var n = Number(v);
    if (!Number.isFinite(n) || n < 0) return 0;
    var p = Math.pow(10, daMoneyDecimals);
    return Math.round(n * p) / p;
}

function daFormatMoney(v) {
    var n = daRoundMoney(daParseMoney(v));
    return n.toFixed(daMoneyDecimals);
}

async function translateNames(arId, enId, opts) {
    const silent = !!(opts && opts.silent);
    const forceFromArabic = !!(opts && opts.forceFromArabic);
    try {
        const res = await postJSON('/admin/api/translate/names.php', {
            name_ar: document.getElementById(arId).value.trim(),
            name_en: forceFromArabic ? '' : document.getElementById(enId).value.trim()
        });
        if (!res || !res.success) {
            if (!silent) alert((res && res.message) ? res.message : 'فشل الترجمة');
            return;
        }
        const t = res.translations || {};
        if (t.name_en) document.getElementById(enId).value = t.name_en;
    } catch (e) {
        if (!silent) alert('فشل طلب الترجمة');
    }
}

function dgSetDefaultAmount(id, val) {
    var el = document.getElementById(id);
    if (!el) return;
    el.value = (val === null || val === undefined || val === '') ? '' : daFormatMoney(val);
}

function resetGovernorateForm() {
    document.getElementById('dg_id').value = '0';
    document.getElementById('dg_name_ar').value = '';
    document.getElementById('dg_name_en').value = '';
    document.getElementById('dg_is_active').checked = true;
    dgSetCompany(0);
    dgSetDefaultAmount('dg_default_delivery_fee', '');
    dgSetDefaultAmount('dg_default_company_cost', '');
    var fa = document.getElementById('dg_fee_apply_all');
    if (fa) fa.checked = false;
    var ca = document.getElementById('dg_cost_apply_all');
    if (ca) ca.checked = false;
    refreshDgSortPreview();
}

function editGovernorate(row) {
    document.getElementById('dg_id').value = String(row.id != null ? row.id : 0);
    document.getElementById('dg_name_ar').value = row.name_ar || '';
    document.getElementById('dg_name_en').value = row.name_en || '';
    document.getElementById('dg_sort_order').value = String(row.sort_order != null ? row.sort_order : 0);
    document.getElementById('dg_is_active').checked = parseInt(row.is_active, 10) === 1;
    dgSetCompany((row.delivery_company_id && parseInt(row.delivery_company_id, 10) > 0) ? row.delivery_company_id : 0);
    dgSetDefaultAmount('dg_default_delivery_fee', (row.default_delivery_fee === null || row.default_delivery_fee === undefined) ? '' : row.default_delivery_fee);
    dgSetDefaultAmount('dg_default_company_cost', (row.default_company_delivery_cost === null || row.default_company_delivery_cost === undefined) ? '' : row.default_company_delivery_cost);
    var fa = document.getElementById('dg_fee_apply_all');
    if (fa) fa.checked = false;
    var ca = document.getElementById('dg_cost_apply_all');
    if (ca) ca.checked = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function loadGovernorates() {
    const tb = document.getElementById('dg_tbody');
    if (!tb) return;
    const res = await postJSON('/admin/api/delivery_areas/manage.php', {
        action: 'list_governorates',
        country_id: daCountryId()
    });
    if (!res || !res.success) {
        if (res && res.message) {
            alert(res.message);
        }
        bindGovernorateEditButtons();
        refreshDgSortPreview();
        refreshDaSortPreview();
        return;
    }
    daGovernoratesCache = res.data || [];
    if (daGovernoratesCache.length === 0 && tb.querySelector('tr')) {
        bindGovernorateEditButtons();
        refreshDgSortPreview();
        refreshDaSortPreview();
        return;
    }
    tb.innerHTML = '';
    const sel = document.getElementById('da_governorate_id');
    if (sel) {
        const cur = sel.value;
        sel.innerHTML = '<option value="">اختر محافظة</option>';
        daGovernoratesCache.forEach(function (g) {
            const opt = document.createElement('option');
            opt.value = String(g.id);
            const label = String(g.name_ar || g.name_en || g.id);
            opt.textContent = label + (parseInt(g.is_active, 10) === 1 ? '' : ' (غير نشطة)');
            sel.appendChild(opt);
        });
        if (cur && daGovernoratesCache.some(function (g) { return String(g.id) === String(cur); })) {
            sel.value = cur;
        } else {
            sel.value = '';
        }
        if (typeof daGovComboSync === 'function') daGovComboSync();
    }
    daGovernoratesCache.forEach(function (g) {
        const tr = document.createElement('tr');
        const active = parseInt(g.is_active, 10) === 1;
        tr.innerHTML =
            '<td>' + escHtml(String(g.id)) + '</td>' +
            '<td>' + escHtml(String(g.name_ar || '')) + '</td>' +
            '<td dir="ltr">' + escHtml(String(g.name_en || '')) + '</td>' +
            '<td>' + escHtml(String(g.sort_order != null ? g.sort_order : '')) + '</td>' +
            '<td>' + (active ? 'نعم' : 'لا') + '</td>' +
            '<td>' + escHtml(String(g.areas_count != null ? g.areas_count : '0')) + '</td>' +
            '<td><button type="button" class="btn-secondary" data-dg-edit="' + escAttr(String(g.id)) + '">تعديل</button></td>';
        tb.appendChild(tr);
    });
    bindGovernorateEditButtons();
    refreshDgSortPreview();
    refreshDaSortPreview();
}

function bindGovernorateEditButtons() {
    const tb = document.getElementById('dg_tbody');
    if (!tb) return;
    tb.querySelectorAll('[data-dg-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = parseInt(btn.getAttribute('data-dg-edit'), 10);
            const row = daGovernoratesCache.find(function (x) { return parseInt(x.id, 10) === id; });
            if (row) editGovernorate(row);
        });
    });
}

async function saveGovernorate() {
    var coSel = document.getElementById('dg_delivery_company_id');
    const payload = {
        action: 'save_governorate',
        id: parseInt(document.getElementById('dg_id').value, 10) || 0,
        country_id: daCountryId(),
        name_ar: document.getElementById('dg_name_ar').value.trim(),
        name_en: document.getElementById('dg_name_en').value.trim(),
        is_active: document.getElementById('dg_is_active').checked ? 1 : 0
    };
    if (coSel) {
        payload.delivery_company_id = parseInt(coSel.value, 10) || 0;
    }
    var defFeeEl = document.getElementById('dg_default_delivery_fee');
    var defCostEl = document.getElementById('dg_default_company_cost');
    var feeApplyAllEl = document.getElementById('dg_fee_apply_all');
    var costApplyAllEl = document.getElementById('dg_cost_apply_all');
    if (defFeeEl || defCostEl) {
        payload.default_delivery_fee = defFeeEl ? defFeeEl.value.trim() : '';
        payload.default_company_delivery_cost = defCostEl ? defCostEl.value.trim() : '';
        var feeApplyAll = !!(feeApplyAllEl && feeApplyAllEl.checked);
        var costApplyAll = !!(costApplyAllEl && costApplyAllEl.checked);
        if (feeApplyAll || costApplyAll) {
            if (!confirm('«كل المناطق» مفعّلة: سيتم فرض قيمة/تكلفة المحافظة على كل مناطقها بما فيها المناطق المخصّصة (تُدوَّس قيمها). متابعة؟')) {
                return;
            }
        }
        payload.fee_apply_all = feeApplyAll ? 1 : 0;
        payload.cost_apply_all = costApplyAll ? 1 : 0;
    }
    const res = await postJSON('/admin/api/delivery_areas/manage.php', payload);
    alert(res.message || (res.success ? 'تم' : 'فشل'));
    if (res.success) {
        resetGovernorateForm();
        loadGovernorates();
        loadDeliveryAreas();
    }
}

function resetDeliveryAreaForm() {
    document.getElementById('da_id').value = '0';
    document.getElementById('da_name_ar').value = '';
    document.getElementById('da_name_en').value = '';
    document.getElementById('da_delivery_fee').value = daFormatMoney(daAreaDefaultFee);
    document.getElementById('da_is_active').checked = true;
    var ccEl = document.getElementById('da_company_delivery_cost');
    if (ccEl) ccEl.value = daFormatMoney(0);
    const sel = document.getElementById('da_governorate_id');
    if (sel) sel.value = '';
    daGovComboSync();
    if (daHasFollowFeature) {
        var nf = document.getElementById('da_fee_follows_gov');
        var nc = document.getElementById('da_cost_follows_gov');
        if (nf) nf.checked = true;
        if (nc) nc.checked = true;
        daSyncFollowField('fee');
        daSyncFollowField('cost');
    }
    daSyncListAllCheckbox();
    refreshDaSortPreview();
    renderDeliveryAreasTable();
}

function editDeliveryArea(row) {
    document.getElementById('da_id').value = String(row.id != null ? row.id : 0);
    document.getElementById('da_name_ar').value = row.name_ar || '';
    document.getElementById('da_name_en').value = row.name_en || '';
    var isPending = parseInt(row.delivery_fee_pending, 10) === 1 && parseInt(row.is_active, 10) === 1;
    document.getElementById('da_delivery_fee').value = isPending ? '' : daFormatMoney(row.delivery_fee);
    document.getElementById('da_sort_order').value = String(row.sort_order != null ? row.sort_order : 0);
    document.getElementById('da_is_active').checked = parseInt(row.is_active, 10) === 1;
    const sel = document.getElementById('da_governorate_id');
    if (sel && row.governorate_id) {
        sel.value = String(row.governorate_id);
    }
    daGovComboSync();
    var ccEl = document.getElementById('da_company_delivery_cost');
    if (ccEl) {
        ccEl.value = daFormatMoney(row.company_delivery_cost != null ? row.company_delivery_cost : 0);
    }
    if (daHasFollowFeature) {
        var ff = document.getElementById('da_fee_follows_gov');
        var cf = document.getElementById('da_cost_follows_gov');
        if (ff) ff.checked = parseInt(row.fee_follows_gov, 10) === 1;
        if (cf) cf.checked = parseInt(row.cost_follows_gov, 10) === 1;
        daSyncFollowField('fee');
        daSyncFollowField('cost');
    }
    daSyncListAllCheckbox();
    refreshDaSortPreview();
    document.querySelector('.da-area-form-grid').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function loadDeliveryAreas() {
    const tb = document.getElementById('da_tbody');
    if (!tb) return;
    const res = await postJSON('/admin/api/delivery_areas/manage.php', {
        action: 'list',
        country_id: daCountryId()
    });
    if (!res || !res.success) {
        if (res && res.message) {
            alert(res.message);
        }
        bindDeliveryAreaEditButtons();
        refreshDaSortPreview();
        return;
    }
    daDeliveryAreasCache = res.data || [];
    if (daDeliveryAreasCache.length === 0 && tb.querySelector('tr')) {
        daSyncListAllCheckbox();
        bindDeliveryAreaEditButtons();
        refreshDaSortPreview();
        return;
    }
    daSyncListAllCheckbox();
    renderDeliveryAreasTable();
    refreshDaSortPreview();
}

function bindDeliveryAreaEditButtons() {
    const tb = document.getElementById('da_tbody');
    if (!tb) return;
    tb.querySelectorAll('[data-da-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = parseInt(btn.getAttribute('data-da-edit'), 10);
            const row = daDeliveryAreasCache.find(function (x) { return parseInt(x.id, 10) === id; });
            if (row) editDeliveryArea(row);
        });
    });
}

async function saveDeliveryArea() {
    const govEl = document.getElementById('da_governorate_id');
    const isActive = document.getElementById('da_is_active').checked ? 1 : 0;
    var feeFollowsEl = document.getElementById('da_fee_follows_gov');
    var costFollowsEl = document.getElementById('da_cost_follows_gov');
    var feeFollows = !!(daHasFollowFeature && feeFollowsEl && feeFollowsEl.checked);
    var costFollows = !!(daHasFollowFeature && costFollowsEl && costFollowsEl.checked);
    const feeRaw = String(document.getElementById('da_delivery_fee').value || '').trim();
    let feePayload = '';
    if (feeRaw !== '') {
        const feeVal = daParseMoney(feeRaw);
        if (!Number.isFinite(feeVal) || feeVal < 0) {
            alert('قيمة التوصيل غير صحيحة');
            return;
        }
        const roundedFee = Number(daRoundMoney(feeVal).toFixed(daMoneyDecimals));
        if (isActive === 1 && roundedFee <= 0 && !feeFollows) {
            alert('لا يمكن تنشيط منطقة بسعر صفر. أدخل قيمة أكبر من صفر أو اترك الحقل فارغاً لحالة "بانتظار التحديد".');
            return;
        }
        feePayload = roundedFee;
    }
    const payload = {
        action: 'save',
        id: parseInt(document.getElementById('da_id').value, 10) || 0,
        country_id: daCountryId(),
        name_ar: document.getElementById('da_name_ar').value.trim(),
        name_en: document.getElementById('da_name_en').value.trim(),
        delivery_fee: feePayload,
        is_active: isActive
    };
    if (daHasFollowFeature) {
        payload.fee_follows_gov = feeFollows ? 1 : 0;
        payload.cost_follows_gov = costFollows ? 1 : 0;
    }
    var ccEl = document.getElementById('da_company_delivery_cost');
    if (ccEl) {
        const ccRaw = String(ccEl.value || '').trim();
        if (ccRaw === '') {
            payload.company_delivery_cost = 0;
        } else {
            const ccVal = daParseMoney(ccRaw);
            if (!Number.isFinite(ccVal) || ccVal < 0) {
                alert('تكلفة التوصيل على الشركة غير صحيحة');
                return;
            }
            payload.company_delivery_cost = Number(daRoundMoney(ccVal).toFixed(daMoneyDecimals));
        }
    }
    if (govEl) {
        payload.governorate_id = parseInt(govEl.value, 10) || 0;
        if (!payload.governorate_id) {
            alert('اختر المحافظة');
            return;
        }
    }
    if (!payload.name_ar) {
        alert('اسم المنطقة بالعربي مطلوب');
        return;
    }
    const res = await postJSON('/admin/api/delivery_areas/manage.php', payload);
    alert(res.message || (res.success ? 'تم' : 'فشل'));
    if (res.success) {
        resetDeliveryAreaForm();
        loadGovernorates();
        loadDeliveryAreas();
    }
}

function scheduleDaFromAr() {
    const ar = document.getElementById('da_name_ar').value.trim();
    if (!ar) {
        document.getElementById('da_name_en').value = '';
        return;
    }
    clearTimeout(daArTimer);
    daArTimer = setTimeout(function () {
        translateNames('da_name_ar', 'da_name_en', { silent: true, forceFromArabic: true });
    }, 700);
}
function scheduleDaFromEn() {
    const en = document.getElementById('da_name_en').value.trim();
    if (!en) return;
    clearTimeout(daEnTimer);
    daEnTimer = setTimeout(function () {
        translateNames('da_name_ar', 'da_name_en', { silent: true, forceFromArabic: false });
    }, 600);
}
async function translateDeliveryAreaFromAr() {
    await translateNames('da_name_ar', 'da_name_en', { silent: false, forceFromArabic: true });
}

function scheduleDgFromAr() {
    const el = document.getElementById('dg_name_ar');
    if (!el) return;
    const ar = el.value.trim();
    if (!ar) {
        document.getElementById('dg_name_en').value = '';
        return;
    }
    clearTimeout(dgArTimer);
    dgArTimer = setTimeout(function () {
        translateNames('dg_name_ar', 'dg_name_en', { silent: true, forceFromArabic: true });
    }, 700);
}
function scheduleDgFromEn() {
    const el = document.getElementById('dg_name_en');
    if (!el) return;
    const en = el.value.trim();
    if (!en) return;
    clearTimeout(dgEnTimer);
    dgEnTimer = setTimeout(function () {
        translateNames('dg_name_ar', 'dg_name_en', { silent: true, forceFromArabic: false });
    }, 600);
}
async function translateGovernorateFromAr() {
    await translateNames('dg_name_ar', 'dg_name_en', { silent: false, forceFromArabic: true });
}

document.getElementById('da_name_ar').addEventListener('input', scheduleDaFromAr);
document.getElementById('da_name_en').addEventListener('input', scheduleDaFromEn);
const dgAr = document.getElementById('dg_name_ar');
const dgEn = document.getElementById('dg_name_en');
if (dgAr) dgAr.addEventListener('input', scheduleDgFromAr);
if (dgEn) dgEn.addEventListener('input', scheduleDgFromEn);
const daGovSel = document.getElementById('da_governorate_id');
if (daGovSel) daGovSel.addEventListener('change', onDaGovernorateChange);
const daListAll = document.getElementById('da_list_all');
if (daListAll) daListAll.addEventListener('change', renderDeliveryAreasTable);
if (daHasFollowFeature) {
    var daFeeFollowCb = document.getElementById('da_fee_follows_gov');
    var daCostFollowCb = document.getElementById('da_cost_follows_gov');
    if (daFeeFollowCb) daFeeFollowCb.addEventListener('change', function () { daSyncFollowField('fee'); });
    if (daCostFollowCb) daCostFollowCb.addEventListener('change', function () { daSyncFollowField('cost'); });
    var daFeeInp = document.getElementById('da_delivery_fee');
    var daCostInp = document.getElementById('da_company_delivery_cost');
    if (daFeeInp) daFeeInp.addEventListener('input', function () { daDetachFollow('fee'); });
    if (daCostInp) daCostInp.addEventListener('input', function () { daDetachFollow('cost'); });
}

function daGovComboSync() {
    var sel = document.getElementById('da_governorate_id');
    var txt = document.getElementById('da_gov_combo_text');
    if (!sel || !txt) return;
    if (!sel.value) {
        txt.textContent = 'اختر محافظة';
        txt.classList.add('muted');
        return;
    }
    var opt = sel.options[sel.selectedIndex];
    txt.textContent = opt ? opt.textContent : 'اختر محافظة';
    txt.classList.remove('muted');
}

function daGovComboRender(q) {
    var sel = document.getElementById('da_governorate_id');
    var list = document.getElementById('da_gov_combo_list');
    if (!sel || !list) return;
    var needle = String(q || '').trim().toLowerCase();
    list.innerHTML = '';
    var opts = Array.prototype.slice.call(sel.options);
    var matches = opts.filter(function (o) {
        if (needle === '') return true;
        return String(o.textContent || '').toLowerCase().indexOf(needle) !== -1;
    });
    if (matches.length === 0) {
        list.innerHTML = '<li class="da-gov-combo__empty">لا توجد نتائج</li>';
        return;
    }
    matches.forEach(function (o) {
        var li = document.createElement('li');
        li.className = 'da-gov-combo__opt' + (String(o.value) === String(sel.value) ? ' is-selected' : '');
        li.setAttribute('role', 'option');
        li.textContent = o.textContent;
        li.addEventListener('click', function () {
            sel.value = o.value;
            sel.dispatchEvent(new Event('change'));
            daGovComboSync();
            daGovComboClose();
        });
        list.appendChild(li);
    });
}

function daGovComboOpen() {
    var panel = document.getElementById('da_gov_combo_panel');
    var field = document.getElementById('da_gov_combo_field');
    var q = document.getElementById('da_gov_combo_q');
    if (!panel || !field) return;
    panel.hidden = false;
    field.setAttribute('aria-expanded', 'true');
    if (q) { q.value = ''; }
    daGovComboRender('');
    if (q) { q.focus(); }
}

function daGovComboClose() {
    var panel = document.getElementById('da_gov_combo_panel');
    var field = document.getElementById('da_gov_combo_field');
    if (panel) panel.hidden = true;
    if (field) field.setAttribute('aria-expanded', 'false');
}

(function daBindGovCombo() {
    var combo = document.getElementById('da_gov_combo');
    var field = document.getElementById('da_gov_combo_field');
    var q = document.getElementById('da_gov_combo_q');
    if (!combo || !field) return;
    field.addEventListener('click', function () {
        var panel = document.getElementById('da_gov_combo_panel');
        if (panel && panel.hidden) {
            daGovComboOpen();
        } else {
            daGovComboClose();
        }
    });
    if (q) q.addEventListener('input', function () { daGovComboRender(q.value); });
    document.addEventListener('click', function (ev) {
        if (!combo.contains(ev.target)) daGovComboClose();
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape') return;
        var panel = document.getElementById('da_gov_combo_panel');
        if (panel && !panel.hidden) daGovComboClose();
    });
    daGovComboSync();
})();

(function dgBindCompanyPicker() {
    var codeEl = document.getElementById('dg_delivery_company_code');
    var clearBtn = document.getElementById('dg_company_clear_btn');
    var q = document.getElementById('dg_company_pick_q');
    var backdrop = document.getElementById('dg_company_pick_backdrop');
    if (codeEl) {
        codeEl.addEventListener('dblclick', dgCompanyPickerOpen);
    }
    if (clearBtn) clearBtn.addEventListener('click', function () { dgSetCompany(0); });
    if (q) q.addEventListener('input', function () { dgCompanyPickerRender(q.value); });
    if (backdrop) backdrop.addEventListener('click', dgCompanyPickerClose);
    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape') return;
        var pm = document.getElementById('dg_company_pick_modal');
        if (pm && !pm.hidden) dgCompanyPickerClose();
    });
})();

(async function daInit() {
    try {
        dgSetCompany(0);
        bindGovernorateEditButtons();
        daSyncListAllCheckbox();
        bindDeliveryAreaEditButtons();
        refreshDgSortPreview();
        refreshDaSortPreview();
        if (document.getElementById('dg_tbody') && daGovernoratesCache.length === 0) {
            await loadGovernorates();
        }
        if (document.getElementById('da_tbody') && daDeliveryAreasCache.length === 0) {
            await loadDeliveryAreas();
        }
    } catch (e) {
        console.error('delivery_areas init', e);
    }
})();
</script>
