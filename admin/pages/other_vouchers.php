<?php

declare(strict_types=1);

$jvPageEntryType = 'other_voucher';
$jvPageTitle = 'سندات أخرى';
$jvPageCardTitle = 'سندات أخرى';
$jvSearchModalTitle = 'بحث في سندات أخرى';
$jvDeepLoadVoucherId = max(0, (int) ($_GET['voucher_id'] ?? 0));
$jvDeepLoadJournalTypeId = max(0, (int) ($_GET['journal_type_id'] ?? 0));

require __DIR__ . '/journal_voucher_screen.php';
