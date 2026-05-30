<?php

declare(strict_types=1);

/**
 * مرجع اختياري لروابط التقارير — الدخول الرسمي من قائمة «التقارير» في الشريط (زر لكل شاشة).
 */

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/upload_paths.php';
require_once __DIR__ . '/../../includes/countries.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$acctIdxPostingLeafCt = 0;
if (orange_journal_vouchers_ready($pdo)) {
    $acctIdxLw = orange_accounts_posting_leaf_where_sql($pdo, 'a');
    try {
        $acctIdxSql = "SELECT COUNT(*) FROM accounts a WHERE $acctIdxLw";
        $acctIdxParams = [];
        $acctIdxFilter = orange_accounts_sql_country_filter($pdo, 'a');
        if ($acctIdxFilter !== null) {
            $acctIdxSql .= $acctIdxFilter['sql'];
            $acctIdxParams = $acctIdxFilter['params'];
        }
        if ($acctIdxParams !== []) {
            $acctIdxSt = $pdo->prepare($acctIdxSql);
            $acctIdxSt->execute($acctIdxParams);
            $acctIdxPostingLeafCt = (int) $acctIdxSt->fetchColumn();
        } else {
            $acctIdxPostingLeafCt = (int) $pdo->query($acctIdxSql)->fetchColumn();
        }
    } catch (Throwable $e) {
        $acctIdxPostingLeafCt = 0;
    }
}

$baseAdmin = storefront_public_path('/admin/index.php');
$financialBase = htmlspecialchars($baseAdmin . '?page=financial_report', ENT_QUOTES, 'UTF-8');
$financialWithFy = $financialBase . (isset($_GET['fy']) && (int) $_GET['fy'] > 0 ? '&fy=' . (int) $_GET['fy'] : '');

?>
<div class="card" style="margin-bottom:1rem;">
    <p class="card-hint" style="margin:0;">للوصول السريع استخدم قائمة <strong>التقارير</strong> في أعلى لوحة التحكم — كل تقرير له رابط مستقل. هذه الصفحة للمراجعة والنسخ اليدوي للروابط فقط.</p>
</div>
<?php if (orange_journal_vouchers_ready($pdo) && $acctIdxPostingLeafCt === 0): ?>
<div class="card" style="margin-bottom:1rem;border:1px solid #fcd34d;background:#fffbeb;">
    <p class="card-hint" style="margin:0;line-height:1.55;"><strong>تنبيه:</strong> لا توجد حسابات ترحيل (أوراق) في الدليل بعد؛ التقارير المالية ومطابقة الذمم تفترض أوراقاً في «الدليل المحاسبي». هذا الفهرس <strong>يعمل للروابط</strong> — المتوقَّع أثناء الإعداد الأول.</p>
</div>
<?php endif; ?>
<div class="page-title page-title--stacked">
    <div>
        <h1>فهرس التقارير المحاسبية (مرجعي)</h1>
        <p class="page-subtitle">جداول مساعدة؛ «التقارير المالية» و«الذمم» تدعم المراسي (#) من القائمة الرئيسية.</p>
    </div>
</div>

<div class="card">
    <h3 class="card-title">تقارير السندات والأطراف</h3>
    <div class="table-wrap">
        <table class="admin-fy-table">
            <thead>
                <tr>
                    <th>التقرير</th>
                    <th>الحالة</th>
                    <th>افتح</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>تقارير السندات (فلاتر نوع القيد والتاريخ)</td>
                    <td><span class="badge approved">جاهز</span></td>
                    <td><a href="<?php echo htmlspecialchars($baseAdmin . '?page=journal_voucher_reports', ENT_QUOTES, 'UTF-8'); ?>">فتح</a></td>
                </tr>
                <tr>
                    <td>كشف حساب (دليل — من تاريخ إلى تاريخ مع طباعة)</td>
                    <td><span class="badge approved">جاهز</span></td>
                    <td><a href="<?php echo htmlspecialchars($baseAdmin . '?page=partner_account_statement', ENT_QUOTES, 'UTF-8'); ?>">فتح</a></td>
                </tr>
                <tr>
                    <td>تقارير الذمم الشاملة (أرصدة، مطابقة، أعمار ذمم اختياري)</td>
                    <td><span class="badge approved">جاهز</span></td>
                    <td>
                        <a href="<?php echo htmlspecialchars($baseAdmin . '?page=partner_reports', ENT_QUOTES, 'UTF-8'); ?>">فتح الكل</a>
                        —
                        <a href="<?php echo htmlspecialchars($baseAdmin . '?page=partner_reports&view=customers', ENT_QUOTES, 'UTF-8'); ?>">عملاء</a>
                        —
                        <a href="<?php echo htmlspecialchars($baseAdmin . '?page=partner_reports&view=suppliers', ENT_QUOTES, 'UTF-8'); ?>">موردين</a>
                        —
                        <a href="<?php echo htmlspecialchars($baseAdmin . '?page=partner_reports&amp;aging=1', ENT_QUOTES, 'UTF-8'); ?>">مع أعمار الذمم</a>
                    </td>
                </tr>
                <tr>
                    <td>أعمار ذمم العملاء / الموردين (تفصيل حسب قائمة الشركاء)</td>
                    <td><span class="badge approved">ضمن الذمم + زر aging</span></td>
                    <td><a href="<?php echo htmlspecialchars($baseAdmin . '?page=partner_reports&amp;aging=1', ENT_QUOTES, 'UTF-8'); ?>">فتح</a></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3 class="card-title">الدليل والحركات والأرصدة</h3>
    <div class="table-wrap">
        <table class="admin-fy-table">
            <thead>
                <tr>
                    <th>التقرير</th>
                    <th>الحالة</th>
                    <th>افتح</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>قائمة الحسابات (إعداد الدليل)</td>
                    <td><span class="badge approved">جاهز</span></td>
                    <td><a href="<?php echo htmlspecialchars($baseAdmin . '?page=chart_of_accounts', ENT_QUOTES, 'UTF-8'); ?>">فتح</a></td>
                </tr>
                <tr>
                    <td>قائمة الحسابات (توضيحية — كود، اسم، مستوى، رئيسي/فرعي)</td>
                    <td><span class="badge approved">جاهز</span></td>
                    <td><a href="<?php echo htmlspecialchars($baseAdmin . '?page=report_account_list', ENT_QUOTES, 'UTF-8'); ?>">فتح</a></td>
                </tr>
                <tr>
                    <td>ميزان المراجعة (تقويمي: من شهر إلى شهر)</td>
                    <td><span class="badge approved">جاهز</span></td>
                    <td>
                        <a href="<?php echo htmlspecialchars($baseAdmin . '?page=report_trial_balance', ENT_QUOTES, 'UTF-8'); ?>">شاشة ميزان المراجعة</a>
                        — أو ضمن سنة مالية من <a href="<?php echo $financialWithFy; ?>#report-trial-balance">التقارير المالية</a>
                    </td>
                </tr>
                <tr>
                    <td>كشف حركة حساب خط بخط خلال سنة مالية</td>
                    <td><span class="badge approved">جاهز من الدليل</span></td>
                    <td>من «الدليل المحاسبي» افتح حساباً ثم روابط الإجماليات والكشف، أو استخدم تقرير <strong>أرباح وخسائر</strong> أدناه مع اختيار الحساب عند اللزوم.</td>
                </tr>
                <tr>
                    <td>الحركة الشهرية لحساب (تجميع مدين / دائن لكل شهر تقويمي في مدى تختاره)</td>
                    <td><span class="badge approved">شاشة مخصّصة</span></td>
                    <td><a href="<?php echo htmlspecialchars($baseAdmin . '?page=report_gl_account_monthly', ENT_QUOTES, 'UTF-8'); ?>">فتح</a></td>
                </tr>
                <tr>
                    <td>أرباح وخسائر (إيرادات، تكلفة مبيعات، مصروفات — من شهر إلى شهر مثل الحركة الشهرية)</td>
                    <td><span class="badge approved">جاهز</span></td>
                    <td><a href="<?php echo htmlspecialchars($baseAdmin . '?page=report_income_statement', ENT_QUOTES, 'UTF-8'); ?>">أرباح وخسائر</a></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3 class="card-title">القوائم المالية الموحدة (صفحة واحدة)</h3>
    <p class="card-hint" style="margin-bottom:14px;">
        شاشة <strong><a href="<?php echo $financialBase; ?>">التقارير المالية</a></strong> تجمع عدّة لوحات: قائمة دخل تقريبية، ميزانية عمومية مبسطة، وميزان مراجعة بتفاصيل المحصّلات عند توفر البيانات.
    </p>
    <div class="table-wrap">
        <table class="admin-fy-table">
            <thead>
                <tr>
                    <th>التقرير</th>
                    <th>الحالة</th>
                    <th>افتح (مرساة داخل الصفحة)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>أرباح وخسائر ملخّصة (إيراد / مصروف وصافي تقريبي — ضمن شاشة «التقارير المالية»)</td>
                    <td><span class="badge approved">معروض ضمن الشاشة</span></td>
                    <td><a href="<?php echo $financialWithFy; ?>#report-income">#report-income</a></td>
                </tr>
                <tr>
                    <td>أرباح وخسائر تفصيلية بتقويم (مثل الجداول المرجعية لقائمة الأرباح والخسائر)</td>
                    <td><span class="badge approved">شاشة مستقلة</span></td>
                    <td><a href="<?php echo htmlspecialchars($baseAdmin . '?page=report_income_statement', ENT_QUOTES, 'UTF-8'); ?>">أرباح وخسائر (شاشة)</a></td>
                </tr>
                <tr>
                    <td>قائمة حساب المتاجرة / تجزئة نتيجة التشغيل (عند احتياج لفصل المتاجرة رسمياً)</td>
                    <td><span class="muted">يجري توثيق المنطق لاحقاً بجانب تقرير أرباح وخسائر</span></td>
                    <td>توجيه مبدئي: نفس مسار أرباح وخسائر حتى يُعتمد دليلكم لأقسام المتاجرة.</td>
                </tr>
                <tr>
                    <td>الميزانية العمومية (موجز أصول وخصوم وحقوق)</td>
                    <td><span class="badge approved">معروض ضمن الشاشة</span></td>
                    <td><a href="<?php echo $financialWithFy; ?>#report-balance-sheet">#report-balance-sheet</a></td>
                </tr>
                <tr>
                    <td>ميزان المراجعة حسب سنة مالية (ملخّص داخل الشاشة)</td>
                    <td><span class="badge approved">معروض ضمن الشاشة</span></td>
                    <td><a href="<?php echo $financialWithFy; ?>#report-trial-balance">#report-trial-balance</a>
                        — للتقويم المنفصل: <a href="<?php echo htmlspecialchars($baseAdmin . '?page=report_trial_balance', ENT_QUOTES, 'UTF-8'); ?>">ميزان المراجعة</a></td>
                </tr>
                <tr>
                    <td>قائمة إيرادات ومصروفات شهرية (تقرير لكل شهر — صفحة طباعة لكل شهر)</td>
                    <td><span class="badge approved">جاهز</span></td>
                    <td><a href="<?php echo htmlspecialchars($baseAdmin . '?page=report_pl_monthly', ENT_QUOTES, 'UTF-8'); ?>">report_pl_monthly</a>
                        — أو <a href="<?php echo htmlspecialchars($baseAdmin . '?page=report_gl_account_monthly', ENT_QUOTES, 'UTF-8'); ?>">حركة حساب واحد شهراً شهراً</a></td>
                </tr>
                <tr>
                    <td>أرباح وخسائر مقارنة بين سنوات مالية متعددة</td>
                    <td><span class="muted">تطوير لاحق</span></td>
                    <td>سيُضاف بعد تثبيت تعريف صافي السنة الموحدة.</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3 class="card-title">ACC-10 — policy v2 (مرحلة 0: هيكل فقط)</h3>
    <div class="table-wrap">
        <table class="admin-fy-table">
            <thead>
                <tr>
                    <th>الشاشة</th>
                    <th>الحالة</th>
                    <th>افتح</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>قائمة التدفقات النقدية (غير مباشرة + مباشرة)</td>
                    <td><span class="badge approved">جاهز</span></td>
                    <td><a href="<?php echo htmlspecialchars($baseAdmin . '?page=report_cash_flow&amp;run=1', ENT_QUOTES, 'UTF-8'); ?>">فتح</a></td>
                </tr>
                <tr>
                    <td>تسوية البنك</td>
                    <td><span class="badge approved">جاهز</span></td>
                    <td><a href="<?php echo htmlspecialchars($baseAdmin . '?page=bank_reconciliation', ENT_QUOTES, 'UTF-8'); ?>">فتح</a></td>
                </tr>
                <tr>
                    <td>تسوية المخزون / الجرد</td>
                    <td><span class="badge approved">جاهز</span></td>
                    <td><a href="<?php echo htmlspecialchars($baseAdmin . '?page=inventory_reconciliation', ENT_QUOTES, 'UTF-8'); ?>">فتح</a></td>
                </tr>
                <tr>
                    <td>الأبعاد التحليلية</td>
                    <td><span class="badge approved">جاهز</span></td>
                    <td><a href="<?php echo htmlspecialchars($baseAdmin . '?page=analytical_dimensions', ENT_QUOTES, 'UTF-8'); ?>">فتح</a></td>
                </tr>
                <tr>
                    <td>التقرير التحليلي</td>
                    <td><span class="badge approved">جاهز</span></td>
                    <td><a href="<?php echo htmlspecialchars($baseAdmin . '?page=report_analytical&amp;run=1', ENT_QUOTES, 'UTF-8'); ?>">فتح</a></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3 class="card-title">تذكير</h3>
    <ul class="card-hint" style="margin:0;padding-right:1.25rem;">
        <li>«التقارير المالية» تعتمد على تصنيف جذور الحساب في <code>includes/account_tree.php</code> ومراجع الأدوار؛ أي حساب لا يُوزَّن لا يُظهر عدلاً في الميزانية الموجزة أو في تقارير الأرباح والخسائر.</li>
        <li>لإضافة صفحة تقرير جديدة حقيقياً ضع ملفاً تحت <code>admin/pages/</code> ثم اسم الصفحة في <code>admin/index.php</code> والصلاحيات والقائمة.</li>
    </ul>
</div>
