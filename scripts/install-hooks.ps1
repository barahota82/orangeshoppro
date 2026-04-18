# يفعّل hooks من المجلد scripts/git-hooks (مرة واحدة لكل clone).
# تشغيل من جذر المشروع:  powershell -File scripts/install-hooks.ps1

$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$hooks = Join-Path $root 'scripts\git-hooks'
if (-not (Test-Path $hooks)) {
    Write-Error "Not found: $hooks"
    exit 1
}
# Git يقبل مساراً بشرطات مائلة
$hooksGit = $hooks.Replace('\', '/')
Push-Location $root
try {
    git config core.hooksPath $hooksGit
    Write-Host "OK: core.hooksPath = $hooksGit" -ForegroundColor Green
    Write-Host "pre-commit سيشغّل scripts/verify-php-utf8.sh عند كل commit (إن وُجد xxd في PATH)." -ForegroundColor Cyan
}
finally {
    Pop-Location
}
