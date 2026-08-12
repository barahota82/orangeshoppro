<?php

declare(strict_types=1);

/**
 * Restore Center worker PHP CLI execution contract (absolute-only) — compatibility layer.
 *
 * Canonical implementation: includes/backup/restore/restore_worker_runtime.php
 * Used by restore_center_orchestrator for non-Step-6 detached workers.
 * Step 6 remains on Backup Center Full callable and must not call this resolver.
 */

require_once __DIR__ . '/restore_worker_runtime.php';

/** @deprecated use orange_restore_worker_runtime_path_is_absolute */
function orange_restore_worker_php_cli_path_is_absolute(string $path): bool
{
    return orange_restore_worker_runtime_path_is_absolute($path);
}

/**
 * @return list<string>
 */
function orange_restore_worker_cli_php_candidate_paths(string $projectRoot): array
{
    $paths = [];
    foreach (orange_restore_worker_runtime_cli_candidates($projectRoot) as $row) {
        $paths[] = (string) ($row['path'] ?? '');
    }

    return array_values(array_filter($paths, static fn (string $p): bool => $p !== ''));
}

function orange_restore_worker_php_cli_is_windows_cgi_sibling(string $candidate, string $phpBinary): bool
{
    // Legacy helper required is_file on both; runtime layout trust is preferred.
    if (!orange_restore_worker_runtime_is_cgi_sibling_layout($candidate, $phpBinary)) {
        return false;
    }

    return is_file($candidate);
}

function orange_restore_worker_php_cli_windows_trust_existing_php_exe(string $candidate): bool
{
    return orange_restore_worker_runtime_is_windows_php_exe_name($candidate) && is_file($candidate);
}

/**
 * @return array<string, int|string>
 */
function orange_restore_worker_cli_php_safe_resolve_diag(string $projectRoot): array
{
    return orange_restore_worker_runtime_safe_diag($projectRoot);
}

function orange_restore_worker_cli_php_binary_is_cli(string $phpBinary): bool
{
    return orange_restore_worker_runtime_sapi_is_cli($phpBinary);
}

/**
 * Resolve absolute PHP CLI for Restore Center detached workers.
 * Never returns bare "php". Throws php_cli_binary_unavailable when unresolved.
 */
function orange_restore_worker_resolve_cli_php_binary(string $projectRoot): string
{
    return orange_restore_worker_runtime_resolve_cli_php_binary($projectRoot);
}
