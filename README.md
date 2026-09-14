# Queueoke 🎤📺

Turn your TV into a karaoke stage. Use your phone as the remote. No apps, no
typing on the TV, no karaoke operator.

- **TV Host Mode** — full-screen HTML5 video player, QR code for guests, 5-second
  "Get Ready to Sing!" countdown (for buffering), guest approval gate, sound
  effects + confetti from the crowd.
- **Mobile Remote** — search songs, queue, vote, trigger reactions, save
  favorites, ask the Gemini AI DJ for mood-based picks.
- **Real-time sync** — every add / skip / reorder / reaction is pushed to all
  devices instantly via Pusher.

Built with **PHP + MySQL + Pusher** for the main app (deploys on any cPanel
host), plus a tiny **Python microservice** (Flask + yt-dlp) that resolves
YouTube video IDs to direct streamable URLs for HTML5 `<video>` playback.

---

## Architecture

```
cPanel host (Apache + PHP)              Managed real-time service (Pusher)
├─ index.php  (role chooser)            ─ relays events to all browsers
├─ tv.php     (TV host mode, <video>)   ─ (free tier is plenty for a party)
├─ remote.php (mobile remote)
├─ api/*.php  (stateless endpoints)     Google Gemini (AI DJ) + YouTube
└─ assets/    (CSS/JS, no build step)    Data API (search) via PHP proxies
                                         │
Resolver microservice (free tier)        │  PHP calls resolver to get
└─ resolver/  (Python + Flask + yt-dlp) ─┘  direct video URLs for <video>
```

- **Playback** uses a standard HTML5 `<video>` element. The TV calls
  `api/resolve.php` (which proxies to the resolver microservice) to get a
  direct streamable URL, then sets it as the `<video src>`. No YouTube IFrame.
- **5-second countdown** ("Get Ready to Sing!") runs while the video URL is
  resolved and preloaded, so playback starts smoothly without buffering stalls.
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

### 7. Deploy the resolver microservice

The resolver converts YouTube video IDs into direct streamable URLs for the
HTML5 `<video>` player. It runs **separately** from your cPanel host (cPanel
shared hosting can't run Python/yt-dlp). Deploy it on any free-tier PaaS:

**Option A: Render (recommended, easiest)**
1. Go to https://render.com → New → **Web Service**.
2. Connect your git repo, set the **Root Directory** to `resolver/`.
3. Build Command: `pip install -r requirements.txt`
4. Start Command: `gunicorn app:app --bind 0.0.0.0:$PORT --workers 2 --timeout 30`
5. Render auto-detects Python and sets `PORT`. Free tier works fine.
6. Copy the deployed URL (e.g. `https://queueoke-resolver.onrender.com`).

**Option B: Railway / Fly.io / any PaaS**
- Same idea: deploy the `resolver/` folder, install `requirements.txt`, run
  the `Procfile` command. The service listens on `$PORT`.

**Option C: Any VPS with Python 3.9+**
```bash
cd resolver
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
gunicorn app:app --bind 0.0.0.0:5000 --workers 2 --timeout 30
```

Once deployed, set `RESOLVER_URL` in your `config.php` to the microservice URL
(e.g. `https://queueoke-resolver.onrender.com`, no trailing slash).

> **Why a separate service?** YouTube's signature cipher is now WASM-based
> and changes frequently. Only actively-maintained tools like yt-dlp can keep
> up. yt-dlp is Python, and cPanel shared hosting can't run it — so we deploy
> a tiny microservice elsewhere and PHP calls it. This is the same pattern the
> reference site (karaoke.zoryva.me) uses.

### 8. Open it
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
   - Search uses this key. Playback uses the resolver microservice (above).

### Resolver microservice — free tier
Deploy the `resolver/` folder (see step 7 above). Set `RESOLVER_URL` in
`config.php` to the deployed URL. No API key needed — yt-dlp does the work.

### Google Gemini (AI DJ) — free tier
1. https://aistudio.google.com/app/apikey → **Create API key**.
2. Copy it into `config.php` as `GEMINI_KEY`. The default model
   `gemini-1.5-flash` is cheap and fast.

---

## How the two modes work

### TV Host Mode (`tv.php?id=XXXX&token=YYYY`)
- Renders the video full-screen in an HTML5 `<video>` element with a 5-second
  "Get Ready to Sing!" countdown before each song (gives the video time to
  buffer for smoother playback).
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

- **Pitch transpose**: the HTML5 `<video>` player uses `playbackRate` with
  `preservesPitch=false`, which shifts both pitch AND tempo (like a tape
  deck). True pitch-only shifting (same tempo, different key) is possible by
  routing the video's audio through Web Audio + a pitch shifter library
  (e.g. SoundTouch.js) — this is a future enhancement. For exact-key karaoke,
  use YouTube videos already uploaded in the target key.
- **Vocal removal**: not included. Use karaoke channels (e.g. "Sing King",
  "Karafun") which already have the lead vocal removed.
- **Mic echo** uses `getUserMedia` and requires the page to be served over
  **HTTPS** and the user to grant mic permission. On the TV, plug a mic into
  the laptop running the browser.
- **Resolver dependency**: playback depends on the resolver microservice
  being online. If it's down (or if yt-dlp needs updating after a YouTube
  change), songs won't resolve. The free tier on Render/Railway may spin down
  after inactivity — the first request after idle takes ~10s to wake up.
- **YouTube ToS**: extracting direct video URLs via yt-dlp is technically
  against YouTube's Terms of Service (Section 4). This is the same approach
  the reference site (karaoke.zoryva.me) uses. Use at your own discretion.

---

## Local development (optional)

You need PHP + MySQL (XAMPP works) and Python 3.9+ for the resolver.

```bash
# 1. Create a test DB and import the schema
mysql -uroot -e "CREATE DATABASE queueoke_test CHARACTER SET utf8mb4;"
mysql -uroot queueoke_test < schema.sql

# 2. Copy config and point it at your local DB + resolver
cp config.sample.php config.php
# edit config.php: DB_NAME=queueoke_test, DB_USER=root, DB_PASS=...
#   RESOLVER_URL=http://127.0.0.1:5001

# 3. Start the resolver microservice
cd resolver
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
PORT=5001 python3 app.py

# 4. In another terminal, run PHP's built-in server
php -S 127.0.0.1:8000 -t .

# 5. Open http://127.0.0.1:8000/  (use "I'm the TV")
```
Real-time (Pusher) and search/AI DJ need real keys in `config.php`; without
them those endpoints return a clear "not configured" error and the rest of the
app still works (you can create rooms, join, queue, and play locally).

---

## File map

```
index.php          Landing / role chooser (TV or Guest)
tv.php             TV host mode (HTML5 <video>, QR, queue, approvals, sfx, confetti)
remote.php         Mobile remote (search, queue, DJ, favorites, soundboard)
config.sample.php  Copy to config.php and fill in keys
schema.sql         MySQL schema (import via phpMyAdmin)
api/               Stateless PHP endpoints (create_room, join, approve, queue,
                   skip, reorder, vote, react, search, dj, now_playing, pitch,
                   resolve, state, cleanup)
lib/               PHP helpers (config loader, PDO, Pusher client, YouTube resolver client, utils)
resolver/          Python microservice (Flask + yt-dlp) — deploys separately
assets/css/        Shared styles
assets/js/         common.js (shared), tv.js (TV), remote.js (mobile)
```

## License
MIT — do whatever you want. Have a great party. 🎉
