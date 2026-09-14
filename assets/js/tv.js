/* Queueoke TV host logic — HTML5 <video> player + resolver microservice. */
(function () {
  'use strict';
  const Q = window.Q, CFG = window.QUEUEOKE_CFG;
  const ROOM = CFG.room_id, TOKEN = CFG.host_token;
  if (!ROOM || !TOKEN) { alert('Missing room id or host token in URL.'); }

  // ---------- State ----------
  let queue = [];
  let nowPlaying = null;
  const player = document.getElementById('player'); // HTML5 <video> element
  let countdownActive = false;
  let resolving = false;
  let audioCtx = null, micStream = null, micNodes = null, micOn = false;

  // ---------- QR code ----------
  new QRCode(document.getElementById('qrcode'), { text: CFG.qr_target, width: 124, height: 124, correctLevel: 1 });

  // ---------- Video element events ----------
  player.addEventListener('ended', onSongEnded);
  player.addEventListener('playing', () => {
    document.getElementById('idle-msg')?.classList.add('hidden');
    document.getElementById('now-bar').classList.remove('hidden');
  });

  // ---------- Audio (soundboard effects + mic echo) ----------
  function ctx() { return audioCtx || (audioCtx = new (window.AudioContext || window.webkitAudioContext)()); }

  // Synthesized sound effects — no audio files needed.
  function noiseBuffer(dur) {
    const c = ctx(), len = Math.floor(c.sampleRate * dur), buf = c.createBuffer(1, len, c.sampleRate);
    const d = buf.getChannelData(0);
    for (let i = 0; i < len; i++) d[i] = Math.random() * 2 - 1;
    return buf;
  }
  function playApplause() {
    const c = ctx(), now = c.currentTime, dur = 2.4;
    for (let i = 0; i < 28; i++) {
      const src = c.createBufferSource(); src.buffer = noiseBuffer(0.05);
      const g = c.createGain(); const t = now + Math.random() * dur * 0.7;
      g.gain.setValueAtTime(0, t); g.gain.linearRampToValueAtTime(0.12 + Math.random() * 0.08, t + 0.005);
      g.gain.exponentialRampToValueAtTime(0.001, t + 0.05);
      src.connect(g).connect(c.destination); src.start(t);
    }
  }
  function playCheer() {
    const c = ctx(), now = c.currentTime, src = c.createBufferSource(); src.buffer = noiseBuffer(1.6);
    const bp = c.createBiquadFilter(); bp.type = 'bandpass'; bp.frequency.value = 800; bp.Q.value = 0.7;
    const g = c.createGain();
    g.gain.setValueAtTime(0, now); g.gain.linearRampToValueAtTime(0.25, now + 0.2);
    g.gain.linearRampToValueAtTime(0.2, now + 1.2); g.gain.exponentialRampToValueAtTime(0.001, now + 1.6);
    bp.frequency.linearRampToValueAtTime(1400, now + 1.6);
    src.connect(bp).connect(g).connect(c.destination); src.start(now);
  }
  function playAirhorn() {
    const c = ctx(), now = c.currentTime, osc = c.createOscillator(); osc.type = 'sawtooth';
    const g = c.createGain(); osc.frequency.setValueAtTime(330, now);
    osc.frequency.linearRampToValueAtTime(440, now + 0.6);
    g.gain.setValueAtTime(0, now); g.gain.linearRampToValueAtTime(0.3, now + 0.05);
    g.gain.setValueAtTime(0.3, now + 0.9); g.gain.exponentialRampToValueAtTime(0.001, now + 1.2);
    osc.connect(g).connect(c.destination); osc.start(now); osc.stop(now + 1.3);
  }
  function playGong() {
    const c = ctx(), now = c.currentTime;
    [110, 165, 220, 330].forEach((f, i) => {
      const o = c.createOscillator(); o.type = 'sine'; o.frequency.value = f;
      const g = c.createGain(); g.gain.setValueAtTime(0.4 / (i + 1), now);
      g.gain.exponentialRampToValueAtTime(0.001, now + 3.5);
      o.connect(g).connect(c.destination); o.start(now); o.stop(now + 3.6);
    });
  }
  function playChime() {
    const c = ctx(), now = c.currentTime, freqs = [880, 1108.73, 1318.51];
    freqs.forEach((f, i) => {
      const o = c.createOscillator(); o.type = 'sine'; o.frequency.value = f;
      const g = c.createGain(); const t = now + i * 0.12;
      g.gain.setValueAtTime(0, t); g.gain.linearRampToValueAtTime(0.25, t + 0.01);
      g.gain.exponentialRampToValueAtTime(0.001, t + 1.2);
      o.connect(g).connect(c.destination); o.start(t); o.stop(t + 1.3);
    });
  }
  function playBoo() {
    const c = ctx(), now = c.currentTime, o = c.createOscillator(); o.type = 'sawtooth';
    const g = c.createGain(); o.frequency.setValueAtTime(220, now);
    o.frequency.exponentialRampToValueAtTime(90, now + 0.8);
    g.gain.setValueAtTime(0, now); g.gain.linearRampToValueAtTime(0.2, now + 0.1);
    g.gain.exponentialRampToValueAtTime(0.001, now + 0.9);
    o.connect(g).connect(c.destination); o.start(now); o.stop(now + 1);
  }
  const SFX = { applause: playApplause, cheer: playCheer, airhorn: playAirhorn, gong: playGong, chime: playChime, boo: playBoo };

  // ---------- Confetti ----------
  const confettiCanvas = document.getElementById('confetti');
  let confettiParticles = [], confettiRAF = null;
  function resizeConfetti() { confettiCanvas.width = innerWidth; confettiCanvas.height = innerHeight; }
  window.addEventListener('resize', resizeConfetti); resizeConfetti();
  function burstConfetti() {
    const colors = ['#ff5a5f', '#7a6bff', '#40d7ce', '#ffb400', '#8ce08a', '#ff7ab6'];
    for (let i = 0; i < 140; i++) {
      confettiParticles.push({
        x: innerWidth / 2 + (Math.random() - 0.5) * 200, y: innerHeight / 2,
        vx: (Math.random() - 0.5) * 14, vy: Math.random() * -16 - 4,
        g: 0.4, size: Math.random() * 8 + 4, color: colors[(Math.random() * colors.length) | 0],
        rot: Math.random() * Math.PI, vr: (Math.random() - 0.5) * 0.3, life: 1,
      });
    }
    if (!confettiRAF) drawConfetti();
  }
  function drawConfetti() {
    const c = confettiCanvas.getContext('2d');
    c.clearRect(0, 0, confettiCanvas.width, confettiCanvas.height);
    confettiParticles = confettiParticles.filter(p => p.y < confettiCanvas.height + 40 && p.life > 0);
    confettiParticles.forEach(p => {
      p.vy += p.g; p.x += p.vx; p.y += p.vy; p.rot += p.vr; p.life -= 0.005;
      c.save(); c.translate(p.x, p.y); c.rotate(p.rot); c.globalAlpha = Math.max(0, p.life);
      c.fillStyle = p.color; c.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 0.6); c.restore();
    });
    if (confettiParticles.length) { confettiRAF = requestAnimationFrame(drawConfetti); }
    else { confettiRAF = null; c.clearRect(0, 0, confettiCanvas.width, confettiCanvas.height); }
  }

  // ---------- Reaction handler (from guests) ----------
  function handleReaction(data) {
    const fn = SFX[data.reaction];
    if (fn) fn();
    if (data.reaction === 'confetti' || data.reaction === 'cheer' || data.reaction === 'applause') burstConfetti();
    const emoji = { applause: '👏', cheer: '🎉', airhorn: '📯', gong: '🔔', chime: '✨', confetti: '🎊', boo: '🍅' }[data.reaction] || '🎵';
    const el = document.createElement('div');
    el.className = 'reaction-burst'; el.textContent = emoji;
    document.querySelector('.stage').appendChild(el);
    setTimeout(() => el.remove(), 2000);
  }

  // ---------- Mic with echo ----------
  async function toggleMic() {
    const btn = document.getElementById('btn-mic');
    if (micOn) {
      micStream?.getTracks().forEach(t => t.stop());
      micStream = null; micNodes = null; micOn = false;
      btn.textContent = '🎤 Mic: off'; btn.classList.remove('btn-primary');
      return;
    }
    try {
      micStream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: false, noiseSuppression: false } });
      const c = ctx();
      if (c.state === 'suspended') await c.resume();
      const src = c.createMediaStreamSource(micStream);
      const dry = c.createGain(); dry.gain.value = 0.8;
      const delay = c.createDelay(2.0); delay.delayTime.value = 0.18;
      const feedback = c.createGain(); feedback.gain.value = 0.35;
      const wet = c.createGain(); wet.gain.value = 0.5;
      src.connect(dry).connect(c.destination);
      src.connect(delay); delay.connect(feedback); feedback.connect(delay);
      delay.connect(wet).connect(c.destination);
      micNodes = { src, dry, delay, feedback, wet };
      micOn = true; btn.textContent = '🎤 Mic: on'; btn.classList.add('btn-primary');
    } catch (e) { Q.toast('Mic access denied: ' + e.message, 'error'); }
  }

  // ---------- Pitch (HTML5 video: playbackRate + preservesPitch=false = tape-style) ----------
  // NOTE: This shifts both pitch AND tempo (like a tape). True pitch-only shifting
  // is possible with Web Audio + a pitch shifter library (future enhancement).
  function applyPitch(s) {
    document.getElementById('pitch-val').textContent = (s > 0 ? '+' : '') + s;
    const rate = Math.pow(2, s / 12);
    player.playbackRate = rate;
    // preservesPitch=false → pitch changes with rate (tape-style transpose)
    player.preservesPitch = false;
    player.maintainsPitch = false; // Safari
    Q.api('pitch.php', { room_id: ROOM, host_token: TOKEN, pitch: s }).catch(() => {});
  }
  document.getElementById('pitch').addEventListener('input', (e) => applyPitch(parseInt(e.target.value, 10)));

  // ---------- Queue rendering ----------
  function renderQueue() {
    const list = document.getElementById('queue-list');
    const upcoming = queue.filter(s => s.status === 'queued');
    if (!upcoming.length) { list.innerHTML = '<div class="small muted">Queue is empty.</div>'; return; }
    list.innerHTML = upcoming.map((s, i) => `
      <div class="queue-item" data-id="${s.id}">
        <div class="pos">${i + 1}</div>
        <img src="${s.thumbnail || ''}" alt="">
        <div class="meta">
          <div class="t">${esc(s.title)}</div>
          <div class="s">${esc(s.nickname)}${s.votes ? ' · 👍 ' + s.votes : ''}</div>
        </div>
      </div>`).join('');
  }
  function renderNowBar() {
    if (!nowPlaying) { document.getElementById('now-bar').classList.add('hidden'); return; }
    document.getElementById('now-bar').classList.remove('hidden');
    document.getElementById('now-title').textContent = nowPlaying.title;
    document.getElementById('now-sub').textContent = '🎤 ' + (nowPlaying.nickname || 'Host');
  }
  function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

  // ---------- Auto-advance / countdown ----------
  function maybeAdvance() {
    if (nowPlaying || countdownActive) return;
    const next = queue.find(s => s.status === 'queued');
    if (!next) {
      document.getElementById('idle-msg')?.classList.remove('hidden');
      return;
    }
    startCountdown(next);
  }

  // 5-second "Get Ready to Sing!" countdown — gives the video time to buffer.
  // During the countdown, we resolve the direct URL and start preloading.
  function startCountdown(song) {
    countdownActive = true;
    const el = document.getElementById('countdown');
    el.classList.remove('hidden');
    el.innerHTML = '<div class="ready-text">Get Ready to Sing!</div><div class="ready-sub">Starting in…</div><div class="ready-num">5</div>';

    // Start resolving the URL immediately so it buffers during the countdown.
    resolving = true;
    Q.api('resolve.php?video_id=' + encodeURIComponent(song.video_id))
      .then(r => {
        player.src = r.url;
        player.load(); // begin buffering
        resolving = false;
      })
      .catch(() => {
        // If resolve fails, show error and skip this song.
        Q.toast('Could not resolve video. Skipping…', 'error');
        el.classList.add('hidden');
        countdownActive = false;
        resolving = false;
        // Mark as skipped and advance.
        if (song.id) queue = queue.filter(s => s.id !== song.id);
        renderQueue();
        setTimeout(maybeAdvance, 300);
      });

    let n = 5;
    const numEl = () => el.querySelector('.ready-num');
    const tick = () => {
      n--;
      if (n > 0) {
        const ne = numEl(); if (ne) { ne.textContent = n; ne.style.animation = 'none'; void ne.offsetWidth; ne.style.animation = ''; }
        setTimeout(tick, 1000);
      } else {
        el.classList.add('hidden');
        countdownActive = false;
        playSong(song);
      }
    };
    setTimeout(tick, 1000);
  }

  function playSong(song) {
    nowPlaying = song;
    Q.api('now_playing.php', { room_id: ROOM, host_token: TOKEN, song_id: song.id }).catch(() => {});
    // If resolve hasn't finished yet, wait briefly; otherwise play now.
    if (resolving || !player.src) {
      // Wait for resolve to complete (max 10s), then play.
      const wait = setInterval(() => {
        if (!resolving && player.src) {
          clearInterval(wait);
          player.play().catch(() => {});
          applyPitch(parseInt(document.getElementById('pitch').value, 10));
        }
      }, 200);
      setTimeout(() => clearInterval(wait), 10000);
    } else {
      player.play().catch(() => {});
      applyPitch(parseInt(document.getElementById('pitch').value, 10));
    }
    renderQueue(); renderNowBar();
  }

  function onSongEnded() {
    if (nowPlaying) {
      Q.api('now_playing.php', { room_id: ROOM, host_token: TOKEN, song_id: null }).catch(() => {});
      nowPlaying = null;
      player.removeAttribute('src');
      player.load();
    }
    renderNowBar();
    setTimeout(maybeAdvance, 400);
  }

  // ---------- Skip ----------
  document.getElementById('btn-skip').addEventListener('click', async () => {
    if (!nowPlaying) return;
    try {
      await Q.api('skip.php', { room_id: ROOM, host_token: TOKEN });
      player.pause();
      nowPlaying = null;
      player.removeAttribute('src');
      player.load();
      renderNowBar();
      setTimeout(maybeAdvance, 300);
    } catch {}
  });

  // ---------- Theater mode ----------
  document.getElementById('btn-theater').addEventListener('click', () => {
    document.querySelector('.sidebar').classList.toggle('hidden');
  });

  // ---------- Mic button ----------
  document.getElementById('btn-mic').addEventListener('click', toggleMic);

  // ---------- Guest approval ----------
  async function decideGuest(guestId, decision) {
    try {
      await Q.api('approve.php', { room_id: ROOM, host_token: TOKEN, guest_id: guestId, decision });
      document.querySelector(`.guest-toast[data-gid="${guestId}"]`)?.remove();
    } catch {}
  }
  function addGuestRequest(g) {
    const host = document.getElementById('guest-requests');
    if (host.querySelector('.small.muted')) host.innerHTML = '';
    if (host.querySelector(`[data-gid="${g.guest_id}"]`)) return;
    const el = document.createElement('div');
    el.className = 'guest-toast'; el.dataset.gid = g.guest_id;
    el.innerHTML = `
      <div class="avatar" style="background:${Q.avatarColor(g.nickname)}">${Q.initials(g.nickname)}</div>
      <div><div style="font-weight:600">${esc(g.nickname)}</div><div class="small muted">wants to join</div></div>
      <div class="btns">
        <button class="btn btn-sm btn-primary" data-d="approved">✓</button>
        <button class="btn btn-sm btn-danger" data-d="kicked">✕</button>
      </div>`;
    el.querySelectorAll('button').forEach(b => b.addEventListener('click', () => decideGuest(g.guest_id, b.dataset.d)));
    host.appendChild(el);
  }

  // ---------- Pusher bindings ----------
  Q.bind(ROOM, 'guest-join-request', (d) => addGuestRequest(d));
  Q.bind(ROOM, 'song-added', (d) => {
    if (!queue.find(s => s.id === d.id)) { queue.push(d); renderQueue(); }
    maybeAdvance();
  });
  Q.bind(ROOM, 'song-skipped', () => {
    player.pause();
    nowPlaying = null;
    player.removeAttribute('src');
    player.load();
    renderNowBar();
    setTimeout(maybeAdvance, 300);
  });
  Q.bind(ROOM, 'song-voted', (d) => {
    const s = queue.find(x => x.id === d.song_id); if (s) { s.votes = d.votes; renderQueue(); }
  });
  Q.bind(ROOM, 'queue-reordered', (d) => {
    const order = d.ordered_ids || [];
    queue.sort((a, b) => {
      const ia = order.indexOf(a.id), ib = order.indexOf(b.id);
      if (ia === -1 && ib === -1) return a.position - b.position;
      if (ia === -1) return 1; if (ib === -1) return -1;
      return ia - ib;
    });
    renderQueue();
  });
  Q.bind(ROOM, 'reaction', (d) => handleReaction(d));
  Q.bind(ROOM, 'now-playing', () => {/* TV is the source of this; ignore own echo */});

  // ---------- Initial state load ----------
  (async function init() {
    try {
      const s = await Q.api('state.php?room_id=' + ROOM);
      queue = s.queue || [];
      const playing = queue.find(x => x.status === 'playing');
      if (playing) {
        nowPlaying = playing;
        // Resume the same video on reload.
        try {
          const r = await Q.api('resolve.php?video_id=' + encodeURIComponent(playing.video_id));
          player.src = r.url;
          player.play().catch(() => {});
        } catch {}
      }
      renderQueue(); renderNowBar();
      if (s.room && typeof s.room.pitch === 'number') {
        document.getElementById('pitch').value = s.room.pitch;
        applyPitch(s.room.pitch);
      }
      if (!playing) maybeAdvance();
    } catch {}
  })();
})();
