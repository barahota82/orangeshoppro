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
 * Country-local Y-m-d → UTC DATETIME for storefront_promo_messages (nullable schedule).
 * $endOfDay=true: last second of that local day in UTC (inclusive end, preserved semantics).
 */
function spm_iso_or_null(?string $v, bool $endOfDay = false, ?PDO $pdo = null, int $countryId = 0): ?string
{
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    $v = str_replace('T', ' ', $v);
    if ($pdo === null || $countryId <= 0) {
        return null;
    }
    require_once __DIR__ . '/../../../includes/admin_time.php';
    require_once __DIR__ . '/../../../includes/cart_promo_schedule.php';
    $ymd = '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        $ymd = orange_admin_time_date_only_normalize($v);
    } elseif (preg_match('/^(\d{4}-\d{2}-\d{2})[ T]\d{2}:\d{2}(:\d{2})?$/', $v, $m)) {
        $ymd = orange_admin_time_date_only_normalize($m[1]);
    }
    if ($ymd === '') {
        return null;
    }
    try {
        $iana = orange_admin_time_timezone_for_country_id($pdo, $countryId);
        $range = orange_cart_promo_local_ymd_range_to_utc_mysql($ymd, $ymd, $iana);
    } catch (Throwable $e) {
        return null;
    }

    return $endOfDay ? $range['valid_to'] : $range['valid_from'];
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
    // Current Country Context فقط — بلا fallback لدولة افتراضية/ثابتة (NULL ≠ Global).
    $ctxCid = (int) orange_admin_context_country_id($pdo);
    $norm = orange_storefront_promo_messages_normalize_null_country_ids($pdo);

    if ($action === 'list') {
        if ($ctxCid <= 0) {
            json_response([
                'success' => false,
                'code' => 'country_context_required',
                'message' => 'يلزم اختيار دولة السياق (السوق الحالي) قبل عرض الرسائل الترويجية',
                'data' => [],
                'slots' => orange_storefront_promo_message_slots(),
                'offer_types' => orange_storefront_promo_message_offer_types(),
                'audiences' => orange_storefront_promo_message_audiences(),
                'null_country_normalize' => $norm,
            ], 422);
        }
        json_response([
            'success' => true,
            'data' => orange_storefront_promo_messages_admin_list($pdo, $ctxCid),
            'slots' => orange_storefront_promo_message_slots(),
            'offer_types' => orange_storefront_promo_message_offer_types(),
            'audiences' => orange_storefront_promo_message_audiences(),
            'null_country_normalize' => $norm,
            'country_id' => $ctxCid,
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
        if ($ctxCid <= 0) {
            json_response([
                'success' => false,
                'code' => 'country_context_required',
                'message' => 'يلزم اختيار دولة السياق (السوق الحالي) قبل حفظ الرسالة الترويجية',
            ], 422);
        }
        $validFrom = $isAlwaysOn ? null : spm_iso_or_null((string) ($data['valid_from'] ?? ''), false, $pdo, $ctxCid);
        $validTo = $isAlwaysOn ? null : spm_iso_or_null((string) ($data['valid_to'] ?? ''), true, $pdo, $ctxCid);
        if (!$isAlwaysOn) {
            if ($validFrom === null || $validTo === null) {
                json_response(['success' => false, 'message' => 'تواريخ بداية/نهاية الرسالة غير صالحة لدولة السياق'], 422);
            }
            if ($validTo < $validFrom) {
                json_response(['success' => false, 'message' => 'تاريخ النهاية قبل البداية'], 422);
            }
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

        // إنشاء: دولة السياق. تعديل: دولة السجل إن وُجدت؛ صف NULL قديم يُنسَب للسياق.
        $countryToStore = $ctxCid;

        if ($id > 0) {
            $chk = $pdo->prepare('SELECT country_id FROM storefront_promo_messages WHERE id = ? LIMIT 1');
            $chk->execute([$id]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                json_response(['success' => false, 'message' => 'السجل غير موجود'], 404);
            }
            $exCid = (int) ($existing['country_id'] ?? 0);
            if ($exCid > 0 && $exCid !== $ctxCid) {
                json_response(['success' => false, 'message' => 'هذا السجل يخص دولة أخرى'], 403);
            }
            if ($exCid > 0) {
                $countryToStore = $exCid;
            }
            // الترتيب تلقائي: لا يتغيّر عند التعديل (يبقى موضع الصف كما هو).
            // Mutation مقيّدة بدولة السجل أو صف NULL قديم يُنسَب للسياق — لا تعديل عبر دولة أخرى.
            if ($exCid > 0) {
                $st = $pdo->prepare(
                    'UPDATE storefront_promo_messages
                     SET country_id = ?, slot = ?, audience = ?, offer_type = ?, offer_id = ?, text_ar = ?, text_en = ?, text_fil = ?, text_hi = ?,
                         is_active = ?, is_always_on = ?, valid_from = ?, valid_to = ?
                     WHERE id = ? AND country_id = ?'
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
                    $id,
                    $exCid,
                ]);
            } else {
                $st = $pdo->prepare(
                    'UPDATE storefront_promo_messages
                     SET country_id = ?, slot = ?, audience = ?, offer_type = ?, offer_id = ?, text_ar = ?, text_en = ?, text_fil = ?, text_hi = ?,
                         is_active = ?, is_always_on = ?, valid_from = ?, valid_to = ?
                     WHERE id = ? AND (country_id IS NULL OR country_id = 0)'
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
                    $id,
                ]);
            }
        } else {
            // الترتيب تلقائي ضمن دولة السياق فقط.
            $sortStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM storefront_promo_messages WHERE country_id = ?'
            );
            $sortStmt->execute([$ctxCid]);
            $sortOrder = (int) $sortStmt->fetchColumn();
            if ($sortOrder < 1) {
                $sortOrder = 1;
            }
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
        if ($ctxCid <= 0) {
            json_response([
                'success' => false,
                'code' => 'country_context_required',
                'message' => 'يلزم اختيار دولة السياق قبل حذف الرسالة',
            ], 422);
        }
        $chk = $pdo->prepare('SELECT country_id FROM storefront_promo_messages WHERE id = ? LIMIT 1');
        $chk->execute([$id]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            json_response(['success' => false, 'message' => 'السجل غير موجود'], 404);
        }
        $exCid = (int) ($existing['country_id'] ?? 0);
        if ($exCid <= 0 || $exCid !== $ctxCid) {
            json_response([
                'success' => false,
                'code' => 'country_mismatch',
                'message' => $exCid <= 0
                    ? 'لا يُحذف صف بلا دولة من القائمة العادية — التطبيع أو التنظيف المنفصل فقط'
                    : 'هذا السجل يخص دولة أخرى',
            ], 403);
        }
        $st = $pdo->prepare('DELETE FROM storefront_promo_messages WHERE id = ? AND country_id = ?');
        $st->execute([$id, $ctxCid]);
        if ($st->rowCount() < 1) {
            json_response(['success' => false, 'code' => 'country_mismatch', 'message' => 'تعذّر الحذف ضمن دولة السياق'], 403);
        }
        json_response(['success' => true, 'message' => 'تم الحذف']);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 400);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر إدارة رسائل العروض');
}
