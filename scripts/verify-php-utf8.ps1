#Requires -Version 5.1
# UTF-8 / BOM check for PHP (Windows). White page in PHP often = BOM before <?php
# Run:  powershell -NoProfile -File scripts/verify-php-utf8.ps1
# Fix UTF-8 BOM only:  powershell -NoProfile -File scripts/verify-php-utf8.ps1 -Fix
# Editor: save as UTF-8 without BOM (not "UTF-8 with BOM").
# Use -NoProfile لتفادي ارتباط مفاتيح (مثل Escape) من PSReadLine في الملف الشخصي.

param(
    [switch]$Fix
)

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$fail = 0
$fixed = 0
$checked = 0
$excludeRe = '(^|[\\/])(vendor|node_modules|\.git)([\\/]|$)'

function Strip-Utf8Bom {
    param([string]$FullPath)
    $bytes = [System.IO.File]::ReadAllBytes($FullPath)
    if ($bytes.Length -lt 3) { return $false }
    if ($bytes[0] -ne 0xEF -or $bytes[1] -ne 0xBB -or $bytes[2] -ne 0xBF) { return $false }
    $rest = New-Object byte[] ($bytes.Length - 3)
    [Array]::Copy($bytes, 3, $rest, 0, $rest.Length)
    [System.IO.File]::WriteAllBytes($FullPath, $rest)
    return $true
}

Write-Host ('UTF-8 verify, root: ' + $root.Path) -ForegroundColor Cyan
if ($Fix) {
    Write-Host "Mode -Fix: will strip UTF-8 BOM from affected files only." -ForegroundColor Yellow
}

try {
    # SilentlyContinue: لا يوقف السكربت عند مجلدات ممنوعة الوصول (مشكلة شائعة على ويندوز).
    $phpFiles = @(Get-ChildItem -LiteralPath $root.Path -Recurse -Filter '*.php' -File -ErrorAction SilentlyContinue |
        Where-Object {
            $rel = $_.FullName.Substring($root.Path.Length).TrimStart('\').TrimStart('/')
            $rel -notmatch $excludeRe
        })
}
catch [System.Management.Automation.PipelineStoppedException] {
    Write-Host ""
    Write-Host "تم إيقاف التشغيل (Ctrl+C أو إلغاء من الطرفية)." -ForegroundColor Yellow
    exit 130
}

try {
    foreach ($file in $phpFiles) {
        $full = $file.FullName
        $rel = $full.Substring($root.Path.Length).TrimStart('\').TrimStart('/')
        $checked++

        try {
            $bytes = [System.IO.File]::ReadAllBytes($full)
        }
        catch {
            Write-Host "  SKIP (قراءة الملف): $rel" -ForegroundColor DarkYellow
            continue
        }
        if ($bytes.Length -lt 2) {
            continue
        }

        if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
            if ($Fix) {
                if (Strip-Utf8Bom -FullPath $full) {
                    Write-Host "  FIXED UTF-8 BOM: $rel" -ForegroundColor Green
                    $fixed++
                }
            }
            else {
                Write-Host "  FAIL UTF-8 BOM: $rel  (re-save UTF-8 no BOM, or run with -Fix)" -ForegroundColor Red
                $fail = 1
            }
            continue
        }

        if ($bytes.Length -ge 4 -and $bytes[0] -eq 0xFF -and $bytes[1] -eq 0xFE -and $bytes[2] -eq 0x3C -and $bytes[3] -eq 0x00) {
            Write-Host "  FAIL UTF-16 LE (BOM): $rel" -ForegroundColor Red
            $fail = 1
            continue
        }

        if ($bytes.Length -ge 4 -and $bytes[0] -eq 0xFE -and $bytes[1] -eq 0xFF -and $bytes[2] -eq 0x00 -and $bytes[3] -eq 0x3C) {
            Write-Host "  FAIL UTF-16 BE (BOM): $rel" -ForegroundColor Red
            $fail = 1
            continue
        }

        if ($bytes.Length -ge 4 -and $bytes[0] -eq 0x3C -and $bytes[1] -eq 0x00 -and $bytes[2] -eq 0x3F -and $bytes[3] -eq 0x00) {
            Write-Host "  FAIL UTF-16 LE (wide PHP open tag): $rel" -ForegroundColor Red
            $fail = 1
            continue
        }
    }
}
catch [System.Management.Automation.PipelineStoppedException] {
    Write-Host ""
    Write-Host "تم إيقاف التشغيل (إلغاء من الطرفية / Ctrl+C)." -ForegroundColor Yellow
    exit 130
}
catch [System.OperationCanceledException] {
    Write-Host ""
    Write-Host "تم إيقاف التشغيل." -ForegroundColor Yellow
    exit 130
}

Write-Host ""
Write-Host "PHP files scanned: $checked"
if ($Fix -and $fixed -gt 0) {
    Write-Host "BOM fixed: $fixed file(s)" -ForegroundColor Green
}
if ($fail -ne 0) {
    Write-Host 'Result: FAIL (exit 1)' -ForegroundColor Red
    exit 1
}
Write-Host 'Result: OK (exit 0)' -ForegroundColor Green
exit 0
