<?php
declare(strict_types=1);
/**
 * COMPANY_DIRECT / CHANNEL order invariant contract (dormant).
 */
final class OrangeCompanyDirectOrderContract
{
    public const SOURCE_COMPANY_DIRECT = 'COMPANY_DIRECT';
    public const SOURCE_CHANNEL = 'CHANNEL';

    /**
     * @param array<string,mixed> $order
     * @return array{ok:bool,code:string}
     */
    public static function validate(array $order): array
    {
        $kind = (string)($order['source_kind'] ?? '');
        if ($kind === self::SOURCE_COMPANY_DIRECT) {
            if (array_key_exists('channel_id', $order) && $order['channel_id'] !== null) {
                return ['ok' => false, 'code' => 'ORDER_E_COMPANY_DIRECT_CHANNEL_NOT_NULL'];
            }
            if (empty($order['source_attribution_started_at'])) {
                return ['ok' => false, 'code' => 'ORDER_E_ATTRIBUTION_STARTED_REQUIRED'];
            }
            return ['ok' => true, 'code' => 'OK'];
        }
        if ($kind === self::SOURCE_CHANNEL) {
            if (!isset($order['channel_id']) || (int)$order['channel_id'] <= 0) {
                return ['ok' => false, 'code' => 'ORDER_E_CHANNEL_ID_REQUIRED'];
            }
            $orderCountry = (int)($order['country_id'] ?? 0);
            $channelCountry = (int)($order['channel_country_id'] ?? 0);
            if ($orderCountry <= 0 || $channelCountry <= 0 || $orderCountry !== $channelCountry) {
                return ['ok' => false, 'code' => 'ORDER_E_CHANNEL_COUNTRY_MISMATCH'];
            }
            if (!array_key_exists('source_channel_id_snapshot', $order) || $order['source_channel_id_snapshot'] === null) {
                return ['ok' => false, 'code' => 'ORDER_E_CHANNEL_SNAPSHOT_REQUIRED'];
            }
            return ['ok' => true, 'code' => 'OK'];
        }
        return ['ok' => false, 'code' => 'ORDER_E_SOURCE_KIND'];
    }
}