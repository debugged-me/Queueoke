<?php
/**
 * GET /api/resolve.php?video_id=XXX
 * Resolves a YouTube video ID to a direct streamable URL for HTML5 <video>.
 * The TV calls this right before playing a song.
 *
 * Output: { ok, url, mime, quality, height, has_audio }
 *         or { ok: false, error } on failure.
 *
 * NOTE: Resolved URLs expire (typically 6h). Resolve fresh each time.
 */
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/youtube.php';

$videoId = trim(in_input($_GET, 'video_id', ''));
if ($videoId === '') json_error('video_id required');

try {
    $result = youtube_resolve($videoId);
    json_ok($result);
} catch (Exception $e) {
    json_error('Resolve failed: ' . $e->getMessage(), 502);
}
