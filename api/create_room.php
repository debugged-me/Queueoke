<?php
/**
 * POST /api/create_room.php
 * TV host creates a new room. Returns room id + host token (store in localStorage on the TV).
 *
 * Input:  { title?: string }
 * Output: { ok, room: { id, title, host_token, created_at } }
 */
require_once __DIR__ . '/../lib/helpers.php';

$in = request_input();
$title = clean_nick(in_input($in, 'title', 'Party Room'));
$c = config_get();

// Loop in the rare case of a code collision.
for ($attempt = 0; $attempt < 5; $attempt++) {
    $id = gen_room_code();
    $token = gen_token(32);
    $expires = gmdate('Y-m-d H:i:s', time() + ((int)$c['ROOM_TTL_HOURS'] * 3600));
    try {
        db()->prepare(
            'INSERT INTO rooms (id, host_token, title, is_locked, expires_at) VALUES (?,?,?,?,?)'
        )->execute([$id, $token, $title, 1, $expires]);
        json_ok(['room' => [
            'id'         => $id,
            'title'      => $title,
            'host_token' => $token,
            'is_locked'  => 1,
            'expires_at' => $expires,
        ]]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') continue; // duplicate id, retry
        json_error('DB error: ' . $e->getMessage(), 500);
    }
}
json_error('Could not allocate a room code, try again', 500);
