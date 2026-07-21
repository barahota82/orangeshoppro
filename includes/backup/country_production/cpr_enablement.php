<?php

declare(strict_types=1);

/**
 * CPR enablement flag read (WP-P3-02 scaffolding).
 *
 * Hard default FALSE. This module never writes true.
 * OD-ENABLE · P1-13 · P3 hard rules.
 */

/**
 * @param array<string, mixed> $env
 */
function orange_cpr_enablement_flag_read(array $env): bool
{
    $raw = $env['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED']
        ?? $env['country_production_restore_enabled']
        ?? false;

    if (is_bool($raw)) {
        return $raw;
    }
    if (is_int($raw) || is_float($raw)) {
        return ((int) $raw) === 1;
    }
    $s = strtolower(trim((string) $raw));

    return in_array($s, ['1', 'true', 'yes', 'on'], true);
}

/**
 * Scaffolding always treats production mutation as disabled unless a future
 * OD-ENABLE path (P9) flips the flag under separate authorization.
 *
 * @param array<string, mixed> $env
 */
function orange_cpr_assert_enablement_false_for_scaffold(array $env): void
{
    if (orange_cpr_enablement_flag_read($env)) {
        // Even if misconfigured true in env during P3, scaffolding refuses mutation paths.
        // Read is recorded; writers that flip the flag are out of P3 scope.
        throw new RuntimeException(
            'CPR scaffold refuses operation while enablement appears true; '
            . 'P3 does not authorize enablement (OD-ENABLE / P9).'
        );
    }
}
