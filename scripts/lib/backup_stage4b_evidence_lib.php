<?php

declare(strict_types=1);

/**
 * Stage 4B evidence helpers — extract Production page CSS/JS, Chrome headless, contact sheet.
 * Evidence only; no Production mutations.
 */

function s4b_ev_evidence_dir(string $folderName): string
{
    $safeName = trim(str_replace(['/', '\\'], '', $folderName));
    if ($safeName === '') {
        throw new InvalidArgumentException('Evidence folder name is required.');
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        return 'D:\\' . $safeName;
    }

    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeName;
}

function s4b_ev_extract_function(string $src, string $name): string
{
    $needle = 'function ' . $name . '(';
    $start = strpos($src, $needle);
    if ($start === false) {
        return '';
    }
    $brace = strpos($src, '{', $start);
    if ($brace === false) {
        return '';
    }
    $depth = 0;
    $len = strlen($src);
    for ($i = $brace; $i < $len; $i++) {
        $ch = $src[$i];
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $start, $i - $start + 1);
            }
        }
    }

    return '';
}

function s4b_ev_extract_const_arrow(string $src, string $name): string
{
    // const name = (...) => { ... };  OR  const name = (pkg) => `...`;
    if (!preg_match('/const\s+' . preg_quote($name, '/') . '\s*=\s*/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    $start = (int) $m[0][1];
    $i = $start + strlen($m[0][0]);
    $len = strlen($src);
    // skip to expression
    while ($i < $len && ctype_space($src[$i])) {
        $i++;
    }
    if ($i >= $len) {
        return '';
    }
    // arrow with block
    if (preg_match('/\A(?:async\s*)?\([^)]*\)\s*=>\s*\{/', substr($src, $i), $am)) {
        $brace = strpos($src, '{', $i);
        $depth = 0;
        for ($j = $brace; $j < $len; $j++) {
            if ($src[$j] === '{') {
                $depth++;
            } elseif ($src[$j] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $j + 1;
                    if ($end < $len && $src[$end] === ';') {
                        $end++;
                    }

                    return substr($src, $start, $end - $start);
                }
            }
        }
    }
    // single-expression arrow ending at semicolon at depth 0
    $depthParen = 0;
    $depthBrace = 0;
    $inStr = '';
    for ($j = $i; $j < $len; $j++) {
        $ch = $src[$j];
        if ($inStr !== '') {
            if ($ch === '\\') {
                $j++;
                continue;
            }
            if ($ch === $inStr) {
                $inStr = '';
            }
            continue;
        }
        if ($ch === '"' || $ch === "'" || $ch === '`') {
            $inStr = $ch;
            continue;
        }
        if ($ch === '(') {
            $depthParen++;
        } elseif ($ch === ')') {
            $depthParen--;
        } elseif ($ch === '{') {
            $depthBrace++;
        } elseif ($ch === '}') {
            $depthBrace--;
        } elseif ($ch === ';' && $depthParen === 0 && $depthBrace === 0) {
            return substr($src, $start, $j - $start + 1);
        }
    }

    return '';
}

function s4b_ev_chrome_path(): string
{
    $candidates = [
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        (string) (getenv('LOCALAPPDATA') ?: '') . '\\Google\\Chrome\\Application\\chrome.exe',
    ];
    foreach ($candidates as $p) {
        if ($p !== '' && is_file($p)) {
            return $p;
        }
    }

    return '';
}

/**
 * Run Chrome headless, dump DOM to $outHtml, return decoded data-s3-b64 / data-s4b-b64 report.
 *
 * @return array{ok:bool,html:string,report:?array,err:string}
 */
function s4b_ev_chrome_dump_report(string $url, string $outHtml, string $errFile, int $w, int $h, string $attr = 'data-s4b-b64'): array
{
    $chrome = s4b_ev_chrome_path();
    if ($chrome === '') {
        return ['ok' => false, 'html' => '', 'report' => null, 'err' => 'chrome_missing'];
    }
    $ps = <<<'PS'
param([string]$Chrome,[string]$Url,[string]$Out,[string]$Err,[int]$W,[int]$H)
$p = Start-Process -FilePath $Chrome -ArgumentList @(
  '--headless=new','--disable-gpu','--allow-file-access-from-files','--hide-scrollbars',
  "--window-size=$W,$H",'--force-device-scale-factor=1','--virtual-time-budget=8000','--dump-dom',$Url
) -NoNewWindow -PassThru -RedirectStandardOutput $Out -RedirectStandardError $Err
if (-not $p.WaitForExit(45000)) {
  Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue
  Write-Output 'TIMEOUT'
  exit 2
}
Write-Output ('EXIT=' + $p.ExitCode)
exit 0
PS;
    $runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s4b_chrome_dump_' . getmypid() . '.ps1';
    file_put_contents($runner, $ps);
    $status = [];
    $exit = 0;
    exec(
        'powershell -NoProfile -File ' . escapeshellarg($runner)
        . ' -Chrome ' . escapeshellarg($chrome)
        . ' -Url ' . escapeshellarg($url)
        . ' -Out ' . escapeshellarg($outHtml)
        . ' -Err ' . escapeshellarg($errFile)
        . ' -W ' . (int) $w
        . ' -H ' . (int) $h,
        $status,
        $exit
    );
    @unlink($runner);
    $html = is_file($outHtml) ? (string) file_get_contents($outHtml) : '';
    $report = null;
    $b64 = null;
    if (preg_match('/<pre id="s4b_report_b64"[^>]*>\s*([A-Za-z0-9+\/=]+)\s*<\/pre>/', $html, $m)) {
        $b64 = $m[1];
    } elseif (preg_match('/<pre id="s3_report_b64"[^>]*>\s*([A-Za-z0-9+\/=]+)\s*<\/pre>/', $html, $m)) {
        $b64 = $m[1];
    } else {
        $attrQ = preg_quote($attr, '/');
        if (preg_match('/' . $attrQ . '="([^"]+)"/', $html, $m)) {
            $b64 = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    if (is_string($b64) && $b64 !== '') {
        $raw = base64_decode($b64, true);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $report = $decoded;
            }
        }
    }

    return [
        'ok' => is_array($report),
        'html' => $html,
        'report' => $report,
        'err' => implode(' ', $status) . ' exit=' . $exit,
    ];
}

/**
 * CDP mobile-faithful capture: Emulation.setDeviceMetricsOverride + screenshot and/or evaluate.
 * Avoids RTL narrow-body sitting inside a wider screenshot canvas (Owner mobile clip defect).
 *
 * @return array{ok:bool,err:string,report:?array,png_ok:bool}
 */
function s4b_ev_chrome_cdp_capture(string $url, string $outPng, int $w, int $h, string $evalJs = '', int $waitSeconds = 12): array
{
    $chrome = s4b_ev_chrome_path();
    if ($chrome === '') {
        return ['ok' => false, 'err' => 'chrome_missing', 'report' => null, 'png_ok' => false];
    }
    if ($waitSeconds < 5) {
        $waitSeconds = 5;
    }
    if ($waitSeconds > 120) {
        $waitSeconds = 120;
    }
    if ($outPng !== '' && is_file($outPng)) {
        @unlink($outPng);
    }
    // Unique port + report path per call (avoids collisions across sequential Chrome launches).
    $port = 9333 + random_int(0, 20000);
    $reportOut = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s4b_cdp_report_' . getmypid() . '_' . $port . '.json';
    if (is_file($reportOut)) {
        @unlink($reportOut);
    }
    $ps = <<<'PS'
param([string]$Chrome,[string]$Url,[string]$OutPng,[string]$ReportOut,[int]$W,[int]$H,[int]$Port,[string]$EvalJs,[int]$WaitSeconds = 12)
$ErrorActionPreference = 'Stop'
$userData = Join-Path $env:TEMP ("orange_s4b_chrome_profile_" + $Port + "_" + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $userData -Force | Out-Null
$chromeProc = $null
try {
  $chromeProc = Start-Process -FilePath $Chrome -ArgumentList @(
    '--headless=new','--disable-gpu','--allow-file-access-from-files','--hide-scrollbars',
    '--force-device-scale-factor=1',"--remote-debugging-port=$Port","--user-data-dir=$userData",
    '--no-first-run','--no-default-browser-check','about:blank'
  ) -PassThru -WindowStyle Hidden
  $deadline = (Get-Date).AddSeconds(25)
  $version = $null
  while ((Get-Date) -lt $deadline) {
    try { $version = Invoke-RestMethod -Uri ("http://127.0.0.1:$Port/json/version") -TimeoutSec 2; break } catch { Start-Sleep -Milliseconds 250 }
  }
  if (-not $version) { throw 'cdp_version_timeout' }
  # Must attach to a PAGE target websocket (browser websocket lacks Page/Runtime domains).
  $page = $null
  # Open blank first; apply device metrics, then navigate (avoids first paint at wrong width).
  $newUrl = "http://127.0.0.1:$Port/json/new?" + [uri]::EscapeDataString('about:blank')
  try { $page = Invoke-RestMethod -Uri $newUrl -Method Put -TimeoutSec 8 } catch {
    try { $page = Invoke-RestMethod -Uri $newUrl -TimeoutSec 8 } catch {}
  }
  if (-not $page) {
    $tabs = Invoke-RestMethod -Uri ("http://127.0.0.1:$Port/json/list") -TimeoutSec 5
    foreach ($t in $tabs) {
      if ($t.type -eq 'page' -and $t.webSocketDebuggerUrl) { $page = $t; break }
    }
  }
  if (-not $page -or -not $page.webSocketDebuggerUrl) { throw 'cdp_page_target_missing' }
  $wsUrl = [string]$page.webSocketDebuggerUrl
  $ws = New-Object System.Net.WebSockets.ClientWebSocket
  $cts = New-Object System.Threading.CancellationTokenSource
  $cts.CancelAfter([Math]::Max(60000, ($WaitSeconds + 30) * 1000))
  [void]$ws.ConnectAsync([Uri]$wsUrl, $cts.Token).GetAwaiter().GetResult()
  $msgId = 0
  function Send-Cdp([string]$method, $paramsJson) {
    # $paramsJson is raw JSON object text or empty string (no params).
    $script:msgId++
    $id = $script:msgId
    if ($paramsJson -and $paramsJson.Trim().Length -gt 0) {
      $json = "{`"id`":$id,`"method`":`"$method`",`"params`":$paramsJson}"
    } else {
      $json = "{`"id`":$id,`"method`":`"$method`"}"
    }
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($json)
    $seg = New-Object System.ArraySegment[byte] -ArgumentList @(,$bytes)
    [void]$ws.SendAsync($seg, [System.Net.WebSockets.WebSocketMessageType]::Text, $true, $cts.Token).GetAwaiter().GetResult()
    $deadline = (Get-Date).AddSeconds(25)
    while ((Get-Date) -lt $deadline) {
      $buf = New-Object byte[] 4194304
      $ms = New-Object System.IO.MemoryStream
      do {
        $segR = New-Object System.ArraySegment[byte] -ArgumentList @(,$buf)
        $res = $ws.ReceiveAsync($segR, $cts.Token).Result
        $ms.Write($buf, 0, $res.Count)
      } while (-not $res.EndOfMessage)
      $text = [System.Text.Encoding]::UTF8.GetString($ms.ToArray())
      $obj = $text | ConvertFrom-Json
      if ($null -ne $obj.id -and [int]$obj.id -eq $id) { return $obj }
    }
    throw ("cdp_timeout method=" + $method)
  }
  function Eval-String([string]$expression) {
    $esc = $expression.Replace('\', '\\').Replace('"', '\"').Replace("`r", '\r').Replace("`n", '\n')
    $params = "{`"expression`":`"$esc`",`"returnByValue`":true}"
    $ev = Send-Cdp 'Runtime.evaluate' $params
    if ($ev.result.exceptionDetails) { return $null }
    return $ev.result.result.value
  }
  Send-Cdp 'Page.enable' '' | Out-Null
  Send-Cdp 'Runtime.enable' '' | Out-Null
  $mobileJson = $(if ($W -le 500) { 'true' } else { 'false' })
  Send-Cdp 'Emulation.setDeviceMetricsOverride' "{`"width`":$W,`"height`":$H,`"deviceScaleFactor`":1,`"mobile`":$mobileJson}" | Out-Null
  $urlEsc = $Url.Replace('\', '\\').Replace('"', '\"')
  Send-Cdp 'Page.navigate' "{`"url`":`"$urlEsc`"}" | Out-Null
  # Wait until evidence/geometry report marker is present (sync IIFE + layout settle).
  $b64 = ''
  $waitExpr = "(function(){ var a=document.getElementById('s4b_report_b64'); var b=document.getElementById('s3_report_b64'); var txt=(a&&a.textContent)?a.textContent:((b&&b.textContent)?b.textContent:''); return txt || ''; })()"
  $waitUntil = (Get-Date).AddSeconds([Math]::Max(5, $WaitSeconds))
  while ((Get-Date) -lt $waitUntil) {
    Start-Sleep -Milliseconds 350
    $got = Eval-String $waitExpr
    if ($got -and [string]$got -ne '' -and ([string]$got).Length -gt 8) { $b64 = [string]$got; break }
    $titleNow = [string](Eval-String 'document.title')
    if ($titleNow -match 'S4B_RACE_(PASS|FAIL)|S4B_EVIDENCE_READY|S4B_GEOM_PASS|MOBILE_GEOM_') {
      $got2 = Eval-String $waitExpr
      if ($got2 -and [string]$got2 -ne '' -and ([string]$got2).Length -gt 8) { $b64 = [string]$got2; break }
    }
  }
  $title = [string](Eval-String 'document.title')
  $cw = [string](Eval-String 'String(document.documentElement.clientWidth)')
  $hasPre = [string](Eval-String "(document.getElementById('s4b_report_b64') ? '1' : '0')")
  if ($EvalJs -and $EvalJs.Trim().Length -gt 0) {
    $val = Eval-String $EvalJs
    if ($null -ne $val) {
      $textOut = $(if ($val -is [string]) { [string]$val } else { ($val | ConvertTo-Json -Depth 20 -Compress) })
      [IO.File]::WriteAllText($ReportOut, $textOut, [System.Text.UTF8Encoding]::new($false))
    }
  } elseif ($b64) {
    $json = [System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String($b64))
    [IO.File]::WriteAllText($ReportOut, $json, [System.Text.UTF8Encoding]::new($false))
  }
  if ($OutPng -and $OutPng.Trim().Length -gt 0) {
    $shot = Send-Cdp 'Page.captureScreenshot' "{`"format`":`"png`",`"fromSurface`":true}"
    $data = $null
    if ($shot.result -and $shot.result.data) { $data = [string]$shot.result.data }
    if (-not $data) { throw 'screenshot_empty' }
    [IO.File]::WriteAllBytes($OutPng, [Convert]::FromBase64String($data))
  }
  try { $ws.CloseAsync([System.Net.WebSockets.WebSocketCloseStatus]::NormalClosure, 'done', $cts.Token).Wait(2000) } catch {}
  Write-Output ("CDP_OK b64len=" + $b64.Length + " title=" + $title + " cw=" + $cw + " hasPre=" + $hasPre + " reportOut=" + (Test-Path $ReportOut))
  exit 0
} catch {
  Write-Output ('CDP_FAIL ' + $_.Exception.Message)
  exit 1
} finally {
  if ($chromeProc -and -not $chromeProc.HasExited) { Stop-Process -Id $chromeProc.Id -Force -ErrorAction SilentlyContinue }
  if (Test-Path $userData) { Remove-Item $userData -Recurse -Force -ErrorAction SilentlyContinue }
}
PS;
    $runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s4b_cdp_' . getmypid() . '.ps1';
    file_put_contents($runner, $ps);
    $status = [];
    $exit = 0;
    $cmd = 'powershell -NoProfile -File ' . escapeshellarg($runner)
        . ' -Chrome ' . escapeshellarg($chrome)
        . ' -Url ' . escapeshellarg($url)
        . ' -OutPng ' . escapeshellarg($outPng)
        . ' -ReportOut ' . escapeshellarg($reportOut)
        . ' -W ' . (int) $w
        . ' -H ' . (int) $h
        . ' -Port ' . (int) $port
        . ' -EvalJs ' . escapeshellarg($evalJs)
        . ' -WaitSeconds ' . (int) $waitSeconds;
    $attempts = 0;
    $exit = 1;
    $status = [];
    $report = null;
    $pngOk = false;
    while ($attempts < 2) {
        $attempts++;
        $status = [];
        $exit = 0;
        exec($cmd, $status, $exit);
        $report = null;
        if (is_file($reportOut)) {
            $raw = (string) file_get_contents($reportOut);
            @unlink($reportOut);
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $report = $decoded;
            } elseif ($raw !== '') {
                $try = base64_decode($raw, true);
                if (is_string($try)) {
                    $decoded2 = json_decode($try, true);
                    if (is_array($decoded2)) {
                        $report = $decoded2;
                    }
                }
            }
        }
        $pngOk = $outPng !== '' && is_file($outPng) && filesize($outPng) > 1000;
        $errText = implode(' ', $status);
        if ($exit === 0 && ($report !== null || $pngOk)) {
            break;
        }
        // Retry once on port/version races.
        if ($attempts < 2 && (str_contains($errText, 'cdp_version_timeout') || str_contains($errText, 'cdp_page_target_missing'))) {
            $port = 9333 + random_int(0, 20000);
            $reportOut = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s4b_cdp_report_' . getmypid() . '_' . $port . '.json';
            $cmd = 'powershell -NoProfile -File ' . escapeshellarg($runner)
                . ' -Chrome ' . escapeshellarg($chrome)
                . ' -Url ' . escapeshellarg($url)
                . ' -OutPng ' . escapeshellarg($outPng)
                . ' -ReportOut ' . escapeshellarg($reportOut)
                . ' -W ' . (int) $w
                . ' -H ' . (int) $h
                . ' -Port ' . (int) $port
                . ' -EvalJs ' . escapeshellarg($evalJs);
            usleep(400000);
            continue;
        }
        break;
    }
    @unlink($runner);

    return [
        'ok' => $exit === 0 && ($report !== null || $pngOk),
        'err' => implode(' ', $status) . ' exit=' . $exit,
        'report' => $report,
        'png_ok' => $pngOk,
    ];
}

/**
 * @return array{ok:bool,err:string}
 */
function s4b_ev_chrome_screenshot(string $url, string $outPng, int $w, int $h): array
{
    // Prefer CDP device metrics for narrow viewports (Owner mobile evidence correctness).
    if ($w <= 500) {
        $cdp = s4b_ev_chrome_cdp_capture($url, $outPng, $w, $h, '');
        if ($cdp['png_ok']) {
            return ['ok' => true, 'err' => $cdp['err']];
        }
    }
    $chrome = s4b_ev_chrome_path();
    if ($chrome === '') {
        return ['ok' => false, 'err' => 'chrome_missing'];
    }
    if (is_file($outPng)) {
        @unlink($outPng);
    }
    $ps = <<<'PS'
param([string]$Chrome,[string]$Url,[string]$Out,[int]$W,[int]$H)
$err = $Out + '.err.txt'
$p = Start-Process -FilePath $Chrome -ArgumentList @(
  '--headless=new','--disable-gpu','--allow-file-access-from-files','--hide-scrollbars',
  "--window-size=$W,$H",'--force-device-scale-factor=1','--virtual-time-budget=5000',
  "--screenshot=$Out",$Url
) -NoNewWindow -PassThru -RedirectStandardError $err
if (-not $p.WaitForExit(45000)) {
  Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue
  Write-Output 'TIMEOUT'
  exit 2
}
exit 0
PS;
    $runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s4b_chrome_shot_' . getmypid() . '.ps1';
    file_put_contents($runner, $ps);
    $status = [];
    $exit = 0;
    exec(
        'powershell -NoProfile -File ' . escapeshellarg($runner)
        . ' -Chrome ' . escapeshellarg($chrome)
        . ' -Url ' . escapeshellarg($url)
        . ' -Out ' . escapeshellarg($outPng)
        . ' -W ' . (int) $w
        . ' -H ' . (int) $h,
        $status,
        $exit
    );
    @unlink($runner);

    return ['ok' => is_file($outPng) && filesize($outPng) > 1000, 'err' => implode(' ', $status) . ' exit=' . $exit];
}

/**
 * @param list<array{path:string,label:string}> $shots
 */
function s4b_ev_build_contact_sheet(array $shots, string $outPng, int $cols = 3): bool
{
    if (!extension_loaded('gd') || $shots === []) {
        return false;
    }
    $images = [];
    $tw = 420;
    $th = 280;
    foreach ($shots as $s) {
        if (!is_file($s['path'])) {
            continue;
        }
        $raw = @imagecreatefrompng($s['path']);
        if ($raw === false) {
            continue;
        }
        $sw = imagesx($raw);
        $sh = imagesy($raw);
        $dst = imagecreatetruecolor($tw, $th);
        $bg = imagecolorallocate($dst, 241, 245, 249);
        imagefilledrectangle($dst, 0, 0, $tw, $th, $bg);
        $scale = min(($tw - 16) / max(1, $sw), ($th - 40) / max(1, $sh));
        $nw = (int) max(1, $sw * $scale);
        $nh = (int) max(1, $sh * $scale);
        $dx = (int) (($tw - $nw) / 2);
        $dy = 28 + (int) (($th - 40 - $nh) / 2);
        imagecopyresampled($dst, $raw, $dx, $dy, 0, 0, $nw, $nh, $sw, $sh);
        $ink = imagecolorallocate($dst, 15, 23, 42);
        imagestring($dst, 3, 8, 8, substr($s['label'], 0, 48), $ink);
        imagedestroy($raw);
        $images[] = $dst;
    }
    if ($images === []) {
        return false;
    }
    $n = count($images);
    $rows = (int) ceil($n / $cols);
    $pad = 12;
    $sheetW = $cols * $tw + ($cols + 1) * $pad;
    $sheetH = $rows * $th + ($rows + 1) * $pad + 36;
    $sheet = imagecreatetruecolor($sheetW, $sheetH);
    $bg = imagecolorallocate($sheet, 255, 255, 255);
    $ink = imagecolorallocate($sheet, 15, 23, 42);
    imagefilledrectangle($sheet, 0, 0, $sheetW, $sheetH, $bg);
    imagestring($sheet, 5, 12, 10, 'Stage 4B Production page evidence (local Diff)', $ink);
    foreach ($images as $i => $im) {
        $r = intdiv($i, $cols);
        $c = $i % $cols;
        $x = $pad + $c * ($tw + $pad);
        $y = 36 + $pad + $r * ($th + $pad);
        imagecopy($sheet, $im, $x, $y, 0, 0, $tw, $th);
        imagedestroy($im);
    }
    $ok = imagepng($sheet, $outPng);
    imagedestroy($sheet);

    return (bool) $ok;
}
