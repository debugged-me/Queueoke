# Queueoke — project notes for agents

## Stack
- **Backend**: plain PHP 8 (no framework, no composer on server). One file per API action in `api/`.
- **DB**: MySQL/MariaDB via PDO. Schema in `schema.sql`. Import via phpMyAdmin.
- **Real-time**: Pusher (managed WebSocket relay). Self-contained minimal client in `lib/pusher.php` (signed REST POST, no SDK).
- **Video playback**: HTML5 `<video>` element (NOT YouTube IFrame API). The TV calls `api/resolve.php` → `lib/youtube.php` → resolver microservice to get a direct streamable URL.
- **Resolver microservice**: Python (Flask + yt-dlp) in `resolver/`. Deploys separately on a free-tier PaaS (Render/Railway/Fly.io). cPanel can't run Python/yt-dlp.
- **Frontend**: vanilla JS + CSS. No build step. Pusher + Web Audio loaded from CDNs. No YouTube IFrame script.
- **Secrets**: `config.php` (gitignored). Copy from `config.sample.php`. Never commit.

## Conventions
- All API endpoints `require_once __DIR__ . '/../lib/helpers.php';` (which pulls in config/db/pusher + sets CORS + JSON helpers).
- Responses use `json_ok([...])` / `json_error($msg, $code)`.
- Room channel name: `room-<ID>` (see `room_channel()` in helpers). Same name used by PHP `pusher_trigger` and JS `Pusher.subscribe`.
- Time handling: PHP uses `gmdate()` (UTC); `lib/db.php` sets the PDO connection to `time_zone='+00:00'` so `NOW()` matches. Do NOT mix local time.
- SQL string literals use **single quotes** (e.g. `status = 'queued'`), never double quotes — double quotes are identifiers under `ANSI_QUOTES` and break portability.
- Favorites/history are client-side LocalStorage only (no personal data server-side), per the product spec.

## Key gotchas found during build
- PDO prepared statement parameter order must match placeholder order in the SQL text. A swapped `(int)$guestId, $roomId` vs `room_id = ? AND guest_id = ?` silently returned 0 and broke the anti-hog limit. Always double-check `execute([...])` order against the query.
- cPanel shared hosting cannot run a persistent WebSocket server (Apache reaps processes). That's why real-time is delegated to Pusher and the host only serves static files + stateless PHP.
- **Pure-PHP YouTube URL extraction is not feasible.** YouTube's signature cipher is now WASM-based (the old JS split/join/reverse/splice patterns are gone from their player JS). The internal player API requires PoT tokens. Public resolver APIs (Cobalt, Piped) are down or require auth. The only working approach is a separate microservice running yt-dlp (Python), which cPanel can't host.
- The 5-second "Get Ready to Sing!" countdown serves double duty: it's a visual cue AND gives the `<video>` element time to buffer the resolved URL before playback starts.

## Pitch transpose limitation
HTML5 `<video>` uses `playbackRate` with `preservesPitch=false` (tape-style: shifts both pitch AND tempo). True pitch-only shifting (same tempo, different key) requires routing video audio through Web Audio + a pitch shifter library (SoundTouch.js). This is a future enhancement — the video element CAN expose its audio to Web Audio via `MediaElementAudioSourceNode`, unlike the YouTube IFrame API which couldn't.

## Local dev
1. Start resolver: `cd resolver && source .venv/bin/activate && PORT=5001 python3 app.py`
2. Start PHP: `php -S 127.0.0.1:8000 -t .` (after creating `queueoke_test` DB + importing `schema.sql`)
3. `config.php` needs `RESOLVER_URL=http://127.0.0.1:5001`
4. Real-time/search/DJ need real Pusher/YouTube/Gemini keys; the rest works without.

## Verification commands
- `php -l <file>` — syntax check every PHP file.
- Smoke test flow: create_room → join → approve → queue (add 5, 6th must be blocked by anti-hog) → state.
