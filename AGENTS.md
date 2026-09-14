# Queueoke — project notes for agents

## Stack
- **Backend**: plain PHP 8 (no framework, no composer on server). One file per API action in `api/`.
- **DB**: MySQL/MariaDB via PDO. Schema in `schema.sql`. Import via phpMyAdmin.
- **Real-time**: Pusher (managed WebSocket relay). Self-contained minimal client in `lib/pusher.php` (signed REST POST, no SDK).
- **Video playback**: YouTube IFrame API (`YT.Player`). 100% client-side, zero server load, no resolver needed.
- **Frontend**: vanilla JS + CSS. No build step. Pusher + YouTube IFrame API loaded from CDNs.
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
- **YouTube datacenter IP blocking**: we tried a separate Python (yt-dlp) resolver microservice on Render to extract direct video URLs for an HTML5 `<video>` element. YouTube aggressively blocks datacenter IPs with "Sign in to confirm you're not a bot" — even with cookies, even with the android client. This was unreliable for most videos. Reverted to the YouTube IFrame API which is 100% reliable.
- The 5-second "Get Ready to Sing!" countdown is a visual cue for the singer before each song starts.

## Pitch transpose limitation
The YouTube IFrame API only allows a fixed set of playback rates (0.25, 0.5, 0.75, 1, 1.25, 1.5, 1.75, 2). Changing the rate shifts both pitch AND tempo (tape-style). True pitch-only shifting isn't possible because the IFrame API doesn't expose audio to Web Audio. The pitch slider snaps to the nearest available rate. For exact-key karaoke, use YouTube videos already uploaded in the target key.

## Local dev
1. Start PHP: `php -S 127.0.0.1:8000 -t .` (after creating `queueoke_test` DB + importing `schema.sql`)
2. `config.php` needs DB credentials + Pusher/YouTube/Gemini keys
3. Real-time/search/DJ need real keys; the rest works without.

## Verification commands
- `php -l <file>` — syntax check every PHP file.
- Smoke test flow: create_room → join → approve → queue (add 5, 6th must be blocked by anti-hog) → state.
