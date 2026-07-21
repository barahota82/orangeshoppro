<?php

declare(strict_types=1);

/**
 * Frozen CPR Special Handlers catalog (WP-P5-04).
 *
 * Handler IDs and policies from COUNTRY_RESTORE_BOUNDARY_POLICY.md §4 and
 * COUNTRY_DEPENDENCY_GRAPH.md §3. Excluded handlers must never execute.
 *
 * Deterministic post-IMPORT execution order (Architecture §6 / §18 CP8):
 * composites/resolvers first → seq_country_namespace last.
 *
 * @see docs/backup/COUNTRY_RESTORE_BOUNDARY_POLICY.md §4–§5
 * @see docs/backup/COUNTRY_DEPENDENCY_GRAPH.md §3
 */

const ORANGE_CPR_SPECIAL_HANDLERS_ORDER_VERSION = 'c1.1-special_handlers/1';

/**
 * Executable special handlers in deterministic order (post CP7).
 *
 * @return list<string>
 */
function orange_cpr_special_handlers_executable_order(): array
{
    return [
        'admins_permissions_composite',
        'expenses_via_accounts',
        'polymorphic_company_documents',
        'gl_voucher_slots_country',
        'seq_country_namespace',
    ];
}

/**
 * Handlers that must never country-mutate (boundary policy excluded).
 *
 * @return list<string>
 */
function orange_cpr_special_handlers_excluded(): array
{
    return [
        'full_only_journal_entries',
        'ignore_screen_copy_log',
    ];
}

/**
 * @return array<string, array{
 *   tables:list<string>,
 *   requires_import_batches:list<int>,
 *   requires_handlers:list<string>,
 *   kind:string,
 *   description:string
 * }>
 */
function orange_cpr_special_handlers_definitions(): array
{
    return [
        'admins_permissions_composite' => [
            'tables' => ['admins', 'admin_permissions'],
            'requires_import_batches' => [1, 2],
            'requires_handlers' => [],
            'kind' => 'composite',
            'description' => 'D4 admin authz composite unit',
        ],
        'expenses_via_accounts' => [
            'tables' => ['expenses'],
            'requires_import_batches' => [1, 2],
            'requires_handlers' => [],
            'kind' => 'resolver',
            'description' => 'Expenses membership via accounts.country_id',
        ],
        'polymorphic_company_documents' => [
            'tables' => ['orange_company_documents'],
            'requires_import_batches' => [3],
            'requires_handlers' => [],
            'kind' => 'polymorphic',
            'description' => 'Company docs polymorphic owner validation',
        ],
        'gl_voucher_slots_country' => [
            'tables' => ['orange_gl_voucher_slots'],
            'requires_import_batches' => [2, 3],
            'requires_handlers' => [],
            'kind' => 'resolver',
            'description' => 'GL voucher slots country resolver',
        ],
        'seq_country_namespace' => [
            'tables' => ['document_sequences'],
            'requires_import_batches' => [6],
            'requires_handlers' => [
                'admins_permissions_composite',
                'expenses_via_accounts',
                'polymorphic_company_documents',
                'gl_voucher_slots_country',
            ],
            'kind' => 'sequence_namespace',
            'description' => 'D3 document_sequences country namespace (never lower counters)',
        ],
        'full_only_journal_entries' => [
            'tables' => ['journal_entries'],
            'requires_import_batches' => [],
            'requires_handlers' => [],
            'kind' => 'excluded',
            'description' => 'D6 full-only — never Country-mutate',
        ],
        'ignore_screen_copy_log' => [
            'tables' => ['orange_country_screen_copy_log'],
            'requires_import_batches' => [],
            'requires_handlers' => [],
            'kind' => 'excluded',
            'description' => 'D5 ignore — never Country-mutate',
        ],
    ];
}

function orange_cpr_special_handler_definition(string $handlerId): ?array
{
    $defs = orange_cpr_special_handlers_definitions();

    return $defs[$handlerId] ?? null;
}

function orange_cpr_special_handler_is_executable(string $handlerId): bool
{
    return in_array($handlerId, orange_cpr_special_handlers_executable_order(), true);
}

function orange_cpr_special_handler_is_excluded(string $handlerId): bool
{
    return in_array($handlerId, orange_cpr_special_handlers_excluded(), true);
}
