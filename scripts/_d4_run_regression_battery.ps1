# FSR D4 §31 regression battery runner (local disposable DBs only; no Production).
param([int]$TimeoutSec = 600)
$ErrorActionPreference = 'Continue'
$php = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
$root = 'D:\orange'
Set-Location $root

$suites = @(
  # D4 non-HTTP / HTTP (watch for HTTP that use orange_db)
  @{ rel = 'scripts/self_test_final_review_d4_phone_normalize.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_final_review_d4_document_sequences.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_final_review_d4_customer_required_fields_direct.php'; mode = 'watch' },
  @{ rel = 'scripts/self_test_final_review_d4_orders_insert_binding.php'; mode = 'watch' },
  @{ rel = 'scripts/self_test_final_review_d4_order_items_gl_slot.php'; mode = 'watch' },
  @{ rel = 'scripts/self_test_final_review_d4_gl_slot_concurrency.php'; mode = 'watch' },
  @{ rel = 'scripts/self_test_final_review_d4_customer_upsert.php'; mode = 'watch' },
  @{ rel = 'scripts/self_test_final_review_d4_customer_delivery_upgrade.php'; mode = 'watch' },
  @{ rel = 'scripts/self_test_final_review_d4_full_checkout_http.php'; mode = 'watch' },
  @{ rel = 'scripts/self_test_final_review_d4_first_delivered.php'; mode = 'watch' },
  @{ rel = 'scripts/self_test_final_review_d4_amendment.php'; mode = 'watch' },
  @{ rel = 'scripts/self_test_final_review_d4_order_finalization.php'; mode = 'watch' },
  @{ rel = 'scripts/self_test_final_review_d4_gift_bogo_combo.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_final_review_d4_promotion_engine.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_final_review_d4_loyalty_points.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_final_review_d4_loyalty_concurrency.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_final_review_d4_promotion_usage_concurrency.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_final_review_d4_closure_verification.php'; mode = 'direct' },
  # Phase 2
  @{ rel = 'scripts/self_test_admin_time_phase2_step4_promotions.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_admin_time_phase2_step4_closure.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_admin_time_phase2_orders_payments.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_admin_time_phase2_step2_purchases_returns.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_admin_time_phase2_step3_inventory_warehouses.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_admin_time_phase2_step5_accounting.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_admin_time_phase2_step1_closure.php'; mode = 'direct' },
  # D1
  @{ rel = 'scripts/self_test_final_review_d1_orders.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_final_review_d1_payments.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_final_review_d1_purchases_returns.php'; mode = 'direct' },
  # D2
  @{ rel = 'scripts/self_test_final_review_d2_fifo_costing.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_final_review_d2_inventory_balances.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_final_review_d2_inventory_workflows.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_final_review_d2_inventory_concurrency.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_final_review_d2_closure_contracts.php'; mode = 'direct' },
  # D3
  @{ rel = 'scripts/self_test_final_review_d3_manual_vouchers.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_final_review_d3_fiscal_numbering.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_final_review_d3_pending_subledger.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_final_review_d3_automatic_posting.php'; mode = 'direct' },
  @{ rel = 'scripts/self_test_final_review_d3_accounting_concurrency.php'; mode = 'direct' },
  # Canonical 97
  @{ rel = 'scripts/self_test_admin_time_phase3_step4_canonical97.php'; mode = 'direct' }
)

# Optional extras if present
# Note: scripts/backup/* self-tests are SKIPPED here — Owner NEVER AUTHORIZED
# executing anything under scripts/backup/ in this task (Batch C cited as SKIP).
$optional = @(
  'scripts/self_test_final_review_email_track_rate_limit.php',
  'scripts/self_test_final_review_hygiene_dead_stubs.php',
  'scripts/self_test_admin_time_phase4_exhaustive_gap_repair.php',
  'scripts/self_test_pre_phase4_product_preview_country_channel.php',
  'scripts/self_test_admin_time_phase3_step4_channel_resolution.php',
  'scripts/self_test_admin_time_phase3_step4_admin_channel_country.php',
  'scripts/self_test_admin_time_phase3_step4_inventory_country.php',
  'scripts/self_test_admin_time_phase3_step4_spm_country.php'
)
foreach ($o in $optional) {
  if (Test-Path (Join-Path $root $o)) {
    $suites += @{ rel = $o; mode = 'direct' }
  }
}

$rows = @()
$rawPass = 0
$rawFail = 0
$rawSkip = 0
foreach ($s in $suites) {
  $rel = $s.rel
  if (-not (Test-Path (Join-Path $root $rel))) {
    Write-Host "MISSING $rel"
    $rows += [pscustomobject]@{suite=$rel; mode=$s.mode; exit=127; pass=0; fail=0; skip=1; result='MISSING'; ms=0}
    $rawSkip++
    continue
  }
  Write-Host "===== REGRESSION $($s.mode) $rel ====="
  $sw = [Diagnostics.Stopwatch]::StartNew()
  if ($s.mode -eq 'watch') {
    powershell -NoProfile -File (Join-Path $root 'scripts\_d4_run_one_watch.ps1') -SuiteRel $rel -TimeoutSec $TimeoutSec
    $code = $LASTEXITCODE
    $baseName = [IO.Path]::GetFileNameWithoutExtension($rel)
    $log = Get-ChildItem $env:TEMP -Filter "orange_d4_suite_${baseName}_*.out.log" | Sort-Object LastWriteTime -Descending | Select-Object -First 1
    $out = if ($log) { Get-Content $log.FullName -Raw } else { '' }
  } else {
    & $php (Join-Path $root 'scripts\_d4_force_cleanup.php') 2>$null | Out-Null
    $out = & $php -d output_buffering=0 $rel 2>&1 | Out-String
    $code = $LASTEXITCODE
    Write-Host $out
  }
  $sw.Stop()
  $pass = if ($out -match 'PASS=(\d+)') { [int]$Matches[1] } elseif ($out -match '(?m)^PASS\s') { ([regex]::Matches($out, '(?m)^PASS\s')).Count } else { 0 }
  $fail = if ($out -match 'FAIL=(\d+)') { [int]$Matches[1] } else { 0 }
  $skip = if ($out -match 'SKIP=(\d+)') { [int]$Matches[1] } else { 0 }
  $result = if ($out -match 'RESULT=(\S+)') { $Matches[1] } elseif ($fail -eq 0 -and $code -eq 0) { 'OK' } else { 'NO_RESULT' }
  if ($fail -gt 0) { $code = 1 }
  Write-Host "EXIT=$code DURATION_MS=$($sw.ElapsedMilliseconds) PASS=$pass FAIL=$fail SKIP=$skip RESULT=$result"
  $rows += [pscustomobject]@{suite=$rel; mode=$s.mode; exit=$code; pass=$pass; fail=$fail; skip=$skip; result=$result; ms=$sw.ElapsedMilliseconds}
  $rawPass += $pass
  $rawFail += $fail
  $rawSkip += $skip
}

$rows | Format-Table -AutoSize
$rows | Export-Csv -NoTypeInformation (Join-Path $root 'scripts\_d4_regression_summary.csv')
$uniqueFailSuites = @($rows | Where-Object { $_.fail -gt 0 -or ($_.exit -ne 0 -and $_.result -ne 'OK' -and $_.pass -eq 0) })
Write-Host "RAW_PASS=$rawPass RAW_FAIL=$rawFail RAW_SKIP=$rawSkip SUITES=$($rows.Count) FAIL_SUITES=$($uniqueFailSuites.Count)"
if ($uniqueFailSuites.Count -gt 0) {
  Write-Host 'FAILING:'
  $uniqueFailSuites | Format-Table suite,exit,pass,fail,result -AutoSize
  exit 1
}
exit 0
