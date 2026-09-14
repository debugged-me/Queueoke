<?php
/**
 * POST /api/approve.php
 * Host approves or denies a pending guest. Requires host token.
 *
 * Input:  { room_id, host_token, guest_id, decision: 'approved'|'kicked' }
 * Pusher: 'guest-decision' on room channel (phones listen to flip their own UI).
 */
require_once __DIR__ . '/../lib/helpers.php';

$in = request_input();
$roomId = strtoupper(trim(in_input($in, 'room_id', '')));
$token  = in_input($in, 'host_token', '');
$guestId = (int) in_input($in, 'guest_id', 0);
$decision = in_input($in, 'decision', '');

if (!in_array($decision, ['approved', 'kicked'], true)) json_error('decision must be approved|kicked');
require_host($roomId, $token);

$stmt = db()->prepare('UPDATE guests SET status = ? WHERE id = ? AND room_id = ?');
$stmt->execute([$decision, $guestId, $roomId]);
if ($stmt->rowCount() === 0) json_error('Guest not found', 404);

pusher_trigger(room_channel($roomId), 'guest-decision', [
    'guest_id' => $guestId,
    'decision' => $decision,
]);

json_ok();
