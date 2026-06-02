# Parse orange_db.sql INSERT rows — detect id gaps (first column = id)
param(
    [string]$SqlPath = "D:\orange\scripts\orange_db.sql"
)

$tables = @{}
$currentTable = $null
$aiNext = @{}

Get-Content -LiteralPath $SqlPath -Encoding UTF8 | ForEach-Object {
    $line = $_
    if ($line -match 'ALTER TABLE `([^`]+)`\s+MODIFY.*AUTO_INCREMENT=(\d+)') {
        $aiNext[$Matches[1]] = [int]$Matches[2]
    }
    if ($line -match '^INSERT INTO `([^`]+)`') {
        $currentTable = $Matches[1]
        if (-not $tables.ContainsKey($currentTable)) {
            $tables[$currentTable] = [System.Collections.Generic.List[int]]::new()
        }
    }
    if ($null -ne $currentTable -and $line -match '\((\d+),') {
        $tables[$currentTable].Add([int]$Matches[1])
    }
    if ($line -eq ';' -or ($line.TrimEnd() -match '\);$')) {
        # keep currentTable until next INSERT
    }
}

$results = foreach ($name in ($tables.Keys | Sort-Object)) {
    $ids = $tables[$name] | Sort-Object -Unique
    if ($ids.Count -eq 0) { continue }
    $min = $ids[0]
    $max = $ids[-1]
    $count = $ids.Count
    $expected = $max - $min + 1
    $gaps = $expected - $count
    $missing = @()
    if ($gaps -gt 0 -and $gaps -le 30) {
        for ($i = $min; $i -le $max; $i++) {
            if ($ids -notcontains $i) { $missing += $i }
        }
    }
    $startsAt1 = ($min -eq 1)
    $ai = $aiNext[$name]
    [PSCustomObject]@{
        Table       = $name
        Rows        = $count
        MinId       = $min
        MaxId       = $max
        GapCount    = $gaps
        StartsAt1   = $startsAt1
        AutoIncNext = $ai
        MissingSample = if ($missing.Count -gt 0) { ($missing | Select-Object -First 15) -join ',' } else { '' }
    }
}

$withGaps = $results | Where-Object { $_.GapCount -gt 0 } | Sort-Object GapCount -Descending
$notFrom1 = $results | Where-Object { -not $_.StartsAt1 -and $_.Rows -gt 0 }
$highAi = $results | Where-Object { $_.AutoIncNext -and ($_.AutoIncNext -gt $_.MaxId + 5) }

Write-Output "=== Tables with ID gaps (missing numbers in sequence) ==="
$withGaps | Format-Table -AutoSize

Write-Output "`n=== Tables where MIN(id) is not 1 ==="
$notFrom1 | Format-Table -AutoSize

Write-Output "`n=== AUTO_INCREMENT much higher than MAX(id) (next insert jumps) ==="
$highAi | Format-Table -AutoSize

Write-Output "`nSummary: $($results.Count) tables with INSERT data | $($withGaps.Count) with gaps | $($notFrom1.Count) not starting at 1"
