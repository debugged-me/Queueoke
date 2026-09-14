<?php
/**
 * Minimal self-contained Pusher client (no composer dependency).
 * Implements just the REST API "trigger" call with HMAC signing.
 * Docs: https://pusher.com/docs/channels/library_auth_reference/rest-api
 *
 * Usage:
 *   pusher_trigger('room-AB7K2', 'song-added', ['id' => 123]);
 *   pusher_trigger('room-AB7K2', 'reaction', ['type' => 'applause']);
 */
function pusher_trigger(string $channel, string $event, array $payload): array {
    $c = config_get();
    $appId  = $c['PUSHER_APP_ID'];
    $key    = $c['PUSHER_KEY'];
    $secret = $c['PUSHER_SECRET'];
    $cluster = $c['PUSHER_CLUSTER'];

    // If the user hasn't filled in real keys, no-op so local dev still works.
    if ($appId === '1234567' || $secret === 'secret-key') {
        return ['ok' => false, 'skipped' => true, 'reason' => 'pusher-not-configured'];
    }

    $body = json_encode([
        'name'      => $event,
        'channel'   => $channel,
        'data'      => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $path    = '/apps/' . $appId . '/events';
    $host    = 'api-' . $cluster . '.pusher.com';
    $method  = 'POST';

    // Auth params (must be sorted, then signed as a query string).
    $params = [
        'auth_key'     => $key,
        'auth_timestamp' => (string) time(),
        'auth_version' => '1.0',
        'body_md5'     => md5($body),
    ];
    ksort($params);
    $paramPairs = [];
    foreach ($params as $k => $v) $paramPairs[] = $k . '=' . rawurlencode($v);
    $queryString = implode('&', $paramPairs);

    $stringToSign = $method . "\n" . $path . "\n" . $queryString;
    $signature = hash_hmac('sha256', $stringToSign, $secret);

    $url = 'https://' . $host . $path . '?' . $queryString . '&auth_signature=' . $signature;

    // cURL is available on virtually every cPanel host.
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST          => true,
        CURLOPT_POSTFIELDS    => $body,
        CURLOPT_HTTPHEADER    => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT       => 5,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return ['ok' => $code >= 200 && $code < 300, 'status' => $code, 'body' => $resp, 'error' => $err];
}
