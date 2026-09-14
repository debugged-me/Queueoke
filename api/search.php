<?php
/**
 * GET /api/search.php?q=QUERY
 * Proxies YouTube Data API v3 search so the API key never ships to the browser.
 * Prefers "karaoke" results by appending "karaoke" to the query, and flags results
 * whose title contains "karaoke" with is_karaoke=true (verified karaoke badge).
 *
 * Output: { ok, songs: [{ video_id, title, channel, thumbnail, duration_sec, is_karaoke }] }
 */
require_once __DIR__ . '/../lib/helpers.php';

$q = trim(in_input($_GET, 'q', ''));
if ($q === '') json_error('q required');

$c = config_get();
$apiKey = $c['YOUTUBE_KEY'];
if (str_starts_with($apiKey, 'AIza...')) json_error('YouTube API key not configured', 503);

// Append "karaoke" to bias results toward karaoke tracks.
$searchQ = $q . ' karaoke';

$url = 'https://www.googleapis.com/youtube/v3/search?' . http_build_query([
    'part'       => 'snippet',
    'type'       => 'video',
    'q'          => $searchQ,
    'maxResults' => 12,
    'videoEmbeddable' => 'true',
    'key'        => $apiKey,
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($code !== 200) json_error('YouTube search failed', 502);

$data = json_decode($resp, true);
$videoIds = array_column($data['items'] ?? [], 'id', 'videoId');
$ids = array_map(fn($i) => $i['videoId'], $data['items'] ?? []);

// Fetch durations + channel titles via a second call (contentDetails).
$durMap = [];
if (count($ids) > 0) {
    $durl = 'https://www.googleapis.com/youtube/v3/videos?' . http_build_query([
        'part' => 'contentDetails,snippet',
        'id'   => implode(',', $ids),
        'key'  => $apiKey,
    ]);
    $ch2 = curl_init($durl);
    curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
    $dresp = json_decode(curl_exec($ch2), true);
    curl_close($ch2);
    foreach (($dresp['items'] ?? []) as $v) {
        $durMap[$v['id']] = $v;
    }
}

$songs = [];
foreach ($data['items'] ?? [] as $item) {
    $vid = $item['id']['videoId'];
    $title = $item['snippet']['title'];
    $isKaraoke = preg_match('/karaoke/i', $title) ? 1 : 0;
    $dur = null;
    $channel = $item['snippet']['channelTitle'] ?? '';
    if (isset($durMap[$vid])) {
        $dur = iso8601_to_seconds($durMap[$vid]['contentDetails']['duration']);
        $channel = $durMap[$vid]['snippet']['channelTitle'] ?? $channel;
    }
    $thumb = $item['snippet']['thumbnails']['medium']['url']
           ?? $item['snippet']['thumbnails']['default']['url'] ?? null;
    $songs[] = [
        'video_id'     => $vid,
        'title'        => $title,
        'channel'      => $channel,
        'thumbnail'    => $thumb,
        'duration_sec' => $dur,
        'is_karaoke'   => $isKaraoke,
    ];
    // Cache it.
    db()->prepare('INSERT IGNORE INTO songs_cache (video_id, title, thumbnail, duration_sec, is_karaoke)
                   VALUES (?,?,?,?,?)')
        ->execute([$vid, $title, $thumb, $dur, $isKaraoke]);
}

json_ok(['songs' => $songs]);

/** ISO 8601 duration (PT1H2M3S) -> seconds. */
function iso8601_to_seconds(string $iso): int {
    preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $iso, $m);
    return ($m[1] ?? 0) * 3600 + ($m[2] ?? 0) * 60 + ($m[3] ?? 0);
}
