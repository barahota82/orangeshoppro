param(
    [Parameter(Mandatory = $true)][string]$SuiteRel,
    [int]$TimeoutSec = 300
)
$ErrorActionPreference = 'Continue'
$php = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
$root = 'D:\orange'
$base = Join-Path $env:TEMP ('orange_d4_suite_' + [IO.Path]::GetFileNameWithoutExtension($SuiteRel) + '_' + $PID)
$logOut = $base + '.out.log'
$logErr = $base + '.err.log'
foreach ($f in @($logOut, $logErr)) { if (Test-Path $f) { Remove-Item $f -Force } }

& $php (Join-Path $root 'scripts\_d4_force_cleanup.php') | Out-Null

Write-Host "===== RUN $SuiteRel ====="
$sw = [Diagnostics.Stopwatch]::StartNew()
$p = Start-Process -FilePath $php -ArgumentList @(
    '-d', 'output_buffering=0',
    '-d', 'implicit_flush=1',
    (Join-Path $root $SuiteRel)
) -WorkingDirectory $root -RedirectStandardOutput $logOut -RedirectStandardError $logErr -PassThru -NoNewWindow

while (-not $p.HasExited) {
    Start-Sleep -Milliseconds 400
    $tail = ''
    if (Test-Path $logOut) { $tail += (Get-Content $logOut -Raw -ErrorAction SilentlyContinue) }
    if (Test-Path $logErr) { $tail += (Get-Content $logErr -Raw -ErrorAction SilentlyContinue) }
    if ($tail -and $tail -match 'RESULT=(\S+)') {
        # Give finally a short window, then kill tree (avoids DROP hang / orphan -S)
        Start-Sleep -Seconds 3
        if (-not $p.HasExited) {
            taskkill /F /T /PID $p.Id 2>$null | Out-Null
        }
        break
    }
    if ($sw.Elapsed.TotalSeconds -gt $TimeoutSec) {
        taskkill /F /T /PID $p.Id 2>$null | Out-Null
        Add-Content $logErr "`nTIMEOUT after ${TimeoutSec}s`n"
        break
    }
}
if (-not $p.HasExited) {
    try { $p.WaitForExit(15000) | Out-Null } catch {}
}
$sw.Stop()

# Always force-clean disposable resources
Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" -ErrorAction SilentlyContinue | ForEach-Object {
    if ($_.CommandLine -and ($_.CommandLine -match 'orange_d4|-S 127\.0\.0\.1')) {
        Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
    }
}
& $php (Join-Path $root 'scripts\_d4_force_cleanup.php') | Out-Null
Get-ChildItem $env:TEMP -Directory -Filter 'orange_d4_session_*' -ErrorAction SilentlyContinue | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item "$env:TEMP\orange_d4_http_exclusive.lock", "$env:TEMP\orange_d4_http_schema_ok_orange_db.flag" -Force -ErrorAction SilentlyContinue
Remove-Item "$env:TEMP\orange_d4_http_state_*.json" -Force -ErrorAction SilentlyContinue
if (Test-Path 'D:\orange_d4_http_runtime\.env.php') { Remove-Item 'D:\orange_d4_http_runtime\.env.php' -Force }

$out = ''
if (Test-Path $logOut) { $out += Get-Content $logOut -Raw }
if (Test-Path $logErr) { $out += Get-Content $logErr -Raw }
Write-Host $out
$pass = if ($out -match 'PASS=(\d+)') { $Matches[1] } else { '?' }
$fail = if ($out -match 'FAIL=(\d+)') { $Matches[1] } else { '?' }
$skip = if ($out -match 'SKIP=(\d+)') { $Matches[1] } else { '?' }
$result = if ($out -match 'RESULT=(\S+)') { $Matches[1] } elseif ($out -match 'TIMEOUT') { 'TIMEOUT' } else { 'NO_RESULT' }
# Suites without RESULT= still OK when PASS>0 and FAIL=0 (e.g. phone/document_sequences).
if ($fail -ne '?' -and [int]$fail -gt 0) { $code = 1 }
elseif ($result -eq 'TIMEOUT') { $code = 1 }
elseif ($result -eq 'NO_RESULT' -and ($pass -eq '?' -or [int]$pass -eq 0)) { $code = 1 }
else { $code = 0 }
Write-Host "EXIT=$code DURATION_MS=$($sw.ElapsedMilliseconds) PASS=$pass FAIL=$fail SKIP=$skip RESULT=$result"
exit $code
