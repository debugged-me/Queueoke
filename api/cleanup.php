<?php
/**
 * GET /api/cleanup.php
 * Deletes expired rooms. Call via cPanel cron once an hour:
 *   0 * * * * curl -s https://yourdomain/api/cleanup.php
 * (The FKs cascade to guests/queue/reactions_log.)
 */
require_once __DIR__ . '/../lib/helpers.php';

$stmt = db()->prepare('DELETE FROM rooms WHERE expires_at <= NOW()');
$stmt->execute();
$deleted = $stmt->rowCount();
json_ok(['deleted' => $deleted]);
