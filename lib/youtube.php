<?php
/**
 * YouTube URL resolver client.
 *
 * Calls the Queueoke resolver microservice (Python + yt-dlp) to get a direct
 * streamable URL for HTML5 <video> playback. The microservice must be deployed
 * separately — see README.md.
 *
 * @param string $videoId  YouTube video id (11 chars)
 * @return array { url, mime, quality, height, has_audio, title, duration }
 * @throws Exception on failure
 */
function youtube_resolve(string $videoId): array {
    $videoId = preg_replace('/[^A-Za-z0-9_-]/', '', $videoId);
    if (strlen($videoId) !== 11) throw new Exception('Invalid video id');

    $c = config_get();
    $resolverUrl = rtrim($c['RESOLVER_URL'] ?? '', '/');
    if (!$resolverUrl || $resolverUrl === 'https://your-resolver.onrender.com') {
        throw new Exception('RESOLVER_URL not configured. Deploy the resolver microservice (see README).');
    }

    $url = $resolverUrl . '/resolve?video_id=' . urlencode($videoId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) throw new Exception("Resolver connection failed: $err");
    if ($code !== 200) {
        $msg = json_decode($resp, true)['error'] ?? "HTTP $code";
        throw new Exception("Resolver error: $msg");
    }

    $data = json_decode($resp, true);
    if (!$data || !($data['ok'] ?? false) || !($data['url'] ?? '')) {
        throw new Exception('Resolver returned no URL');
    }

    return [
        'url'       => $data['url'],
        'mime'      => $data['mime'] ?? 'video/mp4',
        'quality'   => $data['quality'] ?? '',
        'height'    => $data['height'] ?? 0,
        'has_audio' => $data['has_audio'] ?? true,
        'title'     => $data['title'] ?? '',
        'duration'  => $data['duration'] ?? 0,
    ];
}
