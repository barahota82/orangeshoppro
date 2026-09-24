<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/control_public_channel_route_contract.php';
$fail = 0;
$r = OrangePublicChannelRouteContract::validateRouteRow(['match_kind'=>'country_path','match_value'=>'x'], ['KW']);
if (($r['ok'] ?? true) !== false) { echo "FAIL unknown_kind\n"; $fail++; }
$r2 = OrangePublicChannelRouteContract::validateRouteRow(['match_kind'=>'path_segment','match_value'=>'kw'], ['KW']);
if (($r2['code'] ?? '') !== 'ROUTE_E_COLLIDES_COUNTRY') { echo "FAIL country_collide\n"; $fail++; }
echo $fail === 0 ? "SELFTEST_CONTRACTS_MATRIX_PASS\n" : "SELFTEST_CONTRACTS_MATRIX_FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);