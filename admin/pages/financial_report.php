<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/accounting_report_mapping.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/financial_report_breakdown.php';
require_once __DIR__ . '/../../includes/supplier_payable_account.php';
require_once __DIR__ . '/../../includes/countries.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$frCountryBind = orange_gl_voucher_country_bind($pdo, 'jv');

$years = orange_fiscal_years_list($pdo);
$fyId = isset($_GET['fy']) ? (int)$_GET['fy'] : 0;
if ($fyId <= 0 && $years !== []) {
    $fyId = (int)$years[0]['id'];
}

$fyRow = null;
foreach ($years as $y) {
    if ((int)$y['id'] === $fyId) {
        $fyRow = $y;
        break;
    }
}

$acctCountryFilter = orange_accounts_sql_country_filter($pdo, '');
$acctSql = 'SELECT id, name, code FROM accounts WHERE 1=1';
$acctParams = [];
if ($acctCountryFilter !== null) {
    $acctSql .= $acctCountryFilter['sql'];
    $acctParams = $acctCountryFilter['params'];
}
$acctSql .= ' ORDER BY COALESCE(code, \'\'), name';
if ($acctParams !== []) {
    $acctSt = $pdo->prepare($acctSql);
    $acctSt->execute($acctParams);
    $accounts = $acctSt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $accounts = $pdo->query($acctSql)->fetchAll(PDO::FETCH_ASSOC);
}
$accountLabelById = [];
foreach ($accounts as $a) {
    $aid = (int) $a['id'];
    $accountLabelById[$aid] = (trim((string) ($a['code'] ?? '')) !== '' ? $a['code'] . ' — ' : '') . $a['name'];
}
$mapById = orange_accounts_report_mapping_by_ids($pdo, array_map(static fn (array $x): int => (int) $x['id'], $accounts));

$useVouchers = orange_journal_vouchers_ready($pdo);

$frPostingLeafWhere = orange_accounts_posting_leaf_where_sql($pdo, 'a');
$financialReportPostingLeafCt = 0;
try {
    $frCountSql = "SELECT COUNT(*) FROM accounts a WHERE $frPostingLeafWhere";
    $frCountParams = [];
    $frAcctFilter = orange_accounts_sql_country_filter($pdo, 'a');
    if ($frAcctFilter !== null) {
        $frCountSql .= $frAcctFilter['sql'];
        $frCountParams = $frAcctFilter['params'];
    }
    if ($frCountParams !== []) {
        $frCountSt = $pdo->prepare($frCountSql);
        $frCountSt->execute($frCountParams);
        $financialReportPostingLeafCt = (int) $frCountSt->fetchColumn();
    } else {
        $financialReportPostingLeafCt = (int) $pdo->query($frCountSql)->fetchColumn();
    }
} catch (Throwable $e) {
    $financialReportPostingLeafCt = 0;
}

$stmtAccountId = isset($_GET['account']) ? (int) $_GET['account'] : 0;
$statementRows = [];
$statementAccLabel = '';
$statementClosing = 0.0;
$doAutoPrint = isset($_GET['print']) && (string) $_GET['print'] === '1';

if ($stmtAccountId > 0 && $useVouchers && $fyId > 0) {
    foreach ($accounts as $a) {
        if ((int) $a['id'] === $stmtAccountId) {
            $statementAccLabel = (trim((string) ($a['code'] ?? '')) !== '' ? $a['code'] . ' — ' : '') . $a['name'];
            break;
        }
    }
    $stL = $pdo->prepare(
        'SELECT jl.debit, jl.credit, jl.memo, jl.line_no, jv.voucher_date, jv.reference, jv.description, jv.id AS voucher_id
         FROM journal_lines jl
         INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id
         WHERE jl.account_id = ? AND jv.fiscal_year_id = ?' . $frCountryBind['sql'] . '
         ORDER BY jv.voucher_date ASC, jv.id ASC, jl.line_no ASC'
    );
    $stL->execute(array_merge([$stmtAccountId, $fyId], $frCountryBind['params']));
    $bal = 0.0;
    foreach ($stL->fetchAll(PDO::FETCH_ASSOC) as $ln) {
        $d = (float) $ln['debit'];
        $c = (float) $ln['credit'];
        $bal += ($d - $c);
        $ln['balance'] = $bal;
        $statementRows[] = $ln;
    }
    $statementClosing = $bal;
}

$tbAll = [];
$sumDebit = 0.0;
$sumCredit = 0.0;
$rows = [];

if ($useVouchers && $fyId > 0) {
    $tbAll = orange_voucher_account_totals($pdo, $fyId, []);
    foreach ($accounts as $a) {
        $aid = (int)$a['id'];
        $t = $tbAll[$aid] ?? ['debit' => 0.0, 'credit' => 0.0];
        $deb = (float)$t['debit'];
        $cred = (float)$t['credit'];
        if ($deb > 0.0001 || $cred > 0.0001) {
            $label = (trim((string)($a['code'] ?? '')) !== '' ? $a['code'] . ' — ' : '') . $a['name'];
            $code = trim((string) ($a['code'] ?? ''));
            $rows[] = array_merge([
                'account_id' => $aid,
                'code' => $code,
                'label' => $label,
                'debit' => $deb,
                'credit' => $cred,
                'net' => $deb - $cred,
            ], orange_accounts_report_display_and_sort_meta($mapById[$aid] ?? null));
            $sumDebit += $deb;
            $sumCredit += $cred;
        }
    }
    if ($rows !== []) {
        usort($rows, 'orange_accounts_report_tb_rows_compare');
    }
} elseif ($fyId > 0 && orange_table_has_column($pdo, 'journal_entries', 'fiscal_year_id')) {
    foreach ($accounts as $a) {
        $aid = (int)$a['id'];
        $stD = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM journal_entries WHERE fiscal_year_id = ? AND account_debit = ?');
        $stD->execute([$fyId, $aid]);
        $deb = (float)$stD->fetchColumn();
        $stC = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM journal_entries WHERE fiscal_year_id = ? AND account_credit = ?');
        $stC->execute([$fyId, $aid]);
        $cred = (float)$stC->fetchColumn();
        if ($deb > 0.0001 || $cred > 0.0001) {
            $label = (trim((string)($a['code'] ?? '')) !== '' ? $a['code'] . ' — ' : '') . $a['name'];
            $code = trim((string) ($a['code'] ?? ''));
            $rows[] = array_merge([
                'account_id' => $aid,
                'code' => $code,
                'label' => $label,
                'debit' => $deb,
                'credit' => $cred,
                'net' => $deb - $cred,
            ], orange_accounts_report_display_and_sort_meta($mapById[$aid] ?? null));
            $sumDebit += $deb;
            $sumCredit += $cred;
        }
    }
    if ($rows !== []) {
        usort($rows, 'orange_accounts_report_tb_rows_compare');
    }
}

$apAccountId = orange_financial_safe_ap_account_id($pdo);
$supplierFyDetail = [];
$supplierBsBalances = [];
$registeredExpenses = [];
$expenseTbBreakdown = [];
$expenseAccountIdsForTb = [];
if ($useVouchers && $fyId > 0) {
    foreach ($accounts as $a) {
        $eid = (int) $a['id'];
        if (orange_accounts_pnl_bucket_for_report($pdo, $eid, $mapById[$eid] ?? null) === 'expense') {
            $expenseAccountIdsForTb[] = $eid;
        }
    }
    $supplierFyDetail = orange_financial_supplier_fy_subledger($pdo, $fyId);
    $registeredExpenses = orange_financial_registered_expenses_fy($pdo, $fyId);
    if ($expenseAccountIdsForTb !== []) {
        $expenseTbBreakdown = orange_financial_expense_account_line_breakdown($pdo, $fyId, $expenseAccountIdsForTb);
    }
}
if ($useVouchers && is_array($fyRow) && isset($fyRow['end_date']) && trim((string) $fyRow['end_date']) !== '') {
    $supplierBsBalances = orange_financial_supplier_balance_until_date($pdo, (string) $fyRow['end_date']);
}

$dedicatedSupplierPayableByPartyId = [];
$supplierFySubrowsByAccountId = [];
if ($useVouchers && $fyId > 0 && orange_table_has_column($pdo, 'suppliers', 'payable_account_id')) {
    $frSuppliersCountrySql = orange_sql_country_and_fragment($pdo, 'suppliers', 'suppliers', orange_admin_context_country_id($pdo));
    $supPayRows = $pdo->query(
        'SELECT id, payable_account_id FROM suppliers WHERE payable_account_id IS NOT NULL AND payable_account_id > 0' . $frSuppliersCountrySql
    );
    if ($supPayRows) {
        foreach ($supPayRows->fetchAll(PDO::FETCH_ASSOC) as $sr) {
            $sid = (int) $sr['id'];
            $paid = (int) $sr['payable_account_id'];
            if ($paid > 0 && orange_accounts_account_is_posting_leaf($pdo, $paid)) {
                $dedicatedSupplierPayableByPartyId[$sid] = $paid;
            }
        }
    }
    $fyByParty = [];
    foreach ($supplierFyDetail as $sd) {
        $fyByParty[(int) $sd['party_id']] = $sd;
    }
    foreach ($dedicatedSupplierPayableByPartyId as $sid => $paid) {
        if (isset($fyByParty[$sid])) {
            if (!isset($supplierFySubrowsByAccountId[$paid])) {
                $supplierFySubrowsByAccountId[$paid] = [];
            }
            $supplierFySubrowsByAccountId[$paid][] = $fyByParty[$sid];
        }
    }
}

$balanced = abs($sumDebit - $sumCredit) < 0.02;

/* أرباح وخسائر (ملخص) — بدون أرصدة افتتاح ولا إقفال */
$plRevenue = 0.0;
$plExpense = 0.0;
if ($useVouchers && $fyId > 0) {
    $tbPl = orange_voucher_account_totals($pdo, $fyId, ['opening_balance', 'year_end_close']);
    foreach ($tbPl as $aid => $t) {
        $cls = orange_accounts_pnl_bucket_for_report($pdo, (int) $aid, $mapById[(int) $aid] ?? null);
        $deb = (float)$t['debit'];
        $cred = (float)$t['credit'];
        if ($cls === 'revenue') {
            $plRevenue += ($cred - $deb);
        } elseif ($cls === 'expense' || $cls === 'cogs') {
            $plExpense += ($deb - $cred);
        }
    }
}
$netIncome = round($plRevenue - $plExpense, 2);

/* ميزانية عمومية مبسطة */
$bsAssets = 0.0;
$bsLiab = 0.0;
$bsEquity = 0.0;
if ($useVouchers && $fyId > 0) {
    foreach ($tbAll as $aid => $t) {
        $cls = orange_accounts_bs_bucket_for_report($pdo, (int) $aid, $mapById[(int) $aid] ?? null);
        $deb = (float)$t['debit'];
        $cred = (float)$t['credit'];
        if ($cls === 'asset') {
            $bsAssets += ($deb - $cred);
        } elseif ($cls === 'liability') {
            $bsLiab += ($cred - $deb);
        } elseif ($cls === 'equity') {
            $bsEquity += ($cred - $deb);
        }
    }
}
$bsCheck = round($bsAssets - ($bsLiab + $bsEquity), 2);
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>التقارير المالية</h1>
        <p class="page-subtitle">
            ميزان مراجعة، وأرباح وخسائر ملخّصة، وميزانية عمومية مبسطة: تُجمَّع الإيرادات/التكلفة والمصروفات والملخص وفق <strong>الخريطة المحفوظة على الحساب</strong>
            (<code dir="ltr">account_type</code> وربط المرجع) مع <strong>سقوط تلقائي</strong> على تصنيف جذور الشجرة عند ترك الحقول فارغة؛ راجع الوثائق
            <code dir="ltr" style="unicode-bidi:embed;">docs/ACCOUNTING_REPORTING_POLICY_V2.md</code>.
            تفصيل <strong>الموردين</strong> من دفتر الذمم؛ تفصيل <strong>المصروفات</strong> من بنود التسجيل ومذكرات سطور حسابات المصروف.
        </p>
    </div>
</div>

<div class="card">
    <?php if ($years === []): ?>
        <p class="card-hint">لا توجد سنوات مالية معرفة. افتح <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=fiscal_years'), ENT_QUOTES, 'UTF-8'); ?>">السنوات المالية</a>.</p>
    <?php else: ?>
    <form method="get" action="" class="form-grid" style="align-items:end;">
        <input type="hidden" name="page" value="financial_report">
        <?php if ($stmtAccountId > 0): ?>
            <input type="hidden" name="account" value="<?php echo $stmtAccountId; ?>">
        <?php endif; ?>
        <div>
            <label for="fy_rep">السنة المالية</label>
            <select id="fy_rep" name="fy" onchange="this.form.submit()">
                <?php foreach ($years as $y): ?>
                    <option value="<?php echo (int)$y['id']; ?>" <?php echo ((int)$y['id'] === $fyId) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($y['label_ar'] . ' (' . $y['start_date'] . ' — ' . $y['end_date'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <?php endif; ?>
    <?php if ($fyRow): ?>
        <p class="card-hint" style="margin-top:12px;">
            الفترة: <?php echo htmlspecialchars($fyRow['start_date'] . ' — ' . $fyRow['end_date'], ENT_QUOTES, 'UTF-8'); ?>
            — الحالة: <?php echo (int)$fyRow['is_closed'] === 1 ? 'مغلقة' : 'مفتوحة'; ?>
        </p>
    <?php endif; ?>
</div>

<?php if ($useVouchers && $financialReportPostingLeafCt === 0 && $years !== [] && $fyRow): ?>
<div class="card gl-acc-stmt-no-print" style="border:1px solid #fcd34d;background:#fffbeb;">
    <p class="card-hint" style="margin:0;line-height:1.55;"><strong>تنبيه:</strong> لا توجد حسابات ترحيل (أوراق) في الدليل بعد؛ ملخص الإيرادات والمصروفات والميزانية وميزان المراجعة هنا تعتمد على حسابات فعلية بعد إنشاء الدليل. <strong>الصفحة وتبديل السنة تعملان</strong> — المتوقَّع أثناء الإعداد الأول.</p>
</div>
<?php endif; ?>

<?php if ($stmtAccountId > 0): ?>
<div class="card account-statement-print" id="account_statement_card">
    <h3 class="card-title">كشف حساب</h3>
    <?php if ($statementAccLabel !== ''): ?>
        <p class="page-subtitle"><strong><?php echo htmlspecialchars($statementAccLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
        <?php if ($fyRow): ?>
            — السنة: <?php echo htmlspecialchars($fyRow['label_ar'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
        <?php endif; ?>
        </p>
    <?php endif; ?>
    <?php if (!$useVouchers || $fyId <= 0): ?>
        <p class="card-hint">حدد سنة مالية أو فعّل سندات اليومية لعرض الكشف.</p>
    <?php elseif ($statementAccLabel === ''): ?>
        <p class="card-hint">الحساب غير موجود.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>السند</th>
                        <th>نوع القيد</th>
                        <th>البيان</th>
                        <th>مدين</th>
                        <th>دائن</th>
                        <th>الرصيد</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($statementRows as $sr): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(orange_format_date_dmY((string) ($sr['voucher_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($sr['reference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><small title="<?php echo htmlspecialchars(trim((string) ($sr['entry_type'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(orange_gl_entry_type_label_ar((string) ($sr['entry_type'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></small></td>
                            <td><?php echo htmlspecialchars((string) ($sr['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (trim((string) ($sr['memo'] ?? '')) !== ''): ?>
                                    <br><small class="muted"><?php echo htmlspecialchars($sr['memo'], ENT_QUOTES, 'UTF-8'); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo number_format((float) ($sr['debit'] ?? 0), 4); ?></td>
                            <td><?php echo number_format((float) ($sr['credit'] ?? 0), 4); ?></td>
                            <td><?php echo number_format((float) ($sr['balance'] ?? 0), 4); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="6">رصيد نهاية الفترة</th>
                        <th><?php echo number_format($statementClosing, 4); ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php if ($statementRows === []): ?>
            <p class="page-subtitle">لا حركة على هذا الحساب في هذه السنة.</p>
        <?php endif; ?>
        <p class="actions" style="margin-top:12px;">
            <button type="button" class="btn-secondary" onclick="window.print()">طباعة الكشف</button>
            <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=financial_report&fy=' . (int) $fyId), ENT_QUOTES, 'UTF-8'); ?>">العودة للتقارير</a>
        </p>
    <?php endif; ?>
</div>
<?php if ($doAutoPrint && $stmtAccountId > 0 && $statementAccLabel !== '' && $useVouchers && $fyId > 0): ?>
<script>
window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 300);
});
</script>
<?php endif; ?>
<?php endif; ?>

<?php if (!$useVouchers): ?>
<div class="card">
    <p class="card-hint">جاري تهيئة سندات متعددة الأسطر — أعد تحميل الصفحة بعد ثوانٍ.</p>
</div>
<?php endif; ?>

<div class="card" id="report-income">
    <h3 class="card-title">أرباح وخسائر (ملخّص تقريبي)</h3>
    <p class="card-hint">استبعاد أرصدة الافتتاح وقيود إقفال السنة. التصنيف يعتمد أولاً على حقول الدليل ثم جذر الحساب؛ ما لا يُصنَّف كإيراد/تكلفة/مصروف لا يُدخل هنا.</p>
    <div class="grid-2">
        <div class="stat-card"><h3>إجمالي الإيرادات (طبيعة دائنة)</h3><div class="value"><?php echo number_format($plRevenue, 2); ?></div></div>
        <div class="stat-card"><h3>إجمالي المصروفات والتكلفة</h3><div class="value"><?php echo number_format($plExpense, 2); ?></div></div>
    </div>
    <p id="report-net-result" style="scroll-margin-top:92px;margin:14px 0 0;font-size:1.1rem;"><strong>أرباح وخسائر (صافي الدخل):</strong> <?php echo number_format($netIncome, 2); ?> KD</p>
    <?php if ($useVouchers && $fyId > 0 && $registeredExpenses !== []): ?>
        <h4 class="card-title" style="margin-top:18px;font-size:1rem;">تفصيل المصروفات المسجّلة (بالبند)</h4>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>بند المصروف</th>
                        <th>حساب المصروف في الدليل</th>
                        <th>المبلغ (مدين)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registeredExpenses as $re): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($re['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><small><?php echo htmlspecialchars($accountLabelById[$re['account_id']] ?? ('#' . $re['account_id']), ENT_QUOTES, 'UTF-8'); ?></small></td>
                            <td><?php echo number_format($re['amount'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card" id="report-balance-sheet">
    <h3 class="card-title">الميزانية العمومية (مبسطة)</h3>
    <p class="card-hint">أصول = مدين − دائن | خصوم وحقوق = دائن − مدين (للحسابات المصنفة فقط).</p>
    <div class="grid-2">
        <div class="stat-card"><h3>الأصول</h3><div class="value"><?php echo number_format($bsAssets, 2); ?></div></div>
        <div class="stat-card"><h3>الخصوم</h3><div class="value"><?php echo number_format($bsLiab, 2); ?></div></div>
    </div>
    <div class="stat-card" style="margin-top:14px;"><h3>حقوق الملكية</h3><div class="value"><?php echo number_format($bsEquity, 2); ?></div></div>
    <?php if ($useVouchers && $fyId > 0 && $supplierBsBalances !== []): ?>
        <h4 class="card-title" style="margin-top:18px;font-size:1rem;">تفصيل ذمم الموردين (رصيد دائن حتى نهاية السنة)</h4>
        <p class="card-hint">من دفتر الأطراف المرتبط بالسندات؛ إن وُجدت قيود على حساب الموردين دون تسجيل طرف قد لا يظهر هنا.</p>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>المورد</th>
                        <th>الرصيد (دائن لنا)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($supplierBsBalances as $sb): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($sb['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo number_format($sb['balance'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <p class="card-hint" style="margin-top:12px;">
        <?php if (abs($bsCheck) < 0.05): ?>
            <span class="badge approved">أصول ≈ خصوم + حقوق (فرق <?php echo number_format($bsCheck, 2); ?>)</span>
        <?php else: ?>
            <span class="badge cancelled">فرق محاسبي: <?php echo number_format($bsCheck, 2); ?> — راجع التصنيف أو أرصدة الافتتاح أو القيود غير الموزونة.</span>
        <?php endif; ?>
    </p>
</div>

<div class="card">
    <span id="report-account-balances" class="financial-anchor" style="display:block;height:0;scroll-margin-top:92px;"></span>
    <h3 id="report-trial-balance" class="card-title" style="scroll-margin-top:92px;">ميزان المراجعة</h3>
    <p class="card-hint">
        <?php if ($balanced): ?>
            <span class="badge approved">المدين والدائن متطابقان (<?php echo number_format($sumDebit, 2); ?>)</span>
        <?php else: ?>
            <span class="badge cancelled">فرق: <?php echo number_format($sumDebit - $sumCredit, 2); ?></span>
        <?php endif; ?>
        — أسطر «↳» تفصيل: ذمم موردين تستخدم المجمع، أو مورد له حساب ذمة في الدليل (تحت حسابه)، أو بيان/مذكرة ضمن حسابات المصروف؛ الإجماليات في التذييل للصفوف الرئيسية فقط.
    </p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>الحساب</th>
                    <th lang="ar">قسم التقرير</th>
                    <th lang="ar">سطر المرجع</th>
                    <th>إجمالي مدين</th>
                    <th>إجمالي دائن</th>
                    <th>صافي (مدين − دائن)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php $rid = (int) ($r['account_id'] ?? 0); ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="muted"><?php echo htmlspecialchars((string) ($r['sec_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="muted"><?php echo htmlspecialchars((string) ($r['line_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo number_format($r['debit'], 2); ?></td>
                        <td><?php echo number_format($r['credit'], 2); ?></td>
                        <td><?php echo number_format($r['net'], 2); ?></td>
                    </tr>
                    <?php if ($apAccountId > 0 && $rid === $apAccountId && $supplierFyDetail !== []): ?>
                        <?php foreach ($supplierFyDetail as $sd): ?>
                            <?php if (isset($dedicatedSupplierPayableByPartyId[(int) $sd['party_id']])) {
                                continue;
                            } ?>
                            <tr class="fin-report-subrow">
                                <td colspan="3" style="padding-right:1.75rem;"><span class="muted">↳ <?php echo htmlspecialchars($sd['name'], ENT_QUOTES, 'UTF-8'); ?></span> <small class="muted">(ذمة مورد)</small></td>
                                <td><?php echo number_format($sd['debit'], 2); ?></td>
                                <td><?php echo number_format($sd['credit'], 2); ?></td>
                                <td><?php echo number_format($sd['net'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (isset($supplierFySubrowsByAccountId[$rid]) && $supplierFySubrowsByAccountId[$rid] !== []): ?>
                        <?php foreach ($supplierFySubrowsByAccountId[$rid] as $sd): ?>
                            <tr class="fin-report-subrow">
                                <td style="padding-right:1.75rem;"><span class="muted">↳ <?php echo htmlspecialchars($sd['name'], ENT_QUOTES, 'UTF-8'); ?></span> <small class="muted">(ذمة مورد — حساب دليل)</small></td>
                                <td><?php echo number_format($sd['debit'], 2); ?></td>
                                <td><?php echo number_format($sd['credit'], 2); ?></td>
                                <td><?php echo number_format($sd['net'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (isset($expenseTbBreakdown[$rid]) && $expenseTbBreakdown[$rid] !== []): ?>
                        <?php foreach ($expenseTbBreakdown[$rid] as $ex): ?>
                            <tr class="fin-report-subrow">
                                <td colspan="3" style="padding-right:1.75rem;"><span class="muted">↳ <?php echo htmlspecialchars($ex['sublabel'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?php echo number_format($ex['debit'], 2); ?></td>
                                <td><?php echo number_format($ex['credit'], 2); ?></td>
                                <td><?php echo number_format($ex['debit'] - $ex['credit'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3">الإجمالي</th>
                    <th><?php echo number_format($sumDebit, 2); ?></th>
                    <th><?php echo number_format($sumCredit, 2); ?></th>
                    <th><?php echo number_format($sumDebit - $sumCredit, 2); ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php if ($rows === []): ?>
        <p class="page-subtitle">لا حركة في هذه السنة.</p>
    <?php endif; ?>
</div>
