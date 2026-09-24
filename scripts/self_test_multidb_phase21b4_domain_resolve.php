<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/control_domain_resolve.php';
require_once __DIR__ . '/../includes/control_geo_adapter.php';
$fail = 0;
$countries = [
    'KW' => ['id' => 1, 'code' => 'KW', 'lifecycle_status' => 'active', 'geo_iso2_code' => 'KW'],
    'EG' => ['id' => 2, 'code' => 'EG', 'lifecycle_status' => 'active', 'geo_iso2_code' => 'EG'],
];
if (!class_exists('OrangeControlDomainResolve')) {
    echo "FAIL missing_OrangeControlDomainResolve\n";
    $fail++;
} else {
    $r = OrangeControlDomainResolve::resolveRoot([
        'explicit_country_code' => 'KW',
        'countries' => $countries,
    ]);
    if (($r['mode'] ?? '') !== OrangeControlDomainResolve::MODE_COMPANY_DIRECT || ($r['country_code'] ?? '') !== 'KW') {
        echo "FAIL explicit_root\n";
        $fail++;
    }
    $n = OrangeControlDomainResolve::resolveRoot([
        'countries' => $countries,
        'injected_geo_iso2' => 'XX',
    ]);
    if (($n['mode'] ?? '') !== OrangeControlDomainResolve::MODE_NEUTRAL) {
        echo "FAIL neutral_geo\n";
        $fail++;
    }
    $path = OrangeControlDomainResolve::resolveCountryPath($countries['KW'], null, []);
    // COMPANY_DIRECT requires channel_id === null (do not coalesce null→1; that false-fails forever).
    if (($path['mode'] ?? '') !== OrangeControlDomainResolve::MODE_COMPANY_DIRECT || ($path['channel_id'] ?? null) !== null) {
        echo "FAIL company_direct_path\n";
        $fail++;
    }
}
echo $fail === 0 ? "SELFTEST_DOMAIN_RESOLVE_PASS\n" : "SELFTEST_DOMAIN_RESOLVE_FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);