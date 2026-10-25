<h1 align="center">🎤</h1>

<h1 align="center">Queueoke</h1>

<p align="center">
  <i>Turn your TV into a karaoke stage — every phone in the room is the remote 📺</i>
</p>

<h4 align="center">
  <a href="#development"><img src="https://img.shields.io/badge/PHP-8%2B-777BB4?style=flat-square" alt="PHP 8+" style="height: 20px;"></a>
  <a href="#usage"><img src="https://img.shields.io/badge/MySQL-MariaDB-4479A1?style=flat-square" alt="MySQL / MariaDB" style="height: 20px;"></a>
  <a href="#usage"><img src="https://img.shields.io/badge/realtime-Pusher-300D4F?style=flat-square" alt="Pusher" style="height: 20px;"></a>
  <br>
  <a href="#development"><img src="https://img.shields.io/badge/build%20step-none-74B087?style=flat-square" alt="No build step" style="height: 20px;"></a>
  <a href="#usage"><img src="https://img.shields.io/badge/deploys%20on-cPanel-F59620?style=flat-square" alt="Deploys on cPanel" style="height: 20px;"></a>
  <a href="#license"><img src="https://img.shields.io/badge/license-MIT-2E6B4F?style=flat-square" alt="MIT license" style="height: 20px;"></a>
</h4>

## Introduction

Queueoke turns any screen with a browser into a karaoke stage. Open it on the TV, guests join by scanning a QR code with their phones — no apps, no typing on the TV, no karaoke operator.

Guests search YouTube's karaoke catalog, queue songs, vote on the order, and fire reactions that burst confetti on the TV. A Gemini-powered AI DJ suggests tracks by mood.

It's plain PHP + MySQL + Pusher, so it deploys on any cPanel shared host. Playback is 100% client-side (YouTube IFrame API) — the server never touches video, and there's no Node, no build step, no composer, no microservice.

<details open>
<summary><strong>Features</strong></summary> <br />

| 📺 TV Host Mode | 📱 Mobile Remote |
| --- | --- |
| Full-screen YouTube player | Debounced search, biased to karaoke |
| Room QR code + join code | Queue + upvotes — democratic order |
| 5s "Get Ready to Sing!" countdown | Gemini AI DJ mood picks |
| One-tap guest approve / deny | Favorites saved on the phone |
| Soundboard sfx + confetti bursts | Soundboard fires sfx on the TV |
| Skip · mic echo · pitch · theater | Any mobile browser — no app |

Every add, skip, reorder, and reaction is pushed to all devices instantly via Pusher — the free tier is far more than a house party needs.

<details>
<summary>📺 <strong>TV host mode</strong> — <code>tv.php?id=XXXX&amp;token=YYYY</code></summary>

- Renders the YouTube player full-screen with a 5-second "Get Ready to Sing!" countdown before each song.
- Shows the room QR code + room code for guests.
- Pops guest join requests for one-tap approve/deny.
- Plays synthesized applause / cheer / airhorn / gong / chime and bursts confetti when guests tap their soundboard.
- Controls: **Skip**, **Mic** (Web Audio echo monitor for any plugged-in mic), **Pitch** slider (−6…+6 semitones), **Theater** (hide the sidebar).

</details>

<details>
<summary>📱 <strong>Mobile remote</strong> — <code>remote.php?room=XXXX&amp;guest=GID</code></summary>

- **Search** — debounced YouTube search, biased toward karaoke versions, with a "karaoke" badge on verified tracks.
- **Queue** — see what's playing + up next, upvote songs (democratic queue).
- **AI DJ** — pick a mood (Party Belters, Rock Anthems, …) and Gemini suggests tracks, which are then resolved to real YouTube videos.
- **Favorites** — saved on this phone only (LocalStorage); re-queue instantly.
- **React** — soundboard that fires sound + confetti on the TV.

</details>

<details>
<summary>⚡ <strong>Architecture</strong> — stateless PHP + managed real-time</summary>

```text
cPanel host (Apache + PHP)              Managed real-time service (Pusher)
├─ index.php  (role chooser)            ─ relays events to all browsers
├─ tv.php     (TV host mode)            ─ (free tier is plenty for a party)
├─ remote.php (mobile remote)
├─ api/*.php  (stateless endpoints)     Google Gemini (AI DJ) + YouTube
└─ assets/    (CSS/JS, no build step)    Data API (search) via PHP proxies
```

- **Playback** is 100% client-side (YouTube IFrame API) → zero server load.
- **5-second countdown** ("Get Ready to Sing!") gives the singer a cue to get ready before each song starts.
- **Real-time** uses Pusher (a managed WebSocket relay) so your cPanel host never has to hold a persistent connection. PHP just calls `$pusher->trigger(...)` after each MySQL write.
- **Favorites/history** live in each phone's LocalStorage — no accounts, no server-side personal data.
- **Sound effects** are synthesized in-browser with the Web Audio API, so there are no audio files to upload.

</details>

<details>
<summary>🎛️ <strong>Honest limitations</strong> — what YouTube's embed can and can't do</summary>

- **Pitch transpose of YouTube audio**: the YouTube IFrame API only allows a fixed set of playback rates, and changing the rate changes *both* pitch and tempo (like a tape). True pitch-only transpose of a YouTube stream isn't possible in-browser because the audio isn't exposed to Web Audio. The pitch slider snaps to the nearest available rate. For exact-key karaoke, use YouTube videos already uploaded in the target key.
- **Vocal removal**: not included. YouTube embeds don't expose the audio stream for stem separation. Use karaoke channels (e.g. "Sing King", "Karafun") which already have the lead vocal removed.
- **Mic echo** uses `getUserMedia` and requires the page to be served over **HTTPS** and the user to grant mic permission. On the TV, plug a mic into the laptop running the browser.
- **YouTube embeds** must allow embedding; a tiny fraction of videos disable it and will fail to play. The search endpoint filters to `videoEmbeddable=true`.

</details>

</details>

## Usage

Deploy on any cPanel shared host:

1. **Upload the files** — zip this folder, upload via cPanel File Manager to `public_html` (or a subfolder), and extract. **Don't upload `config.php` yet.**
2. **Create the database** — cPanel → **MySQL Databases** → create `yourname_queueoke`, create a user, and **Add to database** with ALL PRIVILEGES.
3. **Import the schema** — phpMyAdmin → your database → **Import** → choose `schema.sql`. You should see 5 tables: `rooms`, `guests`, `queue`, `reactions_log`, `songs_cache`.
4. **Create `config.php`** — copy `config.sample.php`, fill in your keys (below), upload it. It's gitignored — never commit it.
5. **(Optional) cleanup cron** — rooms expire after `ROOM_TTL_HOURS` (default 6h). To actually delete them, add a cron job: `0 * * * * curl -s https://yourdomain/api/cleanup.php`
6. **Party** — open `https://yourdomain/` on the TV, tap **I'm the TV**. A room is created and a QR code appears. Guests scan → join → you approve → party.

> **Note**
> `PUBLIC_URL` is auto-detected from the request, so the QR code on the TV just works — no config needed.

<details>
<summary>Getting your keys</summary> <br />

**Pusher (real-time push) — free tier**

1. Sign up at [pusher.com](https://pusher.com) → **Create app** → Channels.
2. Pick a cluster near you (e.g. `us2`).
3. Copy **App ID**, **Key**, **Secret**, and the **Cluster** into `config.php`.
   The free tier (200k messages/day, 100 concurrent connections) is plenty.

**YouTube Data API v3 (song search) — free quota**

1. [console.cloud.google.com](https://console.cloud.google.com/) → create a project.
2. **APIs & Services → Library** → enable **YouTube Data API v3**.
3. **Credentials → Create credentials → API key** → `YOUTUBE_KEY` in `config.php`.
   Playback itself uses the YouTube IFrame API and needs **no key**.

**Google Gemini (AI DJ) — free tier**

1. [aistudio.google.com/app/apikey](https://aistudio.google.com/app/apikey) → **Create API key**.
2. Copy it into `config.php` as `GEMINI_KEY`. The default `gemini-flash-latest` is cheap and fast.

</details>

<details>
<summary>Local development</summary> <br />

You need PHP + MySQL (XAMPP works).

```bash
# 1. Create a test DB and import the schema
mysql -uroot -e "CREATE DATABASE queueoke_test CHARACTER SET utf8mb4;"
mysql -uroot queueoke_test < schema.sql

# 2. Copy config and point it at your local DB
cp config.sample.php config.php
# edit config.php: DB_NAME=queueoke_test, DB_USER=root, DB_PASS=...

# 3. Run PHP's built-in server
php -S 127.0.0.1:8000 -t .

# 4. Open http://127.0.0.1:8000/  (use "I'm the TV")
```

Real-time (Pusher) and search/AI DJ need real keys in `config.php`; without them those endpoints return a clear "not configured" error and the rest of the app still works — you can create rooms, join, queue, and play locally.

</details>

## Development

No build step — vanilla PHP, JS and CSS. Verify changes with `php -l <file>` and the smoke flow: create_room → join → approve → queue (add 5, the 6th must be blocked by the anti-hog limit) → state.

<details>
<summary>File map</summary> <br />

```text
index.php          Landing / role chooser (TV or Guest)
tv.php             TV host mode (YouTube IFrame API, QR, queue, approvals, sfx, confetti)
remote.php         Mobile remote (search, queue, DJ, favorites, soundboard)
config.sample.php  Copy to config.php and fill in keys
schema.sql         MySQL schema (import via phpMyAdmin)
api/               Stateless PHP endpoints (create_room, join, approve, queue,
                   skip, reorder, vote, react, search, dj, now_playing, pitch,
                   state, cleanup)
lib/               PHP helpers (config loader, PDO, Pusher client, YouTube search, utils)
assets/css/        Shared styles
assets/js/         common.js (shared), tv.js (TV), remote.js (mobile)
```

</details>

## Resources

- **[config.sample.php](config.sample.php)** — every key explained inline.
- **[schema.sql](schema.sql)** — the five tables, ready for phpMyAdmin import.
- **[AGENTS.md](AGENTS.md)** — conventions, gotchas, and the full engineering notes for contributors.
- **[Issues](https://github.com/debugged-me/Queueoke/issues)** — bug reports and feature requests.

## Contributing

Issues and pull requests are welcome — it's a party app, keep the diffs fun.

## License

MIT — do whatever you want. Have a great party. 🎉
