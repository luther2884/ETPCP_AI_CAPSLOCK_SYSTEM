<?php
/**
 * config/xendit.php - Xendit Payments API / Payment Sessions client.
 *
 * Server-side only. XENDIT_SECRET_KEY is read from the environment (see
 * config/env.php) and never leaves this file's HTTP requests to
 * api.xendit.co - it is never echoed to the browser or included in any
 * frontend-facing response.
 *
 * No Xendit SDK dependency - this project has no composer-managed dependency
 * layer beyond a lazily-loaded PHPMailer, and the Payment Sessions API is a
 * small enough surface that a raw, auditable cURL wrapper is simpler and more
 * transparent for something as security-sensitive as a payment integration.
 *
 * Docs: https://docs.xendit.co/
 */

class XenditConfigException extends RuntimeException {}
class XenditApiException extends RuntimeException {}

function xenditSecretKey(): string {
    $key = getenv('XENDIT_SECRET_KEY');
    if ($key === false || trim((string)$key) === '') {
        throw new XenditConfigException('XENDIT_SECRET_KEY is not configured.');
    }
    return trim($key);
}

function xenditWebhookToken(): string {
    $token = getenv('XENDIT_WEBHOOK_TOKEN');
    if ($token === false || trim((string)$token) === '') {
        throw new XenditConfigException('XENDIT_WEBHOOK_TOKEN is not configured.');
    }
    return trim($token);
}

function xenditIsConfigured(): bool {
    $key = getenv('XENDIT_SECRET_KEY');
    $token = getenv('XENDIT_WEBHOOK_TOKEN');
    return $key !== false && trim((string)$key) !== '' && $token !== false && trim((string)$token) !== '';
}

/**
 * Builds this app's own absolute base URL (scheme + host + WEB_ROOT), reusing
 * the same pattern already used elsewhere in this codebase (e.g.
 * verify_login_code.php) instead of introducing a new APP_URL env var.
 */
function xenditAppBaseUrl(): string {
    // Tunnels/reverse proxies like ngrok terminate TLS at their edge and
    // forward plain HTTP to Apache, so $_SERVER['HTTPS'] alone can't see
    // that the customer's browser is actually on https:// - check the
    // standard X-Forwarded-Proto header too (ngrok, and most proxies, set it).
    $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';
    $scheme = $isHttps ? 'https://' : 'http://';
    // Likewise prefer the original forwarded host (e.g. the ngrok domain)
    // over Apache's own idea of the host when behind a tunnel.
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . $host . rtrim(defined('WEB_ROOT') ? WEB_ROOT : '/', '/');
}

/**
 * Low-level authenticated call to the Xendit API. Basic Auth with the secret
 * key as username and an empty password, per Xendit's convention.
 *
 * @throws XenditApiException on network failure or a non-2xx response.
 */
function xenditRequest(string $method, string $path, ?array $body = null): array {
    $secretKey = xenditSecretKey();
    $url = 'https://api.xendit.co' . $path;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($secretKey . ':'),
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    $responseBody = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrno !== 0) {
        error_log("Xendit API network error ($path): [$curlErrno] $curlError");
        throw new XenditApiException('Could not reach the payment gateway. Please try again.');
    }

    $decoded = json_decode((string)$responseBody, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        $safeMessage = is_array($decoded) && !empty($decoded['message']) ? $decoded['message'] : 'Payment gateway request failed.';
        error_log("Xendit API error ($path): HTTP $httpCode - " . $responseBody);
        throw new XenditApiException($safeMessage);
    }

    if (!is_array($decoded)) {
        error_log("Xendit API returned non-JSON response ($path): " . $responseBody);
        throw new XenditApiException('Payment gateway returned an unexpected response.');
    }

    return $decoded;
}

/**
 * Creates a Xendit Payment Session (POST /sessions) for a server-verified
 * order amount. Returns the decoded Xendit session object, which includes
 * `id` (the session id to persist) and `payment_link_url` (or `checkout_url`
 * depending on API version - both are checked by the caller) to redirect the
 * customer to.
 *
 * @param array $order  Row from `orders` (must include id, total_amount).
 * @param array $customer  ['given_names' => ..., 'email' => ..., 'mobile_number' => ...]
 */
function xenditCreatePaymentSession(array $order, array $customer): array {
    $orderId = (int)$order['id'];
    $amount = round((float)$order['total_amount'], 2);
    if ($amount <= 0) {
        throw new XenditApiException('Order has no payable amount.');
    }

    $baseUrl = xenditAppBaseUrl();
    $referenceId = 'ORDER-' . $orderId;

    $payload = [
        'session_type' => 'PAY',
        'mode' => 'PAYMENT_LINK',
        'currency' => 'PHP',
        'country' => 'PH',
        'amount' => $amount,
        'reference_id' => $referenceId,
        'success_return_url' => $baseUrl . '/public/payment/success.php?order_id=' . $orderId,
        'cancel_return_url' => $baseUrl . '/public/payment/cancel.php?order_id=' . $orderId,
    ];

    // Xendit rejects the WHOLE /sessions request with a bare
    // API_VALIDATION_ERROR ("There was an error with the format submitted to
    // the server") if mobile_number isn't strict E.164 - no spaces, dashes or
    // parentheses. user_addresses.phone is free text typed by customers, so
    // values like "+63 9123456789", "0917-123-4567" and "(0917) 1234567" all
    // reach here and all take checkout down with an error that names no field.
    //
    // Normalise to E.164, assuming PH (+63) since that is the only country
    // this store ships to, and drop the field entirely if the result still
    // doesn't look like a phone number: it is optional to Xendit (verified
    // against the live sandbox), so a junk value must never be the thing that
    // blocks a payment.
    $mobile = preg_replace('/[^0-9+]/', '', (string)($customer['mobile_number'] ?? ''));
    if ($mobile !== '') {
        if (strpos($mobile, '+') === 0) {
            // Already international - keep the leading + and digits only, and
            // strip any further stray '+' from mid-string.
            $mobile = '+' . preg_replace('/\D/', '', substr($mobile, 1));
        } elseif (strpos($mobile, '09') === 0) {
            $mobile = '+63' . substr($mobile, 1);          // 09171234567 -> +639171234567
        } elseif (strpos($mobile, '639') === 0) {
            $mobile = '+' . $mobile;                        // 639171234567 -> +639171234567
        } elseif (strpos($mobile, '9') === 0 && strlen($mobile) === 10) {
            $mobile = '+63' . $mobile;                      // 9171234567 -> +639171234567
        } else {
            $mobile = '';                                   // unrecognised - omit rather than fail
        }
    }
    // E.164 allows at most 15 digits; anything outside 8-15 isn't a real number.
    if ($mobile !== '' && !preg_match('/^\+[1-9]\d{7,14}$/', $mobile)) {
        $mobile = '';
    }

    // Xendit's unified Customer object requires `type`, and nests personal
    // details under `individual_detail` rather than flat top-level fields.
    $individualDetail = array_filter([
        'given_names' => $customer['given_names'] ?? null,
    ], static fn($v) => $v !== null && $v !== '');

    // `reference_id` is required and must be unique - a value tied only to
    // the order/user would collide on a retry (failed/expired payment ->
    // "Try Again" -> this runs again for the same order/user), so it's
    // suffixed with a per-call token while staying traceable to the order.
    $customerPayload = array_filter([
        'reference_id' => 'ORDER-' . $orderId . '-' . bin2hex(random_bytes(4)),
        'type' => 'INDIVIDUAL',
        'email' => $customer['email'] ?? null,
        'mobile_number' => $mobile !== '' ? $mobile : null,
        'individual_detail' => !empty($individualDetail) ? $individualDetail : null,
    ], static fn($v) => $v !== null && $v !== '');
    if (!empty($customerPayload)) {
        $payload['customer'] = $customerPayload;
    }

    return xenditRequest('POST', '/sessions', $payload);
}

/**
 * Extracts the hosted checkout URL from a Payment Session response,
 * defensively checking the field names Xendit has used across API versions.
 */
function xenditExtractCheckoutUrl(array $session): ?string {
    return $session['payment_link_url'] ?? $session['checkout_url'] ?? $session['actions']['checkout_url'] ?? null;
}

/**
 * Timing-safe verification of the `x-callback-token` header against
 * XENDIT_WEBHOOK_TOKEN. Returns false (never throws) so callers can respond
 * with a clean 401 instead of a 500 if the token isn't configured.
 */
function xenditVerifyWebhookToken(?string $providedToken): bool {
    if ($providedToken === null || $providedToken === '') { return false; }
    try {
        $expected = xenditWebhookToken();
    } catch (XenditConfigException $e) {
        error_log('Xendit webhook received but XENDIT_WEBHOOK_TOKEN is not configured.');
        return false;
    }
    return hash_equals($expected, $providedToken);
}
