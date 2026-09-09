<?php
/**
 * Enterprise SMS Dispatcher Helper
 * Dispatches SMS directly through the self-hosted SMS Gateway service on localhost:5005
 */

if (!function_exists('send_enterprise_sms')) {
    function send_enterprise_sms($recipient, $message, $module = 'System') {
        $url = 'http://127.0.0.1:5005/api/sms/send';
        $payload = json_encode([
            'recipient' => $recipient,
            'message'   => $message,
            'module'    => $module
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300 && $response) {
            return json_decode($response, true);
        }

        return ['ok' => false, 'error' => 'SMS Gateway unreachable or returned HTTP ' . $httpCode];
    }
}
