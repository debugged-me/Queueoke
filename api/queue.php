<?php
/**
 * GET  /api/queue.php?room_id=XXX        -> list queued + playing songs
 * POST /api/queue.php                    -> add a song to the queue
 *
 * POST input: { room_id, guest_id?, video_id, title, thumbnail?, duration_sec? }
 *   - guest_id optional (host or AI DJ can add without one)
 *   - Enforces MAX_PER_GUEST to stop one person hogging the queue.
 * Pusher: 'song-added' on room channel.
 */
require_once __DIR__ . '/../lib/helpers.php';

$in = request_input();
$roomId = strtoupper(trim(in_input($in, 'room_id', '')));
require_room($roomId);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = db()->prepare(
        "SELECT id, video_id, title, thumbnail, duration_sec, guest_id, nickname, position, votes, status
         FROM queue WHERE room_id = ? AND status IN ('queued','playing') ORDER BY position ASC"
    );
    $stmt->execute([$roomId]);
    json_ok(['queue' => $stmt->fetchAll()]);
}

// ----- POST: add a song -----
$videoId  = trim(in_input($in, 'video_id', ''));
$title    = trim(in_input($in, 'title', ''));
$thumb    = in_input($in, 'thumbnail', null);
$dur      = in_input($in, 'duration_sec', null);
$guestId  = in_input($in, 'guest_id', null);
$nick     = clean_nick(in_input($in, 'nickname', 'Host'));

if ($videoId === '' || $title === '') json_error('video_id and title required');

// Verify guest is approved (if a guest_id was sent).
if ($guestId !== null && $guestId !== '') {
    $gs = db()->prepare('SELECT status, nickname FROM guests WHERE id = ? AND room_id = ?');
    $gs->execute([(int)$guestId, $roomId]);
    $g = $gs->fetch();
    if (!$g) json_error('Guest not found', 404);
    if ($g['status'] !== 'approved') json_error('Guest not approved yet', 403);
    $nick = $g['nickname'];
    // Anti-hog check.
    $c = config_get();
    $cnt = db()->prepare("SELECT COUNT(*) FROM queue WHERE room_id = ? AND guest_id = ? AND status = 'queued'");
    $cnt->execute([$roomId, (int)$guestId]);
    if ((int)$cnt->fetchColumn() >= (int)$c['MAX_PER_GUEST']) {
        json_error('You have too many songs queued. Wait your turn!', 429);
    }
}

// Position = max(position)+1 so new songs go to the end.
$maxPos = db()->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM queue WHERE room_id = ?');
$maxPos->execute([$roomId]);
$position = (float) $maxPos->fetchColumn();

db()->prepare(
    'INSERT INTO queue (room_id, video_id, title, thumbnail, duration_sec, guest_id, nickname, position)
     VALUES (?,?,?,?,?,?,?,?)'
)->execute([$roomId, $videoId, $title, $thumb, $dur ? (int)$dur : null,
            ($guestId === '' ? null : (int)$guestId), $nick, $position]);

$newId = (int) db()->lastInsertId();

// Cache the song metadata so search.php can reuse it.
db()->prepare(
    'INSERT IGNORE INTO songs_cache (video_id, title, thumbnail, duration_sec, is_karaoke)
     VALUES (?,?,?,?,?)'
)->execute([$videoId, $title, $thumb, $dur ? (int)$dur : null,
            preg_match('/karaoke/i', $title) ? 1 : 0]);

pusher_trigger(room_channel($roomId), 'song-added', [
    'id'           => $newId,
    'video_id'     => $videoId,
    'title'        => $title,
    'thumbnail'    => $thumb,
    'duration_sec' => $dur,
    'guest_id'     => $guestId === '' ? null : (int)$guestId,
    'nickname'     => $nick,
    'position'     => $position,
    'votes'        => 0,
    'status'       => 'queued',
]);

json_ok(['song_id' => $newId]);
