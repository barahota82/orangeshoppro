<?php

declare(strict_types=1);

/**
 * CPR Pre-PONR gate catalog (WP-P3-06) — CPR-P1-WP08-PRE_PONR_GATES.
 */

const ORANGE_CPR_GATE_EVAL_SCHEMA = 'cpr_gate_evaluation/1';
const ORANGE_CPR_GATE_EVALUATOR_VERSION = 'P3-06-1.0';

const ORANGE_CPR_GATE_PASS = 'PASS';
const ORANGE_CPR_GATE_FAIL = 'FAIL';

/**
 * @return list<string>
 */
function orange_cpr_gate_ids_pre_ponr_full(): array
{
    $ids = [];
    for ($i = 1; $i <= 30; ++$i) {
        $ids[] = 'G' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    }
    $ids[] = 'G-FA-RESOLVER';
    $ids[] = 'G-FA-STOCK';
    $ids[] = 'G-FA-SCHEMA';

    return $ids;
}

/**
 * package_chain: G07–G19 + FA gates (P1-08 §3.4).
 *
 * @return list<string>
 */
function orange_cpr_gate_ids_package_chain(): array
{
    $ids = [];
    for ($i = 7; $i <= 19; ++$i) {
        $ids[] = 'G' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    }
    $ids[] = 'G-FA-RESOLVER';
    $ids[] = 'G-FA-STOCK';
    $ids[] = 'G-FA-SCHEMA';

    return $ids;
}

/**
 * @return list<string>
 */
function orange_cpr_gate_ids_for_profile(string $profile): array
{
    return match ($profile) {
        'package_chain' => orange_cpr_gate_ids_package_chain(),
        'pre_ponr_full' => orange_cpr_gate_ids_pre_ponr_full(),
        default => throw new InvalidArgumentException('Unknown gate profile: ' . $profile),
    };
}
