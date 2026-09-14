<?php
/**
 * POST /api/react.php
 * Guest triggers a soundboard reaction. We log it (for "most-hyped singer" stats)
 * and push it to the TV instantly so the sound effect + confetti fire on the big screen.
 *
 * Input:  { room_id, guest_id?, reaction }
 *   reaction: 'applause' | 'cheer' | 'airhorn' | 'gong' | 'chime' | 'confetti' | 'boo'
 * Pusher: 'reaction' on room channel.
 */
require_once __DIR__ . '/../lib/helpers.php';

$in = request_input();
$roomId  = strtoupper(trim(in_input($in, 'room_id', '')));
$guestId = in_input($in, 'guest_id', null);
$reaction = in_input($in, 'reaction', '');

$allowed = ['applause','cheer','airhorn','gong','chime','confetti','boo'];
if (!in_array($reaction, $allowed, true)) json_error('unknown reaction');

require_room($roomId);

// Look up nickname + color so the TV can show who reacted.
$nickname = 'Someone';
$color = '#ffffff';
if ($guestId) {
    $gs = db()->prepare('SELECT nickname, color, status FROM guests WHERE id = ? AND room_id = ?');
    $gs->execute([(int)$guestId, $roomId]);
    $g = $gs->fetch();
    if ($g && $g['status'] === 'approved') {
        $nickname = $g['nickname'];
        $color = $g['color'];
    }
}

db()->prepare('INSERT INTO reactions_log (room_id, guest_id, reaction) VALUES (?,?,?)')
    ->execute([$roomId, $guestId ? (int)$guestId : null, $reaction]);

pusher_trigger(room_channel($roomId), 'reaction', [
    'reaction' => $reaction,
    'nickname' => $nickname,
    'color'    => $color,
]);

json_ok();
