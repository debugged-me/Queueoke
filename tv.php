<?php
/**
 * TV Host Mode. Open this on the TV/laptop. URL: tv.php?id=XXX&token=YYY
 * - Full-screen YouTube player with countdown
 * - Room QR code for guests
 * - Guest approval toasts
 * - Sound effects + confetti from guest reactions
 * - Mic echo + pitch transpose controls
 */
require_once __DIR__ . '/lib/config.php';
$cfg = config_get();
$roomId = strtoupper(trim($_GET['id'] ?? ''));
$token  = trim($_GET['token'] ?? '');
$publicUrl = rtrim($cfg['PUBLIC_URL'], '/');
// The QR code points guests at the landing with ?room= so they get the join flow.
$qrTarget = $publicUrl . '/?room=' . urlencode($roomId);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Queueoke · TV</title>
<link rel="stylesheet" href="assets/css/app.css">
<style>
  /* TV-specific full-bleed layout */
  body { background: var(--bg); }
  .tv-root { display: grid; grid-template-columns: 1fr 380px; height: 100vh; }
  .stage { position: relative; background: #000; overflow: hidden; }
  #player-wrap { position: absolute; inset: 0; }
  #player-wrap iframe { width: 100%; height: 100%; }
  .stage-overlay { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 16px; pointer-events: none; z-index: 10; }
  .stage-overlay > * { pointer-events: auto; }
  .idle-msg { text-align: center; color: var(--muted); animation: breathe 3s ease infinite; }
  @keyframes breathe { 0%,100% { opacity: .7; } 50% { opacity: 1; } }
  .idle-msg .big { font-size: 3.5rem; font-weight: 900; background: var(--grad); -webkit-background-clip: text; background-clip: text; color: transparent; filter: drop-shadow(0 0 30px rgba(168,85,247,0.3)); }
  .idle-msg .guest-count { margin-top: 16px; }
  #countdown { text-align: center; }
  #countdown .ready-text { font-size: clamp(2rem, 5vw, 4rem); font-weight: 900; color: #fff; text-shadow: 0 0 40px rgba(255,45,120,0.6); animation: pop .9s ease; }
  #countdown .ready-sub { font-size: 1.2rem; color: var(--muted); margin-top: 8px; }
  #countdown .ready-num { font-size: 6rem; font-weight: 900; background: var(--grad); -webkit-background-clip: text; background-clip: text; color: transparent; margin-top: 12px; animation: pop .8s ease; filter: drop-shadow(0 0 20px rgba(255,45,120,0.4)); }
  @keyframes pop { 0% { transform: scale(0.4); opacity: 0; } 60% { transform: scale(1.1); opacity: 1; } 100% { transform: scale(1); } }
  .now-bar { position: absolute; bottom: 0; left: 0; right: 0; padding: 18px 24px;
    background: linear-gradient(transparent, rgba(0,0,0,0.85)); display: flex; align-items: flex-end; gap: 16px; z-index: 10; }
  .now-bar .title { font-size: 1.5rem; font-weight: 800; }
  .now-bar .sub { color: var(--muted); margin-top: 2px; }

  .sidebar { background: var(--bg-2); border-left: 1px solid var(--panel-2); display: flex; flex-direction: column; overflow: hidden; }
  .sidebar header { padding: 20px; border-bottom: 1px solid var(--panel-2); }
  .sidebar .room-code { font-size: 2.2rem; font-weight: 900; letter-spacing: 8px; background: var(--grad); -webkit-background-clip: text; background-clip: text; color: transparent; }
  .sidebar .qr-wrap { display: flex; gap: 14px; align-items: center; margin-top: 14px; }
  #qrcode { background: #fff; padding: 8px; border-radius: 12px; width: 140px; height: 140px; box-shadow: var(--shadow-sm); }
  #qrcode img, #qrcode canvas { width: 124px !important; height: 124px !important; }
  .sidebar .scroll { flex: 1; overflow-y: auto; padding: 16px; }
  .queue-item { display: flex; gap: 10px; padding: 12px; border-radius: 12px; background: var(--panel); border: 1px solid transparent; margin-bottom: 8px; align-items: center; transition: background var(--t-fast), border-color var(--t-fast); }
  .queue-item:hover { background: var(--panel-2); }
  .queue-item .pos { width: 28px; text-align: center; font-weight: 800; color: var(--purple); font-size: 1.1rem; }
  .queue-item img { width: 64px; height: 40px; object-fit: cover; border-radius: 6px; }
  .queue-item .meta { flex: 1; min-width: 0; }
  .queue-item .t { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .queue-item .s { color: var(--muted); font-size: .78rem; }
  .queue-item.playing { background: var(--grad-pink); border-color: rgba(255,45,120,0.3); }
  .queue-item.playing .pos, .queue-item.playing .s { color: rgba(255,255,255,0.85); }
  .queue-item.playing .t { color: #fff; }

  .controls { padding: 16px; border-top: 1px solid var(--panel-2); display: flex; flex-direction: column; gap: 12px; }
  .control-row { display: flex; align-items: center; gap: 10px; }
  .pitch-slider { flex: 1; accent-color: var(--purple); }
  .guest-toast { background: var(--panel); border: 1px solid var(--panel-3); border-radius: 12px; padding: 12px; margin-bottom: 10px; display: flex; align-items: center; gap: 10px; animation: slidein .3s ease; }
  .guest-toast .avatar { width: 36px; height: 36px; font-size: .9rem; }
  .guest-toast .btns { display: flex; gap: 6px; margin-left: auto; }
  .reaction-burst { position: absolute; top: 12%; left: 50%; transform: translateX(-50%);
    font-size: 4rem; pointer-events: none; animation: floatup 2s ease forwards; z-index: 9500; }
  @keyframes floatup { 0% { opacity: 0; transform: translate(-50%, 20px) scale(0.6); } 20% { opacity: 1; } 100% { opacity: 0; transform: translate(-50%, -120px) scale(1.2); } }
  @media (max-width: 900px) {
    .tv-root { grid-template-columns: 1fr; grid-template-rows: 60vh 40vh; }
    .sidebar { border-left: none; border-top: 1px solid var(--panel-2); }
  }
</style>
</head>
<body>
<div class="tv-root">
  <!-- STAGE -->
  <div class="stage">
    <div id="player-wrap"></div>
    <canvas id="confetti"></canvas>
    <div class="stage-overlay" id="overlay">
      <div class="idle-msg" id="idle-msg">
        <div class="big">Queueoke</div>
        <div>Waiting for the first song...</div>
        <div class="guest-count" id="guest-count" style="margin-top:16px">👥 0 guests</div>
        <div class="small muted" style="margin-top:8px">Guests: scan the QR code on the right →</div>
      </div>
      <div id="countdown" class="hidden"></div>
    </div>
    <div class="now-bar hidden" id="now-bar">
      <div>
        <div class="title" id="now-title">—</div>
        <div class="sub" id="now-sub">—</div>
      </div>
    </div>
  </div>

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <header>
      <div class="row between">
        <div>
          <div class="small muted">ROOM CODE</div>
          <div class="room-code" id="room-code"><?= htmlspecialchars($roomId) ?></div>
        </div>
        <button class="btn btn-sm btn-ghost" id="btn-theater">🎭 Theater</button>
      </div>
      <div class="qr-wrap">
        <div id="qrcode"></div>
        <div class="small muted">Guests scan this with their phone camera to join.</div>
      </div>
    </header>

    <div class="scroll">
      <div class="section-title">Guest join requests</div>
      <div id="guest-requests"><div class="small muted">No pending requests.</div></div>

      <div class="section-title" style="margin-top:18px">Up next</div>
      <div id="queue-list"><div class="small muted">Queue is empty.</div></div>
    </div>

    <div class="controls">
      <div class="control-row">
        <button class="btn btn-sm btn-danger" id="btn-skip">⏭ Skip</button>
        <div class="spacer"></div>
        <button class="btn btn-sm btn-ghost" id="btn-mic">🎤 Mic: off</button>
      </div>
      <div class="control-row">
        <span class="small muted">Pitch</span>
        <input type="range" class="pitch-slider" id="pitch" min="-6" max="6" step="1" value="0">
        <span class="small" id="pitch-val" style="width:34px; text-align:right">0</span>
      </div>
    </div>
  </aside>
</div>

<script>
window.QUEUEOKE_CFG = {
  pusher_key: '<?= htmlspecialchars($cfg['PUSHER_KEY']) ?>',
  pusher_cluster: '<?= htmlspecialchars($cfg['PUSHER_CLUSTER']) ?>',
  api_base: 'api',
  room_id: '<?= htmlspecialchars($roomId) ?>',
  host_token: '<?= htmlspecialchars($token) ?>',
  qr_target: '<?= htmlspecialchars($qrTarget) ?>',
};
</script>
<script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
<script src="https://www.youtube.com/iframe_api"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="assets/js/common.js"></script>
<script src="assets/js/tv.js"></script>
</body>
</html>
