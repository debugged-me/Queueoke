<?php
/**
 * POST /api/vote.php
 * Guest upvotes a queued song (democratic queue mode). +1 only (no downvote spam).
 * For simplicity we don't track who voted per-guest in v1; a future version can add
 * a votes_log table. The queue auto-reorders by votes on the client.
 *
 * Input:  { room_id, guest_id, song_id }
 * Pusher: 'song-voted' with the new vote count.
 */
require_once __DIR__ . '/../lib/helpers.php';

$in = request_input();
$roomId = strtoupper(trim(in_input($in, 'room_id', '')));
$guestId = (int) in_input($in, 'guest_id', 0);
$songId  = (int) in_input($in, 'song_id', 0);
require_room($roomId);
if (!$guestId || !$songId) json_error('guest_id and song_id required');

db()->prepare("UPDATE queue SET votes = votes + 1 WHERE id = ? AND room_id = ? AND status = 'queued'")
    ->execute([$songId, $roomId]);

$stmt = db()->prepare('SELECT votes FROM queue WHERE id = ?');
$stmt->execute([$songId]);
$votes = (int) $stmt->fetchColumn();

pusher_trigger(room_channel($roomId), 'song-voted', ['song_id' => $songId, 'votes' => $votes]);
json_ok(['votes' => $votes]);
