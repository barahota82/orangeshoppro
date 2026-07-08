<?php

declare(strict_types=1);

/**
 * Storefront API: business validation vs internal errors.
 * Never expose raw exception text, SQL/PDO, IDs, or queue internals to customers.
 */

final class OrangeStorefrontCustomerException extends RuntimeException
{
    public function __construct(
        private readonly string $customerCode,
        private readonly string $internalDetail = '',
        private readonly int $httpStatus = 422,
    ) {
        parent::__construct(function_exists('t') ? t($customerCode) : $customerCode);
    }

    public function customerCode(): string
    {
        return $this->customerCode;
    }

    public function internalDetail(): string
    {
        return $this->internalDetail;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}

function orange_storefront_throw_customer(string $code, string $internalDetail = '', int $http = 422): never
{
    throw new OrangeStorefrontCustomerException($code, $internalDetail, $http);
}

/** @return list<string> */
function orange_storefront_customer_error_codes(): array
{
    return [
        'checkout_delivery_area_required',
        'checkout_invalid_phone',
        'phone_country_required',
        'checkout_invalid_email',
        'product_not_found',
        'checkout_cart_items_required',
        'checkout_invalid_channel',
        'no_more_stock_for_cart',
        'out_of_stock',
        'checkout_failed_generic',
        'checkout_internal_error',
    ];
}

/** Map translation/customer code to legacy API response code (cart.js). */
function orange_storefront_customer_api_response_code(string $customerCode): string
{
    static $map = [
        'checkout_delivery_area_required' => 'invalid_delivery_area',
        'checkout_invalid_phone' => 'invalid_phone',
        'checkout_cart_items_required' => 'cart_items_required',
        'checkout_invalid_channel' => 'invalid_channel',
        'checkout_invalid_email' => 'invalid_email',
    ];

    return $map[$customerCode] ?? $customerCode;
}

/**
 * @return array{code: string, message: string, http: int, internal: string}|null
 */
function orange_storefront_customer_error_payload(string $code, int $http, string $internal = ''): array
{
    return [
        'code' => $code,
        'message' => function_exists('t') ? t($code) : $code,
        'http' => $http,
        'internal' => $internal,
    ];
}

/**
 * Resolve a stored or thrown message to a safe customer payload (null if internal/unknown).
 *
 * @return array{code: string, message: string, http: int, internal: string}|null
 */
function orange_storefront_customer_error_from_message(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    if (str_starts_with($raw, 'code:')) {
        $code = substr($raw, 5);
        if ($code !== '' && preg_match('/^[a-z0-9_]+$/', $code)) {
            return orange_storefront_customer_error_payload($code, 422);
        }

        return null;
    }

    if (function_exists('t')) {
        foreach (orange_storefront_customer_error_codes() as $code) {
            if ($raw === t($code)) {
                return orange_storefront_customer_error_payload($code, 422);
            }
        }
    }

    if ($raw === 'Cart items are required') {
        return orange_storefront_customer_error_payload('checkout_cart_items_required', 422, $raw);
    }
    if ($raw === 'Invalid channel') {
        return orange_storefront_customer_error_payload('checkout_invalid_channel', 422, $raw);
    }
    if ($raw === 'Delivery area required' || $raw === 'Invalid delivery area') {
        return orange_storefront_customer_error_payload('checkout_delivery_area_required', 422, $raw);
    }
    if (preg_match('/^Product not found:\s*\d+/i', $raw)) {
        return orange_storefront_customer_error_payload('product_not_found', 422, $raw);
    }
    if (preg_match('/^Variant not found for product:/i', $raw)) {
        return orange_storefront_customer_error_payload('product_not_found', 422, $raw);
    }
    if (preg_match('/^Insufficient stock for product:/i', $raw)) {
        return orange_storefront_customer_error_payload('out_of_stock', 422, $raw);
    }

    return null;
}

/**
 * @return array{code: string, message: string, http: int, internal: string}|null
 */
function orange_storefront_customer_error_from_throwable(Throwable $e): ?array
{
    if ($e instanceof OrangeStorefrontCustomerException) {
        $internal = $e->internalDetail();
        if ($internal === '') {
            $internal = $e->getMessage();
        }

        return orange_storefront_customer_error_payload(
            $e->customerCode(),
            $e->httpStatus(),
            $internal
        );
    }

    if ($e instanceof RuntimeException) {
        return orange_storefront_customer_error_from_message($e->getMessage());
    }

    return null;
}

function orange_storefront_log_customer_api_error(Throwable $e, string $context, string $internalHint = ''): void
{
    if (!function_exists('error_log')) {
        return;
    }
    $detail = $internalHint !== '' ? $internalHint : $e->getMessage();
    error_log(
        '[orange] ' . $context . ': ' . $detail
        . ' @ ' . $e->getFile() . ':' . $e->getLine()
    );
}

/**
 * JSON error for RuntimeException / business validation; generic message for internal failures.
 *
 * @param string|null $internalApiCode API code when error is not a mapped business validation (e.g. amend_failed).
 */
function orange_storefront_api_json_runtime_error(
    Throwable $e,
    string $logContext,
    ?string $internalApiCode = null,
    string $fallbackMessageKey = 'checkout_failed_generic',
    int $fallbackHttp = 422,
): void {
    $mapped = orange_storefront_customer_error_from_throwable($e);
    if ($mapped !== null) {
        orange_storefront_log_customer_api_error($e, $logContext, $mapped['internal']);
        json_response([
            'success' => false,
            'code' => orange_storefront_customer_api_response_code($mapped['code']),
            'message' => $mapped['message'],
        ], $mapped['http']);
    }

    orange_storefront_log_customer_api_error($e, $logContext);
    json_response([
        'success' => false,
        'code' => $internalApiCode ?? 'server_error',
        'message' => function_exists('t') ? t($fallbackMessageKey) : $fallbackMessageKey,
    ], $fallbackHttp);
}

/** Safe value for order_intake_queue.error_message (code:… only, never raw exception text). */
function orange_storefront_order_intake_error_for_queue(Throwable $e): string
{
    $mapped = orange_storefront_customer_error_from_throwable($e);
    if ($mapped !== null) {
        orange_storefront_log_customer_api_error($e, 'order_intake_queue business', $mapped['internal']);

        return 'code:' . $mapped['code'];
    }

    orange_storefront_log_customer_api_error($e, 'order_intake_queue internal');

    return 'code:checkout_failed_generic';
}

/**
 * @return array{code: string, message: string}
 */
function orange_storefront_queue_error_to_customer(string $stored): array
{
    $mapped = orange_storefront_customer_error_from_message($stored);
    if ($mapped !== null) {
        $apiCode = orange_storefront_customer_api_response_code($mapped['code']);
        if ($mapped['code'] === 'checkout_failed_generic' || $mapped['code'] === 'checkout_internal_error') {
            $apiCode = 'processing_failed';
        }

        return [
            'code' => $apiCode,
            'message' => $mapped['message'],
        ];
    }

    if ($stored !== '' && function_exists('error_log')) {
        error_log('[orange] order_intake legacy unsafe error_message suppressed');
    }

    return [
        'code' => 'processing_failed',
        'message' => function_exists('t') ? t('checkout_failed_generic') : 'checkout_failed_generic',
    ];
}
