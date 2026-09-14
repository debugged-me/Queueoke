<?php
/**
 * POST /api/join.php
 * Guest requests to join a room. If the room is unlocked, they're auto-approved.
 * If locked, they go to "pending" and the host sees an approval toast.
 *
 * Input:  { room_id, nickname }
 * Output: { ok, guest: { id, nickname, color, status } }
 *         (status = 'approved' or 'pending')
 * Pusher: 'guest-join-request' on room channel when locked + pending.
 */
require_once __DIR__ . '/../lib/helpers.php';

$in = request_input();
$roomId = strtoupper(trim(in_input($in, 'room_id', '')));
$nick   = clean_nick(in_input($in, 'nickname', 'Guest'));

if ($roomId === '') json_error('room_id required');
$room = require_room($roomId);
$color = pick_color();

$stmt = db()->prepare(
    'INSERT INTO guests (room_id, nickname, color, status) VALUES (?,?,?,?)'
);
$status = $room['is_locked'] ? 'pending' : 'approved';
$stmt->execute([$roomId, $nick, $color, $status]);
$guestId = (int) db()->lastInsertId();

if ($room['is_locked']) {
    pusher_trigger(room_channel($roomId), 'guest-join-request', [
        'guest_id' => $guestId,
        'nickname' => $nick,
        'color'    => $color,
    ]);
} else {
    pusher_trigger(room_channel($roomId), 'guest-joined', [
        'guest_id' => $guestId,
        'nickname' => $nick,
        'color'    => $color,
    ]);
}

json_ok(['guest' => [
    'id'       => $guestId,
    'nickname' => $nick,
    'color'    => $color,
    'status'   => $status,
]]);
