<?php
/**
 * POST /api/reorder.php
 * Reorder the queue. Host can drag songs; guests can't.
 * Sends the new full ordered list of queued ids; we reassign fractional positions.
 *
 * Input:  { room_id, host_token, ordered_ids: [int, int, ...] }
 * Pusher: 'queue-reordered' with the new ordered ids.
 */
require_once __DIR__ . '/../lib/helpers.php';

$in = request_input();
$roomId = strtoupper(trim(in_input($in, 'room_id', '')));
$token  = in_input($in, 'host_token', '');
require_host($roomId, $token);

$ordered = in_input($in, 'ordered_ids', []);
if (!is_array($ordered) || count($ordered) === 0) json_error('ordered_ids required');

$upd = db()->prepare("UPDATE queue SET position = ? WHERE id = ? AND room_id = ? AND status = 'queued'");
$pos = 1.0;
foreach ($ordered as $qid) {
    $upd->execute([$pos, (int)$qid, $roomId]);
    $pos += 1.0;
}

pusher_trigger(room_channel($roomId), 'queue-reordered', ['ordered_ids' => array_map('intval', $ordered)]);
json_ok();
