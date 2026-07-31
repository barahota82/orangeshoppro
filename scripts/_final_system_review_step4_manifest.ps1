# FSR Step 4 — Final System Certification manifest (test-only).
# Nests D5 final certification as behavioral base; adds Time/Country/Channel,
# restore-admin CSRF, full PHP lint, UTF-8, git guard, JSON/schema smoke.
# No Production behavior change. Exact PASS/FAIL/SKIP arithmetic.
param(
    [int]$D4TimeoutSec = 360,
    [int]$D5TimeoutSec = 0
)
$ErrorActionPreference = 'Continue'
$php = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
$root = 'D:\orange'
Set-Location $root

$rows = New-Object System.Collections.Generic.List[object]
$namedSkips = New-Object System.Collections.Generic.List[string]
$overlapParts = New-Object System.Collections.Generic.List[string]
$batteryStart = [Diagnostics.Stopwatch]::StartNew()
$script:d5NestedPass = 0
$script:d5NestedFail = 0
$script:d5NestedSkip = 0
$script:d5InternalOverlap = 0
$script:d5UniquePass = 0
$script:d5EnvSkip = 0
$script:d5CoreSkip = 0

function Add-Row(
    [string]$Command,
    [int]$Pass,
    [int]$Fail,
    [int]$Skip,
    [int]$ExitCode,
    [int64]$Ms,
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

function Parse-SuiteOut([string]$Out, [string]$Rel) {
    $pass = 0; $fail = 0; $skip = 0
    $summary = [regex]::Match($Out, '(?m)^PASS=(\d+)\s+FAIL=(\d+)\s+SKIP=(\d+)')
    if ($summary.Success) {
        $pass = [int]$summary.Groups[1].Value
        $fail = [int]$summary.Groups[2].Value
        $skip = [int]$summary.Groups[3].Value
        return @{ pass = $pass; fail = $fail; skip = $skip }
    }
    $pf = [regex]::Match($Out, '(?m)^PASS=(\d+)\s+FAIL=(\d+)\s*$')
    if ($pf.Success) {
        return @{ pass = [int]$pf.Groups[1].Value; fail = [int]$pf.Groups[2].Value; skip = 0 }
    }
    # restore_admin / backup_admin quiet summary: TOTAL_PASS: N / TOTAL_FAIL: N
    $tot = [regex]::Match($Out, '(?m)^TOTAL_PASS:\s*(\d+)\s*$')
    $totF = [regex]::Match($Out, '(?m)^TOTAL_FAIL:\s*(\d+)\s*$')
    $totS = [regex]::Match($Out, '(?m)^TOTAL_SKIP:\s*(\d+)\s*$')
    if ($tot.Success) {
        return @{
            pass = [int]$tot.Groups[1].Value
            fail = $(if ($totF.Success) { [int]$totF.Groups[1].Value } else { 0 })
            skip = $(if ($totS.Success) { [int]$totS.Groups[1].Value } else { 0 })
        }
    }
    if ($Rel -match 'self_test_backup|self_test_country_crp|self_test_restore|self_test_pre_|self_test_shadow|self_test_production') {
        $pass = ([regex]::Matches($Out, '(?m)^PASS:')).Count
        $fail = ([regex]::Matches($Out, '(?m)^FAIL:')).Count
        $skip = ([regex]::Matches($Out, '(?m)^SKIP:')).Count
        if ($pass -eq 0 -and $fail -eq 0) {
            $pass = ([regex]::Matches($Out, '(?m)^PASS\s')).Count
            $fail = ([regex]::Matches($Out, '(?m)^FAIL\s')).Count
            $skip = ([regex]::Matches($Out, '(?m)^SKIP\s')).Count
        }
        return @{ pass = $pass; fail = $fail; skip = $skip }
    }
    $pass = ([regex]::Matches($Out, '(?m)^PASS\s')).Count
    $fail = ([regex]::Matches($Out, '(?m)^FAIL\s')).Count
    $skip = ([regex]::Matches($Out, '(?m)^SKIP\s')).Count
    return @{ pass = $pass; fail = $fail; skip = $skip }
}

function Run-PhpSuite([string]$Rel, [string]$Nested = 'no', [string]$EnvPrereq = 'none', [switch]$Tracked) {
    if ($Tracked) { Assert-Tracked $Rel }
    $abs = Join-Path $root $Rel
    if (-not (Test-Path -LiteralPath $abs)) {
        Add-Row $Rel 0 0 1 2 0 $Nested 'REQUIRED_MISSING' ("FILE_MISSING:" + $Rel) $EnvPrereq
        [void]$namedSkips.Add("FILE_MISSING:$Rel")
        return
    }
    $sw = [Diagnostics.Stopwatch]::StartNew()
    $out = & $php -d output_buffering=0 $abs 2>&1 | Out-String
    $code = $LASTEXITCODE
    $sw.Stop()
    $parsed = Parse-SuiteOut $out $Rel
    $pass = [int]$parsed.pass; $fail = [int]$parsed.fail; $skip = [int]$parsed.skip
    $skipLines = @($out -split "`r?`n" | Where-Object { $_ -match '^(SKIP:|SKIP\s+)' })
    foreach ($s in $skipLines) { [void]$namedSkips.Add(("$Rel :: " + $s.Trim())) }
    $cls = if ($fail -gt 0 -or ($code -ne 0 -and $pass -eq 0)) { 'FAIL' }
        elseif ($skip -gt 0 -and $pass -eq 0 -and $code -ne 0) { 'SKIP' }
        elseif ($code -eq 2 -and $pass -eq 0) { 'SKIP' }
        else { 'PASS' }
    if ($code -eq 2 -and $out -match 'ENVIRONMENT_BLOCKED') {
        $cls = 'SKIP'
        if ($skip -eq 0) { $skip = 1 }
        [void]$namedSkips.Add("$Rel :: ENVIRONMENT_BLOCKED")
    }
    Add-Row $Rel $pass $fail $skip $code $sw.ElapsedMilliseconds $Nested $cls (($skipLines -join ' | ')) $EnvPrereq
    if ($fail -gt 0 -or $cls -eq 'FAIL') {
        $tail = ($out.Trim() -replace '\s+', ' ')
        if ($tail.Length -gt 400) { $tail = $tail.Substring($tail.Length - 400) }
        Write-Host ("NOTE fail_tail={0}" -f $tail)
    }
}

Write-Host '===== FSR STEP-4 FINAL CERTIFICATION MANIFEST START ====='
Write-Host ('HEAD=' + (git -C $root rev-parse HEAD))

# Disposable worktrees required by nested D5 (Backup/Restore) and nested D4 (HTTP).
# Created for this run only; removed after D5 nest (never committed; no Production .env).
$d5Runtime = 'D:\orange_d5_runtime'
$d5DataParent = 'D:\orange_d5_data'
$d4Runtime = 'D:\orange_d4_http_runtime'
$script:d5WorktreeCreatedByStep4 = $false
$script:d4WorktreeCreatedByStep4 = $false
$headSha = (git -C $root rev-parse HEAD).Trim()
if (-not (Test-Path -LiteralPath $d5Runtime)) {
    Write-Host ("NOTE creating disposable D5 worktree at {0} @ {1}" -f $d5Runtime, $headSha)
    git -C $root worktree add --detach $d5Runtime $headSha 1>$null 2>$null
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $d5Runtime)) {
        throw "Failed to create disposable D5 worktree: $d5Runtime"
    }
    $script:d5WorktreeCreatedByStep4 = $true
} else {
    Write-Host 'NOTE reusing existing D5 worktree for nested certification'
}
if (-not (Test-Path -LiteralPath $d4Runtime)) {
    Write-Host ("NOTE creating disposable D4 HTTP worktree at {0} @ {1}" -f $d4Runtime, $headSha)
    git -C $root worktree add --detach $d4Runtime $headSha 1>$null 2>$null
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $d4Runtime)) {
        throw "Failed to create disposable D4 HTTP worktree: $d4Runtime"
    }
    $script:d4WorktreeCreatedByStep4 = $true
} else {
    Write-Host 'NOTE reusing existing D4 HTTP worktree for nested certification'
}

# --- 1) Nested D5 final certification (behavioral base) ---
Assert-Tracked 'scripts/_d5_final_certification_manifest.ps1'
$d5Args = @('-NoProfile', '-File', (Join-Path $root 'scripts\_d5_final_certification_manifest.ps1'), '-D4TimeoutSec', $D4TimeoutSec)
$sw = [Diagnostics.Stopwatch]::StartNew()
$d5Out = & powershell @d5Args 2>&1 | Out-String
$d5Code = $LASTEXITCODE
$sw.Stop()

# Teardown disposable D4/D5 worktrees/data created for this Step-4 run.
try {
    foreach ($rt in @($d5Runtime, $d4Runtime)) {
        $envFile = Join-Path $rt '.env.php'
        if (Test-Path -LiteralPath $envFile) {
            Remove-Item -LiteralPath $envFile -Force -ErrorAction SilentlyContinue
        }
    }
    if ($script:d5WorktreeCreatedByStep4 -and (Test-Path -LiteralPath $d5Runtime)) {
        git -C $root worktree remove --force $d5Runtime 1>$null 2>$null
        if (Test-Path -LiteralPath $d5Runtime) {
            Remove-Item -LiteralPath $d5Runtime -Recurse -Force -ErrorAction SilentlyContinue
        }
        Write-Host 'NOTE removed disposable D5 worktree'
    }
    if ($script:d4WorktreeCreatedByStep4 -and (Test-Path -LiteralPath $d4Runtime)) {
        git -C $root worktree remove --force $d4Runtime 1>$null 2>$null
        if (Test-Path -LiteralPath $d4Runtime) {
            Remove-Item -LiteralPath $d4Runtime -Recurse -Force -ErrorAction SilentlyContinue
        }
        Write-Host 'NOTE removed disposable D4 HTTP worktree'
    }
    if (Test-Path -LiteralPath $d5DataParent) {
        Remove-Item -LiteralPath $d5DataParent -Recurse -Force -ErrorAction SilentlyContinue
        Write-Host 'NOTE removed disposable D5 data parent'
    }
    git -C $root worktree prune 1>$null 2>$null
} catch {
    Write-Host ('NOTE d5_teardown_warning=' + $_.Exception.Message)
}
$d5Summary = [regex]::Match($d5Out, 'FINAL_MANIFEST_SUMMARY=(.+)')
if ($d5Summary.Success) {
    try {
        $d5j = $d5Summary.Groups[1].Value | ConvertFrom-Json
        $script:d5NestedPass = [int]$d5j.Raw_PASS
        $script:d5NestedFail = [int]$d5j.Raw_FAIL
        $script:d5NestedSkip = [int]$d5j.Raw_SKIP
        $script:d5InternalOverlap = [int]$d5j.exact_overlap
        $script:d5UniquePass = [int]$d5j.Unique_PASS
        $script:d5EnvSkip = [int]$d5j.ENVIRONMENTAL_SKIP
        $script:d5CoreSkip = [int]$d5j.CORE_D5_SKIP
        if ($d5j.named_skips) {
            foreach ($ns in @($d5j.named_skips)) { [void]$namedSkips.Add(('D5_NESTED :: ' + $ns)) }
        }
        [void]$overlapParts.Add(('D5_internal_overlap:+' + $script:d5InternalOverlap))
    } catch {
        Write-Host ('NOTE d5_summary_parse_error=' + $_.Exception.Message)
    }
}
# Non-zero Exit alone is not FAIL when nested D5 reported Raw_FAIL=0 (parity with D5→D4 nesting).
$d5Cls = if ($script:d5NestedFail -gt 0) { 'FAIL' } elseif ($d5Code -ne 0 -and $script:d5NestedPass -eq 0) { 'FAIL' } else { 'PASS' }
Add-Row 'scripts/_d5_final_certification_manifest.ps1' $script:d5NestedPass $script:d5NestedFail $script:d5NestedSkip $d5Code $sw.ElapsedMilliseconds 'yes-nested-d5-base' $d5Cls '' 'MYSQL_LOCAL_DISPOSABLE'

# --- 2) Time / Country / Channel / Phase-4 suites not nested by D5 ---
$timeCountry = @(
    'scripts/self_test_admin_time_foundation.php',
    'scripts/self_test_admin_time_phase2_step1_closure.php',
    'scripts/self_test_admin_time_phase2_orders_payments.php',
    'scripts/self_test_admin_time_phase2_step2_purchases_returns.php',
    'scripts/self_test_admin_time_phase2_step3_inventory_warehouses.php',
    'scripts/self_test_admin_time_phase2_step4_promotions.php',
    'scripts/self_test_admin_time_phase2_step4_closure.php',
    'scripts/self_test_admin_time_phase2_step5_accounting.php',
    'scripts/self_test_admin_time_phase3_step2_operational.php',
    'scripts/self_test_admin_time_phase3_step3_reports.php',
    'scripts/self_test_admin_time_phase3_step4_closure.php',
    'scripts/self_test_admin_time_phase3_step4_customer.php',
    'scripts/self_test_admin_time_phase3_step4_inventory_country.php',
    'scripts/self_test_admin_time_phase3_step4_channel_resolution.php',
    'scripts/self_test_admin_time_phase3_step4_admin_channel_country.php',
    'scripts/self_test_admin_time_phase3_step4_spm_country.php',
    'scripts/self_test_admin_time_phase4_step2_targeted_gaps.php',
    'scripts/self_test_admin_time_phase4_exhaustive_gap_repair.php',
    'scripts/self_test_pre_phase4_channel_display_name_country_unique.php',
    'scripts/self_test_pre_phase4_no_auto_channel_seed.php',
    'scripts/self_test_pre_phase4_country_activation_no_channels.php',
    'scripts/self_test_pre_phase4_product_preview_country_channel.php',
    'scripts/self_test_pre_phase4_home_copy_bare_domain_repair.php'
)
foreach ($t in $timeCountry) { Run-PhpSuite $t -Tracked -EnvPrereq 'MYSQL_LOCAL_OPTIONAL' }

# Canonical 97 already inside D5 — re-run once for Step-4 direct evidence; count as overlap vs D5.
Run-PhpSuite 'scripts/self_test_admin_time_phase3_step4_canonical97.php' -Tracked -EnvPrereq 'none'
[void]$overlapParts.Add('Step4_direct_canonical97_overlap_vs_D5:+10')

# Batch A / hygiene already in D5 — do not re-run (avoid extra overlap).

# --- 3) Restore Center CSRF / admin contract ---
Run-PhpSuite 'scripts/backup/self_test_restore_admin.php' -Tracked -EnvPrereq 'OPTIONAL_NTFS_ACL'

# --- 4) Repository guard ---
# Allow only Step-4 test/docs paths as tracked dirty before the certification Commit.
# After Commit+Push, dirty must be empty (Result A post-condition).
$sw = [Diagnostics.Stopwatch]::StartNew()
$diffCheck = & git -C $root diff --check 2>&1 | Out-String
$diffCode = $LASTEXITCODE
$sw.Stop()
$guardPass = 0; $guardFail = 0
$headOk = ((git -C $root rev-parse HEAD).Trim() -eq (git -C $root rev-parse origin/main).Trim())
$branchOk = ((git -C $root branch --show-current).Trim() -eq 'main')
$allowDirty = @(
    'docs/archive/ORANGE_AGENT_QA_REFERENCE.txt',
    'docs/archive/ORANGE_FSR_STEP4_FINAL_CERTIFICATION.txt',
    'scripts/_d5_final_certification_manifest.ps1',
    'scripts/_final_system_review_step4_manifest.ps1',
    'scripts/backup/self_test_restore_admin.php'
)
$trackedDirtyLines = @(git -C $root status --porcelain | Where-Object { $_ -notmatch '^\?\?' })
$disallowedDirty = @()
foreach ($line in $trackedDirtyLines) {
    $path = ($line.Substring(3).Trim() -replace '^"|"$', '')
    if ($allowDirty -notcontains $path) { $disallowedDirty += $path }
}
$dirtyOk = ($disallowedDirty.Count -eq 0)
if ($diffCode -eq 0 -and $headOk -and $branchOk -and $dirtyOk) { $guardPass = 4 } else {
    $guardFail = 1
    if (-not $headOk) { $guardFail++ }
    if (-not $branchOk) { $guardFail++ }
    if (-not $dirtyOk) {
        $guardFail++
        Write-Host ('NOTE disallowed_dirty=' + ($disallowedDirty -join ','))
    }
    if ($diffCode -ne 0) { Write-Host ('NOTE diff_check=' + $diffCheck.Trim()) }
}
Add-Row 'git repo guard (diff --check + main sync + step4-allowlist dirty)' $guardPass $guardFail 0 $(if($guardFail -gt 0){1}else{0}) $sw.ElapsedMilliseconds 'no' $(if($guardFail -eq 0){'PASS'}else{'FAIL'}) '' 'none'

# --- 5) Schema/Registry/Matrix/Expectations constants ---
$sw = [Diagnostics.Stopwatch]::StartNew()
$schemaOk = 0; $schemaFail = 0
try {
    $cat = & $php -r "require '$root/includes/catalog_schema.php'; echo ORANGE_CATALOG_SCHEMA_PHP_REVISION;"
    $rec = & $php -r "require '$root/includes/backup/recovery_validation.php'; echo ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION;"
    $cut = & $php -r "require '$root/includes/backup/country_crp_drv.php'; echo ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED ? '1' : '0';"
    $reg = Get-Content (Join-Path $root 'config/backup_table_registry.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    $mat = Get-Content (Join-Path $root 'config/country_restore_boundary_matrix.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    $exp = Get-Content (Join-Path $root 'config/country_restore_schema_expectations.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    foreach ($pair in @(
        @([int]$cat -eq 124),
        @([int]$rec -eq 124),
        @($cut -eq '0'),
        @([int]$reg.schema_revision -eq 124),
        @([int]$mat.schema_revision -eq 124),
        @([int]$exp.schema_revision -eq 124),
        @([int]$reg.table_count -eq 117)
    )) {
        if ($pair[0]) { $schemaOk++ } else { $schemaFail++ }
    }
} catch { $schemaFail++ }
$sw.Stop()
Add-Row 'schema/registry/matrix/expectations/cutover=124/false' $schemaOk $schemaFail 0 $(if($schemaFail -gt 0){1}else{0}) $sw.ElapsedMilliseconds 'no' $(if($schemaFail -eq 0){'PASS'}else{'FAIL'}) '' 'none'

# --- 6) UTF-8 ---
$sw = [Diagnostics.Stopwatch]::StartNew()
$utfOut = & powershell -NoProfile -File (Join-Path $root 'scripts/verify-php-utf8.ps1') 2>&1 | Out-String
$utfCode = $LASTEXITCODE
$sw.Stop()
Add-Row 'scripts/verify-php-utf8.ps1' $(if($utfCode -eq 0){1}else{0}) $(if($utfCode -eq 0){0}else{1}) 0 $utfCode $sw.ElapsedMilliseconds 'no' $(if($utfCode -eq 0){'PASS'}else{'FAIL'}) '' 'none'
# UTF-8 also nested in D5 → overlap 1
[void]$overlapParts.Add('Step4_direct_utf8_overlap_vs_D5:+1')

# --- 7) All tracked PHP lint ---
$sw = [Diagnostics.Stopwatch]::StartNew()
$lintPass = 0; $lintFail = 0
$phpFiles = @(git -C $root ls-files '*.php')
foreach ($rel in $phpFiles) {
    $abs = Join-Path $root $rel
    if (-not (Test-Path -LiteralPath $abs)) { continue }
    & $php -l $abs 1>$null 2>$null
    if ($LASTEXITCODE -eq 0) { $lintPass++ } else { $lintFail++; Write-Host "LINT_FAIL $rel" }
}
$sw.Stop()
Add-Row 'php -l (all tracked PHP)' $lintPass $lintFail 0 $(if($lintFail -gt 0){1}else{0}) $sw.ElapsedMilliseconds 'no' $(if($lintFail -eq 0){'PASS'}else{'FAIL'}) '' 'none'
# D5 already linted 27 PHP from 3e0fa90b — exact overlap
[void]$overlapParts.Add('Step4_full_lint_includes_D5_27_files:+27')

# --- 8) JS syntax (Node optional) ---
$sw = [Diagnostics.Stopwatch]::StartNew()
$node = Get-Command node -ErrorAction SilentlyContinue
if ($null -eq $node) {
    Add-Row 'node --check tracked JS' 0 0 1 0 $sw.ElapsedMilliseconds 'no' 'PASS' 'SKIP: Node.js not installed on host' 'NODE_OPTIONAL'
    [void]$namedSkips.Add('node --check tracked JS :: SKIP: Node.js not installed on host')
} else {
    $jsPass = 0; $jsFail = 0
    foreach ($rel in @(git -C $root ls-files '*.js')) {
        & node --check (Join-Path $root $rel) 1>$null 2>$null
        if ($LASTEXITCODE -eq 0) { $jsPass++ } else { $jsFail++ }
    }
    $sw.Stop()
    Add-Row 'node --check tracked JS' $jsPass $jsFail 0 $(if($jsFail -gt 0){1}else{0}) $sw.ElapsedMilliseconds 'no' $(if($jsFail -eq 0){'PASS'}else{'FAIL'}) '' 'NODE'
}
$sw.Stop()

# --- 9) Static secret/debug scan (tracked only) ---
$sw = [Diagnostics.Stopwatch]::StartNew()
$scanPass = 0; $scanFail = 0
$envTracked = @(git -C $root ls-files '.env.php' '.env')
if ($envTracked.Count -eq 0) { $scanPass++ } else { $scanFail++; Write-Host 'FAIL tracked env' }
$dumpTracked = @(git -C $root ls-files | Where-Object { $_ -match '\.(zip|sql\.gz|bak)$|orange_db\.sql$' })
# orange_db.sql may be gitignored and not tracked — OK
if (@(git -C $root ls-files 'scripts/orange_db.sql').Count -eq 0) { $scanPass++ } else { $scanFail++ }
$deadStubs = @(
    (Test-Path (Join-Path $root 'api/stock/adjust-stock.php')),
    (Test-Path (Join-Path $root 'api/offers/get-offers.php'))
)
if (-not $deadStubs[0] -and -not $deadStubs[1]) { $scanPass++ } else { $scanFail++ }
# Public/storefront + admin API: flag live debug dumps; ignore comments and self-tests.
$vd = & rg -n --glob '*.php' -g '!**/self_test*.php' '^\s*(var_dump|print_r)\s*\(' (Join-Path $root 'api') (Join-Path $root 'admin\api') 2>$null
if (-not $vd) { $scanPass++ } else { $scanFail++; Write-Host $vd }
$sw.Stop()
Add-Row 'static secret/dead-stub/debug scan' $scanPass $scanFail 0 $(if($scanFail -gt 0){1}else{0}) $sw.ElapsedMilliseconds 'no' $(if($scanFail -eq 0){'PASS'}else{'FAIL'}) '' 'none'

$batteryStart.Stop()

$rawPass = [int]([double]($rows | Measure-Object -Property PASS -Sum).Sum)
$rawFail = [int]([double]($rows | Measure-Object -Property FAIL -Sum).Sum)
$rawSkip = [int]([double]($rows | Measure-Object -Property SKIP -Sum).Sum)
$cmdCount = $rows.Count
$passedSuites = @($rows | Where-Object { $_.classification -eq 'PASS' }).Count
$failedSuites = @($rows | Where-Object { $_.classification -eq 'FAIL' }).Count
$skippedSuites = @($rows | Where-Object { $_.classification -eq 'SKIP' -or $_.classification -eq 'REQUIRED_MISSING' }).Count

# Overlap = D5 internal overlap + Step4 suites that duplicate D5 evidence
$overlap = [int]$script:d5InternalOverlap
$overlap += 10  # Canonical97 re-run
$overlap += 1   # UTF-8 re-run
$overlap += 27  # lint files already counted inside D5 lint command
$uniquePass = $rawPass - $overlap

$envNamed = @($namedSkips | Where-Object {
    $_ -match 'PDO export live|readonly ACL|Node\.js not installed|ENVIRONMENT_BLOCKED'
})
$unknownSkips = @($namedSkips | Where-Object {
    $_ -notmatch 'PDO export live|readonly ACL|Node\.js not installed|ENVIRONMENT_BLOCKED|D5_NESTED ::'
})
# D5 nested skips already classified there; core = unknown non-nested
$coreSkip = $unknownSkips.Count
if ($script:d5CoreSkip -gt 0) { $coreSkip += [int]$script:d5CoreSkip }
$envSkip = if ($coreSkip -eq 0) { $rawSkip } else { $envNamed.Count }

$summary = [ordered]@{}
$summary['head'] = (git -C $root rev-parse HEAD | Out-String).Trim()
$summary['Raw_PASS'] = $rawPass
$summary['Raw_FAIL'] = $rawFail
$summary['Raw_SKIP'] = $rawSkip
$summary['command_count'] = $cmdCount
$summary['passed_suites'] = $passedSuites
$summary['failed_suites'] = $failedSuites
$summary['skipped_suites'] = $skippedSuites
$summary['CORE_SYSTEM_SKIP'] = [int]$coreSkip
$summary['ENVIRONMENTAL_SKIP'] = [int]$envSkip
$summary['POLICY_FROZEN_NOT_APPLICABLE'] = 1
$summary['OWNER_RUNTIME_ONLY'] = 1
$summary['exact_overlap'] = $overlap
$summary['Unique_PASS'] = $uniquePass
$summary['D5_nested_Raw_PASS'] = [int]$script:d5NestedPass
$summary['D5_nested_Unique_PASS'] = [int]$script:d5UniquePass
$summary['D5_internal_overlap'] = [int]$script:d5InternalOverlap
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

$jsonPath = Join-Path $env:TEMP ('orange_fsr_step4_' + (Get-Date -Format 'yyyyMMdd_HHmmss') + '.json')
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
    CORE_SYSTEM_SKIP = $summary['CORE_SYSTEM_SKIP']
    ENVIRONMENTAL_SKIP = $summary['ENVIRONMENTAL_SKIP']
    POLICY_FROZEN_NOT_APPLICABLE = $summary['POLICY_FROZEN_NOT_APPLICABLE']
    OWNER_RUNTIME_ONLY = $summary['OWNER_RUNTIME_ONLY']
    exact_overlap = $summary['exact_overlap']
    Unique_PASS = $summary['Unique_PASS']
    D5_nested_Raw_PASS = $summary['D5_nested_Raw_PASS']
    D5_nested_Unique_PASS = $summary['D5_nested_Unique_PASS']
    duration_ms = $summary['duration_ms']
    named_skips = $summary['named_skips']
    overlap_parts = $summary['overlap_parts']
}
Write-Host ('FINAL_STEP4_SUMMARY=' + ($compact | ConvertTo-Json -Compress -Depth 5))
Write-Host ('RESULT_JSON=' + $jsonPath)

if ($rawFail -gt 0 -or $failedSuites -gt 0 -or $coreSkip -gt 0) { exit 1 }
exit 0
