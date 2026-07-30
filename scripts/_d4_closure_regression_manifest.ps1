# FSR D4 final concurrency/regression closure manifest — exact PASS/FAIL/SKIP.
param([int]$TimeoutSec = 360)
$ErrorActionPreference = 'Continue'
$php = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
$root = 'D:\orange'
Set-Location $root
$watch = Join-Path $root 'scripts\_d4_run_one_watch.ps1'
$outCsv = Join-Path $root 'scripts\_d4_closure_manifest.csv'

$rows = New-Object System.Collections.Generic.List[object]

function Add-Row($suite, $mode, $code, $pass, $fail, $skip, $result, $ms, $nested, $notes) {
  $script:rows.Add([pscustomobject]@{
    suite = $suite; mode = $mode; exit = $code; pass = $pass; fail = $fail; skip = $skip
    result = $result; ms = $ms; nested = $nested; notes = $notes
  }) | Out-Null
  Write-Host ("EXIT={0} PASS={1} FAIL={2} SKIP={3} RESULT={4} MS={5}" -f $code,$pass,$fail,$skip,$result,$ms)
}

function Run-Direct($rel, $nested = 'no') {
  Write-Host "===== DIRECT $rel ====="
  $sw = [Diagnostics.Stopwatch]::StartNew()
  $out = & $php -d output_buffering=0 (Join-Path $root $rel) 2>&1 | Out-String
  $code = $LASTEXITCODE
  $sw.Stop()
  Write-Host $out
  # Prefer the suite summary line only — never match "FAIL  16/..." assertion labels.
  $summary = [regex]::Match($out, '(?m)^PASS=(\d+)\s+FAIL=(\d+)\s+SKIP=(\d+)')
  if ($summary.Success) {
    $pass = [int]$summary.Groups[1].Value
    $fail = [int]$summary.Groups[2].Value
    $skip = [int]$summary.Groups[3].Value
  } else {
    $pass = ([regex]::Matches($out, '(?m)^PASS\s')).Count
    $fail = ([regex]::Matches($out, '(?m)^FAIL\s')).Count
    $skip = ([regex]::Matches($out, '(?m)^SKIP\s')).Count
  }
  # backup self_test uses "PASS:/FAIL:/SKIP: label" (no PASS=N summary)
  if ($rel -match 'self_test_backup') {
    $pass = ([regex]::Matches($out, '(?m)^PASS:')).Count
    $fail = ([regex]::Matches($out, '(?m)^FAIL:')).Count
    $skip = ([regex]::Matches($out, '(?m)^SKIP:')).Count
  }
  $result = if ($out -match 'RESULT=(\S+)') { $Matches[1] } elseif ($fail -eq 0 -and $code -eq 0) { 'OK' } else { 'NO_RESULT' }
  $skipsNamed = ($out -split "`n" | Where-Object { $_ -match '^SKIP' }) -join ' | '
  Add-Row $rel 'direct' $code $pass $fail $skip $result $sw.ElapsedMilliseconds $nested $skipsNamed
}

function Run-Watch($rel) {
  Write-Host "===== WATCH $rel ====="
  $sw = [Diagnostics.Stopwatch]::StartNew()
  powershell -NoProfile -File $watch -SuiteRel $rel -TimeoutSec $TimeoutSec | Out-Host
  $code = $LASTEXITCODE
  $sw.Stop()
  $baseName = [IO.Path]::GetFileNameWithoutExtension($rel)
  $log = Get-ChildItem $env:TEMP -Filter "orange_d4_suite_${baseName}_*.out.log" | Sort-Object LastWriteTime -Descending | Select-Object -First 1
  $out = if ($log) { Get-Content $log.FullName -Raw } else { '' }
  $summary = [regex]::Match($out, '(?m)^PASS=(\d+)\s+FAIL=(\d+)\s+SKIP=(\d+)')
  if ($summary.Success) {
    $pass = [int]$summary.Groups[1].Value
    $fail = [int]$summary.Groups[2].Value
    $skip = [int]$summary.Groups[3].Value
  } else {
    $pass = 0; $fail = 0; $skip = 0
  }
  $result = if ($out -match 'RESULT=(\S+)') { $Matches[1] } elseif ($fail -eq 0 -and $pass -gt 0) { 'OK' } else { 'NO_RESULT' }
  Add-Row $rel 'watch' $code $pass $fail $skip $result $sw.ElapsedMilliseconds 'no' ''
}

# Prove Batch C tracked
& git ls-files --error-unmatch scripts/backup/self_test_backup.php | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Batch C self_test_backup.php not tracked' }

# Core D4
Run-Watch 'scripts/self_test_final_review_d4_promo_stock_race.php'
Run-Watch 'scripts/self_test_final_review_d4_full_checkout_http.php'
Run-Watch 'scripts/self_test_final_review_d4_order_finalization.php'
Run-Direct 'scripts/self_test_final_review_d4_promotion_engine.php'
Run-Direct 'scripts/self_test_final_review_d4_gift_bogo_combo.php'
Run-Direct 'scripts/self_test_final_review_d4_promotion_usage_concurrency.php'
Run-Direct 'scripts/self_test_final_review_d4_loyalty_points.php'
Run-Direct 'scripts/self_test_final_review_d4_loyalty_concurrency.php'
Run-Direct 'scripts/self_test_final_review_d4_closure_verification.php'

# D2
Run-Direct 'scripts/self_test_final_review_d2_inventory_balances.php'
Run-Direct 'scripts/self_test_final_review_d2_fifo_costing.php'
Run-Direct 'scripts/self_test_final_review_d2_inventory_workflows.php'
Run-Direct 'scripts/self_test_final_review_d2_inventory_concurrency.php'
Run-Direct 'scripts/self_test_final_review_d2_closure_contracts.php'

# D3 concurrency
Run-Direct 'scripts/self_test_final_review_d3_accounting_concurrency.php'

# Batch A x3
1..3 | ForEach-Object {
  Write-Host "===== BATCH A run $_ ====="
  Run-Direct 'scripts/self_test_final_review_email_track_rate_limit.php' ("batch_a_run_$_")
}

# Batch C tracked
Run-Direct 'scripts/backup/self_test_backup.php'

# Batch F
Run-Direct 'scripts/self_test_final_review_hygiene_dead_stubs.php'

# Canonical 97
Run-Direct 'scripts/self_test_admin_time_phase3_step4_canonical97.php'

# UTF-8 + lint
Write-Host '===== UTF-8 ====='
$sw = [Diagnostics.Stopwatch]::StartNew()
$utf = powershell -NoProfile -File (Join-Path $root 'scripts\verify-php-utf8.ps1') 2>&1 | Out-String
$utfCode = $LASTEXITCODE
$sw.Stop()
Write-Host $utf
$utfOk = ($utfCode -eq 0)
Add-Row 'scripts/verify-php-utf8.ps1' 'direct' $utfCode ($(if ($utfOk) {1} else {0})) ($(if ($utfOk) {0} else {1})) 0 ($(if ($utfOk) {'UTF8_OK'} else {'UTF8_FAIL'})) $sw.ElapsedMilliseconds 'no' ''

Write-Host '===== PHP LINT changed tests ====='
$lintPass = 0; $lintFail = 0
$sw = [Diagnostics.Stopwatch]::StartNew()
foreach ($f in @(
  'scripts/self_test_final_review_d4_promo_stock_race.php',
  'scripts/self_test_final_review_d4_order_finalization.php',
  'scripts/self_test_final_review_email_track_rate_limit.php'
)) {
  $o = & $php -l (Join-Path $root $f) 2>&1 | Out-String
  if ($o -match 'No syntax errors') { $lintPass++ } else { $lintFail++; Write-Host $o }
}
$sw.Stop()
Add-Row 'php -l (changed D4 race/finalization/Batch A)' 'direct' ($(if ($lintFail -eq 0) {0} else {1})) $lintPass $lintFail 0 ($(if ($lintFail -eq 0) {'LINT_OK'} else {'LINT_FAIL'})) $sw.ElapsedMilliseconds 'no' ''

$rows | Export-Csv -NoTypeInformation $outCsv
$rawPass = ($rows | Measure-Object -Property pass -Sum).Sum
$rawFail = ($rows | Measure-Object -Property fail -Sum).Sum
$rawSkip = ($rows | Measure-Object -Property skip -Sum).Sum
$suiteCount = $rows.Count
$passedSuites = @($rows | Where-Object { $_.fail -eq 0 -and $_.exit -eq 0 }).Count
$failedSuites = @($rows | Where-Object { $_.fail -gt 0 -or ($_.exit -ne 0 -and $_.pass -eq 0) }).Count
$skippedSuites = @($rows | Where-Object { $_.pass -eq 0 -and $_.fail -eq 0 -and $_.skip -gt 0 }).Count
$coreSkip = ($rows | Where-Object { $_.suite -match 'final_review_d4_' } | Measure-Object -Property skip -Sum).Sum
$envSkip = $rawSkip - $coreSkip
# Batch A runs overlap same suite file three times
$overlap = 2 * (($rows | Where-Object { $_.suite -match 'email_track_rate_limit' } | Measure-Object).Count - 1)
if ($overlap -lt 0) { $overlap = 0 }
$uniquePass = $rawPass # assertion-level unique across one-shot suites; Batch A triple-counts intentionally in Raw
Write-Host ''
Write-Host '===== MANIFEST TOTALS ====='
Write-Host "SUITE_COMMANDS=$suiteCount"
Write-Host "PASSED_SUITES=$passedSuites FAILED_SUITES=$failedSuites SKIPPED_SUITES=$skippedSuites"
Write-Host "RAW_PASS=$rawPass RAW_FAIL=$rawFail RAW_SKIP=$rawSkip"
Write-Host "CORE_D4_SKIP=$coreSkip ENV_OR_OTHER_SKIP=$envSkip"
Write-Host "NESTED_OVERLAP_ASSERTIONS_NOTE=Batch_A_x3_raw_includes_3x_same_suite overlap_suite_reruns=$overlap"
Write-Host "UNIQUE_PASS_ASSERTIONS_IF_DEDUP_BATCH_A=$($rawPass - (2 * (($rows | Where-Object { $_.suite -match 'email_track_rate_limit' } | Select-Object -First 1).pass)))"
$rows | Format-Table suite,mode,exit,pass,fail,skip,result,ms -AutoSize
if ($failedSuites -gt 0 -or $rawFail -gt 0) { exit 1 }
if ($coreSkip -gt 0) { Write-Host 'CORE_D4_SKIP_NONZERO'; exit 1 }
exit 0
