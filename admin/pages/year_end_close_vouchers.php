<?php

declare(strict_types=1);

$jvPageEntryType = 'year_end_close';
$jvPageTitle = 'قيود الإقفال السنوية';
$jvPageCardTitle = 'سند إقفال سنة مالية';
$jvSearchModalTitle = 'بحث في قيود الإقفال السنوية';
$jvYecMode = true;
$jvYecFiscalYearId = (int) ($_GET['fy_id'] ?? 0);
$jvYecLoadVoucherId = (int) ($_GET['id'] ?? 0);

require __DIR__ . '/journal_voucher_screen.php';
