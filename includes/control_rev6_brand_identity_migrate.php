<?php

declare(strict_types=1);

/**
 * Future Control Rev6 binding for Brand Identity tables.
 *
 * Live includes/control_schema.php is NOT edited by this candidate.
 * Live ORANGE_CONTROL_SCHEMA_REVISION must remain 5.
 *
 * Exact later hook (document / future apply only):
 *   require_once __DIR__ . '/brand_identity_schema.php';
 *   require_once __DIR__ . '/control_rev6_brand_identity_migrate.php';
 *   if (installedRev === 5 && targetRev === 6) {
 *       orange_control_brand_identity_migrate_5to6($controlPdo);
 *       // then stamp ctrl_schema_meta.control_schema_revision = 6
 *   }
 *
 * This file must not be invoked against live Control in M03 Step 3.
 */

require_once __DIR__ . '/brand_identity_schema.php';

function orange_control_brand_identity_migrate_5to6(PDO $controlPdo): void
{
    if ((int) ORANGE_BRAND_IDENTITY_LIVE_CONTROL_REVISION_MUST_REMAIN !== 5) {
        throw new RuntimeException('BRAND_IDENTITY_LIVE_REVISION_CONTRACT');
    }
    if ((int) ORANGE_BRAND_IDENTITY_FUTURE_CONTROL_REVISION_BINDING !== 6) {
        throw new RuntimeException('BRAND_IDENTITY_FUTURE_REVISION_CONTRACT');
    }
    orange_brand_identity_install_tables($controlPdo);
    orange_brand_identity_verify_schema($controlPdo);
}

function orange_control_brand_identity_install_fresh_rev6(PDO $controlPdo): void
{
    orange_control_brand_identity_migrate_5to6($controlPdo);
}

function orange_control_brand_identity_verify_rev6(PDO $controlPdo): void
{
    orange_brand_identity_verify_schema($controlPdo);
}
