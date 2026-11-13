<?php
declare(strict_types=1);

// config/gemini_client.php - thin, provider-scoped HTTP client for Google's
// Gemini API. Kept separate from ai_builder_ai.php (which knows about
// products/builds) so the AI *provider* could be swapped later (e.g. for
// OpenAI) without touching the AI Builder's own prompt/validation logic -
// only this file and ai_config.php would need a counterpart.
require_once __DIR__ . '/ai_config.php';

/**
 * Calls Gemini's generateContent endpoint asking for strict JSON output
 * matching $responseSchema (Gemini's structured-output feature - the model
 * is constrained to emit valid JSON in that shape, which is far more
 * reliable than parsing free-form text).
 *
 * $inlineImage, if given, is ['mime_type' => 'image/jpeg', 'data' => <raw
 * bytes, NOT pre-base64-encoded>] - attached as an extra part on the same
 * user turn so the model can see it alongside $userPrompt (used by the AI
 * Add Product feature; the AI Builder's text-only calls just omit this).
 *
 * $model overrides the configured default - config/ai_guard.php uses it to
 * send cheap work to a cheaper model. Omit it for GEMINI_MODEL.
 *
 * $maxOutputTokens caps what the model may spend on the answer. Output tokens
 * cost several times what input tokens do, so this is a real spend control,
 * not just a safety valve.
 *
 * Returns ['success'=>bool, 'data'=>array|null, 'error'=>string|null], plus
 * the diagnostics config/ai_guard.php needs to decide whether a failure is
 * worth retrying and what the call cost: 'http_code', 'transport_error',
 * 'prompt_tokens', 'output_tokens'.
 * $error is always a message safe to show a customer - it never contains
 * the API key or raw upstream payloads.
 *
 * NOTE: call this directly only from config/ai_guard.php. Everything else
 * must go through ai_guarded_json(), which is what applies the rate limits,
 * spend caps, cache and retry ceiling.
 */
function gemini_generate_json(string $systemPrompt, string $userPrompt, array $responseSchema, int $timeoutSeconds = 30, ?array $inlineImage = null, ?string $model = null, int $maxOutputTokens = 8192): array {
    if (!ai_is_configured()) {
        return ['success' => false, 'data' => null, 'error' => 'AI Builder is not configured yet - no Gemini API key is set on the server.'];
    }

    $model = ($model !== null && $model !== '') ? $model : GEMINI_MODEL;
    $url = GEMINI_API_BASE . rawurlencode($model) . ':generateContent?key=' . rawurlencode(GEMINI_API_KEY);

    $parts = [['text' => $userPrompt]];
    if ($inlineImage !== null) {
        $parts[] = ['inline_data' => ['mime_type' => $inlineImage['mime_type'], 'data' => base64_encode($inlineImage['data'])]];
    }

    $body = [
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => [['role' => 'user', 'parts' => $parts]],
        'generationConfig' => [
            'temperature' => 0.4,
            'maxOutputTokens' => max(256, $maxOutputTokens),
            'responseMimeType' => 'application/json',
            'responseSchema' => $responseSchema,
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Token counts are reported even on some error responses, so decode first:
    // an attempt that failed after the model had already produced tokens still
    // costs money and still has to be accounted for.
    $decoded = json_decode((string)$response, true);
    $meta = [
        'http_code' => $httpCode,
        'transport_error' => $curlErrno !== 0,
        'prompt_tokens' => (int)($decoded['usageMetadata']['promptTokenCount'] ?? 0),
        'output_tokens' => (int)(($decoded['usageMetadata']['candidatesTokenCount'] ?? 0)
                                 + ($decoded['usageMetadata']['thoughtsTokenCount'] ?? 0)),
    ];

    if ($curlErrno !== 0) {
        error_log('gemini_generate_json curl error: ' . $curlError);
        return $meta + ['success' => false, 'data' => null, 'error' => 'Could not reach the AI service. Please check your internet connection and try again.'];
    }

    if ($httpCode === 429) {
        return $meta + ['success' => false, 'data' => null, 'error' => 'The AI service is temporarily rate-limited (free-tier quota reached). Please try again in a bit.'];
    }
    if ($httpCode === 401 || $httpCode === 403) {
        error_log('gemini_generate_json auth error: HTTP ' . $httpCode . ' - ' . substr((string)$response, 0, 500));
        return $meta + ['success' => false, 'data' => null, 'error' => 'The AI service rejected the server\'s API key. Please check the GEMINI_API_KEY configuration.'];
    }
    if ($httpCode !== 200) {
        $upstreamMsg = $decoded['error']['message'] ?? null;
        error_log('gemini_generate_json HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 1000));
        return $meta + ['success' => false, 'data' => null, 'error' => 'The AI service returned an error' . ($upstreamMsg ? (': ' . $upstreamMsg) : '.') ];
    }

    $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if ($text === null) {
        // Most common cause: the model hit maxOutputTokens or a safety block
        // before finishing - finishReason carries which.
        $finishReason = $decoded['candidates'][0]['finishReason'] ?? 'unknown';
        error_log('gemini_generate_json empty response, finishReason=' . $finishReason . ': ' . substr((string)$response, 0, 1000));
        return $meta + ['success' => false, 'data' => null, 'error' => 'The AI did not return a usable response (reason: ' . $finishReason . '). Please try again.'];
    }

    $parsed = json_decode($text, true);
    if (!is_array($parsed)) {
        error_log('gemini_generate_json unparsable JSON text: ' . substr($text, 0, 1000));
        return $meta + ['success' => false, 'data' => null, 'error' => 'The AI returned a response that could not be understood. Please try again.'];
    }

    return $meta + ['success' => true, 'data' => $parsed, 'error' => null];
}
