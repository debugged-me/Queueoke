<?php
/**
 * Mobile Remote. URL: remote.php?room=XXX&guest=GID&nick=NAME&color=HEX&status=approved|pending
 * - Search songs, add to queue, vote, react, favorites, AI DJ
 * - If status=pending, shows a "waiting for host approval" screen.
 */
require_once __DIR__ . '/lib/config.php';
$cfg = config_get();
$roomId = strtoupper(trim($_GET['room'] ?? ''));
$guestId = (int)($_GET['guest'] ?? 0);
$nick = $_GET['nick'] ?? 'Guest';
$color = $_GET['color'] ?? '#ff5a5f';
$status = $_GET['status'] ?? 'approved';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Queueoke · Remote</title>
<link rel="stylesheet" href="assets/css/app.css">
<style>
  body { padding-bottom: 70px; }
  .topbar { position: sticky; top: 0; z-index: 100; background: rgba(18,18,31,0.85);
    padding: 12px 16px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid var(--panel-2);
    backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); }
  .topbar .avatar { width: 34px; height: 34px; }
  .topbar .nick { font-weight: 700; }
  .conn-status {
    margin-left: auto; font-size: .8rem; color: var(--muted);
    display: inline-flex; align-items: center; gap: 6px;
  }
  .conn-status .dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: var(--amber); box-shadow: 0 0 8px var(--amber);
    animation: pulse-dot 1.5s ease infinite;
  }
  .conn-status.live .dot { background: var(--green); box-shadow: 0 0 8px var(--green); animation: none; }
  .conn-status.live { color: var(--green); }
  @keyframes pulse-dot { 0%,100% { opacity: 1; } 50% { opacity: .4; }
  .tabs { display: flex; gap: 6px; padding: 10px 12px; position: sticky; top: 57px; z-index: 99;
    background: rgba(8,8,15,0.85); border-bottom: 1px solid var(--panel-2); overflow-x: auto;
    backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); }
  .tab { padding: 8px 16px; border-radius: 999px; background: var(--panel); font-weight: 600; font-size: .88rem; white-space: nowrap; transition: background var(--t-fast), color var(--t-fast); border: 1px solid transparent; }
  .tab.active { background: var(--grad-pink); color: #fff; box-shadow: var(--glow-pink); border-color: transparent; }
  .pane { padding: 16px; display: none; }
  .pane.active { display: block; animation: fadein .25s ease; }
  @keyframes fadein { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
  .search-row { display: flex; gap: 8px; margin-bottom: 14px; }
  .search-row .input { flex: 1; }
  .result { display: flex; gap: 10px; padding: 12px; border-radius: 12px; background: var(--panel); border: 1px solid transparent; margin-bottom: 8px; align-items: center; transition: background var(--t-fast), border-color var(--t-fast); }
  .result:hover { background: var(--panel-2); border-color: var(--panel-3); }
  .result img { width: 96px; height: 54px; object-fit: cover; border-radius: 8px; background: var(--bg-2); }
  .result .meta { flex: 1; min-width: 0; }
  .result .t { font-weight: 600; font-size: .92rem; }
  .result .s { color: var(--muted); font-size: .78rem; }
  .result .acts { display: flex; flex-direction: column; gap: 4px; }
  .icon-btn { width: 38px; height: 38px; border-radius: 10px; background: var(--panel-2);
    display: flex; align-items: center; justify-content: center; font-size: 1.1rem; transition: transform var(--t-fast), background var(--t-fast); }
  .icon-btn:active { transform: scale(0.92); }
  .icon-btn.fav-on { background: var(--amber); color: #1a1a2e; }
  .icon-btn.add { background: var(--grad-pink); color: #fff; box-shadow: var(--glow-pink); }
  .qitem { display: flex; gap: 10px; padding: 12px; border-radius: 12px; background: var(--panel); border: 1px solid transparent; margin-bottom: 8px; align-items: center; transition: background var(--t-fast); }
  .qitem:hover { background: var(--panel-2); }
  .qitem.playing { background: var(--grad-pink); border-color: rgba(255,45,120,0.3); }
  .qitem.playing .t { color: #fff; }
  .qitem.playing .s { color: rgba(255,255,255,0.8); }
  .qitem .pos { width: 24px; text-align: center; font-weight: 800; color: var(--purple); }
  .qitem img { width: 56px; height: 40px; object-fit: cover; border-radius: 6px; }
  .qitem .meta { flex: 1; min-width: 0; }
  .qitem .t { font-weight: 600; font-size: .9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .qitem .s { color: var(--muted); font-size: .76rem; }
  .qitem .vote { display: flex; align-items: center; gap: 4px; font-weight: 700; }
  .soundboard { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
  .sfx { aspect-ratio: 1; border-radius: 16px; background: var(--panel); border: 1px solid var(--panel-2); display: flex;
    flex-direction: column; align-items: center; justify-content: center; gap: 4px; font-size: 2rem; font-weight: 800;
    transition: transform var(--t-fast), background var(--t-fast), box-shadow var(--t-fast); }
  .sfx:active { transform: scale(0.94); background: var(--grad-pink); box-shadow: var(--glow-pink); }
  .sfx .lbl { font-size: .72rem; font-weight: 600; color: var(--muted); }
  .moods { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
  .mood { padding: 8px 16px; border-radius: 999px; background: var(--panel); border: 1px solid var(--panel-2); font-size: .85rem; font-weight: 600; transition: background var(--t-fast), border-color var(--t-fast); }
  .mood.active { background: var(--grad-pink); color: #fff; border-color: transparent; box-shadow: var(--glow-pink); }
  .pending-screen { min-height: 80vh; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 16px; text-align: center; padding: 24px; }
  .pending-screen .big { font-size: 4rem; animation: pulse 1.4s ease infinite; }
  @keyframes pulse { 0%,100% { transform: scale(1); } 50% { transform: scale(1.15); } }
</style>
</head>
<body>
<div class="topbar">
  <div class="avatar" style="background:<?= htmlspecialchars($color) ?>"><?= htmlspecialchars(mb_substr($nick,0,1)) ?></div>
  <div>
    <div class="nick"><?= htmlspecialchars($nick) ?></div>
    <div class="small muted">Room <?= htmlspecialchars($roomId) ?></div>
  </div>
  <div class="conn-status waiting" id="conn-status"><span class="dot"></span> connecting</div>
</div>

<!-- Pending approval screen -->
<div class="pending-screen" id="pending-screen">
  <div class="big">⏳</div>
  <div class="h1">Waiting for host approval</div>
  <div class="muted">The TV host will let you in shortly. Hang tight!</div>
  <div class="small muted" style="margin-top:8px">Room <?= htmlspecialchars($roomId) ?></div>
</div>

<!-- Main app (hidden until approved) -->
<div id="app" class="hidden">
  <div class="tabs">
    <button class="tab active" data-tab="search">🔍 Search</button>
    <button class="tab" data-tab="queue">📋 Queue</button>
    <button class="tab" data-tab="dj">🤖 AI DJ</button>
    <button class="tab" data-tab="favs">⭐ Favorites</button>
    <button class="tab" data-tab="sfx">🎉 React</button>
  </div>

  <!-- SEARCH -->
  <div class="pane active" id="pane-search">
    <div class="search-row">
      <input class="input" id="search-input" placeholder="Search karaoke songs..." autocomplete="off">
    </div>
    <div id="search-results"><div class="muted small">Type to search millions of karaoke tracks.</div></div>
  </div>

  <!-- QUEUE -->
  <div class="pane" id="pane-queue">
    <div class="section-title">Now playing</div>
    <div id="now-playing"><div class="muted small">Nothing playing yet.</div></div>
    <div class="section-title" style="margin-top:14px">Up next</div>
    <div id="upcoming"><div class="muted small">Queue is empty.</div></div>
  </div>

  <!-- AI DJ -->
  <div class="pane" id="pane-dj">
    <div class="section-title">Pick a mood for instant suggestions</div>
    <div class="moods" id="moods">
      <button class="mood" data-mood="Party Belters">🎉 Party Belters</button>
      <button class="mood" data-mood="Rock Anthems">🎸 Rock Anthems</button>
      <button class="mood" data-mood="Pop Nostalgia">💿 Pop Nostalgia</button>
      <button class="mood" data-mood="Emotional Duets">💔 Emotional Duets</button>
      <button class="mood" data-mood="Chill Vibes">🌙 Chill Vibes</button>
    </div>
    <div id="dj-results"><div class="muted small">Tap a mood to get AI-curated karaoke picks.</div></div>
  </div>

  <!-- FAVORITES -->
  <div class="pane" id="pane-favs">
    <div class="section-title">Your signature tracks (saved on this phone)</div>
    <div id="fav-list"><div class="muted small">No favorites yet. Tap the ⭐ on a song to save it.</div></div>
  </div>

  <!-- SOUNDBOARD -->
  <div class="pane" id="pane-sfx">
    <div class="section-title">Hype up the singer</div>
    <div class="soundboard">
      <button class="sfx" data-r="applause">👏<span class="lbl">Applause</span></button>
      <button class="sfx" data-r="cheer">🎉<span class="lbl">Cheer</span></button>
      <button class="sfx" data-r="airhorn">📯<span class="lbl">Airhorn</span></button>
      <button class="sfx" data-r="gong">🔔<span class="lbl">Gong</span></button>
      <button class="sfx" data-r="chime">✨<span class="lbl">Chime</span></button>
      <button class="sfx" data-r="confetti">🎊<span class="lbl">Confetti</span></button>
    </div>
    <div class="small muted" style="margin-top:14px">Reactions play through the TV speakers and trigger confetti on the big screen.</div>
  </div>
</div>

<script>
window.QUEUEOKE_CFG = {
  pusher_key: '<?= htmlspecialchars($cfg['PUSHER_KEY']) ?>',
  pusher_cluster: '<?= htmlspecialchars($cfg['PUSHER_CLUSTER']) ?>',
  api_base: 'api',
  room_id: '<?= htmlspecialchars($roomId) ?>',
  guest_id: <?= $guestId ?>,
  nick: '<?= htmlspecialchars($nick) ?>',
  color: '<?= htmlspecialchars($color) ?>',
  status: '<?= htmlspecialchars($status) ?>',
};
</script>
<script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
<script src="assets/js/common.js"></script>
<script src="assets/js/remote.js"></script>
</body>
</html>
