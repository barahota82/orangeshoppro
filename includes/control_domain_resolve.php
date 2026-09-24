<?php
declare(strict_types=1);
/**
 * Root / Country / Channel path resolution (dormant pure functions).
 * Precedence: explicit selection → persisted → injected Geo Model-D → neutral selector.
 * No silent Kuwait / first-Country / main-Channel fallback.
 */
final class OrangeControlDomainResolve
{
    public const MODE_COMPANY_DIRECT = 'COMPANY_DIRECT';
    public const MODE_CHANNEL = 'CHANNEL';
    public const MODE_NEUTRAL = 'NEUTRAL_SELECTOR';

    /**
     * @param array<string,mixed> $input
     * @return array{mode:string,country_id:?int,country_code:?string,channel_id:?int,channel_slug:?string,code:string,clear_attribution:bool}
     */
    public static function resolveRoot(array $input): array
    {
        // 1) explicit valid customer/Owner Country selection
        if (!empty($input['explicit_country_code']) && !empty($input['countries'][$input['explicit_country_code']])) {
            $c = $input['countries'][$input['explicit_country_code']];
            if (($c['lifecycle_status'] ?? '') === 'active') {
                return self::companyDirect((int)$c['id'], (string)$c['code']);
            }
        }
        // 2) valid persisted selection
        if (!empty($input['persisted_country_code']) && !empty($input['countries'][$input['persisted_country_code']])) {
            $c = $input['countries'][$input['persisted_country_code']];
            if (($c['lifecycle_status'] ?? '') === 'active') {
                return self::companyDirect((int)$c['id'], (string)$c['code']);
            }
        }
        // 3) injected Geo Model-D
        if (!empty($input['injected_geo_iso2'])) {
            require_once __DIR__ . '/control_geo_adapter.php';
            $byIso = [];
            foreach ($input['countries'] as $code => $row) {
                $iso = strtoupper((string)($row['geo_iso2_code'] ?? ''));
                if ($iso !== '') {
                    $byIso[$iso] = $row + ['code' => $code];
                }
            }
            $geo = OrangeControlGeoAdapter::resolveInjectedIso2((string)$input['injected_geo_iso2'], $byIso);
            if ($geo['ok']) {
                return self::companyDirect((int)$geo['country_id'], (string)$geo['country_code']);
            }
        }
        // 4) neutral market selector
        return [
            'mode' => self::MODE_NEUTRAL,
            'country_id' => null,
            'country_code' => null,
            'channel_id' => null,
            'channel_slug' => null,
            'code' => 'ROOT_NEUTRAL',
            'clear_attribution' => true,
        ];
    }

    /**
     * /{country_code}/ → COMPANY_DIRECT; /{country_code}/{channel_slug}/ → explicit same-Country Channel only.
     *
     * @param array<string,mixed> $country
     * @param array<string,array<string,mixed>> $channelsBySlug
     * @return array{mode:string,country_id:int,country_code:string,channel_id:?int,channel_slug:?string,code:string,clear_attribution:bool}
     */
    public static function resolveCountryPath(array $country, ?string $channelSlug, array $channelsBySlug): array
    {
        $cid = (int)$country['id'];
        $code = (string)$country['code'];
        if ($channelSlug === null || trim($channelSlug) === '') {
            return self::companyDirect($cid, $code);
        }
        $slug = strtolower(trim($channelSlug));
        if (!isset($channelsBySlug[$slug])) {
            // Invalid Channel → COMPANY_DIRECT/neutral handling, NOT main Channel
            return self::companyDirect($cid, $code) + ['code' => 'CHANNEL_E_INVALID_FALLBACK_COMPANY_DIRECT'];
        }
        $ch = $channelsBySlug[$slug];
        if ((int)($ch['country_id'] ?? 0) !== $cid) {
            return self::companyDirect($cid, $code) + ['code' => 'CHANNEL_E_CROSS_COUNTRY_FALLBACK_COMPANY_DIRECT'];
        }
        if (!(int)($ch['is_active'] ?? 0)) {
            return self::companyDirect($cid, $code) + ['code' => 'CHANNEL_E_INACTIVE_FALLBACK_COMPANY_DIRECT'];
        }
        return [
            'mode' => self::MODE_CHANNEL,
            'country_id' => $cid,
            'country_code' => $code,
            'channel_id' => (int)$ch['id'],
            'channel_slug' => $slug,
            'code' => 'OK',
            'clear_attribution' => false,
        ];
    }

    /** @return array{mode:string,country_id:int,country_code:string,channel_id:?int,channel_slug:?string,code:string,clear_attribution:bool} */
    private static function companyDirect(int $id, string $code): array
    {
        return [
            'mode' => self::MODE_COMPANY_DIRECT,
            'country_id' => $id,
            'country_code' => $code,
            'channel_id' => null,
            'channel_slug' => null,
            'code' => 'OK',
            'clear_attribution' => true,
        ];
    }
}