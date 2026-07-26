<?php

declare(strict_types=1);

/**
 * رسائل تحفيزية يتحكم بها المشرف (قرار مالك 2026-06-28؛ ملكية الدولة 2026-07-26).
 *
 * نظام مستقل تماماً عن منطق العروض/المطابقة/الطلبات: نص تحفيزي متعدّد اللغات
 * يظهر في «خانة» مُسمّاة (slot) بالواجهة، **بدولة واحدة صريحة** (`country_id`) وجدولة
 * (دائم/فترة). Missing/NULL country_id ≠ Global — لا تُعرض على أي متجر.
 * الخانات أسماء ثابتة لضمان الأداء وسلامة الأماكن (لا أماكن عشوائية).
 */

require_once __DIR__ . '/catalog_schema.php';

/**
 * الخانات المُسمّاة المدعومة (المفتاح => تسمية عربية للأدمن).
 *
 * @return array<string,string>
 */
function orange_storefront_promo_message_slots(): array
{
    return [
        'offers_top' => 'أعلى تاب العروض',
        'home_top' => 'أعلى الصفحة الرئيسية',
        'cart_top' => 'أعلى صفحة السلة',
        'offer_card' => 'بطاقة عرض محدّد (يلزم نوع العرض ورقمه)',
        'register_teaser' => 'تجاوز نص تحفيز التسجيل في صفحات العروض',
    ];
}

/**
 * أنواع العروض المدعومة لخانة «بطاقة عرض محدّد».
 *
 * @return array<string,string>
 */
function orange_storefront_promo_message_offer_types(): array
{
    return [
        'product' => 'عرض منتج',
        'combo' => 'عرض كومبو',
        'bogo' => 'عرض اشترِ واحصل (BOGO)',
    ];
}

function orange_storefront_promo_message_offer_type_valid(string $type): bool
{
    return array_key_exists($type, orange_storefront_promo_message_offer_types());
}

/**
 * جمهور الرسالة: «للكل» (الافتراضي — تظهر لأي عميل) أو «للضيوف فقط» (تحفيز التسجيل).
 *
 * @return array<string,string>
 */
function orange_storefront_promo_message_audiences(): array
{
    return [
        'all' => 'لكل العملاء',
        'guest' => 'للزوّار غير المسجّلين (تحفيز التسجيل)',
    ];
}

function orange_storefront_promo_message_audience_valid(string $audience): bool
{
    return array_key_exists($audience, orange_storefront_promo_message_audiences());
}

/**
 * شرط SQL لفلترة الجمهور حسب حالة تسجيل الزائر.
 * عند الزائر المسجّل: تُخفى الرسائل الموجّهة «للضيوف فقط» (تبقى رسائل «للكل»).
 * يُرجَع '' (بلا فلترة) إن كان الزائر ضيفاً/غير معروف أو العمود غير موجود — حفاظاً على التوافق.
 */
function orange_storefront_promo_audience_sql(PDO $pdo, ?bool $viewerRegistered): string
{
    if ($viewerRegistered !== true) {
        return '';
    }
    if (!orange_table_has_column($pdo, 'storefront_promo_messages', 'audience')) {
        return '';
    }

    return " AND (audience IS NULL OR audience = 'all')";
}

function orange_storefront_promo_message_slot_valid(string $slot): bool
{
    return array_key_exists($slot, orange_storefront_promo_message_slots());
}

/**
 * اختيار نص اللغة مع احتياط للعربي.
 *
 * @param array<string,mixed> $row
 */
function orange_storefront_promo_message_pick_text(array $row, string $lang): string
{
    $map = [
        'en' => 'text_en',
        'fil' => 'text_fil',
        'hi' => 'text_hi',
    ];
    if (isset($map[$lang])) {
        $v = trim((string) ($row[$map[$lang]] ?? ''));
        if ($v !== '') {
            return $v;
        }
    }

    return trim((string) ($row['text_ar'] ?? ''));
}

/**
 * نص الرسالة الفعّالة لخانة مُسمّاة (أو '' إن لا شيء).
 * Country-scoped فقط: country_id = دولة المتجر — NULL لا يُعامل Global.
 */
function orange_storefront_promo_message_for_slot(
    PDO $pdo,
    string $slot,
    ?int $countryId,
    string $lang,
    ?bool $viewerRegistered = null
): string {
    if (!orange_storefront_promo_message_slot_valid($slot)
        || !orange_table_exists($pdo, 'storefront_promo_messages')) {
        return '';
    }
    $cid = ($countryId !== null && $countryId > 0) ? $countryId : 0;
    if ($cid <= 0) {
        return '';
    }
    $audienceSql = orange_storefront_promo_audience_sql($pdo, $viewerRegistered);
    $st = $pdo->prepare(
        'SELECT text_ar, text_en, text_fil, text_hi
         FROM storefront_promo_messages
         WHERE slot = ?
           AND is_active = 1
           AND country_id = ?
           AND (is_always_on = 1 OR (
                (valid_from IS NULL OR valid_from <= UTC_TIMESTAMP())
                AND (valid_to IS NULL OR valid_to >= UTC_TIMESTAMP())
           ))' . $audienceSql . '
         ORDER BY sort_order ASC, id ASC
         LIMIT 1'
    );
    $st->execute([$slot, $cid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return '';
    }

    return orange_storefront_promo_message_pick_text($row, $lang);
}

/**
 * خريطة الرسائل الفعّالة (slot => نص اللغة) باستعلام واحد — لتفادي عدّة استعلامات
 * على المسار الساخن (الصفحة الرئيسية). تختار أول رسالة لكل خانة وفق الترتيب.
 *
 * @param list<string> $slots
 * @return array<string,string>
 */
function orange_storefront_promo_messages_map(PDO $pdo, array $slots, ?int $countryId, string $lang, ?bool $viewerRegistered = null): array
{
    if (!orange_table_exists($pdo, 'storefront_promo_messages')) {
        return [];
    }
    $valid = [];
    foreach ($slots as $s) {
        if (orange_storefront_promo_message_slot_valid((string) $s)) {
            $valid[(string) $s] = true;
        }
    }
    $valid = array_keys($valid);
    if ($valid === []) {
        return [];
    }
    $cid = ($countryId !== null && $countryId > 0) ? $countryId : 0;
    if ($cid <= 0) {
        return [];
    }
    $placeholders = implode(', ', array_fill(0, count($valid), '?'));
    $params = $valid;
    $params[] = $cid;
    $audienceSql = orange_storefront_promo_audience_sql($pdo, $viewerRegistered);
    $st = $pdo->prepare(
        'SELECT slot, text_ar, text_en, text_fil, text_hi
         FROM storefront_promo_messages
         WHERE slot IN (' . $placeholders . ')
           AND is_active = 1
           AND country_id = ?
           AND (is_always_on = 1 OR (
                (valid_from IS NULL OR valid_from <= UTC_TIMESTAMP())
                AND (valid_to IS NULL OR valid_to >= UTC_TIMESTAMP())
           ))' . $audienceSql . '
         ORDER BY sort_order ASC, id ASC'
    );
    $st->execute($params);
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $slot = (string) ($row['slot'] ?? '');
        if ($slot === '' || isset($out[$slot])) {
            continue; // أول رسالة لكل خانة فقط
        }
        $out[$slot] = orange_storefront_promo_message_pick_text($row, $lang);
    }

    return $out;
}

/**
 * نص تجاوز تحفيز التسجيل (خانة register_teaser) أو '' إن لا تجاوز فعّال.
 * يستخدمه استدعاء الواجهة هكذا: $override !== '' ? $override : t('offer_registered_only').
 */
function orange_storefront_promo_register_teaser(PDO $pdo, ?int $countryId, string $lang): string
{
    return orange_storefront_promo_message_for_slot($pdo, 'register_teaser', $countryId, $lang);
}

/**
 * خريطة الرسائل التحفيزية لبطاقات عروض محدّدة: المفتاح "type:id" => نص اللغة.
 * استعلام واحد لكل الصفحة (المسار الساخن) — أول رسالة فعّالة لكل عرض.
 *
 * @return array<string,string>
 */
function orange_storefront_promo_offer_card_map(PDO $pdo, ?int $countryId, string $lang, ?bool $viewerRegistered = null): array
{
    if (!orange_table_exists($pdo, 'storefront_promo_messages')
        || !orange_table_has_column($pdo, 'storefront_promo_messages', 'offer_type')
        || !orange_table_has_column($pdo, 'storefront_promo_messages', 'offer_id')) {
        return [];
    }
    $cid = ($countryId !== null && $countryId > 0) ? $countryId : 0;
    if ($cid <= 0) {
        return [];
    }
    $audienceSql = orange_storefront_promo_audience_sql($pdo, $viewerRegistered);
    $st = $pdo->prepare(
        'SELECT offer_type, offer_id, text_ar, text_en, text_fil, text_hi
         FROM storefront_promo_messages
         WHERE slot = ?
           AND offer_type IS NOT NULL
           AND offer_id IS NOT NULL
           AND is_active = 1
           AND country_id = ?
           AND (is_always_on = 1 OR (
                (valid_from IS NULL OR valid_from <= UTC_TIMESTAMP())
                AND (valid_to IS NULL OR valid_to >= UTC_TIMESTAMP())
           ))' . $audienceSql . '
         ORDER BY sort_order ASC, id ASC'
    );
    $st->execute(['offer_card', $cid]);
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $type = (string) ($row['offer_type'] ?? '');
        $oid = (int) ($row['offer_id'] ?? 0);
        if ($type === '' || $oid <= 0) {
            continue;
        }
        $key = $type . ':' . $oid;
        if (isset($out[$key])) {
            continue; // أول رسالة لكل عرض فقط
        }
        $txt = orange_storefront_promo_message_pick_text($row, $lang);
        if ($txt !== '') {
            $out[$key] = $txt;
        }
    }

    return $out;
}

/**
 * تطبيع إعداد/اختبار: صفوف بلا country_id.
 * إن وُجدت دولة نشطة واحدة فقط تُنسَب إليها (idempotent). إن تعدّدت الدول تُترَك
 * دون تعيين صامت — ولن تظهر في المتجر (fail-closed؛ NULL ≠ Global).
 *
 * @return array{null_before:int,normalized:int,active_countries:int,blocked_ambiguous:bool}
 */
function orange_storefront_promo_messages_normalize_null_country_ids(PDO $pdo): array
{
    $out = [
        'null_before' => 0,
        'normalized' => 0,
        'active_countries' => 0,
        'blocked_ambiguous' => false,
    ];
    if (!orange_table_exists($pdo, 'storefront_promo_messages')
        || !orange_table_exists($pdo, 'countries')) {
        return $out;
    }
    $out['null_before'] = (int) $pdo->query(
        'SELECT COUNT(*) FROM storefront_promo_messages WHERE country_id IS NULL OR country_id = 0'
    )->fetchColumn();
    if ($out['null_before'] <= 0) {
        return $out;
    }
    $ids = $pdo->query(
        'SELECT id FROM countries WHERE is_active = 1 ORDER BY id ASC'
    )->fetchAll(PDO::FETCH_COLUMN);
    $active = [];
    foreach ($ids ?: [] as $id) {
        $cid = (int) $id;
        if ($cid > 0) {
            $active[] = $cid;
        }
    }
    $out['active_countries'] = count($active);
    if ($out['active_countries'] === 1) {
        $only = $active[0];
        $st = $pdo->prepare(
            'UPDATE storefront_promo_messages SET country_id = ?
             WHERE country_id IS NULL OR country_id = 0'
        );
        $st->execute([$only]);
        $out['normalized'] = (int) $st->rowCount();

        return $out;
    }
    if ($out['active_countries'] > 1) {
        $out['blocked_ambiguous'] = true;
    }

    return $out;
}

/**
 * قائمة الأدمن لدولة السياق فقط (أو كل الصفوف ذات country_id صريح عند نظرة عامة).
 *
 * @return list<array<string,mixed>>
 */
function orange_storefront_promo_messages_admin_list(PDO $pdo, ?int $countryId): array
{
    if (!orange_table_exists($pdo, 'storefront_promo_messages')) {
        return [];
    }
    $params = [];
    if ($countryId !== null && $countryId > 0) {
        $countrySql = ' WHERE country_id = ?';
        $params[] = $countryId;
    } else {
        // نظرة إدارية واسعة: صفوف مملوكة لدولة فقط — لا NULL-as-Global.
        $countrySql = ' WHERE country_id IS NOT NULL AND country_id > 0';
    }
    $hasOfferCols = orange_table_has_column($pdo, 'storefront_promo_messages', 'offer_type')
        && orange_table_has_column($pdo, 'storefront_promo_messages', 'offer_id');
    $offerSel = $hasOfferCols ? ', offer_type, offer_id' : '';
    $hasAudience = orange_table_has_column($pdo, 'storefront_promo_messages', 'audience');
    $audienceSel = $hasAudience ? ', audience' : '';
    $st = $pdo->prepare(
        'SELECT id, country_id, slot, text_ar, text_en, text_fil, text_hi,
                is_active, is_always_on, valid_from, valid_to, sort_order' . $offerSel . $audienceSel . '
         FROM storefront_promo_messages' . $countrySql . '
         ORDER BY slot ASC, sort_order ASC, id ASC'
    );
    $st->execute($params);
    require_once __DIR__ . '/cart_promo_schedule.php';
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $audienceVal = isset($row['audience']) && (string) $row['audience'] !== '' ? (string) $row['audience'] : 'all';
        $rowCid = (int) ($row['country_id'] ?? 0);
        $item = [
            'id' => (int) $row['id'],
            'country_id' => $rowCid > 0 ? $rowCid : null,
            'slot' => (string) ($row['slot'] ?? ''),
            'audience' => orange_storefront_promo_message_audience_valid($audienceVal) ? $audienceVal : 'all',
            'offer_type' => isset($row['offer_type']) ? (string) $row['offer_type'] : '',
            'offer_id' => isset($row['offer_id']) && $row['offer_id'] !== null ? (int) $row['offer_id'] : 0,
            'text_ar' => (string) ($row['text_ar'] ?? ''),
            'text_en' => (string) ($row['text_en'] ?? ''),
            'text_fil' => (string) ($row['text_fil'] ?? ''),
            'text_hi' => (string) ($row['text_hi'] ?? ''),
            'is_active' => (int) ($row['is_active'] ?? 0),
            'is_always_on' => (int) ($row['is_always_on'] ?? 0),
            'valid_from' => (string) ($row['valid_from'] ?? ''),
            'valid_to' => (string) ($row['valid_to'] ?? ''),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];
        if ((int) ($item['is_always_on'] ?? 0) === 1) {
            $item['valid_from'] = '';
            $item['valid_to'] = '';
        } elseif ($rowCid > 0 && $item['valid_from'] !== '' && $item['valid_to'] !== '') {
            $item = orange_cart_promo_admin_localize_schedule_row($pdo, $item, $rowCid);
        }
        $out[] = $item;
    }

    return $out;
}
