<?php
/**
 * Queueoke configuration.
 *
 * 1. Copy this file to config.php
 * 2. Fill in the values below.
 * 3. NEVER commit config.php to git (it's in .gitignore already).
 *
 * Where to get each value:
 *  - DB_*        : cPanel -> MySQL Databases / phpMyAdmin. Create a DB + user, grant all.
 *  - PUSHER_*    : https://pusher.com -> Create app (cluster e.g. "us2", get app_id/key/secret).
 *  - YOUTUBE_KEY : https://console.cloud.google.com -> enable "YouTube Data API v3", create API key.
 *  - GEMINI_KEY  : https://aistudio.google.com/app/apikey -> Create API key.
 */

return [
    // ----- MySQL (cPanel / phpMyAdmin) -----
    'DB_HOST'     => 'localhost',
    'DB_NAME'     => 'yourname_queueoke',
    'DB_USER'     => 'yourname_queue',
    'DB_PASS'     => 'change-me',

    // ----- Pusher (real-time push) -----
    // Free tier is plenty for a party. Pick the cluster shown in your Pusher dashboard.
    'PUSHER_APP_ID'   => '1234567',
    'PUSHER_KEY'      => 'public-key',
    'PUSHER_SECRET'   => 'secret-key',
    'PUSHER_CLUSTER'  => 'us2',

    // ----- YouTube Data API v3 (song search) -----
    // Used by api/search.php to search for songs. Playback uses the YouTube IFrame API (no key needed).
    'YOUTUBE_KEY'  => 'AIza...your-key',

    // ----- Google Gemini (AI DJ) -----
    'GEMINI_KEY'   => 'AIza...your-key',
    'GEMINI_MODEL' => 'gemini-flash-latest',

    // ----- App settings -----
    // PUBLIC_URL is auto-detected from the request — no need to set it.
    // Rooms auto-expire after this many hours of inactivity (cleaned by api/cleanup.php or cron).
    'ROOM_TTL_HOURS' => 6,
    // Max songs a single guest can have queued at once (anti-hog).
    'MAX_PER_GUEST'  => 5,
];
