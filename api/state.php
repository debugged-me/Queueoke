<?php
/**
 * GET /api/state.php?room_id=XXX
 * Returns the full current room state. Used on first load AND on reconnect
 * (so a phone that dropped mid-party re-syncs instantly instead of waiting for events).
 *
 * Output: { ok, room, queue, guests, now_playing }
 */
require_once __DIR__ . '/../lib/helpers.php';

$roomId = strtoupper(trim(in_input($_GET, 'room_id', '')));
$room = require_room($roomId);

$q = db()->prepare(
    "SELECT id, video_id, title, thumbnail, duration_sec, guest_id, nickname, position, votes, status
     FROM queue WHERE room_id = ? AND status IN ('queued','playing') ORDER BY position ASC"
);
$q->execute([$roomId]);
$queue = $q->fetchAll();

$g = db()->prepare('SELECT id, nickname, color, status FROM guests WHERE room_id = ? ORDER BY id ASC');
$g->execute([$roomId]);
$guests = $g->fetchAll();

json_ok([
    'room' => [
        'id'         => $room['id'],
        'title'      => $room['title'],
        'is_locked'   => (int)$room['is_locked'],
        'now_playing'=> $room['now_playing'],
        'started_at'  => $room['started_at'],
        'pitch'       => (int)$room['pitch'],
        'expires_at'  => $room['expires_at'],
    ],
    'queue'  => $queue,
    'guests' => $guests,
]);
