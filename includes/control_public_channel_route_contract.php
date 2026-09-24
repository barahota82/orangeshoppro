<?php
declare(strict_types=1);
/**
 * Public channel route registry validation/resolution (dormant).
 */
final class OrangePublicChannelRouteContract
{
    public const RESERVED_APP_SEGMENTS = [
        'admin','api','assets','cart','checkout','login','logout','account','track',
        'health','c','static','uploads','webhook','webhooks','favicon.ico','robots.txt',
        'sitemap.xml','privacy','terms','search','product','products','page','pages',
    ];

    /**
     * @param array<string,mixed> $route
     * @param array<int,string> $countryCodes
     * @return array{ok:bool,code:string}
     */
    public static function validateRouteRow(array $route, array $countryCodes): array
    {
        $kind = (string)($route['match_kind'] ?? '');
        $value = strtolower(trim((string)($route['match_value'] ?? '')));
        if ($kind === '' || $value === '') {
            return ['ok' => false, 'code' => 'ROUTE_E_REQUIRED'];
        }
        if (!in_array($kind, ['path_segment', 'query_channel'], true)) {
            return ['ok' => false, 'code' => 'ROUTE_E_MATCH_KIND'];
        }
        if ($kind === 'path_segment') {
            foreach ($countryCodes as $cc) {
                if ($value === strtolower($cc)) {
                    return ['ok' => false, 'code' => 'ROUTE_E_COLLIDES_COUNTRY'];
                }
            }
            if (in_array($value, self::RESERVED_APP_SEGMENTS, true)) {
                return ['ok' => false, 'code' => 'ROUTE_E_RESERVED'];
            }
        }
        $status = (string)($route['route_status'] ?? '');
        if ($status === 'active_compat' && empty($route['source_channel_id_snapshot'])) {
            return ['ok' => false, 'code' => 'ROUTE_E_SNAPSHOT_REQUIRED'];
        }
        return ['ok' => true, 'code' => 'OK'];
    }

    /**
     * @param array<int,array<string,mixed>> $routes
     * @return array{ok:bool,route:?array<string,mixed>,code:string}
     */
    public static function resolvePathSegment(string $segment, array $routes): array
    {
        $seg = strtolower(trim($segment));
        foreach ($routes as $r) {
            if (($r['match_kind'] ?? '') === 'path_segment'
                && strtolower((string)($r['match_value'] ?? '')) === $seg
                && in_array(($r['route_status'] ?? ''), ['active_compat', 'dormant'], true)
            ) {
                if (($r['route_status'] ?? '') === 'dormant') {
                    continue;
                }
                return ['ok' => true, 'route' => $r, 'code' => 'OK'];
            }
        }
        return ['ok' => false, 'route' => null, 'code' => 'ROUTE_E_NOT_FOUND'];
    }
}