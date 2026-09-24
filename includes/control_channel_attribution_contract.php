<?php
declare(strict_types=1);
/**
 * HMAC-SHA256 channel attribution issue/verify (dormant, server-key input only).
 * Frozen payload uses explicit attribution_version (not abbreviated "v").
 */
final class OrangeChannelAttributionContract
{
    public const ALG = 'sha256';
    public const TTL_SECONDS = 34560000;
    public const COOKIE_NAME = 'orange_sf_ch';
    public const ATTRIBUTION_VERSION = 1;
    public const VERSION = 1; // backward alias

    /**
     * @return array{token:string,payload:array<string,mixed>,expires_at:int}
     */
    public static function issue(
        string $serverKey,
        string $countryCode,
        int $channelId,
        string $channelSlug,
        ?int $issuedAt = null,
        ?int $countryId = null
    ): array {
        if ($serverKey === '') {
            throw new InvalidArgumentException('ATTR_E_KEY_REQUIRED');
        }
        $issuedAt = $issuedAt ?? time();
        $expiresAt = $issuedAt + self::TTL_SECONDS;
        $payload = [
            'attribution_version' => self::ATTRIBUTION_VERSION,
            'country_code' => strtoupper($countryCode),
            'channel_id' => $channelId,
            'channel_slug' => $channelSlug,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ];
        if ($countryId !== null) {
            $payload['country_id'] = $countryId;
        }
        $body = self::canonical($payload);
        $sig = hash_hmac(self::ALG, $body, $serverKey);
        return [
            'token' => rtrim(strtr(base64_encode($body), '+/', '-_'), '=') . '.' . $sig,
            'payload' => $payload,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array{ok:bool,code:string,payload:?array<string,mixed>}
     */
    public static function verify(
        string $serverKey,
        string $token,
        ?string $expectedCountryCode = null,
        ?int $now = null,
        ?int $expectedChannelId = null
    ): array {
        $now = $now ?? time();
        if ($serverKey === '' || $token === '') {
            return ['ok' => false, 'code' => 'ATTR_E_UNSIGNED', 'payload' => null];
        }
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return ['ok' => false, 'code' => 'ATTR_E_MALFORMED', 'payload' => null];
        }
        [$b64, $sig] = $parts;
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $body = base64_decode(strtr($b64, '-_', '+/'), true);
        if ($body === false) {
            return ['ok' => false, 'code' => 'ATTR_E_MALFORMED', 'payload' => null];
        }
        $expect = hash_hmac(self::ALG, $body, $serverKey);
        if (!hash_equals($expect, $sig)) {
            return ['ok' => false, 'code' => 'ATTR_E_INVALID', 'payload' => null];
        }
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return ['ok' => false, 'code' => 'ATTR_E_MALFORMED', 'payload' => null];
        }
        foreach (['attribution_version', 'country_code', 'channel_id', 'channel_slug', 'issued_at', 'expires_at'] as $req) {
            if (!array_key_exists($req, $payload)) {
                return ['ok' => false, 'code' => 'ATTR_E_REQUIRED_FIELD', 'payload' => null];
            }
        }
        if ((int)($payload['attribution_version'] ?? 0) !== self::ATTRIBUTION_VERSION) {
            return ['ok' => false, 'code' => 'ATTR_E_VERSION', 'payload' => null];
        }
        $cc = strtoupper(trim((string)($payload['country_code'] ?? '')));
        if ($cc === '' || !preg_match('/^[A-Z]{2}$/', $cc)) {
            return ['ok' => false, 'code' => 'ATTR_E_COUNTRY_CODE', 'payload' => null];
        }
        $slug = (string)($payload['channel_slug'] ?? '');
        if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug)) {
            return ['ok' => false, 'code' => 'ATTR_E_CHANNEL_SLUG', 'payload' => null];
        }
        if (!is_int($payload['channel_id']) && !ctype_digit((string)$payload['channel_id'])) {
            return ['ok' => false, 'code' => 'ATTR_E_CHANNEL_ID', 'payload' => null];
        }
        if (array_key_exists('country_id', $payload)) {
            if (!is_int($payload['country_id']) && !ctype_digit((string)$payload['country_id'])) {
                return ['ok' => false, 'code' => 'ATTR_E_COUNTRY_ID', 'payload' => null];
            }
            if ((int)$payload['country_id'] <= 0) {
                return ['ok' => false, 'code' => 'ATTR_E_COUNTRY_ID', 'payload' => null];
            }
        }
        $issued = (int)($payload['issued_at'] ?? 0);
        $expires = (int)($payload['expires_at'] ?? 0);
        if ($issued <= 0 || $expires <= 0 || $expires <= $issued) {
            return ['ok' => false, 'code' => 'ATTR_E_TIME_ORDER', 'payload' => null];
        }
        if ($expires < $now) {
            return ['ok' => false, 'code' => 'ATTR_E_EXPIRED', 'payload' => null];
        }
        if ($expectedCountryCode !== null) {
            $cc = strtoupper((string)$expectedCountryCode);
            if (strtoupper((string)($payload['country_code'] ?? '')) !== $cc) {
                return ['ok' => false, 'code' => 'ATTR_E_CROSS_COUNTRY', 'payload' => null];
            }
        }
        if ($expectedChannelId !== null && (int)$payload['channel_id'] !== $expectedChannelId) {
            return ['ok' => false, 'code' => 'ATTR_E_CHANNEL_MISMATCH', 'payload' => null];
        }
        return ['ok' => true, 'code' => 'OK', 'payload' => $payload];
    }

    /** Direct Root / Country Company entry clears channel attribution. */
    public static function clearOnDirectEntry(?string $existingToken): ?string
    {
        return null;
    }

    /** @param array<string,mixed> $payload */
    private static function canonical(array $payload): string
    {
        ksort($payload);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('ATTR_E_JSON');
        }
        return $json;
    }
}