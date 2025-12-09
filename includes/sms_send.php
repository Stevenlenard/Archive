<?php
// includes/sms_send.php
// Fixed for iPROGSMS: Correct endpoint, JSON body, optional sender.
// Usage: sms_send(string $to, string $message, string $from = null): array

if (!defined('IPROGSMS_API_KEY')) {
    define('IPROGSMS_API_KEY', 'adfd6d7a7ecb69c900a48083be98701f4ac58b4b');
}

function sms_send(string $to, string $message, string $from = null): array {
    $api_key = IPROGSMS_API_KEY;
    // Sender: explicit > config > default (optional for iPROGSMS)
    if ($from === null) {
        if (defined('IPROGSMS_SENDER_NAME') && !empty(IPROGSMS_SENDER_NAME)) {
            $from = IPROGSMS_SENDER_NAME;
        } else {
            $from = null; // Let iPROGSMS default it
        }
    }

    if (empty($to) || empty($message)) {
        return ['success' => false, 'error' => 'Missing to or message'];
    }

    // Normalize PH phone: 09xx -> +63..., or add +63 if 10 digits
    $to = trim($to);
    if (substr($to, 0, 1) === '0') {
        $to = '+63' . substr($to, 1);
    } elseif (strlen($to) === 10 && ctype_digit($to)) {
        $to = '+63' . $to;
    }
    if (substr($to, 0, 1) !== '+') {
        $to = '+63' . $to; // Fallback for invalid
    }

    // iPROGSMS: JSON body, no 'from' if not set
    $payload = [
        'api_token' => $api_key,
        'phone_number' => $to,
        'message' => $message
    ];
    if ($from !== null) {
        $payload['from'] = $from; // Optional; only add if provided
    }

    $ch = curl_init('https://www.iprogsms.com/api/v1/sms_messages'); // FIXED: Correct URL
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload), // FIXED: JSON, not form
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false // For testing; enable in prod
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // Enhanced logging
    error_log('[sms_send] iPROGSMS Request: HTTP POST to /v1/sms_messages | Payload: ' . json_encode($payload));
    error_log('[sms_send] iPROGSMS Response: HTTP ' . $http_code . ', Body: ' . $response);

    if ($curl_error) {
        error_log('[sms_send] cURL Error: ' . $curl_error);
        return ['success' => false, 'error' => 'cURL Error: ' . $curl_error];
    }

    if ($http_code !== 200 && $http_code !== 201) {
        return ['success' => false, 'error' => 'HTTP ' . $http_code . ': ' . $response];
    }

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('[sms_send] JSON Decode Error: ' . json_last_error_msg());
        return ['success' => false, 'error' => 'Invalid JSON response: ' . $response];
    }

    error_log('[sms_send] Decoded: ' . json_encode($decoded));

    // iPROGSMS success: status 200 or 201 HTTP codes and no explicit error
    // Response looks like: {"status":200,"message":"SMS successfully queued for delivery.","message_id":"..."}
    if (isset($decoded['status'])) {
        $status_code = intval($decoded['status']);
        if ($status_code === 200 || $status_code === 201) {
            return ['success' => true, 'response' => $decoded, 'message_id' => $decoded['message_id'] ?? 'N/A'];
        }
    }
    
    // If HTTP code is 200/201 and no error field, consider it success
    if (($http_code === 200 || $http_code === 201) && empty($decoded['error'])) {
        return ['success' => true, 'response' => $decoded, 'message_id' => $decoded['message_id'] ?? 'N/A'];
    }

    // Error
    $error_msg = $decoded['error'] ?? $decoded['message'] ?? 'Unknown iPROGSMS error';
    error_log('[sms_send] API Error: ' . $error_msg);
    return ['success' => false, 'error' => $error_msg, 'response' => $decoded];
}
?>