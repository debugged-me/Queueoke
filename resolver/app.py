"""
Queueoke YouTube Resolver Microservice

A tiny Flask app that wraps yt-dlp to resolve YouTube video IDs into direct
streamable URLs for HTML5 <video> playback.

Deploy on Render / Railway / Fly.io / any free tier that runs Python.
See README.md for deployment steps.

Endpoints:
  GET /resolve?video_id=XXX  ->  { url, mime, quality, height, has_audio }
  GET /health                ->  { ok: true }
"""
import re
import logging
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


def resolve_video(video_id: str) -> dict:
    """Resolve a YouTube video ID to a direct streamable URL."""
    if not re.match(r"^[A-Za-z0-9_-]{11}$", video_id):
        raise ValueError("Invalid video ID")

    url = f"https://www.youtube.com/watch?v={video_id}"
    opts = {
        **YT_OPTS_BASE,
        # Use 'best' — always available. May be progressive (av+audio) or video-only.
        # We check has_audio in the response so the TV knows.
        "format": "best",
    }

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


@app.route("/debug")
@cross_origin()
def debug():
    """Debug endpoint — lists all available formats for a video."""
    video_id = request.args.get("video_id", "dQw4w9WgXcQ").strip()
    url = f"https://www.youtube.com/watch?v={video_id}"
    opts = {**YT_OPTS_BASE, "listformats": True}
    try:
        with yt_dlp.YoutubeDL({**YT_OPTS_BASE, "skip_download": True}) as ydl:
            info = ydl.extract_info(url, download=False)
        formats = []
        for f in info.get("formats", []):
            formats.append({
                "format_id": f.get("format_id"),
                "ext": f.get("ext"),
                "height": f.get("height"),
                "vcodec": f.get("vcodec"),
                "acodec": f.get("acodec"),
                "has_url": bool(f.get("url")),
                "format_note": f.get("format_note", ""),
            })
        # Split into progressive (av+audio) and adaptive
        progressive = [f for f in formats if f["vcodec"] not in (None, "none") and f["acodec"] not in (None, "none")]
        video_only = [f for f in formats if f["vcodec"] not in (None, "none") and f["acodec"] in (None, "none")]
        audio_only = [f for f in formats if f["vcodec"] in (None, "none") and f["acodec"] not in (None, "none")]
        return jsonify({
            "ok": True,
            "title": info.get("title", ""),
            "yt_dlp_version": yt_dlp.version.__version__,
            "format_count": len(formats),
            "progressive_count": len(progressive),
            "video_only_count": len(video_only),
            "audio_only_count": len(audio_only),
            "progressive": progressive,
            "video_only_best": video_only[:3],
        })
    except Exception as e:
        return jsonify({"ok": False, "error": str(e), "yt_dlp_version": yt_dlp.version.__version__}), 500


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
