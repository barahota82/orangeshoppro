#Requires -Version 5.1
# UTF-8 / BOM check for PHP (Windows). White page in PHP often = BOM before <?php
# Run:  powershell -File scripts/verify-php-utf8.ps1
# Fix UTF-8 BOM only:  powershell -File scripts/verify-php-utf8.ps1 -Fix
# Editor: save as UTF-8 without BOM (not "UTF-8 with BOM").

param(
    [switch]$Fix
)

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$fail = 0
$fixed = 0
$checked = 0

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

Get-ChildItem -Path $root -Recurse -Filter '*.php' -File | ForEach-Object {
    $full = $_.FullName
    $rel = $full.Substring($root.Path.Length).TrimStart('\').TrimStart('/')
    if ($rel -match '(^|[\\/])(vendor|node_modules|\.git)([\\/]|$)') {
        return
    }
    $script:checked++

    $bytes = [System.IO.File]::ReadAllBytes($full)
    if ($bytes.Length -lt 2) {
        return
    }

    if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
        if ($Fix) {
            if (Strip-Utf8Bom -FullPath $full) {
                Write-Host "  FIXED UTF-8 BOM: $rel" -ForegroundColor Green
                $script:fixed++
            }
        }
        else {
            Write-Host "  FAIL UTF-8 BOM: $rel  (re-save UTF-8 no BOM, or run with -Fix)" -ForegroundColor Red
            $script:fail = 1
        }
        return
    }

    if ($bytes.Length -ge 4 -and $bytes[0] -eq 0xFF -and $bytes[1] -eq 0xFE -and $bytes[2] -eq 0x3C -and $bytes[3] -eq 0x00) {
        Write-Host "  FAIL UTF-16 LE (BOM): $rel" -ForegroundColor Red
        $script:fail = 1
        return
    }

    if ($bytes.Length -ge 4 -and $bytes[0] -eq 0xFE -and $bytes[1] -eq 0xFF -and $bytes[2] -eq 0x00 -and $bytes[3] -eq 0x3C) {
        Write-Host "  FAIL UTF-16 BE (BOM): $rel" -ForegroundColor Red
        $script:fail = 1
        return
    }

    if ($bytes.Length -ge 4 -and $bytes[0] -eq 0x3C -and $bytes[1] -eq 0x00 -and $bytes[2] -eq 0x3F -and $bytes[3] -eq 0x00) {
        Write-Host "  FAIL UTF-16 LE (wide PHP open tag): $rel" -ForegroundColor Red
        $script:fail = 1
        return
    }
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
