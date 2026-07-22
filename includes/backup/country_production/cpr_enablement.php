<?php

declare(strict_types=1);

/**
 * CPR enablement flag read + sealed ops-state substrate.
 *
 * Hard default FALSE. Ops-state writes are authorized only from WP-P9-03
 * (`cpr_enablement_action_live.php`). No other module may flip the flag.
 *
 * OD-ENABLE · OD-PERM · OD-SCHEMA · P1-13 · P9-03.
 */

const ORANGE_CPR_ENABLEMENT_OPS_DIRNAME = 'enablement_ops';
const ORANGE_CPR_ENABLEMENT_OPS_STATE_SCHEMA = 'cpr_enablement_ops_state/1';
const ORANGE_CPR_ENABLEMENT_OPS_STATE_FILENAME = 'cpr_enablement_ops_state_latest.json';

function orange_cpr_enablement_ops_state_directory(string $cprRoot): string
{
    return rtrim($cprRoot, "\\/") . DIRECTORY_SEPARATOR . ORANGE_CPR_ENABLEMENT_OPS_DIRNAME;
}

function orange_cpr_enablement_ops_state_path(string $cprRoot): string
{
    return orange_cpr_enablement_ops_state_directory($cprRoot)
        . DIRECTORY_SEPARATOR . ORANGE_CPR_ENABLEMENT_OPS_STATE_FILENAME;
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_enablement_ops_state_load(string $cprRoot): ?array
{
    require_once __DIR__ . '/cpr_paths.php';
    require_once __DIR__ . '/cpr_authority_engine.php';

    $path = orange_cpr_enablement_ops_state_path($cprRoot);
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !orange_cpr_auth_verify_seal($data)) {
        return null;
    }
    if (($data['schema_version'] ?? '') !== ORANGE_CPR_ENABLEMENT_OPS_STATE_SCHEMA) {
        return null;
    }

    return $data;
}

/**
 * WP-P9-03 only — write sealed operational enablement state (no SQL / no uploads).
 *
 * @param array<string, mixed> $meta
 */
function orange_cpr_enablement_ops_state_write(string $cprRoot, bool $enabled, array $meta = []): void
{
    require_once __DIR__ . '/cpr_paths.php';
    require_once __DIR__ . '/cpr_authority_engine.php';

    $dir = orange_cpr_enablement_ops_state_directory($cprRoot);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR enablement_ops directory.');
    }

    $payload = array_merge([
        'schema_version' => ORANGE_CPR_ENABLEMENT_OPS_STATE_SCHEMA,
        'enabled' => $enabled,
        'enablement_state' => $enabled ? 'E6_enabled' : 'E7_disabled_operational',
        'written_by_wp' => 'WP-P9-03',
        'automatic' => false,
        'auto_reenable' => false,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'sealed' => true,
        'updated_at' => gmdate('c'),
    ], $meta);
    $payload['enabled'] = $enabled;
    $payload['automatic'] = false;
    $payload['auto_reenable'] = false;
    $payload['written_by_wp'] = 'WP-P9-03';
    $payload['schema_version'] = ORANGE_CPR_ENABLEMENT_OPS_STATE_SCHEMA;
    $payload['sealed'] = true;

    $path = orange_cpr_enablement_ops_state_path($cprRoot);
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $sealed = orange_cpr_auth_seal($payload);
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Enablement ops state write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $path);
}

/**
 * @param array<string, mixed> $env
 */
function orange_cpr_enablement_flag_read(array $env): bool
{
    // Authoritative sealed ops state (WP-P9-03) when present under CPR root.
    if (function_exists('orange_cpr_resolve_work_root')) {
        try {
            $cprRoot = orange_cpr_resolve_work_root($env);
            if (is_string($cprRoot) && $cprRoot !== '' && is_dir($cprRoot)) {
                $ops = orange_cpr_enablement_ops_state_load($cprRoot);
                if (is_array($ops) && array_key_exists('enabled', $ops)) {
                    return !empty($ops['enabled']);
                }
            }
        } catch (Throwable $e) {
            // Fall through to env / default.
        }
    } else {
        require_once __DIR__ . '/cpr_paths.php';
        try {
            $cprRoot = orange_cpr_resolve_work_root($env);
            if (is_string($cprRoot) && $cprRoot !== '' && is_dir($cprRoot)) {
                $ops = orange_cpr_enablement_ops_state_load($cprRoot);
                if (is_array($ops) && array_key_exists('enabled', $ops)) {
                    return !empty($ops['enabled']);
                }
            }
        } catch (Throwable $e) {
            // Fall through.
        }
    }

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
 * Scaffolding refuses mutation paths while ops enablement appears true.
 *
 * @param array<string, mixed> $env
 */
function orange_cpr_assert_enablement_false_for_scaffold(array $env): void
{
    if (orange_cpr_enablement_flag_read($env)) {
        throw new RuntimeException(
            'CPR scaffold refuses operation while enablement appears true; '
            . 'mutation scaffolds remain fail-closed unless OD-ENABLE ops path authorizes (P9).'
        );
    }
}
