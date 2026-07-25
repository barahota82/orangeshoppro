<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/cart_promotion_country.php';
require_once __DIR__ . '/promo_always_on.php';
require_once __DIR__ . '/admin_time.php';

/**
 * أهليّة عرض سلة بشرط «أول طلب مُسلَّم»: نعيد استخدام نفس منطق عروض التوصيل
 * (التعرّف على العميل بحساب أو هاتف، وألا يكون لديه طلب مُسلَّم سابق ضمن نفس الدولة).
 * إن تعذّر التعرّف (لا حساب ولا هاتف) → غير مؤهَّل (fail-safe مالي، مطابق للتوصيل).
 */
function orange_cart_promo_buyer_first_delivered_ok(
    PDO $pdo,
    ?int $buyerAccountId,
    ?string $buyerPhone,
    ?int $countryId
): bool {
    if (!function_exists('orange_delivery_buyer_first_delivered_eligible')) {
        require_once __DIR__ . '/delivery_areas.php';
    }
    if (!function_exists('orange_delivery_buyer_first_delivered_eligible')) {
        return false;
    }

    return orange_delivery_buyer_first_delivered_eligible($pdo, $buyerAccountId, $buyerPhone, $countryId);
}

/**
 * اسم العرض المعروض للعميل حسب لغة المتجر — أو سلسلة فارغة إذا كان العلم مُغلقاً
 * (`show_name_to_customer` = 0) أو لا اسم محفوظ. الافتراضي مُغلق للأمان القانوني (قرار مالك 2026-06-27).
 * يُستعمل لعروض السلة والتوصيل (name_ar/name_en) ولعروض المنتجات (name_ar/name_en) وللكومبو (title_ar/title_en).
 *
 * @param array<string,mixed> $row صف العرض (يجب أن يحوي show_name_to_customer + حقلَي الاسم)
 */
function orange_promo_customer_display_name(array $row, ?string $lang = null, string $arKey = 'name_ar', string $enKey = 'name_en'): string
{
    if ((int) ($row['show_name_to_customer'] ?? 0) !== 1) {
        return '';
    }
    if ($lang === null) {
        $lang = function_exists('current_lang') ? (string) current_lang() : 'ar';
    }
    $lang = strtolower(trim((string) $lang));
    $ar = trim((string) ($row[$arKey] ?? ''));
    $en = trim((string) ($row[$enKey] ?? ''));
    if ($lang === 'ar') {
        return $ar !== '' ? $ar : $en;
    }

    return $en !== '' ? $en : $ar;
}

/**
 * جدول عرض سلة: جدولة تواريخ + إيقاف تلقائي عند نفاد مخزون منتجات العرض أو الهدية.
 *
 * @return list<string>
 */
function orange_cart_promo_scheduled_tables(): array
{
    return [
        'cart_promotions',
        'cart_gift_promotions',
        'cart_bogo_promotions',
        'cart_combo_promotions',
    ];
}

/**
 * @return list<string> جداول فيها هدية (إيقاف gift_stock).
 */
function orange_cart_promo_gift_stock_tables(): array
{
    return ['cart_gift_promotions', 'cart_bogo_promotions'];
}

/**
 * @return list<string> أسباب إيقاف تلقائي معروفة في الأدمن.
 */
function orange_cart_promo_auto_pause_reasons(): array
{
    return ['promo_stock', 'gift_stock'];
}

function orange_cart_promo_admin_pause_reason_label_ar(string $reason): string
{
    return match (trim($reason)) {
        'promo_stock' => 'نفاد مخزون منتجات العرض',
        'gift_stock' => 'عدم توفر الهدية (نفاد مخزون الهدية)',
        'stock_resumed' => 'إعادة تفعيل تلقائي (عودة المخزون)',
        default => 'إيقاف تلقائي',
    };
}

/**
 * مرحلة 10: إعادة تفعيل تلقائي فقط بعد إيقاف مخزون — لا تُطبَّق إن انتهت الفترة أو أوقف الأدمن (is_active=0).
 *
 * @param array<string,mixed> $row
 */
function orange_cart_promo_row_eligible_stock_auto_unpause(array $row): bool
{
    if ((int) ($row['is_active'] ?? 0) !== 1) {
        return false;
    }
    $reason = trim((string) ($row['auto_paused_reason'] ?? ''));
    if ($reason === '' || !in_array($reason, orange_cart_promo_auto_pause_reasons(), true)) {
        return false;
    }

    return orange_cart_promo_is_within_schedule(
        (string) ($row['valid_from'] ?? ''),
        (string) ($row['valid_to'] ?? ''),
        orange_promo_always_on_enabled($row)
    );
}

/**
 * @param array<string,mixed> $rule
 *
 * @return array<string,mixed>
 */
function orange_cart_promo_rule_row_for_stock_unpause(array $rule): array
{
    return [
        'id' => (int) ($rule['id'] ?? 0),
        'is_active' => (int) ($rule['is_active'] ?? 1),
        'valid_from' => (string) ($rule['valid_from'] ?? ''),
        'valid_to' => (string) ($rule['valid_to'] ?? ''),
        'is_always_on' => orange_promo_always_on_to_int($rule['is_always_on'] ?? 0),
        'auto_paused_at' => $rule['auto_paused_at'] ?? null,
        'auto_paused_reason' => (string) ($rule['auto_paused_reason'] ?? ''),
    ];
}

/**
 * @param array<string,mixed> $row
 */
function orange_cart_promo_row_is_customer_effective(array $row): bool
{
    if ((int) ($row['is_active'] ?? 0) !== 1) {
        return false;
    }
    if (!empty($row['auto_paused_at']) || trim((string) ($row['auto_paused_reason'] ?? '')) !== '') {
        return false;
    }

    return orange_cart_promo_is_within_schedule(
        (string) ($row['valid_from'] ?? ''),
        (string) ($row['valid_to'] ?? ''),
        orange_promo_always_on_enabled($row)
    );
}

/**
 * Country authority for schedule parse on admin save (existing row country, else insert context).
 */
function orange_cart_promo_schedule_country_id_for_save(PDO $pdo, string $table, int $id, int $insertCountryId): int
{
    if ($id > 0 && orange_table_exists($pdo, $table) && orange_table_has_column($pdo, $table, 'country_id')) {
        $st = $pdo->prepare('SELECT country_id FROM `' . str_replace('`', '``', $table) . '` WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $cid = (int) ($st->fetchColumn() ?: 0);
        if ($cid > 0) {
            return $cid;
        }
    }
    if ($insertCountryId > 0) {
        return $insertCountryId;
    }

    return orange_cart_promotion_admin_country_id($pdo);
}

/**
 * Absolute-moment window stored as UTC DATETIME (date-only admin days → country IANA day bounds).
 * Inclusive: now >= from AND now <= to (to = last second of the end local day in UTC).
 */
function orange_cart_promo_is_within_schedule(string $validFrom, string $validTo, bool $isAlwaysOn = false): bool
{
    if ($isAlwaysOn) {
        return true;
    }
    $fromRaw = trim($validFrom);
    $toRaw = trim($validTo);
    if ($fromRaw === '' || $toRaw === '') {
        return false;
    }
    try {
        $from = orange_admin_time_parse_mysql_utc_datetime($fromRaw)->getTimestamp();
        $to = orange_admin_time_parse_mysql_utc_datetime($toRaw)->getTimestamp();
    } catch (OrangeAdminTimeConfigException $e) {
        return false;
    }
    $now = orange_admin_time_unix_now();

    return $now >= $from && $now <= $to;
}

/**
 * Date-only schedule (delivery_fee_promotions.valid_* DATE): compare country-local calendar today.
 */
function orange_cart_promo_is_within_date_only_schedule(
    PDO $pdo,
    int $countryId,
    string $validFromYmd,
    string $validToYmd,
    bool $isAlwaysOn = false
): bool {
    if ($isAlwaysOn) {
        return true;
    }
    if ($countryId <= 0) {
        return false;
    }
    $from = orange_admin_time_date_only_normalize($validFromYmd);
    $to = orange_admin_time_date_only_normalize($validToYmd);
    if ($from === '' || $to === '') {
        return false;
    }
    try {
        $today = orange_admin_time_document_date_today_for_country_id($pdo, $countryId);
    } catch (OrangeAdminTimeConfigException $e) {
        return false;
    }

    return $today >= $from && $today <= $to;
}

/**
 * شريط SQL: نشط + ضمن المدة + غير موقوف تلقائياً.
 * Uses UTC_TIMESTAMP() so DATETIME columns stored as UTC wall compare correctly under session +03.
 */
function orange_cart_promo_schedule_sql(string $alias): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
    if ($a === '') {
        $a = 't';
    }

    return ' AND ' . $a . '.is_active = 1'
        . ' AND ' . $a . '.auto_paused_at IS NULL'
        . ' AND ('
        . $a . '.is_always_on = 1'
        . ' OR (' . $a . '.valid_from <= UTC_TIMESTAMP() AND ' . $a . '.valid_to >= UTC_TIMESTAMP())'
        . ')';
}

/**
 * Local Y-m-d of a stored UTC DATETIME instant in the offer country's IANA zone.
 *
 * @throws OrangeAdminTimeConfigException
 */
function orange_cart_promo_utc_mysql_to_country_ymd(PDO $pdo, string $utcMysql, int $countryId): string
{
    $tzName = orange_admin_time_timezone_for_country_id($pdo, $countryId);
    $local = orange_admin_time_parse_mysql_utc_datetime($utcMysql)
        ->setTimezone(new DateTimeZone($tzName));

    return $local->format('Y-m-d');
}

/**
 * Admin form roundtrip: expose schedule as country-local Y-m-d (Date-only input contract).
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function orange_cart_promo_admin_localize_schedule_row(PDO $pdo, array $row, int $countryId): array
{
    $cid = $countryId > 0 ? $countryId : (int) ($row['country_id'] ?? 0);
    if (orange_promo_always_on_enabled($row)) {
        // Permanent authority is is_always_on — do not expose NOT NULL technical fillers to admin UI/API.
        $row['schedule_label'] = 'تفعيل دائم';
        $row['valid_from_utc'] = (string) ($row['valid_from'] ?? '');
        $row['valid_to_utc'] = (string) ($row['valid_to'] ?? '');
        $row['valid_from'] = '';
        $row['valid_to'] = '';

        return $row;
    }
    $vf = trim((string) ($row['valid_from'] ?? ''));
    $vt = trim((string) ($row['valid_to'] ?? ''));
    $row['valid_from_utc'] = $vf;
    $row['valid_to_utc'] = $vt;
    if ($cid <= 0 || $vf === '' || $vt === '') {
        return $row;
    }
    try {
        // Date-only columns already Y-m-d
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $vf) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $vt)) {
            $row['valid_from'] = $vf;
            $row['valid_to'] = $vt;
        } else {
            $row['valid_from'] = orange_cart_promo_utc_mysql_to_country_ymd($pdo, $vf, $cid);
            $row['valid_to'] = orange_cart_promo_utc_mysql_to_country_ymd($pdo, $vt, $cid);
        }
    } catch (OrangeAdminTimeConfigException $e) {
        $row['valid_from'] = '';
        $row['valid_to'] = '';
        $row['schedule_error'] = $e->getMessage();
    }

    return $row;
}

/**
 * Verify admin dates (Y-m-d) for an offer country.
 * - DATETIME storage (cart/offers/messages): country-local day → UTC Absolute Moments (end inclusive).
 * - DATE storage (delivery): keep Y-m-d (Date-only; no TZ shift of the calendar day).
 * Permanent (is_always_on): no fake 2099 end — NOT NULL filler uses country-local today only; evaluation ignores dates.
 *
 * @return array{valid_from:string,valid_to:string}|null null + رسالة عبر $err
 */
function orange_cart_promo_parse_required_admin_dates(
    string $fromIso,
    string $toIso,
    ?string &$err = null,
    bool $isAlwaysOn = false,
    ?PDO $pdo = null,
    int $countryId = 0,
    bool $asDateOnly = false
): ?array {
    $err = null;
    $fromIso = trim($fromIso);
    $toIso = trim($toIso);

    if ($pdo === null || $countryId <= 0) {
        $err = 'دولة العرض مطلوبة لتفسير تواريخ الجدولة';

        return null;
    }

    try {
        $iana = orange_admin_time_timezone_for_country_id($pdo, $countryId);
        $today = orange_admin_time_document_date_today_for_country_id($pdo, $countryId);
    } catch (OrangeAdminTimeConfigException $e) {
        $err = 'إعداد المنطقة الزمنية لدولة العرض غير صالح';

        return null;
    }

    if ($isAlwaysOn) {
        // Permanent flag remains authority; columns may be NOT NULL — use today as technical filler (never 2099).
        $fillerFrom = $fromIso !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromIso)
            ? orange_admin_time_date_only_normalize($fromIso)
            : $today;
        $fillerTo = $toIso !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toIso)
            ? orange_admin_time_date_only_normalize($toIso)
            : $today;
        if ($fillerFrom === '' || $fillerTo === '') {
            $fillerFrom = $today;
            $fillerTo = $today;
        }
        if ($fillerFrom > $fillerTo) {
            $fillerFrom = $today;
            $fillerTo = $today;
        }
        if ($asDateOnly) {
            return ['valid_from' => $fillerFrom, 'valid_to' => $fillerTo];
        }
        try {
            return orange_cart_promo_local_ymd_range_to_utc_mysql($fillerFrom, $fillerTo, $iana);
        } catch (OrangeAdminTimeConfigException $e) {
            $err = 'تعذر تحويل تواريخ التفعيل الدائم';

            return null;
        }
    }

    if ($fromIso === '' || $toIso === '') {
        $err = 'تاريخ بداية ونهاية العرض إلزاميان';

        return null;
    }
    $fromYmd = orange_admin_time_date_only_normalize($fromIso);
    $toYmd = orange_admin_time_date_only_normalize($toIso);
    if ($fromYmd === '' || $toYmd === '') {
        $err = 'صيغة التاريخ غير صالحة (YYYY-MM-DD)';

        return null;
    }
    if ($fromYmd > $toYmd) {
        $err = 'تاريخ البداية يجب أن يسبق أو يساوي تاريخ النهاية';

        return null;
    }
    if ($asDateOnly) {
        return ['valid_from' => $fromYmd, 'valid_to' => $toYmd];
    }
    try {
        return orange_cart_promo_local_ymd_range_to_utc_mysql($fromYmd, $toYmd, $iana);
    } catch (OrangeAdminTimeConfigException $e) {
        $err = 'تاريخ غير صالح لتوقيت دولة العرض';

        return null;
    }
}

/**
 * Country-local inclusive date range → UTC DATETIME walls (end = last second of end day).
 *
 * @return array{valid_from:string,valid_to:string}
 * @throws OrangeAdminTimeConfigException
 */
function orange_cart_promo_local_ymd_range_to_utc_mysql(string $fromYmd, string $toYmd, string $ianaTimezone): array
{
    $fromB = orange_admin_time_day_bounds_mysql_utc($fromYmd, $ianaTimezone);
    $toB = orange_admin_time_day_bounds_mysql_utc($toYmd, $ianaTimezone);
    $endExclusive = orange_admin_time_parse_mysql_utc_datetime($toB['end_exclusive_utc_mysql']);
    $endInclusive = $endExclusive->modify('-1 second');

    return [
        'valid_from' => $fromB['start_utc_mysql'],
        'valid_to' => $endInclusive->format('Y-m-d H:i:s'),
    ];
}

/**
 * @param array<string,mixed> $row
 */
function orange_cart_promo_admin_schedule_label(array $row): string
{
    if (orange_promo_always_on_enabled($row)) {
        return 'تفعيل دائم';
    }
    $vf = trim((string) ($row['valid_from'] ?? ''));
    $vt = trim((string) ($row['valid_to'] ?? ''));
    if ($vf === '' || $vt === '') {
        return '—';
    }

    return substr($vf, 0, 10) . ' → ' . substr($vt, 0, 10);
}

/**
 * @return list<array{table:string,id:int,kind:string,label:string,reason:string,reason_label:string,paused_at:string}>
 */
function orange_cart_promo_admin_auto_paused_alerts(PDO $pdo): array
{
    $out = [];
    $cid = orange_cart_promotion_admin_country_id($pdo);
    $labels = [
        'cart_promotions' => 'خصم مجموع السلة',
        'cart_gift_promotions' => 'هدية مجموع السلة',
        'cart_bogo_promotions' => 'BOGO',
        'cart_combo_promotions' => 'كومبو',
        'offers' => 'عرض منتج',
    ];
    $pageMap = [
        'cart_promotions' => 'cart_promotions',
        'cart_gift_promotions' => 'cart_gift_promotions',
        'cart_bogo_promotions' => 'cart_bogo_promotions',
        'cart_combo_promotions' => 'cart_combo_promotions',
        'offers' => 'offers',
    ];
    $tables = array_merge(orange_cart_promo_scheduled_tables(), ['offers']);
    $reasonIn = implode(',', array_map(static fn (string $r): string => $pdo->quote($r), orange_cart_promo_auto_pause_reasons()));
    foreach ($tables as $table) {
        if (!orange_table_exists($pdo, $table) || !orange_table_has_column($pdo, $table, 'auto_paused_reason')) {
            continue;
        }
        $bind = orange_cart_promotion_sql_bind($pdo, $table, 't', $cid);
        $st = $pdo->prepare(
            'SELECT t.id, t.auto_paused_at, t.auto_paused_reason
             FROM ' . $table . ' t
             WHERE t.auto_paused_reason IN (' . $reasonIn . ')' . $bind['sql'] . '
             ORDER BY t.auto_paused_at DESC, t.id DESC
             LIMIT 20'
        );
        $st->execute($bind['params']);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $reason = trim((string) ($row['auto_paused_reason'] ?? ''));
            $kind = $labels[$table] ?? $table;
            $out[] = [
                'table' => $table,
                'page' => $pageMap[$table] ?? $table,
                'id' => (int) ($row['id'] ?? 0),
                'kind' => $kind,
                'label' => $kind . ' #' . (int) ($row['id'] ?? 0) . ' — ' . orange_cart_promo_admin_pause_reason_label_ar($reason),
                'reason' => $reason,
                'reason_label' => orange_cart_promo_admin_pause_reason_label_ar($reason),
                'paused_at' => (string) ($row['auto_paused_at'] ?? ''),
            ];
        }
    }

    return $out;
}

function orange_cart_promo_clear_auto_pause(PDO $pdo, string $table, int $id): void
{
    $pausable = array_merge(orange_cart_promo_scheduled_tables(), ['offers']);
    if ($id <= 0 || !in_array($table, $pausable, true)) {
        return;
    }
    if (!orange_table_exists($pdo, $table) || !orange_table_has_column($pdo, $table, 'auto_paused_at')) {
        return;
    }
    $st = $pdo->prepare(
        'UPDATE ' . $table . ' SET auto_paused_at = NULL, auto_paused_reason = NULL WHERE id = ?'
    );
    $st->execute([$id]);
}

function orange_cart_promo_auto_pause_with_reason(PDO $pdo, string $table, int $id, string $reason): bool
{
    $reason = trim($reason);
    if ($id <= 0 || !in_array($reason, orange_cart_promo_auto_pause_reasons(), true)) {
        return false;
    }
    $pausable = array_merge(orange_cart_promo_scheduled_tables(), ['offers']);
    if (!in_array($table, $pausable, true)) {
        return false;
    }
    if (!orange_table_exists($pdo, $table) || !orange_table_has_column($pdo, $table, 'auto_paused_at')) {
        return false;
    }
    $st = $pdo->prepare(
        'UPDATE ' . $table . ' SET auto_paused_at = ?, auto_paused_reason = ?
         WHERE id = ? AND (auto_paused_at IS NULL OR auto_paused_reason IS NULL OR auto_paused_reason = \'\')'
    );
    $st->execute([orange_admin_time_utc_now_mysql(), $reason, $id]);

    return $st->rowCount() > 0;
}

/**
 * منتجات «العرض» المربوطة بقاعدة (شراء كومبو/BOGO) — ليست مجموعة الهدية.
 *
 * @param array<string,mixed> $rule
 *
 * @return list<int> product_ids
 */
function orange_cart_promo_rule_offer_product_ids(PDO $pdo, string $table, array $rule): array
{
    require_once __DIR__ . '/cart_promo_products.php';

    if ($table === 'cart_combo_promotions') {
        $comps = orange_cart_promo_parse_components_json($pdo, $rule['components_json'] ?? null);
        $ids = [];
        foreach ($comps as $c) {
            $pid = (int) ($c['product_id'] ?? 0);
            if ($pid > 0) {
                $ids[$pid] = true;
            }
        }

        return array_keys($ids);
    }
    if ($table === 'cart_bogo_promotions') {
        $kind = strtolower(trim((string) ($rule['bogo_kind'] ?? '')));
        if ($kind !== 'buy_bundle') {
            return [];
        }
        $comps = orange_cart_promo_parse_components_json($pdo, $rule['buy_components_json'] ?? null);
        $ids = [];
        foreach ($comps as $c) {
            $pid = (int) ($c['product_id'] ?? 0);
            if ($pid > 0) {
                $ids[$pid] = true;
            }
        }

        return array_keys($ids);
    }

    return [];
}

/**
 * أي منتج مطلوب للعرض بلا مخزون للزائر → إيقاف promo_stock.
 *
 * @param array<string,mixed> $rule
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 */
function orange_cart_promo_offer_products_have_stock(
    PDO $pdo,
    string $table,
    array $rule,
    array $validatedItems,
    ?int $countryId = null
): bool {
    require_once __DIR__ . '/cart_promo_products.php';

    $pids = orange_cart_promo_rule_offer_product_ids($pdo, $table, $rule);
    if ($pids === []) {
        return true;
    }
    foreach ($pids as $pid) {
        if (!orange_cart_promo_product_has_visitor_stock($pdo, (int) $pid, $validatedItems, $countryId)) {
            return false;
        }
    }

    return true;
}

/**
 * هل للقاعدة هدية متوفرة مخزوناً (أي متغير لأي منتج في المجموعة/الثابت).
 *
 * @param array<string,mixed> $rule
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 */
function orange_cart_promo_gift_rule_has_stock(
    PDO $pdo,
    array $rule,
    array $validatedItems,
    ?int $countryId = null
): bool {
    require_once __DIR__ . '/cart_gift_promotions.php';

    $gKind = strtolower(trim((string) ($rule['gift_kind'] ?? 'choice'))) === 'fixed' ? 'fixed' : 'choice';
    if ($gKind === 'fixed') {
        $fv = (int) ($rule['fixed_variant_id'] ?? $rule['fixed_product_id'] ?? 0);

        return $fv > 0
            && count(orange_cart_gift_promotion_pool_options($pdo, [$fv], $validatedItems, false, $countryId)) > 0;
    }
    $pool = $rule['pool_variant_ids'] ?? $rule['pool_product_ids'] ?? [];
    if (!is_array($pool) || count($pool) === 0) {
        return false;
    }

    return count(
        orange_cart_gift_promotion_pool_options($pdo, $pool, $validatedItems, false, $countryId)
    ) > 0;
}

/**
 * @param array<string,mixed> $rule
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 */
function orange_cart_promo_maybe_pause_rule_if_no_stock(
    PDO $pdo,
    string $table,
    array $rule,
    array $validatedItems,
    ?int $countryId = null
): bool
{
    require_once __DIR__ . '/cart_promo_stock_health.php';

    return orange_cart_promo_apply_stock_pause_for_rule($pdo, $table, $rule, $countryId);
}

/** @deprecated استخدم orange_cart_promo_maybe_pause_rule_if_no_stock */
function orange_cart_promo_maybe_pause_gift_rule_if_no_stock(
    PDO $pdo,
    string $table,
    array $rule,
    array $validatedItems,
    ?int $countryId = null
): bool {
    return orange_cart_promo_maybe_pause_rule_if_no_stock($pdo, $table, $rule, $validatedItems, $countryId);
}
