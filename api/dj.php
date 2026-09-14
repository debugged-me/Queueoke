<?php
/**
 * POST /api/dj.php
 * Gemini AI DJ. Given a mood, returns 5-8 song suggestions (title + artist).
 * The phone then searches each via search.php and lets the guest queue them.
 * We keep the Gemini key server-side.
 *
 * Input:  { mood }
 *   mood: 'Party Belters' | 'Rock Anthems' | 'Pop Nostalgia' | 'Emotional Duets' | 'Chill Vibes'
 * Output: { ok, suggestions: [{ title, artist }] }
 */
require_once __DIR__ . '/../lib/helpers.php';

$in = request_input();
$mood = trim(in_input($in, 'mood', ''));
$allowed = ['Party Belters','Rock Anthems','Pop Nostalgia','Emotional Duets','Chill Vibes'];
if (!in_array($mood, $allowed, true)) json_error('unknown mood');

$c = config_get();
$key = $c['GEMINI_KEY'];
$model = $c['GEMINI_MODEL'];
if (str_starts_with($key, 'AIza...')) json_error('Gemini API key not configured', 503);

$prompt = "You are a karaoke DJ. Suggest 6 well-known karaoke songs for the mood \"{$mood}\". "
        . "Return ONLY a JSON array, no prose, of objects with \"title\" and \"artist\". "
        . "Pick songs that are widely known, fun to sing, and have karaoke versions on YouTube.";

$body = json_encode([
    'contents' => [['parts' => [['text' => $prompt]]]],
    'generationConfig' => ['temperature' => 0.9, 'maxOutputTokens' => 600],
]);

$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $key;
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 12,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($code !== 200) json_error('Gemini request failed', 502);

$j = json_decode($resp, true);
$text = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
// Strip ```json fences if present.
$text = preg_replace('/^```json\s*|\s*```$/', '', trim($text));
$suggestions = json_decode($text, true);
if (!is_array($suggestions)) json_error('Could not parse DJ suggestions', 502);

json_ok(['suggestions' => array_slice($suggestions, 0, 8)]);
