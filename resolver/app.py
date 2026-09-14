"""
Queueoke YouTube Resolver Microservice

Resolves YouTube video IDs to direct streamable URLs for HTML5 <video> playback.

Uses a two-step approach to bypass YouTube bot detection on datacenter IPs:
1. Pre-fetch the watch page with cookies (bypasses bot detection)
2. Pass the pre-fetched page to yt-dlp for signature deciphering

Authentication:
  Set COOKIES_B64 env var with base64-encoded Netscape cookies exported from
  your browser while logged into YouTube.

Endpoints:
  GET /resolve?video_id=XXX  ->  { url, mime, quality, height, has_audio }
  GET /health                ->  { ok: true }
"""
import re
import os
import json
import base64
import logging
import tempfile
import urllib.request
from flask import Flask, jsonify, request
from flask_cors import cross_origin
import yt_dlp

app = Flask(__name__)
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("queueoke-resolver")

COOKIES_B64 = os.environ.get("COOKIES_B64", "")


def get_cookies_path():
    """Write cookies to a temp file and return the path."""
    if not COOKIES_B64:
        return None
    try:
        content = base64.b64decode(COOKIES_B64).decode("utf-8")
        tmp = tempfile.NamedTemporaryFile(mode="w", suffix=".txt", delete=False)
        tmp.write(content)
        tmp.close()
        return tmp.name
    except Exception:
        return None


def get_cookie_header():
    """Build a Cookie header string from the base64-encoded cookie file."""
    if not COOKIES_B64:
        return ""
    try:
        content = base64.b64decode(COOKIES_B64).decode("utf-8")
        cookies = {}
        for line in content.splitlines():
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            parts = line.split("\t")
            if len(parts) >= 7:
                cookies[parts[5]] = parts[6]
        return "; ".join(f"{k}={v}" for k, v in cookies.items())
    except Exception:
        return ""


def resolve_video(video_id: str) -> dict:
    """Resolve a YouTube video ID to a direct streamable URL."""
    if not re.match(r"^[A-Za-z0-9_-]{11}$", video_id):
        raise ValueError("Invalid video ID")

    cookies_path = get_cookies_path()
    cookie_header = get_cookie_header()

    # Try multiple client strategies in order. The android client often works
    # on datacenter IPs without cookies. If it fails, fall back to cookies.
    strategies = [
        # Strategy 1: Android client, no cookies (works for most videos)
        {
            "format": "18/best[ext=mp4][vcodec!=none][acodec!=none]/best",
            "extractor_args": {"youtube": {"player_client": ["android"]}},
        },
        # Strategy 2: Android client with cookies (for bot-detected videos)
        {
            "format": "18/best[ext=mp4][vcodec!=none][acodec!=none]/best",
            "extractor_args": {"youtube": {"player_client": ["android"]}},
            "use_cookies": True,
        },
        # Strategy 3: Web client with cookies (last resort)
        {
            "format": "18/best[ext=mp4][vcodec!=none][acodec!=none]/best",
            "extractor_args": {"youtube": {"player_client": ["web"]}},
            "use_cookies": True,
        },
    ]

    last_error = None
    for i, strategy in enumerate(strategies):
        use_cookies = strategy.pop("use_cookies", False)
        opts = {
            "quiet": True,
            "no_warnings": True,
            "noplaylist": True,
            "skip_download": True,
            **strategy,
        }
        if use_cookies and cookies_path:
            opts["cookiefile"] = cookies_path
            log.info("Strategy %d: %s + cookies for %s", i + 1, strategy["extractor_args"]["youtube"]["player_client"], video_id)
        else:
            log.info("Strategy %d: %s for %s", i + 1, strategy["extractor_args"]["youtube"]["player_client"], video_id)

        try:
            with yt_dlp.YoutubeDL(opts) as ydl:
                info = ydl.extract_info(
                    f"https://www.youtube.com/watch?v={video_id}",
                    download=False,
                )

            direct_url = info.get("url")
            ext = info.get("ext", "mp4")
            height = info.get("height") or 0
            quality = info.get("format_note") or f"{height}p"
            has_audio = info.get("acodec", "none") != "none"

            if not direct_url:
                for f in info.get("requested_formats", []):
                    if f.get("url"):
                        direct_url = f["url"]
                        ext = f.get("ext", ext)
                        height = f.get("height", height)
                        has_audio = f.get("acodec", "none") != "none"
                        break

            if not direct_url:
                raise RuntimeError("No direct URL found")

            mime_map = {"mp4": "video/mp4", "webm": "video/webm"}
            mime = mime_map.get(ext, f"video/{ext}")

            log.info("Resolved %s -> %s %s %s (strategy %d)", video_id, quality, mime, "audio" if has_audio else "video-only", i + 1)

            return {
                "url": direct_url,
                "mime": mime,
                "quality": quality,
                "height": height,
                "has_audio": has_audio,
                "title": info.get("title", ""),
                "duration": info.get("duration", 0),
            }
        except Exception as e:
            last_error = e
            log.warning("Strategy %d failed for %s: %s", i + 1, video_id, str(e)[:100])
            continue

    raise last_error or RuntimeError("All strategies failed")


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
    port = int(os.environ.get("PORT", 5000))
    app.run(host="0.0.0.0", port=port)
