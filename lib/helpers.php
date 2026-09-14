<?php
/**
 * Shared helpers for all API endpoints.
 * Each endpoint file does: require_once __DIR__ . '/../lib/helpers.php';
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pusher.php';

// ----- Error reporting: log but don't leak to client -----
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ----- CORS: same-origin for TV/remote pages, allow GET/POST from anywhere -----
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

/** Standard JSON response. */
function json_out($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
/** Error response. */
function json_error(string $msg, int $code = 400, array $extra = []): void {
    json_out(array_merge(['ok' => false, 'error' => $msg], $extra), $code);
}
/** Success response. */
function json_ok(array $data = []): void {
    json_out(array_merge(['ok' => true], $data));
}

/** Read + decode JSON body, fall back to $_POST / $_GET. */
function request_input(): array {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $j = json_decode($raw, true);
        if (is_array($j)) return $j;
    }
    return array_merge($_GET, $_POST);
}
function in_input(array $src, string $key, $default = null) {
    return $src[$key] ?? $default;
}

/** Generate a short, unambiguous room code (no 0/O/1/I). */
function gen_room_code(int $len = 5): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $out;
}
function gen_token(int $len = 32): string {
    return bin2hex(random_bytes($len / 2));
}

/** Pick a pleasant avatar color from a fixed palette (no clashing hues). */
function pick_color(): string {
    $palette = ['#ff5a5f','#ffb400','#8ce08a','#40d7ce','#7a6bff','#ff7ab6','#00c6c6','#f5a623'];
    return $palette[random_int(0, count($palette) - 1)];
}

/** Room channel name used by both PHP (pusher_trigger) and JS (Pusher.subscribe). */
function room_channel(string $roomId): string {
    return 'room-' . preg_replace('/[^A-Za-z0-9]/', '', $roomId);
}

/** Validate that a room exists and is not expired; return its row or 404. */
function require_room(string $roomId): array {
    $stmt = db()->prepare('SELECT * FROM rooms WHERE id = ? AND expires_at > NOW()');
    $stmt->execute([$roomId]);
    $row = $stmt->fetch();
    if (!$row) json_error('Room not found or expired', 404);
    return $row;
}

/** Validate host token against a room. */
function require_host(string $roomId, string $token): array {
    $room = require_room($roomId);
    if (!hash_equals($room['host_token'] ?? '', $token)) {
        json_error('Invalid host token', 403);
    }
    return $room;
}

/** Basic nickname sanitization. */
function clean_nick(string $nick): string {
    $nick = trim($nick);
    if ($nick === '') $nick = 'Guest';
    return mb_substr($nick, 0, 40);
}
