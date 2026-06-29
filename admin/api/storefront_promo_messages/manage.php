<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/cart_promotion_country.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/storefront_promo_messages.php';
require_admin_api();

/**
 * قصّ نص الرسالة بطول آمن مع دعم UTF-8.
 */
function spm_clip(string $s): string
{
    $s = trim($s);

    return function_exists('mb_substr') ? mb_substr($s, 0, 500, 'UTF-8') : substr($s, 0, 500);
}

/**
 * تحويل تاريخ ISO اختياري إلى صيغة قاعدة أو null.
 * $endOfDay=true: التاريخ المجرّد (يوم النهاية) يُغلق على 23:59:59 ليكون شاملاً (لا ينتهي قبل يوم).
 */
function spm_iso_or_null(?string $v, bool $endOfDay = false): ?string
{
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    $v = str_replace('T', ' ', $v);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return $v . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $v)) {
        return strlen($v) === 16 ? $v . ':00' : $v;
    }

    return null;
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    if (!orange_table_exists($pdo, 'storefront_promo_messages')) {
        json_response(['success' => false, 'message' => 'جدول storefront_promo_messages غير جاهز'], 422);
    }

    $data = get_json_input();
    if (!is_array($data) || count($data) === 0) {
        $data = $_POST;
    }
    $action = trim((string) ($data['action'] ?? 'list'));
    // المشرف المقيَّد بدولة (قفل الجلسة) محصور بدولته؛ المشرف العام (lock<=0) ينشئ «كل الدول» (NULL) أو دولة بعينها.
    $adminCid = (int) orange_admin_session_locked_country_id();

    if ($action === 'list') {
        json_response([
            'success' => true,
            'data' => orange_storefront_promo_messages_admin_list($pdo, $adminCid > 0 ? $adminCid : null),
            'slots' => orange_storefront_promo_message_slots(),
            'offer_types' => orange_storefront_promo_message_offer_types(),
            'audiences' => orange_storefront_promo_message_audiences(),
        ]);
    }

    if ($action === 'save') {
        $id = (int) ($data['id'] ?? 0);
        $slot = trim((string) ($data['slot'] ?? ''));
        if (!orange_storefront_promo_message_slot_valid($slot)) {
            json_response(['success' => false, 'message' => 'خانة العرض غير صالحة'], 422);
        }
        $textAr = spm_clip((string) ($data['text_ar'] ?? ''));
        if ($textAr === '') {
            json_response(['success' => false, 'message' => 'نص الرسالة بالعربي مطلوب'], 422);
        }
        $textEn = spm_clip((string) ($data['text_en'] ?? ''));
        $textFil = spm_clip((string) ($data['text_fil'] ?? ''));
        $textHi = spm_clip((string) ($data['text_hi'] ?? ''));
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $isAlwaysOn = !empty($data['is_always_on']) ? 1 : 0;
        $sortOrder = (int) ($data['sort_order'] ?? 0);
        $validFrom = $isAlwaysOn ? null : spm_iso_or_null((string) ($data['valid_from'] ?? ''));
        $validTo = $isAlwaysOn ? null : spm_iso_or_null((string) ($data['valid_to'] ?? ''), true);
        if (!$isAlwaysOn && $validFrom !== null && $validTo !== null && $validTo < $validFrom) {
            json_response(['success' => false, 'message' => 'تاريخ النهاية قبل البداية'], 422);
        }

        // خانة «بطاقة عرض محدّد»: تتطلّب نوع عرض ورقمه؛ غير ذلك تُصفّر.
        $offerType = null;
        $offerId = null;
        if ($slot === 'offer_card') {
            $offerTypeRaw = trim((string) ($data['offer_type'] ?? ''));
            $offerIdRaw = (int) ($data['offer_id'] ?? 0);
            if (!orange_storefront_promo_message_offer_type_valid($offerTypeRaw)) {
                json_response(['success' => false, 'message' => 'اختر نوع العرض لبطاقة عرض محدّد'], 422);
            }
            if ($offerIdRaw <= 0) {
                json_response(['success' => false, 'message' => 'أدخل رقم العرض'], 422);
            }
            $offerTableMap = [
                'product' => 'offers',
                'combo' => 'cart_combo_promotions',
                'bogo' => 'cart_bogo_promotions',
            ];
            $offerTable = $offerTableMap[$offerTypeRaw];
            if (orange_table_exists($pdo, $offerTable)) {
                $chkOffer = $pdo->prepare('SELECT id FROM ' . $offerTable . ' WHERE id = ? LIMIT 1');
                $chkOffer->execute([$offerIdRaw]);
                if (!$chkOffer->fetchColumn()) {
                    json_response(['success' => false, 'message' => 'رقم العرض غير موجود للنوع المحدّد'], 422);
                }
            }
            $offerType = $offerTypeRaw;
            $offerId = $offerIdRaw;
        }

        // جمهور الرسالة: 'all' (تظهر للكل) أو 'guest' (للزوّار غير المسجّلين فقط).
        // خانة register_teaser للضيف بطبيعتها (تجاوز نص «سجّل لفتح العرض») فتُثبَّت 'guest'.
        if ($slot === 'register_teaser') {
            $audience = 'guest';
        } else {
            $audienceRaw = trim((string) ($data['audience'] ?? 'all'));
            $audience = orange_storefront_promo_message_audience_valid($audienceRaw) ? $audienceRaw : 'all';
        }

        // نطاق الدولة: المشرف المقيّد بدولة لا ينشئ رسائل خارج نطاقه.
        $reqCountry = array_key_exists('country_id', $data) ? (int) $data['country_id'] : 0;
        if ($adminCid > 0) {
            $countryToStore = $adminCid;
        } else {
            $countryToStore = $reqCountry > 0 ? $reqCountry : null;
        }

        if ($id > 0) {
            // التأكد أن السجل ضمن نطاق المشرف.
            if ($adminCid > 0) {
                $chk = $pdo->prepare('SELECT country_id FROM storefront_promo_messages WHERE id = ? LIMIT 1');
                $chk->execute([$id]);
                $existing = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$existing || ((int) ($existing['country_id'] ?? 0) !== $adminCid)) {
                    json_response(['success' => false, 'message' => 'لا تملك صلاحية تعديل هذا السجل'], 403);
                }
            }
            $st = $pdo->prepare(
                'UPDATE storefront_promo_messages
                 SET country_id = ?, slot = ?, audience = ?, offer_type = ?, offer_id = ?, text_ar = ?, text_en = ?, text_fil = ?, text_hi = ?,
                     is_active = ?, is_always_on = ?, valid_from = ?, valid_to = ?, sort_order = ?
                 WHERE id = ?'
            );
            $st->execute([
                $countryToStore,
                $slot,
                $audience,
                $offerType,
                $offerId,
                $textAr,
                $textEn,
                $textFil,
                $textHi,
                $isActive,
                $isAlwaysOn,
                $validFrom,
                $validTo,
                $sortOrder,
                $id,
            ]);
        } else {
            $st = $pdo->prepare(
                'INSERT INTO storefront_promo_messages
                    (country_id, slot, audience, offer_type, offer_id, text_ar, text_en, text_fil, text_hi, is_active, is_always_on, valid_from, valid_to, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $countryToStore,
                $slot,
                $audience,
                $offerType,
                $offerId,
                $textAr,
                $textEn,
                $textFil,
                $textHi,
                $isActive,
                $isAlwaysOn,
                $validFrom,
                $validTo,
                $sortOrder,
            ]);
        }

        json_response(['success' => true, 'message' => 'تم الحفظ']);
    }

    if ($action === 'delete') {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            json_response(['success' => false, 'message' => 'معرّف غير صالح'], 422);
        }
        if ($adminCid > 0) {
            $chk = $pdo->prepare('SELECT country_id FROM storefront_promo_messages WHERE id = ? LIMIT 1');
            $chk->execute([$id]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$existing || ((int) ($existing['country_id'] ?? 0) !== $adminCid)) {
                json_response(['success' => false, 'message' => 'لا تملك صلاحية حذف هذا السجل'], 403);
            }
        }
        $st = $pdo->prepare('DELETE FROM storefront_promo_messages WHERE id = ?');
        $st->execute([$id]);
        json_response(['success' => true, 'message' => 'تم الحذف']);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 400);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => 'خطأ: ' . $e->getMessage()], 500);
}
