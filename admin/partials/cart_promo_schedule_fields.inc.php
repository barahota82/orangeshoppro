<?php

declare(strict_types=1);

/** @var string $ocpFieldPrefix معرّف الحقول (مثل cp, cgp, cbp, ccp) */
$ocpFieldPrefix = isset($ocpFieldPrefix) ? preg_replace('/[^a-z0-9_]/', '', (string) $ocpFieldPrefix) : 'cp';
if ($ocpFieldPrefix === '') {
    $ocpFieldPrefix = 'cp';
}
?>
<div class="form-grid" style="margin-top:8px;">
    <div>
        <label for="<?php echo htmlspecialchars($ocpFieldPrefix, ENT_QUOTES, 'UTF-8'); ?>_valid_from">بداية العرض <span dir="ltr">*</span></label>
        <input type="text" id="<?php echo htmlspecialchars($ocpFieldPrefix, ENT_QUOTES, 'UTF-8'); ?>_valid_from" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off" required>
    </div>
    <div>
        <label for="<?php echo htmlspecialchars($ocpFieldPrefix, ENT_QUOTES, 'UTF-8'); ?>_valid_to">نهاية العرض <span dir="ltr">*</span></label>
        <input type="text" id="<?php echo htmlspecialchars($ocpFieldPrefix, ENT_QUOTES, 'UTF-8'); ?>_valid_to" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off" required>
    </div>
    <div style="grid-column:1/-1;">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;max-width:22rem;">
            <input type="checkbox" id="<?php echo htmlspecialchars($ocpFieldPrefix, ENT_QUOTES, 'UTF-8'); ?>_always_on">
            <span><strong>التفعيل الدائم</strong></span>
        </label>
    </div>
</div>
<p class="card-hint" style="margin:6px 0 0;">عند تفعيل «التفعيل الدائم» تبقى حقول من/إلى ظاهرة ولكن غير مفعّلة. عند إلغاء التفعيل الدائم تعود الحقول مفعّلة وإلزامية.</p>
