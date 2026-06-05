<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/catalog_schema.php';
require_once __DIR__ . '/../includes/countries.php';
require_once __DIR__ . '/../includes/payments/payment_gateway.php';

$pdoPr = db();
orange_catalog_ensure_storefront_page($pdoPr);

include __DIR__ . '/../includes/header.php';

$prHomeUrl = storefront_url('home', $channelSlug, $lang);
$prTrackUrl = storefront_url('track', $channelSlug, $lang);

$prState = 'pending';
$prMessage = 'جاري التحقق من حالة الدفع…';

try {
    $cid = (int) orange_storefront_current_country_id($pdoPr);
    $provider = orange_payment_gateway_default_provider();
    $prefix = orange_payment_gateway_load($provider);
    $cfg = orange_payment_gateway_config($provider);

    $paymentId = trim((string) ($_GET['paymentId'] ?? $_GET['PaymentId'] ?? ''));
    $orderNumber = trim((string) ($_GET['order'] ?? ''));

    if ($prefix === null || !orange_payment_gateway_is_configured($provider, $cfg) || $paymentId === '') {
        $prState = 'failed';
        $prMessage = 'تعذّر التحقق من الدفع. إن كنت قد دفعت فسيُؤكَّد تلقائياً، أو تواصل معنا.';
    } else {
        $verifyFn = $prefix . '_verify';
        /* تأكيد خادمي — لا نثق بإعادة التوجيه. */
        $verify = $verifyFn($cfg, $paymentId, 'PaymentId');
        if (empty($verify['ok'])) {
            $prState = 'pending';
            $prMessage = 'لم نتمكن من تأكيد الدفع الآن. إن خُصم المبلغ فسيُؤكَّد قريباً.';
        } else {
            $orderRef = trim((string) ($verify['order_ref'] ?? '')) !== '' ? trim((string) $verify['order_ref']) : $orderNumber;
            $invoiceId = trim((string) ($verify['invoice_id'] ?? '')) !== '' ? trim((string) $verify['invoice_id']) : $paymentId;
            $orderId = 0;
            if ($orderRef !== '') {
                $st = $pdoPr->prepare('SELECT id FROM orders WHERE order_number = ? LIMIT 1');
                $st->execute([$orderRef]);
                $orderId = (int) ($st->fetchColumn() ?: 0);
            }
            if (($verify['status'] ?? '') === 'paid' && $orderId > 0) {
                orange_payment_gateway_settle($pdoPr, $orderId, $cid, $provider, $invoiceId, $verify);
                $prState = 'paid';
                $prMessage = 'تم استلام دفعتك بنجاح. شكراً لك!';
            } elseif (($verify['status'] ?? '') === 'failed') {
                $prState = 'failed';
                $prMessage = 'لم تكتمل عملية الدفع. يمكنك المحاولة مرة أخرى من صفحة تتبّع الطلب.';
            } else {
                $prState = 'pending';
                $prMessage = 'دفعتك قيد المعالجة. سيُحدَّث الطلب تلقائياً عند التأكيد.';
            }
        }
    }
} catch (Throwable $e) {
    $prState = 'pending';
    $prMessage = 'تعذّر التحقق الآن. إن خُصم المبلغ فسيُؤكَّد تلقائياً.';
}
?>
<div class="container">
    <div class="card-box" style="max-width:560px;margin:32px auto;text-align:center;">
        <div style="font-size:48px;line-height:1;margin-bottom:12px;">
            <?php echo $prState === 'paid' ? '✅' : ($prState === 'failed' ? '⚠️' : '⏳'); ?>
        </div>
        <h2 style="margin:0 0 10px;">
            <?php echo $prState === 'paid' ? 'تم الدفع' : ($prState === 'failed' ? 'لم يكتمل الدفع' : 'قيد المعالجة'); ?>
        </h2>
        <p style="margin:0 0 20px;"><?php echo htmlspecialchars($prMessage, ENT_QUOTES, 'UTF-8'); ?></p>
        <div class="actions-row" style="justify-content:center;gap:10px;">
            <a class="btn" href="<?php echo htmlspecialchars($prTrackUrl, ENT_QUOTES, 'UTF-8'); ?>">تتبّع الطلب</a>
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars($prHomeUrl, ENT_QUOTES, 'UTF-8'); ?>">المتجر</a>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
