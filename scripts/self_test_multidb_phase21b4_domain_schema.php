<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/control_domain_schema.php';
$fail = 0;
if (count(ORANGE_CONTROL_DOMAIN_TABLES) !== 9) { echo "FAIL table_count\n"; $fail++; }
if (count(ORANGE_CONTROL_DOMAIN_ROUTINES) !== 27) { echo "FAIL routine_count\n"; $fail++; }
$sql = orange_control_domain_nine_tables_sql();
if (!str_contains($sql, 'verification_method') || !str_contains($sql, "DEFAULT 'unknown'")) {
    echo "FAIL k02_fields\n"; $fail++;
}
if (!str_contains($sql, 'fk_ctrl_mep_country') || !str_contains($sql, 'fk_ctrl_wa_country_country')) {
    echo "FAIL required_fks\n"; $fail++;
}
if (!str_contains($sql, 'ctrl_domain_hosts')) { echo "FAIL hosts\n"; $fail++; }
$mut = count(ORANGE_CONTROL_DOMAIN_ROUTINES) - 1;
if ($mut === 27) { echo "FAIL mutation_insensitive\n"; $fail++; }
echo $fail === 0 ? "SELFTEST_DOMAIN_SCHEMA_PASS\n" : "SELFTEST_DOMAIN_SCHEMA_FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);