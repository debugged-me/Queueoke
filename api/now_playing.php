<?php
/**
 * POST /api/now_playing.php
 * TV calls this when it starts playing a song (or goes idle).
 * Marks the queue item 'playing'/'done' and updates rooms.now_playing + started_at
 * so reconnecting phones see the correct current song.
 *
 * Input:  { room_id, host_token, song_id?: int|null }
 *   song_id = null means "idle / nothing playing".
 * Pusher: 'now-playing' on room channel.
 */
require_once __DIR__ . '/../lib/helpers.php';

$in = request_input();
$roomId = strtoupper(trim(in_input($in, 'room_id', '')));
$token  = in_input($in, 'host_token', '');
$songId = in_input($in, 'song_id', null);
require_host($roomId, $token);

// Mark any previously-playing song as done.
db()->prepare("UPDATE queue SET status = 'done' WHERE room_id = ? AND status = 'playing'")
    ->execute([$roomId]);

if ($songId === null || $songId === '') {
    db()->prepare('UPDATE rooms SET now_playing = NULL, started_at = NULL WHERE id = ?')
        ->execute([$roomId]);
    pusher_trigger(room_channel($roomId), 'now-playing', ['song' => null]);
    json_ok();
}

$songId = (int) $songId;
$row = db()->prepare('SELECT video_id, title, thumbnail, duration_sec, nickname FROM queue WHERE id = ? AND room_id = ?');
$row->execute([$songId, $roomId]);
$song = $row->fetch();
if (!$song) json_error('Song not found', 404);

$now = gmdate('Y-m-d H:i:s');
db()->prepare("UPDATE queue SET status = 'playing' WHERE id = ?")
    ->execute([$songId]);
db()->prepare('UPDATE rooms SET now_playing = ?, started_at = ? WHERE id = ?')
    ->execute([$song['video_id'], $now, $roomId]);

pusher_trigger(room_channel($roomId), 'now-playing', [
    'song' => [
        'id'           => $songId,
        'video_id'     => $song['video_id'],
        'title'        => $song['title'],
        'thumbnail'    => $song['thumbnail'],
        'duration_sec' => $song['duration_sec'],
        'nickname'     => $song['nickname'],
    ],
]);
json_ok();
