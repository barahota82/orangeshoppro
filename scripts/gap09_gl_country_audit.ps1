# GAP-09 GL country scope audit (PowerShell)
$root = 'd:\orange'
$patterns = @(
    [regex]'\b(FROM|JOIN)\s+journal_vouchers\b',
    [regex]'\b(FROM|JOIN)\s+accounts\b'
)
$okSignals = @(
    'country_id', 'orange_gl_voucher_country_bind', 'orange_accounts_fetch',
    'orange_accounts_sql_country_filter', 'orange_admin_settings_effective_country_id',
    'orange_admin_assert_entity_country', 'orange_accounts_account_is_posting_leaf',
    'orange_accounts_filter', 'orange_accounts_count_posting_leaves',
    'orange_accounts_posting_leaf', 'orange_gl_pending_row_visible_for_country',
    'orange_fy_pl_summary', 'orange_accounts_fy_pl_summary',
    'orange_voucher_by_reference', 'orange_gl_voucher_next_id_preview',
    'ctxCountryId', 'glCountryId', '$countryId',
    'WHERE id =', 'WHERE a.id =', 'WHERE jv.id =',
    'fiscal_year', 'orange_fiscal_', 'per country', 'scoped'
)
$maintScopedSignals = @(
    '@country_id', '@cid', '@has_acct_country', '@has_jv_country',
    'country_id = ', 'jv.country_id', 'a.country_id = c.id', 'c.country_id = a.country_id',
    'post-v52', 'per country', 'SIGNAL SQLSTATE'
)

$detail = New-Object System.Collections.Generic.List[object]
$byFile = @{}

Get-ChildItem -Path $root -Recurse -Include *.php, *.sql -File | Where-Object {
    $rel = $_.FullName.Substring($root.Length + 1).Replace('\', '/')
    $rel -notmatch '^(vendor/|\.git/|\.pl_extract|ref_extract|\.tmp_|_compare_)' -and
        $rel -ne 'scripts/gap09_gl_country_audit.php' -and
        $rel -ne 'scripts/gap09_gl_country_audit.ps1'
} | ForEach-Object {
    $rel = $_.FullName.Substring($root.Length + 1).Replace('\', '/')
    $lines = Get-Content -LiteralPath $_.FullName -Encoding UTF8
    $fileText = $lines -join [Environment]::NewLine
    $isMaintPath = $rel -match '^scripts/(migrations/|maintenance_|mysql-)'
    $isMaintScoped = $false
    if ($isMaintPath) {
        foreach ($sig in $maintScopedSignals) {
            if ($fileText -like "*$sig*") {
                $isMaintScoped = $true
                break
            }
        }
    }
    for ($i = 0; $i -lt $lines.Count; $i++) {
        $line = $lines[$i]
        $isJv = $patterns[0].IsMatch($line)
        $isAcc = $patterns[1].IsMatch($line)
        if (-not $isJv -and -not $isAcc) { continue }

        $start = [Math]::Max(0, $i - 12)
        $end = [Math]::Min($lines.Count - 1, $i + 12)
        $ctx = ($lines[$start..$end] -join [Environment]::NewLine)

        $risk = 'LOW'
        $note = 'Review — entity-scoped or legacy path'

        if ($isMaintPath) {
            if ($isMaintScoped) {
                $risk = 'OK'
                $note = 'MAINT script — country scoped (manual run only)'
            } else {
                $risk = 'MAINT'
                $note = 'MAINT script — review country scope'
            }
        }
        elseif ($rel -match 'includes/countries\.php|includes/country_provision\.php') {
            $risk = 'OK'
            $note = 'Explicit country filter or wrapper'
        }
        else {
            foreach ($sig in $okSignals) {
                if ($ctx -like "*$sig*") {
                    $risk = 'OK'
                    $note = 'Scoped signal in context window'
                    break
                }
            }
            if ($risk -eq 'LOW' -and $ctx -match 'CountryBind|countryBind|JvCountryBind|glCountryId|ctxCountryId') {
                $risk = 'OK'
                $note = 'country bind variable in context'
            }
            if ($risk -eq 'LOW' -and (($lines -join [Environment]::NewLine) -match 'orange_gl_voucher_country_bind|orange_admin_context_country_id|orange_admin_settings_effective_country_id|orange_accounts_sql_country_filter|orange_accounts_fetch\s*\(\s*\$pdo[^)]*\$[a-zA-Z]*[Cc]ountry')) {
                $risk = 'OK'
                $note = 'File-level scoped GL helper'
            }
            if ($risk -eq 'LOW' -and $ctx -match 'WHERE\s+\w+\.id\s+IN\s*\(') {
                $risk = 'OK'
                $note = 'BY id IN list (caller-scoped)'
            }
        }

        $table = if ($isJv) { 'journal_vouchers' } else { 'accounts' }
        $snippet = ($line.Trim() -replace '\s+', ' ')
        $detail.Add([pscustomobject]@{ file = $rel; line = $i + 1; table = $table; risk = $risk; snippet = $snippet })

        if (-not $byFile.ContainsKey($rel)) {
            $byFile[$rel] = @{ hits = 0; jv = 0; acc = 0; worst = 'OK'; note = '' }
        }
        $byFile[$rel].hits++
        if ($isJv) { $byFile[$rel].jv++ }
        if ($isAcc) { $byFile[$rel].acc++ }
        $rank = @{ OK = 0; LOW = 1; MAINT = 2; HIGH = 3 }
        if ($rank[$risk] -gt $rank[$byFile[$rel].worst]) {
            $byFile[$rel].worst = $risk
            $byFile[$rel].note = $note
        }
    }
}

$date = Get-Date -Format 'yyyy-MM-dd'
$exportDir = Join-Path $root 'docs/exports'
New-Item -ItemType Directory -Force -Path $exportDir | Out-Null

$detailPath = Join-Path $exportDir "GAP09_GL_country_audit_detail_$date.csv"
$byFilePath = Join-Path $exportDir "GAP09_GL_country_audit_by_file_$date.csv"
$summaryPath = Join-Path $exportDir "GAP09_GL_country_audit_summary_$date.csv"

$detail | Export-Csv -LiteralPath $detailPath -NoTypeInformation -Encoding UTF8
$byFile.GetEnumerator() | Sort-Object Name | ForEach-Object {
    [pscustomobject]@{
        File      = $_.Key
        Hits      = $_.Value.hits
        JV        = $_.Value.jv
        Acc       = $_.Value.acc
        WorstRisk = $_.Value.worst
        Note      = $_.Value.note
    }
} | Export-Csv -LiteralPath $byFilePath -NoTypeInformation -Encoding UTF8

$ok = @($detail | Where-Object { $_.risk -eq 'OK' }).Count
$low = @($detail | Where-Object { $_.risk -eq 'LOW' }).Count
$maint = @($detail | Where-Object { $_.risk -eq 'MAINT' }).Count
$high = @($detail | Where-Object { $_.risk -eq 'HIGH' }).Count
$jv = @($detail | Where-Object { $_.table -eq 'journal_vouchers' }).Count
$acc = @($detail | Where-Object { $_.table -eq 'accounts' }).Count

@(
    [pscustomobject]@{ Metric = 'Scan_date'; Value = $date },
    [pscustomobject]@{ Metric = 'Total_SQL_hits'; Value = $detail.Count },
    [pscustomobject]@{ Metric = 'Unique_files'; Value = $byFile.Count },
    [pscustomobject]@{ Metric = 'journal_vouchers_hits'; Value = $jv },
    [pscustomobject]@{ Metric = 'accounts_hits'; Value = $acc },
    [pscustomobject]@{ Metric = 'Risk_OK'; Value = $ok },
    [pscustomobject]@{ Metric = 'Risk_LOW'; Value = $low },
    [pscustomobject]@{ Metric = 'Risk_MAINT'; Value = $maint },
    [pscustomobject]@{ Metric = 'Risk_HIGH'; Value = $high }
) | Export-Csv -LiteralPath $summaryPath -NoTypeInformation -Encoding UTF8

Write-Host "GAP-09 audit $date"
Write-Host "Hits $($detail.Count)  Files $($byFile.Count)  OK $ok  LOW $low  MAINT $maint  HIGH $high"
Write-Host "LOW files:"
$byFile.GetEnumerator() | Where-Object { $_.Value.worst -eq 'LOW' } | Sort-Object Name | ForEach-Object {
    Write-Host "  $($_.Key) ($($_.Value.hits))"
}
