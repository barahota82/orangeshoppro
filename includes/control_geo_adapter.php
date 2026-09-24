<?php
declare(strict_types=1);
/**
 * Model-D injected Geo adapter (dormant) — maps ISO2 → Control Country when active.
 * No Production network / provider calls.
 */
final class OrangeControlGeoAdapter
{
    public const MODEL = 'D';

    /**
     * @param array<string,array<string,mixed>> $countriesByIso2 keyed uppercase ISO2
     * @return array{ok:bool,country_id:?int,country_code:?string,code:string}
     */
    public static function resolveInjectedIso2(?string $iso2, array $countriesByIso2): array
    {
        if ($iso2 === null || trim($iso2) === '') {
            return ['ok' => false, 'country_id' => null, 'country_code' => null, 'code' => 'GEO_E_EMPTY'];
        }
        $n = strtoupper(trim($iso2));
        if (!preg_match('/^[A-Z]{2}$/', $n)) {
            return ['ok' => false, 'country_id' => null, 'country_code' => null, 'code' => 'GEO_E_ISO2'];
        }
        if (!isset($countriesByIso2[$n])) {
            return ['ok' => false, 'country_id' => null, 'country_code' => null, 'code' => 'GEO_E_UNMAPPED'];
        }
        $row = $countriesByIso2[$n];
        $life = (string)($row['lifecycle_status'] ?? '');
        if ($life !== 'active') {
            return ['ok' => false, 'country_id' => null, 'country_code' => null, 'code' => 'GEO_E_INACTIVE'];
        }
        return [
            'ok' => true,
            'country_id' => (int)$row['id'],
            'country_code' => (string)$row['code'],
            'code' => 'OK',
        ];
    }
}