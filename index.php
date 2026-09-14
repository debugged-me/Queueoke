<?php
/**
 * Landing / role chooser. Two paths:
 *   - "I'm the TV"  -> creates a room, redirects to tv.php?id=XXX&token=YYY
 *   - "I'm a Guest" -> show join form (room code + nickname) -> remote.php?room=XXX
 */
require_once __DIR__ . '/lib/config.php';
$cfg = config_get();
$publicUrl = rtrim($cfg['PUBLIC_URL'], '/');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Queueoke</title>
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="center-screen">
  <div class="brand">Queueoke</div>
  <div class="tagline">Turn your TV into a karaoke stage. Use your phone as the remote. No apps, no typing on the TV.</div>

  <div class="role-pick">
    <button class="role-card" id="role-tv" type="button">
      <div class="icon">📺</div>
      <h3>I'm the TV</h3>
      <p>Open this on your TV browser or laptop. Creates a room and shows a QR code for guests to scan.</p>
    </button>

    <button class="role-card" id="role-guest" type="button">
      <div class="icon">📱</div>
      <h3>I'm a Guest</h3>
      <p>Scan the TV's QR code, or enter the room code to join from your phone.</p>
    </button>
  </div>

  <!-- Guest join form (hidden until "I'm a Guest" tapped) -->
  <form id="join-form" class="panel hidden" style="width:100%; max-width:380px;">
    <div class="field">
      <label for="room_id">Room code</label>
      <input class="input" id="room_id" name="room_id" autocomplete="off" autocapitalize="characters"
             maxlength="5" placeholder="AB7K2" style="text-align:center; font-size:1.4rem; letter-spacing:6px;">
    </div>
    <div class="field">
      <label for="nickname">Your nickname</label>
      <input class="input" id="nickname" name="nickname" maxlength="40" placeholder="DJ Sparkles">
    </div>
    <button class="btn btn-primary btn-lg" type="submit" style="width:100%">Join the party</button>
  </form>

  <div class="small muted">No accounts. No passwords. Room data is wiped when the party ends.</div>
</div>

<script>
window.QUEUEOKE_CFG = { pusher_key: '', pusher_cluster: '<?= htmlspecialchars($cfg['PUSHER_CLUSTER']) ?>', api_base: 'api' };
</script>
<script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
<script src="assets/js/common.js"></script>
<script>
const Q = window.Q;
// TV path: create a room then redirect.
document.getElementById('role-tv').addEventListener('click', async () => {
  const btn = document.getElementById('role-tv');
  btn.disabled = true; btn.querySelector('h3').textContent = 'Creating room...';
  try {
    const r = await Q.api('create_room.php', { title: 'Party Room' });
    location.href = 'tv.php?id=' + r.room.id + '&token=' + r.room.host_token;
  } catch { btn.disabled = false; btn.querySelector('h3').textContent = 'I\'m the TV'; }
});

// Guest path: reveal join form.
document.getElementById('role-guest').addEventListener('click', () => {
  document.getElementById('join-form').classList.remove('hidden');
  document.getElementById('role-guest').disabled = true;
  document.getElementById('room_id').focus();
});

// If ?room=XXX in URL (from QR code), prefill + auto-focus nickname.
const preRoom = Q.qs('room');
if (preRoom) {
  document.getElementById('role-guest').click();
  document.getElementById('room_id').value = preRoom.toUpperCase();
  document.getElementById('nickname').focus();
}

document.getElementById('join-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const roomId = document.getElementById('room_id').value.trim().toUpperCase();
  const nick = document.getElementById('nickname').value.trim() || 'Guest';
  if (!roomId) { Q.toast('Enter the room code', 'error'); return; }
  try {
    const r = await Q.api('join.php', { room_id: roomId, nickname: nick });
    location.href = 'remote.php?room=' + roomId + '&guest=' + r.guest.id + '&nick=' + encodeURIComponent(r.guest.nickname) + '&color=' + encodeURIComponent(r.guest.color) + '&status=' + r.guest.status;
  } catch { /* toast shown by api() */ }
});
</script>
</body>
</html>
