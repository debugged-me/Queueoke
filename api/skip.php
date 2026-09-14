<?php
/**
 * POST /api/skip.php
 * Host skips the currently-playing song. Marks it 'skipped', clears now_playing,
 * and the TV auto-advances to the next queued song.
 *
 * Input:  { room_id, host_token }
 * Pusher: 'song-skipped' on room channel.
 */
require_once __DIR__ . '/../lib/helpers.php';

$in = request_input();
$roomId = strtoupper(trim(in_input($in, 'room_id', '')));
$token  = in_input($in, 'host_token', '');
require_host($roomId, $token);

db()->prepare("UPDATE queue SET status = 'skipped' WHERE room_id = ? AND status = 'playing'")
    ->execute([$roomId]);
db()->prepare('UPDATE rooms SET now_playing = NULL, started_at = NULL WHERE id = ?')
    ->execute([$roomId]);

pusher_trigger(room_channel($roomId), 'song-skipped', []);
json_ok();
