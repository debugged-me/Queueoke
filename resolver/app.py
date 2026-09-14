"""
Queueoke YouTube Resolver Microservice

A tiny Flask app that wraps yt-dlp to resolve YouTube video IDs into direct
streamable URLs for HTML5 <video> playback.

Deploy on Render / Railway / Fly.io / any free tier that runs Python.
See README.md for deployment steps.

Authentication:
  YouTube blocks datacenter IPs with bot detection. To bypass it, export
  your YouTube cookies and set the COOKIES_FILE env var (or COOKIES_B64 for
  the base64-encoded cookie file content) on the hosting platform.

Endpoints:
  GET /resolve?video_id=XXX  ->  { url, mime, quality, height, has_audio }
  GET /health                ->  { ok: true }
"""
import re
import os
import base64
import logging
import tempfile
from flask import Flask, jsonify, request
from flask_cors import cross_origin
import yt_dlp

app = Flask(__name__)
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("queueoke-resolver")

# Silence yt-dlp's verbose output.
YT_OPTS_BASE = {
    "quiet": True,
    "no_warnings": True,
    "noplaylist": True,
    "skip_download": True,
}

# Cookie file path — set via env var on Render.
# To get cookies: see https://github.com/yt-dlp/yt-dlp/wiki/Extractors#exporting-youtube-cookies
COOKIES_FILE = os.environ.get("COOKIES_FILE", "")
COOKIES_B64 = os.environ.get("COOKIES_B64", "")


def get_cookies_path():
    """Return a path to a cookies file, or None."""
    if COOKIES_FILE and os.path.exists(COOKIES_FILE):
        return COOKIES_FILE
    if COOKIES_B64:
        # Decode base64 cookies to a temp file.
        try:
            content = base64.b64decode(COOKIES_B64).decode("utf-8")
            tmp = tempfile.NamedTemporaryFile(mode="w", suffix=".txt", delete=False)
            tmp.write(content)
            tmp.close()
            return tmp.name
        except Exception:
            return None
    return None


def resolve_video(video_id: str) -> dict:
    """Resolve a YouTube video ID to a direct streamable URL."""
    if not re.match(r"^[A-Za-z0-9_-]{11}$", video_id):
        raise ValueError("Invalid video ID")

    url = f"https://www.youtube.com/watch?v={video_id}"
    cookies_path = get_cookies_path()
    opts = {
        **YT_OPTS_BASE,
        # Use 'best' — always available. May be progressive (av+audio) or video-only.
        # We check has_audio in the response so the TV knows.
        "format": "best",
        # Try multiple clients to bypass bot detection on datacenter IPs.
        # Order matters: tv client often bypasses bot checks, then android, then web.
        "extractor_args": {"youtube": {"player_client": ["tv", "ios", "android", "web"]}},
    }
    if cookies_path:
        opts["cookiefile"] = cookies_path
        log.info("Using cookies from %s", cookies_path)

    with yt_dlp.YoutubeDL(opts) as ydl:
        info = ydl.extract_info(url, download=False)

    # The chosen format is in info directly when format selection picks one.
    direct_url = info.get("url")
    ext = info.get("ext", "mp4")
    height = info.get("height") or 0
    quality = info.get("format_note") or info.get("resolution") or f"{height}p"
    has_audio = info.get("acodec", "none") != "none"

    if not direct_url:
        # Fallback: look through requested_formats
        for f in info.get("requested_formats", []):
            if f.get("url"):
                direct_url = f["url"]
                ext = f.get("ext", ext)
                height = f.get("height", height)
                has_audio = f.get("acodec", "none") != "none"
                break

    if not direct_url:
        raise RuntimeError("No direct URL found")

    mime_map = {"mp4": "video/mp4", "webm": "video/webm", "mkv": "video/x-matroska"}
    mime = mime_map.get(ext, f"video/{ext}")

    log.info("Resolved %s -> %s %s %s", video_id, quality, mime, "audio" if has_audio else "video-only")

    return {
        "url": direct_url,
        "mime": mime,
        "quality": quality,
        "height": height,
        "has_audio": has_audio,
        "title": info.get("title", ""),
        "duration": info.get("duration", 0),
    }


@app.route("/health")
def health():
    return jsonify({"ok": True, "service": "queueoke-resolver"})


@app.route("/resolve")
@cross_origin()
def resolve():
    video_id = request.args.get("video_id", "").strip()
    if not video_id:
        return jsonify({"ok": False, "error": "video_id required"}), 400
    try:
        result = resolve_video(video_id)
        return jsonify({"ok": True, **result})
    except yt_dlp.utils.DownloadError as e:
        log.error("yt-dlp error for %s: %s", video_id, e)
        return jsonify({"ok": False, "error": f"yt-dlp: {e}"}), 502
    except Exception as e:
        log.error("resolve error for %s: %s", video_id, e)
        return jsonify({"ok": False, "error": str(e)}), 500


if __name__ == "__main__":
    # Render/railway set PORT env var.
    port = int(__import__("os").environ.get("PORT", 5000))
    app.run(host="0.0.0.0", port=port)
