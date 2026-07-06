#Requires -Version 5.1
<#
.SYNOPSIS
    Orange Shop Pro — database + uploads backup (Windows / Plesk / MariaDB).

.DESCRIPTION
    Creates a timestamped, compressed backup of the MySQL/MariaDB database and the
    uploads/ directory. Writes a manifest and log. Applies retention only after a
    fully successful run. Does not restore.

    Reads DB_HOST and DB_NAME from config.php and DB_USER / DB_PASS from .env.php
    via PHP CLI when available; falls back to parsing those files as text.

.PARAMETER ProjectRoot
    Orange project root (folder containing config.php). Defaults to two levels above
    this script (repository root when run from scripts/backup/).

.PARAMETER BackupRoot
    Root folder for all backup artifacts. Defaults to D:\orange_backups (sibling of
    D:\orange per owner convention). Nothing outside this path is ever deleted.

.PARAMETER MysqldumpPath
    Full path to mysqldump.exe. When empty, common MariaDB/MySQL/Plesk paths are tried.

.PARAMETER RetentionDaily
    Keep backups from the last N calendar days (default 7).

.PARAMETER RetentionWeekly
    Additionally keep the newest backup in each of the last N ISO weeks (default 4).

.PARAMETER RetentionMonthly
    Additionally keep the newest backup in each of the last N calendar months (default 6).
#>
[CmdletBinding()]
param(
    [string]$ProjectRoot = '',
    [string]$BackupRoot = '',
    [string]$MysqldumpPath = '',
    [int]$RetentionDaily = 7,
    [int]$RetentionWeekly = 4,
    [int]$RetentionMonthly = 6
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Write-Log {
    param(
        [string]$Message,
        [ValidateSet('INFO', 'WARN', 'ERROR')]
        [string]$Level = 'INFO'
    )

    $line = '[{0}] [{1}] {2}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Level, $Message
    Write-Host $line
    if ($script:LogFilePath) {
        Add-Content -LiteralPath $script:LogFilePath -Value $line -Encoding UTF8
    }
}

function Resolve-ExistingDirectory {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path,
        [Parameter(Mandatory = $true)]
        [string]$Label
    )

    if ([string]::IsNullOrWhiteSpace($Path)) {
        throw "$Label is required."
    }

    $resolved = [System.IO.Path]::GetFullPath($Path)
    if (-not (Test-Path -LiteralPath $resolved -PathType Container)) {
        throw "$Label does not exist or is not a directory: $resolved"
    }

    return $resolved
}

function Resolve-BackupRootInside {
    param(
        [Parameter(Mandatory = $true)]
        [string]$BackupRoot,
        [Parameter(Mandatory = $true)]
        [string]$RelativePath
    )

    $target = [System.IO.Path]::GetFullPath((Join-Path $BackupRoot $RelativePath))
    $rootNorm = [System.IO.Path]::GetFullPath($BackupRoot)
    if (-not $target.StartsWith($rootNorm, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing path outside BackupRoot: $target"
    }

    return $target
}

function Get-PhpExecutable {
    $php = Get-Command php -ErrorAction SilentlyContinue
    if ($php) {
        return $php.Source
    }

    $candidates = @(
        'C:\Program Files\PHP\php.exe',
        'C:\Program Files (x86)\PHP\php.exe',
        'C:\plesk\php\8.2\php.exe',
        'C:\plesk\php\8.3\php.exe'
    )

    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate) {
            return $candidate
        }
    }

    return $null
}

function Read-OrangeDbSettings {
    param(
        [Parameter(Mandatory = $true)]
        [string]$ProjectRoot
    )

    $configPath = Join-Path $ProjectRoot 'config.php'
    $envPath = Join-Path $ProjectRoot '.env.php'

    if (-not (Test-Path -LiteralPath $configPath)) {
        throw "Missing config.php at $configPath"
    }
    if (-not (Test-Path -LiteralPath $envPath)) {
        throw "Missing .env.php at $envPath — create it on the server before running backups."
    }

    $phpExe = Get-PhpExecutable
    if ($phpExe) {
        $phpSnippet = @'
declare(strict_types=1);
$root = getenv('ORANGE_BACKUP_PROJECT_ROOT');
if ($root === false || $root === '') {
    fwrite(STDERR, "ORANGE_BACKUP_PROJECT_ROOT not set\n");
    exit(2);
}
chdir($root);
$envPath = $root . DIRECTORY_SEPARATOR . '.env.php';
if (!is_file($envPath)) {
    fwrite(STDERR, "Missing .env.php\n");
    exit(2);
}
$env = require $envPath;
if (!is_array($env)) {
    $env = [];
}
require $root . DIRECTORY_SEPARATOR . 'config.php';
echo json_encode([
    'host' => DB_HOST,
    'name' => DB_NAME,
    'user' => DB_USER,
    'pass' => DB_PASS,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
'@

        $prev = $env:ORANGE_BACKUP_PROJECT_ROOT
        $env:ORANGE_BACKUP_PROJECT_ROOT = $ProjectRoot
        try {
            $json = & $phpExe -r $phpSnippet 2>&1
            if ($LASTEXITCODE -ne 0) {
                throw "PHP failed to read DB settings: $json"
            }
            $parsed = $json | ConvertFrom-Json
            if ([string]::IsNullOrWhiteSpace($parsed.user)) {
                throw 'DB_USER is empty after reading config.php / .env.php.'
            }
            return [pscustomobject]@{
                Host = [string]$parsed.host
                Name = [string]$parsed.name
                User = [string]$parsed.user
                Pass = [string]$parsed.pass
            }
        }
        finally {
            if ($null -eq $prev) {
                Remove-Item Env:ORANGE_BACKUP_PROJECT_ROOT -ErrorAction SilentlyContinue
            }
            else {
                $env:ORANGE_BACKUP_PROJECT_ROOT = $prev
            }
        }
    }

    Write-Log 'PHP CLI not found; parsing config.php and .env.php as text.' 'WARN'

    $configText = Get-Content -LiteralPath $configPath -Raw -Encoding UTF8
    $envText = Get-Content -LiteralPath $envPath -Raw -Encoding UTF8

    $hostValue = 'localhost'
    $nameValue = 'orange_db'
    if ($configText -match "const\s+DB_HOST\s*=\s*'([^']*)'") {
        $hostValue = $Matches[1]
    }
    elseif ($configText -match 'const\s+DB_HOST\s*=\s*"([^"]*)"') {
        $hostValue = $Matches[1]
    }
    if ($configText -match "const\s+DB_NAME\s*=\s*'([^']*)'") {
        $nameValue = $Matches[1]
    }
    elseif ($configText -match 'const\s+DB_NAME\s*=\s*"([^"]*)"') {
        $nameValue = $Matches[1]
    }

    $userValue = $null
    $passValue = $null
    if ($envText -match "'DB_USER'\s*=>\s*'((?:\\'|[^'])*)'") {
        $userValue = $Matches[1] -replace "\\'", "'"
    }
    elseif ($envText -match '"DB_USER"\s*=>\s*"((?:\\"|[^"])*)"') {
        $userValue = $Matches[1] -replace '\\"', '"'
    }
    if ($envText -match "'DB_PASS'\s*=>\s*'((?:\\'|[^'])*)'") {
        $passValue = $Matches[1] -replace "\\'", "'"
    }
    elseif ($envText -match '"DB_PASS"\s*=>\s*"((?:\\"|[^"])*)"') {
        $passValue = $Matches[1] -replace '\\"', '"'
    }

    if ([string]::IsNullOrWhiteSpace($userValue)) {
        throw 'Could not parse DB_USER from .env.php and PHP CLI is unavailable.'
    }
    if ($null -eq $passValue) {
        $passValue = ''
    }

    return [pscustomobject]@{
        Host = $hostValue
        Name = $nameValue
        User = $userValue
        Pass = [string]$passValue
    }
}

function Resolve-MysqldumpPath {
    param([string]$ExplicitPath)

    if (-not [string]::IsNullOrWhiteSpace($ExplicitPath)) {
        if (-not (Test-Path -LiteralPath $ExplicitPath)) {
            throw "MysqldumpPath not found: $ExplicitPath"
        }
        return [System.IO.Path]::GetFullPath($ExplicitPath)
    }

    $cmd = Get-Command mysqldump -ErrorAction SilentlyContinue
    if ($cmd) {
        return $cmd.Source
    }

    $candidates = @(
        'C:\Program Files\MariaDB 11.4\bin\mysqldump.exe',
        'C:\Program Files\MariaDB 11.3\bin\mysqldump.exe',
        'C:\Program Files\MariaDB 10.11\bin\mysqldump.exe',
        'C:\Program Files\MariaDB 10.6\bin\mysqldump.exe',
        'C:\Program Files\MariaDB\bin\mysqldump.exe',
        'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe',
        'C:\Program Files\MySQL\MySQL Server 5.7\bin\mysqldump.exe'
    )

    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate) {
            return $candidate
        }
    }

    throw 'mysqldump.exe not found. Pass -MysqldumpPath explicitly.'
}

function New-TemporaryClientDefaultsFile {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Directory,
        [Parameter(Mandatory = $true)]
        [string]$HostName,
        [Parameter(Mandatory = $true)]
        [string]$User,
        [Parameter(Mandatory = $true)]
        [string]$Password
    )

    $file = Join-Path $Directory ('mysqldump-client-{0}.cnf' -f ([guid]::NewGuid().ToString('N')))
    $escapedPassword = $Password -replace '\\', '\\\\' -replace '"', '\"'
    @"
[client]
host=$HostName
user=$User
password="$escapedPassword"
"@ | Set-Content -LiteralPath $file -Encoding ASCII -NoNewline
    Add-Content -LiteralPath $file -Value '' -Encoding ASCII
    return $file
}

function Compress-FileToGzip {
    param(
        [Parameter(Mandatory = $true)]
        [string]$SourceFile,
        [Parameter(Mandatory = $true)]
        [string]$DestinationFile
    )

    $sourceStream = [System.IO.File]::OpenRead($SourceFile)
    try {
        $destStream = [System.IO.File]::Create($DestinationFile)
        try {
            $gzip = New-Object System.IO.Compression.GzipStream($destStream, [System.IO.Compression.CompressionMode]::Compress)
            try {
                $sourceStream.CopyTo($gzip)
            }
            finally {
                $gzip.Dispose()
            }
        }
        finally {
            $destStream.Dispose()
        }
    }
    finally {
        $sourceStream.Dispose()
    }
}

function Get-GitCommitHash {
    param([string]$ProjectRoot)

    $git = Get-Command git -ErrorAction SilentlyContinue
    if (-not $git) {
        return $null
    }

    try {
        $hash = & git -C $ProjectRoot rev-parse --short HEAD 2>$null
        if ($LASTEXITCODE -eq 0 -and -not [string]::IsNullOrWhiteSpace($hash)) {
            return $hash.Trim()
        }
    }
    catch {
        return $null
    }

    return $null
}

function Get-SnapshotFolderName {
    return (Get-Date -Format 'yyyy-MM-dd_HHmmss')
}

function Invoke-RetentionCleanup {
    param(
        [Parameter(Mandatory = $true)]
        [string]$BackupRoot,
        [Parameter(Mandatory = $true)]
        [string]$SnapshotsDir,
        [Parameter(Mandatory = $true)]
        [string]$CurrentSnapshotName,
        [int]$RetentionDaily,
        [int]$RetentionWeekly,
        [int]$RetentionMonthly
    )

    $allDirs = Get-ChildItem -LiteralPath $SnapshotsDir -Directory -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -match '^\d{4}-\d{2}-\d{2}_\d{6}$' }

    if (-not $allDirs -or $allDirs.Count -eq 0) {
        Write-Log 'Retention: no prior snapshots to evaluate.'
        return
    }

    $keep = New-Object 'System.Collections.Generic.HashSet[string]' ([System.StringComparer]::OrdinalIgnoreCase)
    [void]$keep.Add($CurrentSnapshotName)

    $now = Get-Date
    $dailyCutoff = $now.Date.AddDays(-1 * [Math]::Max(1, $RetentionDaily))
    foreach ($dir in $allDirs) {
        if ($dir.LastWriteTime -ge $dailyCutoff) {
            [void]$keep.Add($dir.Name)
        }
    }

    for ($weekOffset = 0; $weekOffset -lt [Math]::Max(1, $RetentionWeekly); $weekOffset++) {
        $weekStart = $now.Date.AddDays(-7 * $weekOffset)
        while ($weekStart.DayOfWeek -ne [DayOfWeek]::Monday) {
            $weekStart = $weekStart.AddDays(-1)
        }
        $weekEnd = $weekStart.AddDays(7)
        $newest = $allDirs |
            Where-Object { $_.LastWriteTime -ge $weekStart -and $_.LastWriteTime -lt $weekEnd } |
            Sort-Object LastWriteTime -Descending |
            Select-Object -First 1
        if ($newest) {
            [void]$keep.Add($newest.Name)
        }
    }

    for ($monthOffset = 0; $monthOffset -lt [Math]::Max(1, $RetentionMonthly); $monthOffset++) {
        $monthStart = New-Object DateTime($now.Year, $now.Month, 1).AddMonths(-1 * $monthOffset)
        $monthEnd = $monthStart.AddMonths(1)
        $newest = $allDirs |
            Where-Object { $_.LastWriteTime -ge $monthStart -and $_.LastWriteTime -lt $monthEnd } |
            Sort-Object LastWriteTime -Descending |
            Select-Object -First 1
        if ($newest) {
            [void]$keep.Add($newest.Name)
        }
    }

    foreach ($dir in $allDirs) {
        if ($keep.Contains($dir.Name)) {
            continue
        }

        $fullPath = $dir.FullName
        $rootNorm = [System.IO.Path]::GetFullPath($BackupRoot)
        if (-not $fullPath.StartsWith($rootNorm, [System.StringComparison]::OrdinalIgnoreCase)) {
            throw "Retention safety check failed; refusing to delete: $fullPath"
        }

        Write-Log ("Retention: removing expired snapshot {0}" -f $dir.Name)
        Remove-Item -LiteralPath $fullPath -Recurse -Force
    }

    Write-Log ("Retention complete. Protected snapshots: {0}" -f $keep.Count)
}

$exitCode = 0
$tempWorkDir = $null
$clientDefaultsFile = $null

try {
    if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
        $ProjectRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
    }
    if ([string]::IsNullOrWhiteSpace($BackupRoot)) {
        $parentDrive = Split-Path $ProjectRoot -Qualifier
        if ($parentDrive) {
            $BackupRoot = Join-Path $parentDrive 'orange_backups'
        }
        else {
            $BackupRoot = Join-Path (Split-Path $ProjectRoot -Parent) 'orange_backups'
        }
    }

    $ProjectRoot = Resolve-ExistingDirectory -Path $ProjectRoot -Label 'ProjectRoot'
    $BackupRoot = Resolve-ExistingDirectory -Path $BackupRoot -Label 'BackupRoot'

    $logsDir = Resolve-BackupRootInside -BackupRoot $BackupRoot -RelativePath 'logs'
    $snapshotsDir = Resolve-BackupRootInside -BackupRoot $BackupRoot -RelativePath 'snapshots'
    New-Item -ItemType Directory -Force -Path $logsDir | Out-Null
    New-Item -ItemType Directory -Force -Path $snapshotsDir | Out-Null

    $script:LogFilePath = Join-Path $logsDir ('orange_backup_{0}.log' -f (Get-Date -Format 'yyyyMMdd_HHmmss'))
    Write-Log 'Orange backup started.'
    Write-Log ("ProjectRoot={0}" -f $ProjectRoot)
    Write-Log ("BackupRoot={0}" -f $BackupRoot)

    $db = Read-OrangeDbSettings -ProjectRoot $ProjectRoot
    Write-Log ("Database target: host={0} name={1} user={2}" -f $db.Host, $db.Name, $db.User)

    $uploadsDir = Join-Path $ProjectRoot 'uploads'
    if (-not (Test-Path -LiteralPath $uploadsDir -PathType Container)) {
        throw "uploads directory not found: $uploadsDir"
    }

    $mysqldumpExe = Resolve-MysqldumpPath -ExplicitPath $MysqldumpPath
    Write-Log ("Using mysqldump: {0}" -f $mysqldumpExe)

    $snapshotName = Get-SnapshotFolderName
    $finalSnapshotDir = Join-Path $snapshotsDir $snapshotName
    if (Test-Path -LiteralPath $finalSnapshotDir) {
        throw "Snapshot directory already exists: $finalSnapshotDir"
    }

    $tempWorkDir = Join-Path $snapshotsDir ('._work_{0}' -f $snapshotName)
    if (Test-Path -LiteralPath $tempWorkDir) {
        Remove-Item -LiteralPath $tempWorkDir -Recurse -Force
    }
    New-Item -ItemType Directory -Force -Path $tempWorkDir | Out-Null

    $rawSqlFile = Join-Path $tempWorkDir ('{0}.sql' -f $db.Name)
    $dumpFileName = '{0}.sql.gz' -f $db.Name
    $compressedDumpFile = Join-Path $tempWorkDir $dumpFileName
    $uploadsArchiveName = 'uploads.zip'
    $uploadsArchiveFile = Join-Path $tempWorkDir $uploadsArchiveName

    $clientDefaultsFile = New-TemporaryClientDefaultsFile -Directory $tempWorkDir -HostName $db.Host -User $db.User -Password $db.Pass
    Write-Log 'Running mysqldump (this may take several minutes)...'

    $dumpArgs = @(
        "--defaults-extra-file=$clientDefaultsFile",
        '--single-transaction',
        '--routines',
        '--triggers',
        '--events',
        '--hex-blob',
        '--default-character-set=utf8mb4',
        '--result-file=' + $rawSqlFile,
        $db.Name
    )

    $dumpOutput = & $mysqldumpExe @dumpArgs 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "mysqldump failed (exit $LASTEXITCODE): $dumpOutput"
    }
    if (-not (Test-Path -LiteralPath $rawSqlFile)) {
        throw "mysqldump did not create dump file: $rawSqlFile"
    }
    $rawSize = (Get-Item -LiteralPath $rawSqlFile).Length
    if ($rawSize -lt 64) {
        throw "mysqldump output is unexpectedly small ($rawSize bytes): $rawSqlFile"
    }

    Write-Log ("mysqldump OK ({0} bytes uncompressed)" -f $rawSize)
    Compress-FileToGzip -SourceFile $rawSqlFile -DestinationFile $compressedDumpFile
    Remove-Item -LiteralPath $rawSqlFile -Force
    Write-Log ("Compressed database dump -> {0}" -f $dumpFileName)

    Write-Log 'Archiving uploads directory...'
    Compress-Archive -LiteralPath $uploadsDir -DestinationPath $uploadsArchiveFile -CompressionLevel Optimal -Force
    if (-not (Test-Path -LiteralPath $uploadsArchiveFile)) {
        throw "Failed to create uploads archive: $uploadsArchiveFile"
    }
    Write-Log ("Uploads archive OK -> {0}" -f $uploadsArchiveName)

    $gitCommit = Get-GitCommitHash -ProjectRoot $ProjectRoot
    $timestampIso = (Get-Date).ToString('yyyy-MM-ddTHH:mm:ssK')
    $manifest = [ordered]@{
        timestamp         = $timestampIso
        project_root      = $ProjectRoot
        db_name           = $db.Name
        dump_file         = $dumpFileName
        uploads_archive   = $uploadsArchiveName
        git_commit        = $gitCommit
        backup_script     = 'scripts/backup/orange_backup.ps1'
        retention_daily   = $RetentionDaily
        retention_weekly  = $RetentionWeekly
        retention_monthly = $RetentionMonthly
    }
    $manifestPath = Join-Path $tempWorkDir 'manifest.json'
    $manifest | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $manifestPath -Encoding UTF8
    Write-Log 'Manifest written.'

    Rename-Item -LiteralPath $tempWorkDir -NewName $snapshotName
    $tempWorkDir = $null
    $finalSnapshotDir = Join-Path $snapshotsDir $snapshotName
    Write-Log ("Backup snapshot ready: {0}" -f $finalSnapshotDir)

    Invoke-RetentionCleanup -BackupRoot $BackupRoot -SnapshotsDir $snapshotsDir -CurrentSnapshotName $snapshotName `
        -RetentionDaily $RetentionDaily -RetentionWeekly $RetentionWeekly -RetentionMonthly $RetentionMonthly

    Write-Log 'Orange backup completed successfully.'
}
catch {
    $exitCode = 1
    $message = $_.Exception.Message
    if ($script:LogFilePath) {
        Write-Log $message 'ERROR'
        if ($_.ScriptStackTrace) {
            Write-Log $_.ScriptStackTrace 'ERROR'
        }
    }
    else {
        Write-Error $message
    }

    if ($tempWorkDir -and (Test-Path -LiteralPath $tempWorkDir)) {
        try {
            Remove-Item -LiteralPath $tempWorkDir -Recurse -Force
            if ($script:LogFilePath) {
                Write-Log 'Removed incomplete temporary backup workspace.' 'WARN'
            }
        }
        catch {
            if ($script:LogFilePath) {
                Write-Log ('Failed to remove temporary workspace: {0}' -f $_.Exception.Message) 'WARN'
            }
        }
    }
}
finally {
    if ($clientDefaultsFile -and (Test-Path -LiteralPath $clientDefaultsFile)) {
        Remove-Item -LiteralPath $clientDefaultsFile -Force -ErrorAction SilentlyContinue
    }
}

exit $exitCode
