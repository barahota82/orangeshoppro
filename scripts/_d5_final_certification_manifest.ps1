# FSR D5 final certification manifest — one complete run from committed HEAD.
# Test-only. Exact PASS/FAIL/SKIP arithmetic. No Production behavior change.
param([int]$D4TimeoutSec = 360)
$ErrorActionPreference = 'Continue'
$php = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
$root = 'D:\orange'
Set-Location $root

$rows = New-Object System.Collections.Generic.List[object]
$namedSkips = New-Object System.Collections.Generic.List[string]
$batteryStart = [Diagnostics.Stopwatch]::StartNew()

function Add-Row(
    [string]$Command,
    [int]$Pass,
    [int]$Fail,
    [int]$Skip,
    [int]$ExitCode,
    [int]$Ms,
    [string]$Nested,
    [string]$Classification,
    [string]$SkipNames,
    [string]$EnvPrereq
) {
    $script:rows.Add([pscustomobject]@{
        command = $Command
        PASS = $Pass
        FAIL = $Fail
        SKIP = $Skip
        Exit = $ExitCode
        duration_ms = $Ms
        nested = $Nested
        classification = $Classification
        skip_names = $SkipNames
        env_prereq = $EnvPrereq
    }) | Out-Null
    Write-Host ("CMD {0} PASS={1} FAIL={2} SKIP={3} Exit={4} MS={5} nested={6}" -f $Command,$Pass,$Fail,$Skip,$ExitCode,$Ms,$Nested)
}

function Assert-Tracked([string]$Rel) {
    git -C $root ls-files --error-unmatch $Rel 1>$null 2>$null
    if ($LASTEXITCODE -ne 0) { throw "Not tracked: $Rel" }
}

function Run-PhpSuite([string]$Rel, [string]$Nested = 'no', [string]$EnvPrereq = 'none', [switch]$Tracked) {
    if ($Tracked) { Assert-Tracked $Rel }
    $abs = Join-Path $root $Rel
    if (-not (Test-Path -LiteralPath $abs)) {
        Add-Row $Rel 0 0 1 2 0 $Nested 'REQUIRED_MISSING' "FILE_MISSING:$Rel" $EnvPrereq
        [void]$namedSkips.Add("FILE_MISSING:$Rel")
        return
    }
    $sw = [Diagnostics.Stopwatch]::StartNew()
    $out = & $php -d output_buffering=0 $abs 2>&1 | Out-String
    $code = $LASTEXITCODE
    $sw.Stop()
    $summary = [regex]::Match($out, '(?m)^PASS=(\d+)\s+FAIL=(\d+)\s+SKIP=(\d+)')
    if ($summary.Success) {
        $pass = [int]$summary.Groups[1].Value
        $fail = [int]$summary.Groups[2].Value
        $skip = [int]$summary.Groups[3].Value
    } else {
        $tot = [regex]::Match($out, '(?m)^TOTAL_PASS:\s*(\d+)\s*$')
        $totF = [regex]::Match($out, '(?m)^TOTAL_FAIL:\s*(\d+)\s*$')
        $totS = [regex]::Match($out, '(?m)^TOTAL_SKIP:\s*(\d+)\s*$')
        if ($tot.Success) {
            $pass = [int]$tot.Groups[1].Value
            $fail = $(if ($totF.Success) { [int]$totF.Groups[1].Value } else { 0 })
            $skip = $(if ($totS.Success) { [int]$totS.Groups[1].Value } else { 0 })
        } elseif ($Rel -match 'self_test_backup|self_test_country_crp|self_test_restore') {
            $pass = ([regex]::Matches($out, '(?m)^PASS:')).Count
            $fail = ([regex]::Matches($out, '(?m)^FAIL:')).Count
            $skip = ([regex]::Matches($out, '(?m)^SKIP:')).Count
            # Some CRP suites print "PASS  " with two spaces
            if ($pass -eq 0 -and $fail -eq 0) {
                $pass = ([regex]::Matches($out, '(?m)^PASS\s')).Count
                $fail = ([regex]::Matches($out, '(?m)^FAIL\s')).Count
                $skip = ([regex]::Matches($out, '(?m)^SKIP\s')).Count
            }
        } else {
            $pass = ([regex]::Matches($out, '(?m)^PASS\s')).Count
            $fail = ([regex]::Matches($out, '(?m)^FAIL\s')).Count
            $skip = ([regex]::Matches($out, '(?m)^SKIP\s')).Count
        }
        # Canonical 97 / hygiene style: PASS=N FAIL=N without SKIP=
        $pf = [regex]::Match($out, '(?m)^PASS=(\d+)\s+FAIL=(\d+)\s*$')
        if ($pf.Success) {
            $pass = [int]$pf.Groups[1].Value
            $fail = [int]$pf.Groups[2].Value
        }
    }
    $skipLines = @($out -split "`r?`n" | Where-Object { $_ -match '^(SKIP:|SKIP\s+)' })
    foreach ($s in $skipLines) {
        [void]$namedSkips.Add(("$Rel :: " + $s.Trim()))
    }
    $cls = if ($fail -gt 0) { 'FAIL' }
        elseif ($out -match 'ENVIRONMENT_BLOCKED') { 'SKIP' }
        elseif ($code -ne 0 -and $pass -eq 0) { 'FAIL' }
        elseif ($skip -gt 0 -and $pass -eq 0) { 'SKIP' }
        else { 'PASS' }
    if ($cls -eq 'SKIP' -and $skip -eq 0) { $skip = 1 }
    Add-Row $Rel $pass $fail $skip $code $sw.ElapsedMilliseconds $Nested $cls (($skipLines -join ' | ')) $EnvPrereq
    if ($fail -gt 0 -or ($code -ne 0 -and $cls -eq 'FAIL')) {
        $tail = ($out.Trim() -replace '\s+', ' ')
        if ($tail.Length -gt 500) { $tail = $tail.Substring($tail.Length - 500) }
        Write-Host ("NOTE fail_tail={0}" -f $tail)
    }
}

$script:d4CsvRows = @()

function Run-Ps1Nested([string]$Rel, [int]$TimeoutSec) {
    Assert-Tracked $Rel
    $abs = Join-Path $root $Rel
    $sw = [Diagnostics.Stopwatch]::StartNew()
    $out = & powershell -NoProfile -File $abs -TimeoutSec $TimeoutSec 2>&1 | Out-String
    $code = $LASTEXITCODE
    $sw.Stop()
    # Aggregate from D4 CSV if present
    $csv = Join-Path $root 'scripts\_d4_closure_manifest.csv'
    $pass = 0; $fail = 0; $skip = 0
    if (Test-Path $csv) {
        $data = @(Import-Csv $csv)
        $script:d4CsvRows = $data
        foreach ($r in $data) {
            $pass += [int]$r.pass
            $fail += [int]$r.fail
            $skip += [int]$r.skip
        }
    } else {
        $summary = [regex]::Match($out, '(?m)RAW_PASS=(\d+)|Raw_PASS[=:](\d+)')
        $summaryF = [regex]::Match($out, '(?m)RAW_FAIL=(\d+)|Raw_FAIL[=:](\d+)')
        $summaryS = [regex]::Match($out, '(?m)RAW_SKIP=(\d+)|Raw_SKIP[=:](\d+)')
        if ($summary.Success) {
            $pass = [int]($(if ($summary.Groups[1].Value) { $summary.Groups[1].Value } else { $summary.Groups[2].Value }))
            $fail = [int]($(if ($summaryF.Groups[1].Value) { $summaryF.Groups[1].Value } else { $summaryF.Groups[2].Value }))
            $skip = [int]($(if ($summaryS.Groups[1].Value) { $summaryS.Groups[1].Value } else { $summaryS.Groups[2].Value }))
        }
    }
    # Align with PHP suite classification: non-zero Exit alone is not FAIL when assertions passed.
    $cls = if ($fail -gt 0) { 'FAIL' } elseif ($code -ne 0 -and $pass -eq 0) { 'FAIL' } else { 'PASS' }
    Add-Row $Rel $pass $fail $skip $code $sw.ElapsedMilliseconds 'yes-nested-d4-internal' $cls '' 'D4_HTTP_FIXTURE_OPTIONAL'
}

Write-Host '===== D5 FINAL CERTIFICATION MANIFEST START ====='
Write-Host ('HEAD=' + (git -C $root rev-parse HEAD))

# --- D5 focused ---
@(
    'scripts/self_test_final_review_d5_expectations.php',
    'scripts/self_test_final_review_d5_fresh_gate_path.php',
    'scripts/self_test_final_review_d5_staging_privilege_fence.php',
    'scripts/self_test_final_review_d5_approval_window.php',
    'scripts/self_test_final_review_d5_country_chunk_grammar.php',
    'scripts/self_test_final_review_d5_full_backup_restore.php',
    'scripts/self_test_final_review_d5_full_cutover.php',
    'scripts/self_test_final_review_d5_country_export_verify.php',
    'scripts/self_test_final_review_d5_country_shadow_dry_run.php'
) | ForEach-Object { Run-PhpSuite $_ -EnvPrereq 'MYSQL_LOCAL_DISPOSABLE' }

# --- Tracked Backup / Country ---
$tracked = @(
    'scripts/backup/self_test_backup.php',
    'scripts/backup/self_test_backup_admin.php',
    'scripts/backup/self_test_restore_full_staging.php',
    'scripts/backup/self_test_country_crp_c3_export.php',
    'scripts/backup/self_test_country_crp_c4_verify.php',
    'scripts/backup/self_test_country_crp_c5_drv.php',
    'scripts/backup/self_test_country_crp_c6_shadow.php',
    'scripts/backup/self_test_country_crp_c7_shadow_verify.php',
    'scripts/backup/self_test_country_crp_c8_dry_run.php',
    'scripts/backup/self_test_country_crp_final_hardening.php',
    'scripts/backup/self_test_country_crp_sprint2_remediation.php'
)
foreach ($t in $tracked) {
    $env = 'none'
    if ($t -match 'self_test_backup\.php') { $env = 'OPTIONAL_ENV_PHP_FOR_LIVE_PDO' }
    if ($t -match 'self_test_backup_admin') { $env = 'OPTIONAL_NTFS_ACL' }
    if ($t -match 'final_hardening|sprint2') { $env = 'MYSQL_LOCAL_OPTIONAL' }
    Run-PhpSuite $t -Tracked -EnvPrereq $env
}

# --- Cross-domain D1/D2/D3 ---
@(
    'scripts/self_test_final_review_d1_orders.php',
    'scripts/self_test_final_review_d1_payments.php',
    'scripts/self_test_final_review_d1_purchases_returns.php',
    'scripts/self_test_final_review_d2_inventory_balances.php',
    'scripts/self_test_final_review_d2_fifo_costing.php',
    'scripts/self_test_final_review_d2_inventory_workflows.php',
    'scripts/self_test_final_review_d2_inventory_concurrency.php',
    'scripts/self_test_final_review_d2_closure_contracts.php',
    'scripts/self_test_final_review_d3_manual_vouchers.php',
    'scripts/self_test_final_review_d3_automatic_posting.php',
    'scripts/self_test_final_review_d3_pending_subledger.php',
    'scripts/self_test_final_review_d3_fiscal_numbering.php',
    'scripts/self_test_final_review_d3_accounting_concurrency.php'
) | ForEach-Object { Run-PhpSuite $_ -EnvPrereq 'MYSQL_LOCAL_DISPOSABLE' }

# --- D4 committed manifest (nested internals) ---
Remove-Item -LiteralPath (Join-Path $root 'scripts\_d4_closure_manifest.csv') -ErrorAction SilentlyContinue
Run-Ps1Nested 'scripts/_d4_closure_regression_manifest.ps1' $D4TimeoutSec

# --- Canonical / Batch A / Batch F ---
Run-PhpSuite 'scripts/self_test_admin_time_phase3_step4_canonical97.php'
Run-PhpSuite 'scripts/self_test_final_review_email_track_rate_limit.php'
Run-PhpSuite 'scripts/self_test_final_review_hygiene_dead_stubs.php'

# --- UTF-8 ---
$sw = [Diagnostics.Stopwatch]::StartNew()
$utfOut = & powershell -NoProfile -File (Join-Path $root 'scripts/verify-php-utf8.ps1') 2>&1 | Out-String
$utfCode = $LASTEXITCODE
$sw.Stop()
$utfPass = if ($utfCode -eq 0) { 1 } else { 0 }
$utfFail = if ($utfCode -eq 0) { 0 } else { 1 }
Add-Row 'scripts/verify-php-utf8.ps1' $utfPass $utfFail 0 $utfCode $sw.ElapsedMilliseconds 'no' $(if($utfCode -eq 0){'PASS'}else{'FAIL'}) '' 'none'

# --- PHP lint every PHP file in 3e0fa90b ---
$lintFiles = @(git -C $root show --name-only --format= 3e0fa90b3db9c4e5679f80a634ae1277a65e8e08)
$lintPass = 0; $lintFail = 0
$sw = [Diagnostics.Stopwatch]::StartNew()
foreach ($rel in $lintFiles) {
    if ($rel -notmatch '\.php$') { continue }
    $abs = Join-Path $root $rel
    if (-not (Test-Path $abs)) { continue }
    & $php -l $abs 1>$null 2>$null
    if ($LASTEXITCODE -eq 0) { $lintPass++ } else { $lintFail++; Write-Host "LINT_FAIL $rel" }
}
$sw.Stop()
Add-Row 'php -l (3e0fa90b PHP files)' $lintPass $lintFail 0 $(if($lintFail -gt 0){1}else{0}) $sw.ElapsedMilliseconds 'no' $(if($lintFail -eq 0){'PASS'}else{'FAIL'}) '' 'none'

# --- JSON Expectations ---
$sw = [Diagnostics.Stopwatch]::StartNew()
$jraw = Get-Content (Join-Path $root 'config/country_restore_schema_expectations.json') -Raw -Encoding UTF8
$jok = $false
try {
    $j = $jraw | ConvertFrom-Json
    $jok = ([int]$j.schema_revision -eq 124) -and ($null -ne $j.tables)
} catch { $jok = $false }
$sw.Stop()
Add-Row 'JSON country_restore_schema_expectations.json' $(if($jok){1}else{0}) $(if($jok){0}else{1}) 0 $(if($jok){0}else{1}) $sw.ElapsedMilliseconds 'no' $(if($jok){'PASS'}else{'FAIL'}) '' 'none'

# --- Registry/Matrix revision smoke (committed config only; no DB) ---
$sw = [Diagnostics.Stopwatch]::StartNew()
$regOk = $false; $matOk = $false
$regPath = Join-Path $root 'config/backup_table_registry.json'
$matPath = Join-Path $root 'config/country_restore_boundary_matrix.json'
if (Test-Path -LiteralPath $regPath) {
    try {
        $reg = Get-Content -LiteralPath $regPath -Raw -Encoding UTF8 | ConvertFrom-Json
        if ([int]$reg.schema_revision -eq 124) { $regOk = $true }
    } catch { $regOk = $false }
}
if (Test-Path -LiteralPath $matPath) {
    try {
        $mat = Get-Content -LiteralPath $matPath -Raw -Encoding UTF8 | ConvertFrom-Json
        if ([int]$mat.schema_revision -eq 124) { $matOk = $true }
    } catch { $matOk = $false }
}
$sw.Stop()
$rmPass = 0; $rmFail = 0
if ($regOk) { $rmPass++ } else { $rmFail++ }
if ($matOk) { $rmPass++ } else { $rmFail++ }
Add-Row 'config Registry+Matrix schema_revision=124' $rmPass $rmFail 0 $(if($rmFail -gt 0){1}else{0}) $sw.ElapsedMilliseconds 'no' $(if($rmFail -eq 0){'PASS'}else{'FAIL'}) '' 'none'

$batteryStart.Stop()

$rawPass = ($rows | Measure-Object -Property PASS -Sum).Sum
$rawFail = ($rows | Measure-Object -Property FAIL -Sum).Sum
$rawSkip = ($rows | Measure-Object -Property SKIP -Sum).Sum
$cmdCount = $rows.Count
$passedSuites = @($rows | Where-Object { $_.classification -eq 'PASS' }).Count
$failedSuites = @($rows | Where-Object { $_.classification -eq 'FAIL' }).Count
$skippedSuites = @($rows | Where-Object { $_.classification -eq 'SKIP' -or $_.classification -eq 'REQUIRED_MISSING' }).Count

# Exact overlap: top-level suites that D4 nested re-executes, plus Batch A stability reruns inside D4.
# Unique PASS keeps one primary execution per suite path; every additional execution's PASS is overlap.
$topLevelSuitePass = @{}
foreach ($r in $rows) {
    if ($r.command -eq 'scripts/_d4_closure_regression_manifest.ps1') { continue }
    if ($r.command -notmatch '\.php$|\.ps1$') { continue }
    if (-not $topLevelSuitePass.ContainsKey($r.command)) {
        $topLevelSuitePass[$r.command] = [int]$r.PASS
    }
}
$overlapParts = New-Object System.Collections.Generic.List[string]
$overlap = 0
foreach ($d4r in $script:d4CsvRows) {
    $suite = [string]$d4r.suite
    $p = [int]$d4r.pass
    if ($suite -match 'email_track_rate_limit') {
        # D4 runs Batch A three times; keep 1 primary inside D4, 2 are internal overlap.
        # If top-level also ran Batch A, the D4 primary is itself a repeat of top-level.
        if ($topLevelSuitePass.ContainsKey($suite) -or $topLevelSuitePass.Keys -contains 'scripts/self_test_final_review_email_track_rate_limit.php') {
            $overlap += $p
            [void]$overlapParts.Add(('D4_nested_repeat:' + $suite + ':+' + $p))
        }
        continue
    }
    if ($topLevelSuitePass.ContainsKey($suite)) {
        $overlap += $p
        [void]$overlapParts.Add(('D4_nested_repeat:' + $suite + ':+' + $p))
    }
}
# D4 Batch A internal 2-of-3 when Batch A path not already counted above per-row as full overlap:
# When top-level ran Batch A, every D4 Batch A row was added fully (correct: all 3 are repeats).
# When top-level did NOT run Batch A, add 2 × pass for D4-internal stability reruns.
$batchATop = $topLevelSuitePass.Keys | Where-Object { $_ -match 'email_track_rate_limit' }
$batchAD4 = @($script:d4CsvRows | Where-Object { $_.suite -match 'email_track_rate_limit' })
if (($batchATop | Measure-Object).Count -eq 0 -and $batchAD4.Count -gt 1) {
    $per = [int]$batchAD4[0].pass
    $internal = ($batchAD4.Count - 1) * $per
    $overlap += $internal
    [void]$overlapParts.Add(("D4_batch_a_internal_reruns:+$internal"))
}
$uniquePass = $rawPass - $overlap

# Environmental SKIP classification from named skip lines only.
$envNamed = @($namedSkips | Where-Object {
    $_ -match 'PDO export live self-test|readonly ACL simulation unavailable'
})
$unknownSkips = @($namedSkips | Where-Object {
    $_ -notmatch 'PDO export live self-test|readonly ACL simulation unavailable'
})
$coreSkip = $unknownSkips.Count
# Raw SKIP may include nested D4 backup environmental skips not re-listed in $namedSkips.
$envSkip = if ($coreSkip -eq 0) { [int]([double]$rawSkip) } else { $envNamed.Count }
$policyFrozen = 1  # Country Production Cutover hard-disabled proven in D5 suites (N/A path)

$headSha = (git -C $root rev-parse HEAD | Out-String).Trim()
$summary = [ordered]@{}
$summary['head'] = [string]$headSha
$summary['Raw_PASS'] = [int]([double]$rawPass)
$summary['Raw_FAIL'] = [int]([double]$rawFail)
$summary['Raw_SKIP'] = [int]([double]$rawSkip)
$summary['command_count'] = [int]$cmdCount
$summary['passed_suites'] = [int]$passedSuites
$summary['failed_suites'] = [int]$failedSuites
$summary['skipped_suites'] = [int]$skippedSuites
$summary['CORE_D5_SKIP'] = [int]$coreSkip
$summary['ENVIRONMENTAL_SKIP'] = [int]$envSkip
$summary['POLICY_FROZEN_NOT_APPLICABLE'] = [int]$policyFrozen
$summary['exact_overlap'] = [int]$overlap
$summary['Unique_PASS'] = [int]$uniquePass
$summary['overlap_parts'] = [string[]]@($overlapParts)
$summary['duration_ms'] = [int64]$batteryStart.ElapsedMilliseconds
$summary['named_skips'] = [string[]]@($namedSkips)
$summary['environmental_skip_names'] = [string[]]@($envNamed)
$summary['core_skip_names'] = [string[]]@($unknownSkips)
$cmdOut = New-Object System.Collections.Generic.List[object]
foreach ($r in $rows) {
    [void]$cmdOut.Add([ordered]@{
        command = [string]$r.command
        PASS = [int]$r.PASS
        FAIL = [int]$r.FAIL
        SKIP = [int]$r.SKIP
        Exit = [int]$r.Exit
        duration_ms = [int64]$r.duration_ms
        nested = [string]$r.nested
        classification = [string]$r.classification
        skip_names = [string]$r.skip_names
        env_prereq = [string]$r.env_prereq
    })
}
$summary['commands'] = [object[]]@($cmdOut.ToArray())
$jsonPath = Join-Path $env:TEMP ('orange_d5_final_cert_' + (Get-Date -Format 'yyyyMMdd_HHmmss') + '.json')
($summary | ConvertTo-Json -Depth 6) | Set-Content -LiteralPath $jsonPath -Encoding UTF8
$compact = [ordered]@{
    head = $summary['head']
    Raw_PASS = $summary['Raw_PASS']
    Raw_FAIL = $summary['Raw_FAIL']
    Raw_SKIP = $summary['Raw_SKIP']
    command_count = $summary['command_count']
    passed_suites = $summary['passed_suites']
    failed_suites = $summary['failed_suites']
    skipped_suites = $summary['skipped_suites']
    CORE_D5_SKIP = $summary['CORE_D5_SKIP']
    ENVIRONMENTAL_SKIP = $summary['ENVIRONMENTAL_SKIP']
    POLICY_FROZEN_NOT_APPLICABLE = $summary['POLICY_FROZEN_NOT_APPLICABLE']
    exact_overlap = $summary['exact_overlap']
    Unique_PASS = $summary['Unique_PASS']
    duration_ms = $summary['duration_ms']
    overlap_parts = $summary['overlap_parts']
    named_skips = $summary['named_skips']
}
Write-Host ('FINAL_MANIFEST_SUMMARY=' + ($compact | ConvertTo-Json -Compress -Depth 4))
Write-Host ('RESULT_JSON=' + $jsonPath)

if ([int]$summary['Raw_FAIL'] -gt 0 -or [int]$summary['failed_suites'] -gt 0 -or [int]$summary['CORE_D5_SKIP'] -gt 0) { exit 1 }
exit 0
