<?php
/**
 * POST /api/pitch.php
 * TV updates the room's pitch transpose (-6..+6 semitones). Persisted so a TV
 * reload restores the setting. No pusher event needed (TV-only control).
 *
 * Input:  { room_id, host_token, pitch: int }
 */
require_once __DIR__ . '/../lib/helpers.php';

$in = request_input();
$roomId = strtoupper(trim(in_input($in, 'room_id', '')));
$token  = in_input($in, 'host_token', '');
$pitch  = (int) in_input($in, 'pitch', 0);
require_host($roomId, $token);
if ($pitch < -6 || $pitch > 6) json_error('pitch must be -6..+6');

db()->prepare('UPDATE rooms SET pitch = ? WHERE id = ?')->execute([$pitch, $roomId]);
json_ok(['pitch' => $pitch]);
