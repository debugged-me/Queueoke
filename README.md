# Queueoke 🎤📺

Turn your TV into a karaoke stage. Use your phone as the remote. No apps, no
typing on the TV, no karaoke operator.

- **TV Host Mode** — full-screen YouTube player, QR code for guests, 5-second
  "Get Ready to Sing!" countdown, guest approval gate, sound effects + confetti
  from the crowd.
- **Mobile Remote** — search songs, queue, vote, trigger reactions, save
  favorites, ask the Gemini AI DJ for mood-based picks.
- **Real-time sync** — every add / skip / reorder / reaction is pushed to all
  devices instantly via Pusher.

Built with plain **PHP + MySQL + Pusher** so it deploys on any cPanel host.
No Node, no build step, no composer on the server, no external microservice.

---

## Architecture

```
cPanel host (Apache + PHP)              Managed real-time service (Pusher)
├─ index.php  (role chooser)            ─ relays events to all browsers
├─ tv.php     (TV host mode)            ─ (free tier is plenty for a party)
├─ remote.php (mobile remote)
├─ api/*.php  (stateless endpoints)     Google Gemini (AI DJ) + YouTube
└─ assets/    (CSS/JS, no build step)    Data API (search) via PHP proxies
```

- **Playback** is 100% client-side (YouTube IFrame API) → zero server load.
- **5-second countdown** ("Get Ready to Sing!") gives the singer a cue to get
  ready before each song starts.
- **Real-time** uses Pusher (a managed WebSocket relay) so your cPanel host
  never has to hold a persistent connection. PHP just calls
  `$pusher->trigger(...)` after each MySQL write.
- **Favorites/history** live in each phone's LocalStorage (per the spec — no
  accounts, no server-side personal data).
- **Sound effects** are synthesized in-browser with the Web Audio API, so
  there are no audio files to upload.

---

## Deploy on cPanel (step by step)

### 1. Upload the files
Upload the whole `Queueoke` folder to your cPanel `public_html` (or a
subfolder). You can zip it, upload via cPanel File Manager, and extract.
**Do not upload `config.php` yet** — you'll create it in step 4.

### 2. Create the MySQL database
- cPanel → **MySQL Databases** → Create a database, e.g. `yourname_queueoke`.
- Create a user + password, and **Add the user to the database** with **ALL PRIVILEGES**.

### 3. Import the schema
- cPanel → **phpMyAdmin** → select your `queueoke` database → **Import** tab.
- Choose `schema.sql` from this repo → **Go**.
- You should see 5 tables: `rooms`, `guests`, `queue`, `reactions_log`, `songs_cache`.

### 4. Create your `config.php`
- Copy `config.sample.php` to `config.php` (in the project root).
- Edit `config.php` and fill in the values (see "Getting your keys" below).
- Upload `config.php` to the server. **Never commit it to git** (it's in `.gitignore`).

### 5. Set the public URL
In `config.php`, set `PUBLIC_URL` to the full URL of the deployed site, e.g.
`https://queueoke.example.com` (no trailing slash). The TV's QR code points
guests at `PUBLIC_URL/?room=XXXX`.

### 6. (Optional) Set up room cleanup cron
Rooms auto-expire after `ROOM_TTL_HOURS` (default 6h). To actually delete
expired rows, add a cron job in cPanel → **Cron Jobs**:
```
0 * * * * curl -s https://yourdomain/api/cleanup.php
```

### 7. Open it
- On your TV (or a laptop connected to speakers): open `https://yourdomain/`
  → tap **I'm the TV**. A room is created and a QR code appears.
- Guests scan the QR code with their phone camera → join → host approves →
  party.

---

## Getting your keys

### Pusher (real-time push) — free tier
1. Sign up at https://pusher.com → **Create app** → Channels.
2. Pick a cluster near you (e.g. `us2`).
3. Copy **App ID**, **Key**, **Secret**, and the **Cluster** into `config.php`.
   The free tier (200k messages/day, 100 concurrent connections) is far more
   than any house party needs.

### YouTube Data API v3 (song search) — free quota
1. https://console.cloud.google.com/ → create a project.
2. **APIs & Services → Library** → enable **YouTube Data API v3**.
3. **Credentials → Create credentials → API key**. Copy it into `config.php`
   as `YOUTUBE_KEY`.
   - Playback itself uses the YouTube IFrame API and needs **no key**.

### Google Gemini (AI DJ) — free tier
1. https://aistudio.google.com/app/apikey → **Create API key**.
2. Copy it into `config.php` as `GEMINI_KEY`. The default model
   `gemini-flash-latest` is cheap and fast.

---

## How the two modes work

### TV Host Mode (`tv.php?id=XXXX&token=YYYY`)
- Renders the YouTube player full-screen with a 5-second "Get Ready to Sing!"
  countdown before each song.
- Shows the room QR code + room code for guests.
- Pops guest join requests for one-tap approve/deny.
- Plays synthesized applause / cheer / airhorn / gong / chime and bursts
  confetti when guests tap their soundboard.
- Controls: **Skip**, **Mic** (Web Audio echo monitor for any plugged-in
  mic), **Pitch** slider (−6…+6 semitones), **Theater** (hide the sidebar).

### Mobile Remote (`remote.php?room=XXXX&guest=GID&...`)
- **Search** — debounced YouTube search, biased toward karaoke versions, with
  a "karaoke" badge on verified tracks.
- **Queue** — see what's playing + up next, upvote songs (democratic queue).
- **AI DJ** — pick a mood (Party Belters, Rock Anthems, …) and Gemini
  suggests tracks, which are then resolved to real YouTube videos.
- **Favorites** — saved on this phone only (LocalStorage); re-queue instantly.
- **React** — soundboard that fires sound + confetti on the TV.

---

## Notes & limitations (honest engineering reality)

- **Pitch transpose of YouTube audio**: the YouTube IFrame API only allows a
  fixed set of playback rates, and changing the rate changes *both* pitch and
  tempo (like a tape). True pitch-only transpose of a YouTube stream isn't
  possible in-browser because the audio isn't exposed to Web Audio. The
  pitch slider snaps to the nearest available rate. For exact-key karaoke,
  use YouTube videos that are already uploaded in the target key.
- **Vocal removal**: not included. YouTube embeds don't expose the audio
  stream for stem separation. Use karaoke channels (e.g. "Sing King",
  "Karafun") which already have the lead vocal removed.
- **Mic echo** uses `getUserMedia` and requires the page to be served over
  **HTTPS** and the user to grant mic permission. On the TV, plug a mic into
  the laptop running the browser.
- **YouTube embeds** must allow embedding; a tiny fraction of videos disable
  it and will fail to play. The search endpoint filters to `videoEmbeddable=true`.

---

## Local development (optional)

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
Real-time (Pusher) and search/AI DJ need real keys in `config.php`; without
them those endpoints return a clear "not configured" error and the rest of the
app still works (you can create rooms, join, queue, and play locally).

---

## File map

```
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

## License
MIT — do whatever you want. Have a great party. 🎉
