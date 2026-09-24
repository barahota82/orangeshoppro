<?php
declare(strict_types=1);
/**
 * Market entry + WhatsApp hierarchy + derived-content publish resolver (dormant).
 */
final class OrangeMarketEntryContract
{
    public const SHIPPING_INTERNATIONAL = 'OFF';

    /**
     * Publish derived i18n only when approved AND lineage matches current Approved-EN.
     *
     * @param array<string,mixed> $content base market content row
     * @param array<string,mixed> $i18n derived row
     * @return array{publish:bool,stale:bool,code:string,message:?array<string,string>}
     */
    public static function resolveDerivedPublish(array $content, array $i18n): array
    {
        $life = (string)($content['lifecycle_status'] ?? '');
        // Empty/blank lifecycle is explicitly non-publishable (no fall-through).
        if ($life === '' || !in_array($life, ['approved'], true)) {
            return ['publish' => false, 'stale' => false, 'code' => 'CONTENT_E_NOT_PUBLISHABLE_LIFECYCLE', 'message' => null];
        }
        $enReview = (string)($content['english_review_status'] ?? '');
        if ($enReview !== 'approved') {
            return ['publish' => false, 'stale' => false, 'code' => 'CONTENT_E_ENGLISH_NOT_APPROVED', 'message' => null];
        }
        $review = (string)($i18n['review_status'] ?? '');
        if ($review !== 'approved') {
            return ['publish' => false, 'stale' => false, 'code' => 'I18N_E_NOT_APPROVED', 'message' => null];
        }
        $trStatus = (string)($i18n['translation_status'] ?? '');
        if ($trStatus !== 'translated') {
            return ['publish' => false, 'stale' => false, 'code' => 'I18N_E_NOT_TRANSLATED', 'message' => null];
        }
        $curRev = (int)($content['approved_en_revision'] ?? 0);
        $curHash = (string)($content['approved_en_hash'] ?? '');
        $bindRev = (int)($i18n['translated_from_en_revision'] ?? -1);
        $bindHash = (string)($i18n['translated_from_en_hash'] ?? '');
        if ($curRev <= 0 || $curHash === '' || $bindRev !== $curRev || !hash_equals($curHash, $bindHash)) {
            return ['publish' => false, 'stale' => true, 'code' => 'I18N_E_STALE_LINEAGE', 'message' => null];
        }
        return [
            'publish' => true,
            'stale' => false,
            'code' => 'OK',
            'message' => [
                'title' => (string)($i18n['title_utf8'] ?? ''),
                'body' => (string)($i18n['body_utf8'] ?? ''),
                'locale' => (string)($i18n['locale_code'] ?? ''),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $policy
     * @return array{market_open:bool,show_message:bool,mode:string,code:string}
     */
    public static function resolveMarketGate(array $policy): array
    {
        $status = (string)($policy['market_status'] ?? 'closed');
        $mode = (string)($policy['unavailable_message_mode'] ?? 'AUTO');
        if (!in_array($mode, ['AUTO', 'SHOW', 'HIDE'], true)) {
            $mode = 'AUTO';
        }
        if ($status === 'open') {
            return ['market_open' => true, 'show_message' => false, 'mode' => $mode, 'code' => 'OK'];
        }
        $show = match ($mode) {
            'SHOW' => true,
            'HIDE' => false,
            default => in_array($status, ['closed', 'suspended'], true),
        };
        return ['market_open' => false, 'show_message' => $show, 'mode' => $mode, 'code' => 'MARKET_' . strtoupper($status)];
    }

    /**
     * WhatsApp hierarchy: PRE_COUNTRY→Global; COMPANY_DIRECT Country→Global if allowed;
     * CHANNEL Country-DB→Country Control→Global if allowed.
     *
     * @return array{e164:?string,source:string,code:string}
     */
    public static function resolveWhatsApp(
        string $entryKind,
        ?string $channelCountryWa,
        ?string $countryControlWa,
        ?string $globalWa,
        bool $allowGlobalFallback
    ): array {
        if ($entryKind === 'PRE_COUNTRY') {
            return ['e164' => $globalWa, 'source' => 'GLOBAL', 'code' => $globalWa ? 'OK' : 'WA_E_GLOBAL_MISSING'];
        }
        if ($entryKind === 'COMPANY_DIRECT') {
            if ($countryControlWa) {
                return ['e164' => $countryControlWa, 'source' => 'COUNTRY_CONTROL', 'code' => 'OK'];
            }
            if ($allowGlobalFallback && $globalWa) {
                return ['e164' => $globalWa, 'source' => 'GLOBAL_FALLBACK', 'code' => 'OK'];
            }
            return ['e164' => null, 'source' => 'NONE', 'code' => 'WA_E_NO_FALLBACK'];
        }
        if ($entryKind === 'CHANNEL') {
            if ($channelCountryWa) {
                return ['e164' => $channelCountryWa, 'source' => 'CHANNEL_COUNTRY_DB', 'code' => 'OK'];
            }
            if ($countryControlWa) {
                return ['e164' => $countryControlWa, 'source' => 'COUNTRY_CONTROL', 'code' => 'OK'];
            }
            if ($allowGlobalFallback && $globalWa) {
                return ['e164' => $globalWa, 'source' => 'GLOBAL_FALLBACK', 'code' => 'OK'];
            }
            return ['e164' => null, 'source' => 'NONE', 'code' => 'WA_E_NO_FALLBACK'];
        }
        return ['e164' => null, 'source' => 'NONE', 'code' => 'WA_E_ENTRY_KIND'];
    }
}