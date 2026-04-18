# UTF-8 / BOM check for PHP files (Windows-friendly, no xxd).
# Run from repo root:  pwsh -File scripts/verify-php-utf8.ps1
# Or double-click from scripts folder if execution policy allows.

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$fail = 0

Get-ChildItem -Path $root -Recurse -Filter '*.php' -File | ForEach-Object {
    $rel = $_.FullName.Substring($root.Path.Length + 1)
    if ($rel -match '(^|\\)(vendor|node_modules|\.git)(\\|$)') {
        return
    }
    $bytes = [System.IO.File]::ReadAllBytes($_.FullName)
    if ($bytes.Length -lt 2) {
        return
    }
    # UTF-8 BOM
    if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
        Write-Host "UTF-8 BOM: $rel"
        $script:fail = 1
        return
    }
    # UTF-16 LE BOM + <
    if ($bytes.Length -ge 4 -and $bytes[0] -eq 0xFF -and $bytes[1] -eq 0xFE -and $bytes[2] -eq 0x3C -and $bytes[3] -eq 0x00) {
        Write-Host "UTF-16 LE (BOM): $rel"
        $script:fail = 1
        return
    }
    # UTF-16 BE BOM + <
    if ($bytes.Length -ge 4 -and $bytes[0] -eq 0xFE -and $bytes[1] -eq 0xFF -and $bytes[2] -eq 0x00 -and $bytes[3] -eq 0x3C) {
        Write-Host "UTF-16 BE (BOM): $rel"
        $script:fail = 1
        return
    }
    # UTF-16 LE <? without BOM
    if ($bytes.Length -ge 4 -and $bytes[0] -eq 0x3C -and $bytes[1] -eq 0x00 -and $bytes[2] -eq 0x3F -and $bytes[3] -eq 0x00) {
        Write-Host "UTF-16 LE (like <?php wide): $rel"
        $script:fail = 1
        return
    }
}

exit $fail
